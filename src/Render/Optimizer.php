<?php
/**
 * Output format and size budget.
 *
 * Implements SPEC.md §7.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the quality ladder until the card fits its byte budget.
 *
 * WebP and AVIF are impossible here by construction, not by configuration. The
 * renderers encode through Imagick and GD directly rather than through
 * WP_Image_Editor, so the site's media preferences never reach this code and there is
 * no `image_editor_output_format` filter to leak through — which is the outcome
 * SPEC §7.2 asks for, reached by removing the pathway instead of guarding it.
 *
 * @since 0.1.0
 */
final class Optimizer {

	/**
	 * Quality ladder from SPEC §7.2.
	 */
	public const LADDER = array( 82, 74, 66, 58 );

	/**
	 * Target size: cards below this are left alone.
	 */
	public const TARGET_BYTES = 600 * 1024;

	/**
	 * Hard ceiling.
	 */
	public const MAX_BYTES = 1024 * 1024;

	/**
	 * Above this a card is discarded outright: Facebook drops it and WhatsApp will
	 * not preview it, so shipping it would be worse than falling back.
	 */
	public const DISCARD_BYTES = 5 * 1024 * 1024;

	/**
	 * Formats this plugin will ever write.
	 */
	public const MIME_JPEG = 'image/jpeg';
	public const MIME_PNG  = 'image/png';

	/**
	 * Runs the ladder.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(int): string $encode       Encodes at a given quality, returning bytes.
	 * @param int                   $start        Starting quality.
	 * @param int                   $target_bytes Size to get under, if possible.
	 *
	 * @throws RenderException When even the lowest quality exceeds the discard threshold.
	 *
	 * @return array{bytes: string, quality: int, steps: int} Chosen encoding.
	 */
	public function optimize(
		callable $encode,
		int $start = 82,
		int $target_bytes = self::TARGET_BYTES
	): array {
		$ladder = $this->ladder_from( $start );
		$best   = null;
		$steps  = 0;

		foreach ( $ladder as $quality ) {
			++$steps;
			$bytes = (string) $encode( $quality );
			$size  = strlen( $bytes );

			$best = array(
				'bytes'   => $bytes,
				'quality' => $quality,
				'steps'   => $steps,
			);

			if ( $size <= $target_bytes ) {
				return $best;
			}
		}

		$size = strlen( (string) ( $best['bytes'] ?? '' ) );

		if ( $size > self::DISCARD_BYTES ) {
			throw new RenderException(
				RenderException::REASON_OVERSIZE,
				sprintf( 'The rendered card is %d bytes, above the %d byte discard threshold.', $size, self::DISCARD_BYTES )
			);
		}

		if ( null === $best ) {
			throw new RenderException( RenderException::REASON_ENCODE, 'The encoder produced nothing.' );
		}

		/*
		 * Between the ceiling and the discard threshold the card still ships. It is
		 * larger than we would like, but a slightly heavy card that unfurls beats no
		 * card at all; the caller logs the overshoot.
		 */
		return $best;
	}

	/**
	 * Whether a result overshot the ceiling.
	 *
	 * @since 0.1.0
	 *
	 * @param int $size      Encoded size.
	 * @param int $max_bytes Ceiling.
	 *
	 * @return bool True when oversize.
	 */
	public function is_oversize( int $size, int $max_bytes = self::MAX_BYTES ): bool {
		return $size > $max_bytes;
	}

	/**
	 * The ladder to walk, starting at or below the requested quality.
	 *
	 * @since 0.1.0
	 *
	 * @param int $start Starting quality.
	 *
	 * @return int[] Descending qualities.
	 */
	private function ladder_from( int $start ): array {
		$ladder = array();

		foreach ( self::LADDER as $quality ) {
			if ( $quality <= $start ) {
				$ladder[] = $quality;
			}
		}

		if ( array() === $ladder ) {
			return array( min( self::LADDER ) );
		}

		// An explicit quality above the ladder's top is honoured as a first attempt.
		if ( $start > self::LADDER[0] ) {
			array_unshift( $ladder, min( 100, $start ) );
		}

		return $ladder;
	}
}
