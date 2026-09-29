<?php
/**
 * Contrast guard for text over imagery.
 *
 * Implements the auto-contrast scrim of SPEC.md §2.1 and the legibility guard that
 * AI backgrounds depend on in §13.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Support\Color;

defined( 'ABSPATH' ) || exit;

/**
 * Deepens a scrim until the text above it is readable.
 *
 * A headline that cannot be read is not a design choice, and a photographic
 * background is not under anyone's control at design time. This is what stops a
 * template that looks fine over a dark photo from becoming unreadable over a bright
 * one.
 *
 * @since 0.1.0
 */
final class Legibility {

	/**
	 * WCAG AA for large text, and the target named in SPEC §2.1.
	 */
	public const TARGET = 4.5;

	/**
	 * How far a scrim may be deepened before we stop.
	 *
	 * Past this the card stops looking like a photograph at all, so the honest
	 * outcome is a slightly-under-target card rather than a black rectangle.
	 */
	public const MAX_ALPHA = 0.92;

	/**
	 * Alpha increment per attempt.
	 */
	private const STEP = 0.06;

	/**
	 * Raises a scrim's alpha until the text meets the contrast target.
	 *
	 * @since 0.1.0
	 *
	 * @param Color $backdrop  Average colour sampled beneath the text.
	 * @param Color $text      Text colour.
	 * @param Color $scrim     Scrim colour, alpha included.
	 * @param float $target    Contrast target.
	 * @param float $max_alpha Alpha ceiling.
	 *
	 * @return array{scrim: Color, contrast: float, reached: bool, steps: int} Outcome.
	 */
	public function deepen(
		Color $backdrop,
		Color $text,
		Color $scrim,
		float $target = self::TARGET,
		float $max_alpha = self::MAX_ALPHA
	): array {
		$alpha    = $scrim->a;
		$steps    = 0;
		$current  = $scrim;
		$contrast = $text->contrast( $current->over( $backdrop ) );

		while ( $contrast < $target && $alpha < $max_alpha ) {
			$alpha    = min( $max_alpha, $alpha + self::STEP );
			$current  = $scrim->with_alpha( $alpha );
			$contrast = $text->contrast( $current->over( $backdrop ) );
			++$steps;
		}

		return array(
			'scrim'    => $current,
			'contrast' => $contrast,
			'reached'  => $contrast >= $target,
			'steps'    => $steps,
		);
	}

	/**
	 * Whether text is already readable over a backdrop.
	 *
	 * @since 0.1.0
	 *
	 * @param Color $backdrop Composited colour beneath the text.
	 * @param Color $text     Text colour.
	 * @param float $target   Contrast target.
	 *
	 * @return bool True when no scrim is needed.
	 */
	public function is_legible( Color $backdrop, Color $text, float $target = self::TARGET ): bool {
		return $text->contrast( $backdrop ) >= $target;
	}

	/**
	 * Averages a set of sampled pixels into one backdrop colour.
	 *
	 * Averaging is deliberately crude. The alternative — checking every pixel — makes
	 * a single bright speck in a dark photo deepen the scrim to opaque, which is a
	 * worse card than one measured against the region's overall tone.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: int, 2: int}> $samples RGB triples.
	 *
	 * @return Color Average colour, fully opaque.
	 */
	public function average( array $samples ): Color {
		if ( array() === $samples ) {
			return Color::parse( '#808080' ) ?? Color::parse( '#000000' );
		}

		$r = 0;
		$g = 0;
		$b = 0;

		foreach ( $samples as $sample ) {
			$r += (int) $sample[0];
			$g += (int) $sample[1];
			$b += (int) $sample[2];
		}

		$count = count( $samples );

		return Color::parse(
			sprintf(
				'#%02X%02X%02X',
				(int) round( $r / $count ),
				(int) round( $g / $count ),
				(int) round( $b / $count )
			)
		) ?? Color::parse( '#808080' );
	}
}
