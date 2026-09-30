<?php
/**
 * Render output.
 *
 * Implements the RenderResult half of the Renderer contract in SPEC.md §2.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Encoded image bytes plus what it cost to make them.
 *
 * The timings and peak memory are carried out of the renderer rather than logged
 * inside it, so the performance budget in SPEC §4.4 can be asserted by the test suite
 * and shown on the Diagnostics test-render button.
 *
 * @since 0.1.0
 */
final class RenderResult {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $bytes        Encoded image data.
	 * @param int      $width        Pixel width.
	 * @param int      $height       Pixel height.
	 * @param string   $mime         Output MIME type.
	 * @param string   $engine       Engine identifier, e.g. "gd-2.3.3".
	 * @param int      $quality      Quality the encoder settled on.
	 * @param float    $duration_ms  Wall-clock render time.
	 * @param int      $peak_bytes   Peak memory during the render.
	 * @param string[] $degradations Effects skipped to save memory.
	 */
	public function __construct(
		public readonly string $bytes,
		public readonly int $width,
		public readonly int $height,
		public readonly string $mime,
		public readonly string $engine,
		public readonly int $quality,
		public readonly float $duration_ms = 0.0,
		public readonly int $peak_bytes = 0,
		public readonly array $degradations = array()
	) {}

	/**
	 * Encoded size in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @return int Byte length.
	 */
	public function size(): int {
		return strlen( $this->bytes );
	}

	/**
	 * Whether any effect was skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when degraded.
	 */
	public function is_degraded(): bool {
		return array() !== $this->degradations;
	}
}
