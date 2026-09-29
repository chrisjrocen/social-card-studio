<?php
/**
 * Rectangle value object.
 *
 * Implements the `box` structure of SPEC.md §2.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable rectangle in card coordinates.
 *
 * @since 0.1.0
 */
final class Box {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int $x Left edge.
	 * @param int $y Top edge.
	 * @param int $w Width.
	 * @param int $h Height.
	 */
	public function __construct(
		public readonly int $x,
		public readonly int $y,
		public readonly int $w,
		public readonly int $h
	) {}

	/**
	 * Builds from a validated array.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $raw Validated box data.
	 *
	 * @return self New box.
	 */
	public static function from_array( array $raw ): self {
		return new self(
			(int) ( $raw['x'] ?? 0 ),
			(int) ( $raw['y'] ?? 0 ),
			(int) ( $raw['w'] ?? 0 ),
			(int) ( $raw['h'] ?? 0 )
		);
	}

	/**
	 * Right edge.
	 *
	 * @since 0.1.0
	 *
	 * @return int X coordinate of the right edge.
	 */
	public function right(): int {
		return $this->x + $this->w;
	}

	/**
	 * Bottom edge.
	 *
	 * @since 0.1.0
	 *
	 * @return int Y coordinate of the bottom edge.
	 */
	public function bottom(): int {
		return $this->y + $this->h;
	}

	/**
	 * Serialises back to an array.
	 *
	 * @since 0.1.0
	 *
	 * @return array{x: int, y: int, w: int, h: int} Box data.
	 */
	public function to_array(): array {
		return array(
			'x' => $this->x,
			'y' => $this->y,
			'w' => $this->w,
			'h' => $this->h,
		);
	}
}
