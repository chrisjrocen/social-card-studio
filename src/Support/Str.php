<?php
/**
 * String helpers.
 *
 * Implements the normalisation steps of SPEC.md §6.3 step 1 and §8 token trimming.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Text normalisation used by the layout engine and the token resolver.
 *
 * @since 0.1.0
 */
final class Str {

	/**
	 * Matches emoji, pictographs, dingbats, variation selectors and ZWJ.
	 */
	private const EMOJI_PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{200D}\x{20E3}]/u';

	/**
	 * Normalises post-derived text for rendering.
	 *
	 * Order matters: shortcodes are stripped before tags, because a shortcode
	 * attribute can contain angle brackets that would otherwise leave debris behind.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text  Raw text.
	 * @param bool   $emoji Whether to keep emoji. False strips them (SPEC §6.2).
	 *
	 * @return string Normalised single-line text.
	 */
	public static function normalize( string $text, bool $emoji = false ): string {
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! $emoji ) {
			$text = self::strip_emoji( $text );
		}

		return self::collapse_whitespace( $text );
	}

	/**
	 * Removes emoji and their joiners.
	 *
	 * GD cannot draw colour emoji at all (SPEC §6.2), and a monochrome fallback glyph
	 * is worse than nothing on a branded card.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text without emoji.
	 */
	public static function strip_emoji( string $text ): string {
		$stripped = preg_replace( self::EMOJI_PATTERN, '', $text );

		return null === $stripped ? $text : $stripped;
	}

	/**
	 * Collapses all whitespace runs, including non-breaking spaces, to one space.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Input text.
	 *
	 * @return string Trimmed, single-spaced text.
	 */
	public static function collapse_whitespace( string $text ): string {
		$text      = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B" ), ' ', $text );
		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( null === $collapsed ? $text : $collapsed );
	}

	/**
	 * Truncates at a word boundary.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text   Input text.
	 * @param int    $length Maximum length in characters.
	 * @param string $append Appended when truncation occurred.
	 *
	 * @return string Truncated text.
	 */
	public static function truncate_words( string $text, int $length, string $append = '…' ): string {
		if ( $length <= 0 || mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $length );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t\n\r\0\x0B,.;:—-" ) . $append;
	}

	/**
	 * Splits a string into grapheme clusters.
	 *
	 * Used by the layout engine, which may never break mid-cluster (SPEC §6.3 step 2b).
	 * Falls back to code points when the intl extension is unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Input text.
	 *
	 * @return string[] Ordered clusters.
	 */
	public static function graphemes( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		if ( function_exists( 'grapheme_strlen' ) && function_exists( 'grapheme_substr' ) ) {
			$count = grapheme_strlen( $text );

			if ( is_int( $count ) ) {
				$out = array();

				for ( $i = 0; $i < $count; $i++ ) {
					$out[] = (string) grapheme_substr( $text, $i, 1 );
				}

				return $out;
			}
		}

		$split = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return false === $split ? array() : $split;
	}
}
