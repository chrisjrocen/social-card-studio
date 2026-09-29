<?php
/**
 * Table-driven text measurement.
 *
 * Supports the PHP-versus-JavaScript parity check required by SPEC.md §2.3 and
 * §19.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Measures from a table of advance widths rather than a rasteriser.
 *
 * The parity rule says the PHP and JavaScript layout engines must choose the same
 * font sizes and produce the same line counts. Comparing them through their real
 * rasterisers would not test that: GD, Imagick and a browser canvas each report
 * slightly different widths, so any mismatch would be ambiguous between "the
 * algorithms disagree" and "the rasterisers disagree".
 *
 * Feeding both engines identical advance widths — real ones, extracted from the
 * bundled font — isolates the algorithm, which is the thing that must not drift.
 *
 * @since 0.1.0
 */
final class TableMetrics implements MetricsProvider {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $advances          Advance width per codepoint, in font units.
	 * @param int             $units_per_em      Font design units per em.
	 * @param int             $default_advance   Advance used for codepoints absent from the table.
	 * @param float           $ascender_ratio    Ascender as a fraction of the em.
	 * @param float           $descender_ratio   Descender as a fraction of the em.
	 */
	public function __construct(
		private readonly array $advances,
		private readonly int $units_per_em = 1000,
		private readonly int $default_advance = 0,
		private readonly float $ascender_ratio = 0.8,
		private readonly float $descender_ratio = 0.2
	) {}

	/**
	 * Builds from a decoded metrics fixture.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data Fixture data.
	 *
	 * @return self New provider.
	 */
	public static function from_array( array $data ): self {
		$advances = array();

		foreach ( (array) ( $data['advances'] ?? array() ) as $codepoint => $advance ) {
			$advances[ (int) $codepoint ] = (int) $advance;
		}

		return new self(
			$advances,
			(int) ( $data['unitsPerEm'] ?? 1000 ),
			(int) ( $data['defaultAdvance'] ?? 0 ),
			(float) ( $data['ascenderRatio'] ?? 0.8 ),
			(float) ( $data['descenderRatio'] ?? 0.2 )
		);
	}

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Provider identifier.
	 */
	public function id(): string {
		return 'table';
	}

	/**
	 * Measures a line.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Text to measure.
	 * @param ResolvedFont $font     Unused; the table is the font.
	 * @param float        $size     Nominal font size in pixels.
	 * @param float        $tracking Letter spacing as a fraction of the font size.
	 *
	 * @return float Advance width in pixels.
	 */
	public function width( string $text, ResolvedFont $font, float $size, float $tracking = 0.0 ): float {
		if ( '' === $text ) {
			return 0.0;
		}

		$units = 0;

		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( is_array( $characters ) ? $characters : array() as $character ) {
			$codepoint = mb_ord( $character, 'UTF-8' );

			if ( false === $codepoint ) {
				continue;
			}

			$units += $this->advances[ $codepoint ] ?? $this->default_advance;
		}

		$width = ( $units / $this->units_per_em ) * $size;

		return $width + TextLayout::tracking_width( $text, $size, $tracking );
	}

	/**
	 * Vertical metrics.
	 *
	 * @since 0.1.0
	 *
	 * @param ResolvedFont $font Unused; the table is the font.
	 * @param float        $size Nominal font size in pixels.
	 *
	 * @return array{ascender: float, descender: float} Pixel metrics.
	 */
	public function vertical( ResolvedFont $font, float $size ): array {
		return array(
			'ascender'  => $this->ascender_ratio * $size,
			'descender' => $this->descender_ratio * $size,
		);
	}
}
