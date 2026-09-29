<?php
/**
 * The bundled OFL families.
 *
 * Implements SPEC.md §6.1 step 4.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue of the fonts shipped with the plugin.
 *
 * All three are SIL OFL 1.1, subsetted to Latin and Latin-Ext, with their licence
 * files committed alongside them. They exist so the chain in SPEC §6.1 always has a
 * step that cannot fail: a card renderer that falls back to nothing renders tofu.
 *
 * @since 0.1.0
 */
final class BundledFonts {

	/**
	 * Family definitions, keyed by slug.
	 *
	 * @var array<string, array{name: string, weights: array<int, string>, roles: string[]}>
	 */
	private const FAMILIES = array(
		'inter'          => array(
			'name'    => 'Inter',
			'weights' => array(
				400 => 'Inter-Regular.ttf',
				600 => 'Inter-SemiBold.ttf',
				700 => 'Inter-Bold.ttf',
			),
			'roles'   => array( ResolvedFont::ROLE_HEADING, ResolvedFont::ROLE_BODY ),
		),
		'source-serif-4' => array(
			'name'    => 'Source Serif 4',
			'weights' => array(
				400 => 'SourceSerif4-Regular.ttf',
				700 => 'SourceSerif4-Bold.ttf',
			),
			'roles'   => array( ResolvedFont::ROLE_HEADING, ResolvedFont::ROLE_BODY ),
		),
		'jetbrains-mono' => array(
			'name'    => 'JetBrains Mono',
			'weights' => array( 500 => 'JetBrainsMono-Medium.ttf' ),
			'roles'   => array( ResolvedFont::ROLE_MONO ),
		),
	);

	/**
	 * Default family per role.
	 */
	private const DEFAULTS = array(
		ResolvedFont::ROLE_HEADING => 'inter',
		ResolvedFont::ROLE_BODY    => 'inter',
		ResolvedFont::ROLE_MONO    => 'jetbrains-mono',
	);

	/**
	 * Absolute path to the bundled font directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string Directory path without a trailing slash.
	 */
	public static function directory(): string {
		return rtrim( SCSTUDIO_DIR, '/' ) . '/assets/fonts';
	}

	/**
	 * Returns every family, for the settings UI.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{name: string, weights: array<int, string>, roles: string[]}> Families.
	 */
	public static function all(): array {
		return self::FAMILIES;
	}

	/**
	 * Resolves a bundled family and weight to a font.
	 *
	 * Falls to the nearest available weight rather than failing: a template asking for
	 * 600 on a family that ships 400 and 700 should get one of them, not tofu.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug   Family slug.
	 * @param string $role   Role the font is being resolved for.
	 * @param int    $weight Desired weight.
	 * @param string $step   Chain step to record.
	 *
	 * @return ResolvedFont|null Font, or null when the family is unknown or missing.
	 */
	public static function get( string $slug, string $role, int $weight = 400, string $step = ResolvedFont::STEP_BUNDLED ): ?ResolvedFont {
		if ( ! isset( self::FAMILIES[ $slug ] ) ) {
			return null;
		}

		$family    = self::FAMILIES[ $slug ];
		$available = array_keys( $family['weights'] );

		usort(
			$available,
			static fn ( int $a, int $b ): int => abs( $a - $weight ) <=> abs( $b - $weight )
		);

		$closest = (int) ( $available[0] ?? 400 );
		$path    = self::directory() . '/' . $family['weights'][ $closest ];

		if ( ! is_readable( $path ) ) {
			return null;
		}

		return new ResolvedFont( $role, $family['name'], $path, $closest, $step );
	}

	/**
	 * The default bundled font for a role.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role   One of the ResolvedFont::ROLE_* constants.
	 * @param int    $weight Desired weight.
	 *
	 * @return ResolvedFont|null Font, or null when the bundle is missing.
	 */
	public static function default_for( string $role, int $weight = 400 ): ?ResolvedFont {
		return self::get( self::DEFAULTS[ $role ] ?? 'inter', $role, $weight );
	}

	/**
	 * Reports whether every bundled file is present.
	 *
	 * Surfaced in Diagnostics: an incomplete build is otherwise only discovered when a
	 * card renders blank.
	 *
	 * @since 0.1.0
	 *
	 * @return array{ok: bool, missing: string[]} Bundle health.
	 */
	public static function verify(): array {
		$missing = array();

		foreach ( self::FAMILIES as $family ) {
			foreach ( $family['weights'] as $file ) {
				if ( ! is_readable( self::directory() . '/' . $file ) ) {
					$missing[] = $file;
				}
			}
		}

		return array(
			'ok'      => array() === $missing,
			'missing' => $missing,
		);
	}
}
