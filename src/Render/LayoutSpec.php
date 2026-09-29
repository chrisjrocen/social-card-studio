<?php
/**
 * Text layout inputs.
 *
 * Implements the input side of SPEC.md §6.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Card\Layer;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the layout engine needs about one text layer.
 *
 * @since 0.1.0
 */
final class LayoutSpec {

	public const OVERFLOW_ELLIPSIS   = 'ellipsis';
	public const OVERFLOW_SHRINK_BOX = 'shrink_box';
	public const OVERFLOW_CLIP       = 'clip';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Box    $box            Layer rectangle.
	 * @param float  $size_min       Smallest font size in pixels.
	 * @param float  $size_max       Largest font size in pixels.
	 * @param bool   $autofit        Whether to search for the largest fitting size.
	 * @param float  $line_height    Line height as a multiple of the font size.
	 * @param float  $tracking       Letter spacing as a fraction of the font size.
	 * @param string $align          left | center | right.
	 * @param string $vertical_align top | middle | bottom.
	 * @param int    $max_lines      Line ceiling.
	 * @param string $overflow       One of the OVERFLOW_* constants.
	 * @param string $transform      none | uppercase | lowercase.
	 * @param bool   $flexible       Whether the box may shrink to fit.
	 */
	public function __construct(
		public readonly Box $box,
		public readonly float $size_min = 16.0,
		public readonly float $size_max = 64.0,
		public readonly bool $autofit = true,
		public readonly float $line_height = 1.2,
		public readonly float $tracking = 0.0,
		public readonly string $align = 'left',
		public readonly string $vertical_align = 'top',
		public readonly int $max_lines = 3,
		public readonly string $overflow = self::OVERFLOW_ELLIPSIS,
		public readonly string $transform = 'none',
		public readonly bool $flexible = false
	) {}

	/**
	 * Builds from a validated text layer.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer $layer Text layer.
	 *
	 * @return self New spec.
	 */
	public static function from_layer( Layer $layer ): self {
		$size = (array) $layer->get( 'size', array() );

		return new self(
			$layer->box(),
			(float) ( $size['min'] ?? 16 ),
			(float) ( $size['max'] ?? 64 ),
			(bool) ( $size['autofit'] ?? true ),
			(float) $layer->get( 'line_height', 1.2 ),
			(float) $layer->get( 'tracking', 0.0 ),
			(string) $layer->get( 'align', 'left' ),
			(string) $layer->get( 'vertical_align', 'top' ),
			(int) $layer->get( 'max_lines', 3 ),
			(string) $layer->get( 'overflow', self::OVERFLOW_ELLIPSIS ),
			(string) $layer->get( 'transform', 'none' ),
			$layer->is_flexible()
		);
	}
}
