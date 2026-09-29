<?php
/**
 * Imagick text measurement.
 *
 * Implements the Imagick half of SPEC.md §6.3 step 2a.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Measures with queryFontMetrics.
 *
 * @since 0.1.0
 */
final class ImagickMetrics implements MetricsProvider {

	/**
	 * Measured widths, keyed by font, size, tracking and text.
	 *
	 * @var array<string, float>
	 */
	private array $cache = array();

	/**
	 * Shared measuring surface.
	 *
	 * @var \Imagick|null
	 */
	private ?\Imagick $canvas = null;

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Provider identifier.
	 */
	public function id(): string {
		return 'imagick';
	}

	/**
	 * Measures a line.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Text to measure.
	 * @param ResolvedFont $font     Font to measure with.
	 * @param float        $size     Nominal font size in pixels.
	 * @param float        $tracking Letter spacing as a fraction of the font size.
	 *
	 * @return float Advance width in pixels.
	 */
	public function width( string $text, ResolvedFont $font, float $size, float $tracking = 0.0 ): float {
		if ( '' === $text ) {
			return 0.0;
		}

		$key = $font->path . '|' . $size . '|' . $tracking . '|' . $text;

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$metrics = $this->metrics( $text, $font, $size );
		$width   = (float) ( $metrics['textWidth'] ?? 0.0 );

		$this->cache[ $key ] = $width + TextLayout::tracking_width( $text, $size, $tracking );

		return $this->cache[ $key ];
	}

	/**
	 * Vertical metrics.
	 *
	 * @since 0.1.0
	 *
	 * @param ResolvedFont $font Font to measure.
	 * @param float        $size Nominal font size in pixels.
	 *
	 * @return array{ascender: float, descender: float} Pixel metrics.
	 */
	public function vertical( ResolvedFont $font, float $size ): array {
		$metrics = $this->metrics( 'Hg', $font, $size );

		if ( array() === $metrics ) {
			return array(
				'ascender'  => $size * 0.8,
				'descender' => $size * 0.2,
			);
		}

		return array(
			'ascender'  => (float) abs( $metrics['ascender'] ?? $size * 0.8 ),
			'descender' => (float) abs( $metrics['descender'] ?? $size * 0.2 ),
		);
	}

	/**
	 * Reports whether Imagick can open a font file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute font path.
	 *
	 * @return bool True when readable.
	 */
	public static function can_read( string $path ): bool {
		if ( ! class_exists( '\Imagick' ) || ! is_readable( $path ) ) {
			return false;
		}

		try {
			$canvas = new \Imagick();
			$draw   = new \ImagickDraw();
			$draw->setFont( $path );
			$draw->setFontSize( 12.0 );
			$canvas->queryFontMetrics( $draw, 'A' );
			$canvas->clear();

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Runs queryFontMetrics.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text Text to measure.
	 * @param ResolvedFont $font Font to measure with.
	 * @param float        $size Nominal font size in pixels.
	 *
	 * @return array<string, float> Raw metrics, empty on failure.
	 */
	private function metrics( string $text, ResolvedFont $font, float $size ): array {
		try {
			if ( null === $this->canvas ) {
				$this->canvas = new \Imagick();
			}

			$draw = new \ImagickDraw();
			$draw->setFont( $font->path );
			$draw->setFontSize( $size );

			$metrics = (array) $this->canvas->queryFontMetrics( $draw, $text );

			return $metrics;
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}
