<?php
/**
 * Text measurement abstraction.
 *
 * Implements the measurement seam required by SPEC.md §6.3 step 2a, so that
 * ImagickRenderer, GdRenderer and the parity harness all drive one layout engine.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Measures text for the layout engine.
 *
 * Every implementation must report widths in **pixels** for a nominal pixel font
 * size, whatever units its underlying library prefers. GD in particular takes points
 * at 96 DPI, so GdMetrics converts; without that the two engines disagree by a third
 * and no amount of layout care produces matching output.
 *
 * @since 0.1.0
 */
interface MetricsProvider {

	/**
	 * Identifier used in diagnostics and the input hash.
	 *
	 * @since 0.1.0
	 *
	 * @return string Provider identifier.
	 */
	public function id(): string;

	/**
	 * Measures the advance width of a single line.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Text to measure, already normalised.
	 * @param ResolvedFont $font     Font to measure with.
	 * @param float        $size     Nominal font size in pixels.
	 * @param float        $tracking Letter spacing as a fraction of the font size.
	 *
	 * @return float Advance width in pixels.
	 */
	public function width( string $text, ResolvedFont $font, float $size, float $tracking = 0.0 ): float;

	/**
	 * Returns vertical metrics for a font at a size.
	 *
	 * @since 0.1.0
	 *
	 * @param ResolvedFont $font Font to measure.
	 * @param float        $size Nominal font size in pixels.
	 *
	 * @return array{ascender: float, descender: float} Pixel metrics; descender is positive.
	 */
	public function vertical( ResolvedFont $font, float $size ): array;
}
