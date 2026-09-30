<?php
/**
 * Text layout output.
 *
 * Implements the output side of SPEC.md §6.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Positioned lines ready for a rasteriser to draw.
 *
 * Every coordinate is absolute within the card, and every baseline is a baseline —
 * not a bounding-box top. Renderers draw from these numbers without re-deriving
 * anything, which is what keeps server output aligned with the editor preview.
 *
 * @since 0.1.0
 */
final class LayoutResult {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param float                                                                    $size      Chosen font size in pixels.
	 * @param array<int, array{text: string, x: float, baseline: float, width: float}> $lines Positioned lines.
	 * @param bool                                                                     $collapsed Whether the layer resolved to nothing.
	 * @param bool                                                                     $truncated Whether overflow handling removed text.
	 * @param float                                                                    $height    Total laid-out height in pixels.
	 * @param bool                                                                     $rtl       Whether the text was laid out right to left.
	 */
	public function __construct(
		public readonly float $size,
		public readonly array $lines,
		public readonly bool $collapsed = false,
		public readonly bool $truncated = false,
		public readonly float $height = 0.0,
		public readonly bool $rtl = false
	) {}

	/**
	 * An empty result for a collapsed layer.
	 *
	 * @since 0.1.0
	 *
	 * @return self Collapsed result.
	 */
	public static function collapsed(): self {
		return new self( 0.0, array(), true );
	}

	/**
	 * Number of laid-out lines.
	 *
	 * @since 0.1.0
	 *
	 * @return int Line count.
	 */
	public function line_count(): int {
		return count( $this->lines );
	}

	/**
	 * The plain text of each line.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Line texts.
	 */
	public function texts(): array {
		return array_map(
			static fn ( array $line ): string => $line['text'],
			$this->lines
		);
	}
}
