<?php
/**
 * Unicode script detection.
 *
 * Implements the detection half of SPEC.md §6.1 step 5 and §6.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the dominant script of a string and what drawing it would require.
 *
 * @since 0.1.0
 */
final class Script {

	public const LATIN      = 'latin';
	public const CYRILLIC   = 'cyrillic';
	public const GREEK      = 'greek';
	public const HEBREW     = 'hebrew';
	public const ARABIC     = 'arabic';
	public const DEVANAGARI = 'devanagari';
	public const ETHIOPIC   = 'ethiopic';
	public const HAN        = 'han';
	public const KANA       = 'kana';
	public const HANGUL     = 'hangul';
	public const THAI       = 'thai';
	public const COMMON     = 'common';

	/**
	 * Scripts whose correct rendering needs contextual shaping.
	 *
	 * Per SPEC §6.2 these need HarfBuzz, which neither GD nor a typical Imagick build
	 * exposes to PHP. Drawing them without it produces disconnected or reversed
	 * glyphs, which is worse than not drawing them at all.
	 */
	private const NEEDS_SHAPING = array(
		self::ARABIC,
		self::DEVANAGARI,
		self::THAI,
	);

	/**
	 * Scripts written right to left.
	 */
	private const RTL = array(
		self::ARABIC,
		self::HEBREW,
	);

	/**
	 * Codepoint ranges per script, checked in order.
	 *
	 * @var array<string, array<int, array{0: int, 1: int}>>
	 */
	private const RANGES = array(
		self::LATIN      => array( array( 0x0041, 0x005A ), array( 0x0061, 0x007A ), array( 0x00C0, 0x024F ), array( 0x1E00, 0x1EFF ), array( 0x2C60, 0x2C7F ), array( 0xA720, 0xA7FF ) ),
		self::GREEK      => array( array( 0x0370, 0x03FF ), array( 0x1F00, 0x1FFF ) ),
		self::CYRILLIC   => array( array( 0x0400, 0x04FF ), array( 0x0500, 0x052F ) ),
		self::HEBREW     => array( array( 0x0590, 0x05FF ), array( 0xFB1D, 0xFB4F ) ),
		self::ARABIC     => array( array( 0x0600, 0x06FF ), array( 0x0750, 0x077F ), array( 0x08A0, 0x08FF ), array( 0xFB50, 0xFDFF ), array( 0xFE70, 0xFEFF ) ),
		self::DEVANAGARI => array( array( 0x0900, 0x097F ), array( 0xA8E0, 0xA8FF ) ),
		self::ETHIOPIC   => array( array( 0x1200, 0x137F ), array( 0x1380, 0x139F ), array( 0x2D80, 0x2DDF ) ),
		self::THAI       => array( array( 0x0E00, 0x0E7F ) ),
		self::HAN        => array( array( 0x4E00, 0x9FFF ), array( 0x3400, 0x4DBF ), array( 0xF900, 0xFAFF ) ),
		self::KANA       => array( array( 0x3040, 0x309F ), array( 0x30A0, 0x30FF ) ),
		self::HANGUL     => array( array( 0xAC00, 0xD7AF ), array( 0x1100, 0x11FF ) ),
	);

	/**
	 * Returns the dominant script of a string.
	 *
	 * Dominant means "most characters", not "first character": a headline that is
	 * mostly Latin with one Greek symbol is Latin, and should not be diverted into
	 * the fallback chain because of one glyph.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to inspect.
	 *
	 * @return string One of the class constants.
	 */
	public static function detect( string $text ): string {
		$counts = self::counts( $text );

		if ( array() === $counts ) {
			return self::COMMON;
		}

		arsort( $counts );

		return (string) array_key_first( $counts );
	}

	/**
	 * Counts characters per script.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to inspect.
	 *
	 * @return array<string, int> Script counts, excluding punctuation and spaces.
	 */
	public static function counts( string $text ): array {
		$counts = array();

		foreach ( self::codepoints( $text ) as $codepoint ) {
			$script = self::script_of( $codepoint );

			if ( self::COMMON === $script ) {
				continue;
			}

			$counts[ $script ] = ( $counts[ $script ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Reports whether a string contains any character needing complex shaping.
	 *
	 * This deliberately asks "any", not "dominant": a Latin headline with one Arabic
	 * word still cannot be drawn correctly.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to inspect.
	 *
	 * @return bool True when shaping is required.
	 */
	public static function needs_shaping( string $text ): bool {
		foreach ( array_keys( self::counts( $text ) ) as $script ) {
			if ( in_array( $script, self::NEEDS_SHAPING, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether a script is written right to left.
	 *
	 * @since 0.1.0
	 *
	 * @param string $script Script identifier.
	 *
	 * @return bool True for RTL scripts.
	 */
	public static function is_rtl_script( string $script ): bool {
		return in_array( $script, self::RTL, true );
	}

	/**
	 * Reports whether a string is dominantly right to left.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to inspect.
	 *
	 * @return bool True when the dominant script is RTL.
	 */
	public static function is_rtl( string $text ): bool {
		return self::is_rtl_script( self::detect( $text ) );
	}

	/**
	 * Reports whether the environment can shape complex scripts.
	 *
	 * Always false in Phase 1: PHP exposes no HarfBuzz binding through either image
	 * extension. It is a method rather than a constant so that the Phase 4 HTML
	 * renderer, which can shape, has somewhere to say so.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when complex shaping is available.
	 */
	public static function can_shape(): bool {
		/**
		 * Filters whether the environment can shape complex scripts.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $can_shape Whether complex shaping is available.
		 */
		return (bool) apply_filters( 'scstudio_can_shape_complex_scripts', false );
	}

	/**
	 * Reverses a simple RTL string for renderers that lay out left to right.
	 *
	 * Only correct for scripts without contextual forms, per SPEC §6.2. Combining
	 * marks are kept attached to their base by reversing grapheme clusters rather
	 * than codepoints.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to reverse.
	 *
	 * @return string Visually ordered text.
	 */
	public static function reverse_logical( string $text ): string {
		return implode( '', array_reverse( Str::graphemes( $text ) ) );
	}

	/**
	 * Returns the codepoints of a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to convert.
	 *
	 * @return int[] Codepoints.
	 */
	private static function codepoints( string $text ): array {
		$out = array();

		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( is_array( $characters ) ? $characters : array() as $character ) {
			$codepoint = mb_ord( $character, 'UTF-8' );

			if ( false !== $codepoint ) {
				$out[] = $codepoint;
			}
		}

		return $out;
	}

	/**
	 * Returns the script of one codepoint.
	 *
	 * @since 0.1.0
	 *
	 * @param int $codepoint Unicode codepoint.
	 *
	 * @return string Script identifier.
	 */
	private static function script_of( int $codepoint ): string {
		foreach ( self::RANGES as $script => $ranges ) {
			foreach ( $ranges as $range ) {
				if ( $codepoint >= $range[0] && $codepoint <= $range[1] ) {
					return $script;
				}
			}
		}

		return self::COMMON;
	}
}
