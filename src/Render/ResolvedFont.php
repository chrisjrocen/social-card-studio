<?php
/**
 * A font that has been resolved to a usable file.
 *
 * Implements the output of SPEC.md §6.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The end of the resolution chain: an absolute path a rasteriser can open.
 *
 * Carries the chain step that produced it and the reasons earlier steps were
 * skipped, because "why is my theme font not being used" is the question the
 * Diagnostics screen exists to answer without a support ticket.
 *
 * @since 0.1.0
 */
final class ResolvedFont {

	public const ROLE_HEADING = 'heading';
	public const ROLE_BODY    = 'body';
	public const ROLE_MONO    = 'mono';

	public const ROLES = array( self::ROLE_HEADING, self::ROLE_BODY, self::ROLE_MONO );

	public const STEP_SETTING  = 'setting';
	public const STEP_THEME    = 'theme';
	public const STEP_BUNDLED  = 'bundled';
	public const STEP_FALLBACK = 'script_fallback';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $role    One of the ROLE_* constants.
	 * @param string   $family  Human-readable family name.
	 * @param string   $path    Absolute path to a .ttf or .otf file.
	 * @param int      $weight  Numeric weight, 100-900.
	 * @param string   $step    Chain step that produced this font.
	 * @param string[] $skipped Reasons earlier steps did not win.
	 */
	public function __construct(
		public readonly string $role,
		public readonly string $family,
		public readonly string $path,
		public readonly int $weight = 400,
		public readonly string $step = self::STEP_BUNDLED,
		public readonly array $skipped = array()
	) {}

	/**
	 * A stable identity for the font file.
	 *
	 * Feeds the font fingerprint in SPEC §9.1, so that a theme switch or a re-uploaded
	 * font file invalidates cards without any special-case hook. Size and modification
	 * time are included because a replaced file at the same path is a different font.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Identity data.
	 */
	public function identity(): array {
		$exists = '' !== $this->path && is_readable( $this->path );

		return array(
			'family'   => $this->family,
			'weight'   => $this->weight,
			'path'     => $this->path,
			'size'     => $exists ? (int) filesize( $this->path ) : 0,
			'modified' => $exists ? (int) filemtime( $this->path ) : 0,
		);
	}

	/**
	 * Whether the font file can actually be opened.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when readable.
	 */
	public function is_usable(): bool {
		return '' !== $this->path && is_readable( $this->path );
	}
}
