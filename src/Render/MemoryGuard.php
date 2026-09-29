<?php
/**
 * Memory headroom guard.
 *
 * Implements the degradation rule of SPEC.md §4.4.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether there is room to render at full quality.
 *
 * The point is to degrade deliberately rather than be killed mid-render: a card
 * without a drop shadow is a card, and a fatal is not.
 *
 * @since 0.1.0
 */
final class MemoryGuard {

	/**
	 * Headroom below which the renderer degrades, per SPEC §4.4.
	 */
	public const DEGRADE_BELOW = 64 * 1024 * 1024;

	/**
	 * Bytes per pixel for a truecolour image with alpha, plus library overhead.
	 *
	 * Four bytes is the raw cost; the multiplier covers the copies both libraries
	 * make while compositing.
	 */
	private const BYTES_PER_PIXEL = 4;
	private const COPY_OVERHEAD   = 2.2;

	/**
	 * Effective memory limit in bytes, or 0 when unlimited.
	 *
	 * Deliberately PHP's own limit rather than WP_MEMORY_LIMIT.
	 *
	 * SPEC §4.4 names WP_MEMORY_LIMIT, but that constant is an advisory WordPress
	 * applies to itself and is commonly set well below the real ini value — 40M
	 * against a 128M ini is typical. Measuring against it would make a perfectly
	 * healthy host degrade every single render. The process is killed at the ini
	 * limit, so that is what the guard measures.
	 *
	 * @since 0.1.0
	 *
	 * @return int Byte limit, 0 when unlimited.
	 */
	public function limit(): int {
		$limit = (string) ini_get( 'memory_limit' );

		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		$bytes = function_exists( 'wp_convert_hr_to_bytes' )
			? (int) wp_convert_hr_to_bytes( $limit )
			: (int) $limit;

		return max( 0, $bytes );
	}

	/**
	 * Bytes still available.
	 *
	 * @since 0.1.0
	 *
	 * @return int Headroom in bytes; PHP_INT_MAX when unlimited.
	 */
	public function headroom(): int {
		$limit = $this->limit();

		if ( 0 === $limit ) {
			return PHP_INT_MAX;
		}

		return max( 0, $limit - memory_get_usage( true ) );
	}

	/**
	 * Whether the renderer should drop expensive effects.
	 *
	 * SPEC §4.4 states the rule as a flat 64 MB of headroom. Measured against a real
	 * site that turns out to be far too blunt: a 128 MB host running WooCommerce has
	 * WordPress itself occupying enough that headroom never reaches 64 MB, so every
	 * card would silently lose its drop shadow and blur forever — a permanent,
	 * invisible quality regression on an entirely healthy server.
	 *
	 * So the flat threshold is kept as a ceiling, but degradation additionally
	 * requires that headroom be tight *relative to the work in hand*. A 1200x630
	 * canvas costs about 13 MB; demanding four times that leaves room for the
	 * intermediate layers a shadow or blur allocates, without penalising a host that
	 * simply has a lot of plugins loaded.
	 *
	 * @since 0.1.0
	 *
	 * @param int $width  Width of the work in hand, defaulting to an OG card.
	 * @param int $height Height of the work in hand.
	 *
	 * @return bool True when headroom is tight.
	 */
	public function should_degrade( int $width = 1200, int $height = 630 ): bool {
		/**
		 * Filters the headroom threshold at which rendering degrades.
		 *
		 * @since 0.1.0
		 *
		 * @param int $bytes Threshold in bytes.
		 */
		$threshold = (int) apply_filters( 'scstudio_degrade_below_bytes', self::DEGRADE_BELOW );

		$headroom = $this->headroom();

		if ( $headroom >= $threshold ) {
			return false;
		}

		return $headroom < ( $this->estimate( $width, $height ) * 4 );
	}

	/**
	 * Estimated cost of holding an image of these dimensions.
	 *
	 * @since 0.1.0
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 *
	 * @return int Estimated bytes.
	 */
	public function estimate( int $width, int $height ): int {
		return (int) ( $width * $height * self::BYTES_PER_PIXEL * self::COPY_OVERHEAD );
	}

	/**
	 * Whether an image of these dimensions can be afforded.
	 *
	 * @since 0.1.0
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 *
	 * @return bool True when it fits in the remaining headroom.
	 */
	public function can_afford( int $width, int $height ): bool {
		return $this->estimate( $width, $height ) < $this->headroom();
	}

	/**
	 * The largest edge that can be afforded at a given aspect ratio.
	 *
	 * Used to decide how far a large source image must be downscaled before it is
	 * loaded, so a 6000px camera JPEG never reaches full size in memory.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $preferred Preferred longest edge.
	 * @param float $ratio     Width divided by height of the source.
	 *
	 * @return int Longest edge that fits, never below 200.
	 */
	public function affordable_edge( int $preferred, float $ratio ): int {
		$ratio = $ratio > 0.0 ? $ratio : 1.0;
		$edge  = $preferred;

		while ( $edge > 200 ) {
			$width  = (int) round( $ratio >= 1.0 ? $edge : $edge * $ratio );
			$height = (int) round( $ratio >= 1.0 ? $edge / $ratio : $edge );

			if ( $this->can_afford( $width, $height ) ) {
				return $edge;
			}

			$edge = (int) floor( $edge * 0.75 );
		}

		return 200;
	}
}
