<?php
/**
 * Canvas value object.
 *
 * Implements the `canvas` and `safe_area` structures of SPEC.md §2.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

defined( 'ABSPATH' ) || exit;

/**
 * The drawing surface and its safe area.
 *
 * @since 0.1.0
 */
final class Canvas {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int                                                 $w         Width in pixels.
	 * @param int                                                 $h         Height in pixels.
	 * @param string                                              $bg        Background colour literal.
	 * @param array{top: int, right: int, bottom: int, left: int} $safe_area Safe-area insets.
	 */
	public function __construct(
		public readonly int $w,
		public readonly int $h,
		public readonly string $bg,
		public readonly array $safe_area
	) {}

	/**
	 * Builds from validated data.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $canvas    Validated canvas data.
	 * @param array<string, mixed> $safe_area Validated safe-area data.
	 *
	 * @return self New canvas.
	 */
	public static function from_array( array $canvas, array $safe_area ): self {
		return new self(
			(int) ( $canvas['w'] ?? 1200 ),
			(int) ( $canvas['h'] ?? 630 ),
			(string) ( $canvas['bg'] ?? '#000000' ),
			array(
				'top'    => (int) ( $safe_area['top'] ?? 0 ),
				'right'  => (int) ( $safe_area['right'] ?? 0 ),
				'bottom' => (int) ( $safe_area['bottom'] ?? 0 ),
				'left'   => (int) ( $safe_area['left'] ?? 0 ),
			)
		);
	}

	/**
	 * The safe area expressed as a box.
	 *
	 * @since 0.1.0
	 *
	 * @return Box Inset rectangle.
	 */
	public function safe_box(): Box {
		return new Box(
			$this->safe_area['left'],
			$this->safe_area['top'],
			$this->w - $this->safe_area['left'] - $this->safe_area['right'],
			$this->h - $this->safe_area['top'] - $this->safe_area['bottom']
		);
	}

	/**
	 * Aspect ratio.
	 *
	 * @since 0.1.0
	 *
	 * @return float Width divided by height.
	 */
	public function ratio(): float {
		return 0 === $this->h ? 0.0 : $this->w / $this->h;
	}
}
