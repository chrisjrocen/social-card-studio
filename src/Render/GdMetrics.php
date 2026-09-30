<?php
/**
 * GD text measurement.
 *
 * Implements the GD half of SPEC.md §6.3 step 2a.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Measures with imagettfbbox.
 *
 * @since 0.1.0
 */
final class GdMetrics implements MetricsProvider {

	/**
	 * Points per pixel.
	 *
	 * GD's `$size` argument is a point size which it rasterises at 96 DPI, while
	 * the layout engine and the browser preview think in pixels. Passing the same
	 * number to both makes GD render about a third larger, which is exactly the
	 * kind of silent divergence the parity rule in SPEC §2.3 forbids. Every size crossing this boundary is
	 * converted; nothing above this class needs to know GD thinks in points.
	 */
	private const POINTS_PER_PIXEL = 0.75;

	/**
	 * Measured widths, keyed by font, size, tracking and text.
	 *
	 * Autofit measures the same strings repeatedly across candidate sizes, and
	 * imagettfbbox is not cheap.
	 *
	 * @var array<string, float>
	 */
	private array $cache = array();

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Provider identifier.
	 */
	public function id(): string {
		return 'gd';
	}

	/**
	 * Measures a line.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Text to measure.
	 * @param ResolvedFont $font     Font to measure with.
	 * @param float        $size     Nominal font size in pixels.
	 * @param float        $tracking Letter spacing as a fraction of the font size.
	 *
	 * @return float Advance width in pixels.
	 */
	public function width( string $text, ResolvedFont $font, float $size, float $tracking = 0.0 ): float {
		if ( '' === $text ) {
			return 0.0;
		}

		$key = $font->path . '|' . $size . '|' . $tracking . '|' . $text;

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$box = imagettfbbox( $size * self::POINTS_PER_PIXEL, 0.0, $font->path, $text );

		if ( false === $box ) {
			$this->cache[ $key ] = 0.0;

			return 0.0;
		}

		// Lower-right minus lower-left is the advance, including leading side bearing.
		$width = (float) ( $box[2] - $box[0] );

		$this->cache[ $key ] = $width + TextLayout::tracking_width( $text, $size, $tracking );

		return $this->cache[ $key ];
	}

	/**
	 * Vertical metrics.
	 *
	 * GD exposes no font-wide ascender, so it is derived from a string of tall
	 * characters. That is stable for a given face and size, which is what
	 * baseline placement needs.
	 *
	 * @since 0.1.0
	 *
	 * @param ResolvedFont $font Font to measure.
	 * @param float        $size Nominal font size in pixels.
	 *
	 * @return array{ascender: float, descender: float} Pixel metrics.
	 */
	public function vertical( ResolvedFont $font, float $size ): array {
		$points = $size * self::POINTS_PER_PIXEL;
		$upper  = imagettfbbox( $points, 0.0, $font->path, 'HÅMbdfhklt' );
		$lower  = imagettfbbox( $points, 0.0, $font->path, 'gjpqy,' );

		if ( false === $upper || false === $lower ) {
			// Typical proportions, so a measurement failure still produces a card.
			return array(
				'ascender'  => $size * 0.8,
				'descender' => $size * 0.2,
			);
		}

		return array(
			'ascender'  => (float) abs( $upper[5] ),
			'descender' => (float) max( 0, $lower[1] ),
		);
	}

	/**
	 * Reports whether a font file can be measured at all.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute font path.
	 *
	 * @return bool True when GD can open it.
	 */
	public static function can_read( string $path ): bool {
		if ( ! function_exists( 'imagettfbbox' ) || ! is_readable( $path ) ) {
			return false;
		}

		return false !== @imagettfbbox( 12.0, 0.0, $path, 'A' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed font emits a warning; the boolean is the answer we want.
	}

	/**
	 * Reports whether a font covers every character of a string.
	 *
	 * Implements the coverage check of SPEC §6.1 step 5. GD draws a missing glyph as
	 * a blank or a box, so a width comparison against a known-absent codepoint is the
	 * available signal.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text Text to check.
	 * @param ResolvedFont $font Font to check against.
	 *
	 * @return bool True when every character appears to have a glyph.
	 */
	public function covers( string $text, ResolvedFont $font ): bool {
		$missing = $this->width( "\u{FFFF}", $font, 64.0 );

		foreach ( Str::graphemes( $text ) as $grapheme ) {
			if ( '' === trim( $grapheme ) ) {
				continue;
			}

			$width = $this->width( $grapheme, $font, 64.0 );

			if ( $width <= 0.0 || ( $missing > 0.0 && abs( $width - $missing ) < 0.01 ) ) {
				return false;
			}
		}

		return true;
	}
}
