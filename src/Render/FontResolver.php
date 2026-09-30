<?php
/**
 * Font resolution chain.
 *
 * Implements SPEC.md §6.1 — the single most common way server-side card generators
 * produce garbage, and therefore specified and implemented in detail.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Hash;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a role to a font file a rasteriser can open.
 *
 * Explicit bundled choice, then theme.json, then the bundled families, then the
 * script-coverage filter. First success wins, every step recorded. The recording is
 * not decoration: "inherit theme fonts" fails silently whenever the theme's font is
 * WOFF2 or remote-hosted, and without a trace the symptom is a card that looks
 * wrong for no visible reason.
 *
 * @since 0.1.0
 */
final class FontResolver {

	/**
	 * Extensions a rasteriser can actually open.
	 */
	private const RASTERISABLE = array( 'ttf', 'otf', 'ttc' );

	/**
	 * Extensions that are web-only and cannot be rasterised.
	 */
	private const WEB_ONLY = array( 'woff', 'woff2', 'eot', 'svg' );

	/**
	 * Magic byte sequences for the sfnt container formats, per SPEC §6.1.
	 */
	private const MAGIC = array(
		"\x00\x01\x00\x00", // TrueType outlines.
		'OTTO',             // CFF outlines.
		'true',             // Legacy Apple TrueType.
		'ttcf',             // TrueType collection.
	);

	/**
	 * Cached resolutions, keyed by role and weight.
	 *
	 * @var array<string, ResolvedFont>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct(
		private readonly Settings $settings
	) {}

	/**
	 * Reports whether a byte string starts with a font signature.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bytes At least the first four bytes of a candidate file.
	 *
	 * @return bool True when the signature matches a usable format.
	 */
	public static function has_font_magic( string $bytes ): bool {
		foreach ( self::MAGIC as $magic ) {
			if ( str_starts_with( $bytes, $magic ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves a role.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role   One of the ResolvedFont::ROLE_* constants.
	 * @param int    $weight Desired numeric weight.
	 * @param string $prefer Bundled family slug a template would like, if the chain
	 *                       reaches the bundled step.
	 *
	 * @return ResolvedFont A usable font; the bundled families guarantee one.
	 */
	public function resolve( string $role, int $weight = 400, string $prefer = '' ): ResolvedFont {
		$role = in_array( $role, ResolvedFont::ROLES, true ) ? $role : ResolvedFont::ROLE_BODY;
		$key  = $role . '|' . $weight . '|' . $prefer;

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$skipped = array();

		foreach ( array( 'from_setting', 'from_theme' ) as $step ) {
			$font = $this->{$step}( $role, $weight, $skipped );

			if ( $font instanceof ResolvedFont ) {
				$this->cache[ $key ] = new ResolvedFont(
					$font->role,
					$font->family,
					$font->path,
					$font->weight,
					$font->step,
					$skipped
				);

				return $this->cache[ $key ];
			}
		}

		$bundled = '' !== $prefer
			? BundledFonts::get( $prefer, $role, $weight )
			: null;

		if ( ! $bundled instanceof ResolvedFont ) {
			$bundled = BundledFonts::default_for( $role, $weight );
		}

		if ( ! $bundled instanceof ResolvedFont ) {
			// Only reachable if the plugin's own assets are missing from the build.
			$skipped[]           = 'bundled: the plugin font bundle is incomplete';
			$this->cache[ $key ] = new ResolvedFont( $role, '', '', $weight, ResolvedFont::STEP_BUNDLED, $skipped );

			return $this->cache[ $key ];
		}

		$this->cache[ $key ] = new ResolvedFont(
			$bundled->role,
			$bundled->family,
			$bundled->path,
			$bundled->weight,
			ResolvedFont::STEP_BUNDLED,
			$skipped
		);

		return $this->cache[ $key ];
	}

	/**
	 * Step 5 of SPEC §6.1: substitute when the chosen face lacks coverage.
	 *
	 * Phase 1 bundles Latin-Ext only, so there is nothing to substitute *to* for other
	 * scripts. Rather than pretend, this reports the gap so the caller can fall back
	 * through SPEC §12.1 and log it, which is the honest behaviour for a card that
	 * would otherwise render as boxes.
	 *
	 * @since 0.1.0
	 *
	 * @param ResolvedFont         $font    Font chosen by the chain.
	 * @param string               $text    Text about to be laid out.
	 * @param MetricsProvider|null $metrics Provider able to test coverage.
	 *
	 * @return array{ok: bool, font: ResolvedFont, reason: string} Coverage verdict.
	 */
	public function ensure_coverage( ResolvedFont $font, string $text, ?MetricsProvider $metrics = null ): array {
		$script = Script::detect( $text );

		if ( in_array( $script, array( Script::LATIN, Script::COMMON ), true ) ) {
			return array(
				'ok'     => true,
				'font'   => $font,
				'reason' => '',
			);
		}

		if ( $metrics instanceof GdMetrics && $metrics->covers( $text, $font ) ) {
			return array(
				'ok'     => true,
				'font'   => $font,
				'reason' => '',
			);
		}

		/**
		 * Filters the font used for a script the bundled families do not cover.
		 *
		 * The documented extension point for supplying a Noto subset, per SPEC §6.1
		 * step 5, until the plugin ships them itself.
		 *
		 * @since 0.1.0
		 *
		 * @param ResolvedFont|null $substitute Replacement font, or null.
		 * @param string            $script     Detected script.
		 * @param ResolvedFont      $font       Font the chain chose.
		 */
		$substitute = apply_filters( 'scstudio_script_fallback_font', null, $script, $font );

		if ( $substitute instanceof ResolvedFont && $substitute->is_usable() ) {
			return array(
				'ok'     => true,
				'font'   => $substitute,
				'reason' => '',
			);
		}

		if ( $metrics instanceof GdMetrics ) {
			return array(
				'ok'     => false,
				'font'   => $font,
				'reason' => sprintf( 'no bundled font covers the %s script', $script ),
			);
		}

		return array(
			'ok'     => true,
			'font'   => $font,
			'reason' => '',
		);
	}

	/**
	 * A stable fingerprint of every resolved font.
	 *
	 * Feeds the input hash in SPEC §9.1, which is what makes a theme switch invalidate
	 * affected cards with no special-case hook.
	 *
	 * @since 0.1.0
	 *
	 * @return string Sixteen-character fingerprint.
	 */
	public function fingerprint(): string {
		$identity = array();

		foreach ( ResolvedFont::ROLES as $role ) {
			$identity[ $role ] = $this->resolve( $role )->identity();
		}

		return Hash::of( $identity, 16 );
	}

	/**
	 * The full chain trace, for the Diagnostics screen.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{family: string, path: string, weight: int, step: string, skipped: string[], usable: bool}> Trace per role.
	 */
	public function trace(): array {
		$trace = array();

		foreach ( ResolvedFont::ROLES as $role ) {
			$font = $this->resolve( $role );

			$trace[ $role ] = array(
				'family'  => $font->family,
				'path'    => $font->path,
				'weight'  => $font->weight,
				'step'    => $font->step,
				'skipped' => $font->skipped,
				'usable'  => $font->is_usable(),
			);
		}

		return $trace;
	}

	/**
	 * Discards cached resolutions.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Step 1 of SPEC §6.1: an explicit admin choice.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $role    Role being resolved.
	 * @param int      $weight  Desired weight.
	 * @param string[] $skipped Collected skip reasons, by reference.
	 *
	 * @return ResolvedFont|null Font, or null to continue the chain.
	 */
	private function from_setting( string $role, int $weight, array &$skipped ): ?ResolvedFont {
		$source = (string) $this->settings->get( 'typography.source', 'theme' );

		if ( 'theme' === $source ) {
			return null;
		}

		$choice = (string) $this->settings->get( 'typography.' . $this->setting_key( $role ), '' );

		if ( '' === $choice ) {
			$skipped[] = 'setting: no font chosen for the ' . $role . ' role';

			return null;
		}

		$bundled = BundledFonts::get( $choice, $role, $weight, ResolvedFont::STEP_SETTING );

		if ( $bundled instanceof ResolvedFont ) {
			return $bundled;
		}

		$skipped[] = 'setting: the chosen font "' . $choice . '" is not a bundled family';

		return null;
	}

	/**
	 * Step 2 of SPEC §6.1: theme.json and the Font Library.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $role    Role being resolved.
	 * @param int      $weight  Desired weight.
	 * @param string[] $skipped Collected skip reasons, by reference.
	 *
	 * @return ResolvedFont|null Font, or null to continue the chain.
	 */
	private function from_theme( string $role, int $weight, array &$skipped ): ?ResolvedFont {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			$skipped[] = 'theme: wp_get_global_settings() is unavailable';

			return null;
		}

		$typography = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		$families   = array();

		foreach ( (array) $typography as $group ) {
			foreach ( (array) $group as $family ) {
				if ( is_array( $family ) ) {
					$families[] = $family;
				}
			}
		}

		if ( array() === $families ) {
			$skipped[] = 'theme: the theme declares no font families';

			return null;
		}

		foreach ( $families as $family ) {
			$faces = (array) ( $family['fontFace'] ?? array() );

			if ( array() === $faces ) {
				continue;
			}

			$face = $this->closest_face( $faces, $weight );

			foreach ( (array) ( $face['src'] ?? array() ) as $src ) {
				$resolved = $this->resolve_src( (string) $src, $role, (int) ( $face['fontWeight'] ?? $weight ), (string) ( $family['name'] ?? 'Theme font' ), $skipped );

				if ( $resolved instanceof ResolvedFont ) {
					return $resolved;
				}
			}
		}

		return null;
	}

	/**
	 * Resolves one `src` entry from a theme font face.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $src     Source value.
	 * @param string   $role    Role being resolved.
	 * @param int      $weight  Face weight.
	 * @param string   $family  Family name.
	 * @param string[] $skipped Collected skip reasons, by reference.
	 *
	 * @return ResolvedFont|null Font, or null to keep looking.
	 */
	private function resolve_src( string $src, string $role, int $weight, string $family, array &$skipped ): ?ResolvedFont {
		$extension = strtolower( (string) pathinfo( wp_parse_url( $src, PHP_URL_PATH ) ?? $src, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, self::WEB_ONLY, true ) ) {
			$skipped[] = sprintf(
				'theme: "%s" is %s, which GD cannot rasterise',
				$family,
				strtoupper( $extension )
			);

			return null;
		}

		if ( str_starts_with( $src, 'file:./' ) ) {
			$path = trailingslashit( get_stylesheet_directory() ) . substr( $src, 7 );

			if ( ! is_readable( $path ) ) {
				$path = trailingslashit( get_template_directory() ) . substr( $src, 7 );
			}

			if ( is_readable( $path ) && $this->is_rasterisable( $path ) ) {
				return new ResolvedFont( $role, $family, $path, $weight, ResolvedFont::STEP_THEME );
			}

			$skipped[] = sprintf( 'theme: "%s" points at a file that is missing or unreadable', $family );

			return null;
		}

		if ( preg_match( '#^https?://#i', $src ) ) {
			$skipped[] = sprintf( 'theme: "%s" is hosted remotely; remote fonts are not downloaded', $family );

			return null;
		}

		if ( is_readable( $src ) && $this->is_rasterisable( $src ) ) {
			return new ResolvedFont( $role, $family, $src, $weight, ResolvedFont::STEP_THEME );
		}

		return null;
	}

	/**
	 * Picks the face closest to a desired weight.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $faces  Font faces.
	 * @param int               $weight Desired weight.
	 *
	 * @return array<string, mixed> The closest face.
	 */
	private function closest_face( array $faces, int $weight ): array {
		$best     = array();
		$distance = PHP_INT_MAX;

		foreach ( $faces as $face ) {
			if ( ! is_array( $face ) ) {
				continue;
			}

			// A face weight may be a range such as "400 700"; take the first number.
			$declared = (int) preg_replace( '/\D.*$/', '', (string) ( $face['fontWeight'] ?? '400' ) );
			$gap      = abs( $declared - $weight );

			if ( $gap < $distance ) {
				$distance = $gap;
				$best     = $face;
			}
		}

		return $best;
	}

	/**
	 * Reports whether a file is a font a rasteriser can open.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path.
	 *
	 * @return bool True when usable.
	 */
	private function is_rasterisable( string $path ): bool {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::RASTERISABLE, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading four magic bytes of a local font file; WP_Filesystem needs a credentials round-trip a render cannot perform.
		$magic = (string) file_get_contents( $path, false, null, 0, 4 );

		return self::has_font_magic( $magic );
	}

	/**
	 * Maps a role to its settings key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role Role identifier.
	 *
	 * @return string Settings key.
	 */
	private function setting_key( string $role ): string {
		return ResolvedFont::ROLE_HEADING === $role ? 'heading' : 'body';
	}
}
