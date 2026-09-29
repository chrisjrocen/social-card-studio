<?php
/**
 * Imagick renderer.
 *
 * Implements the preferred engine of SPEC.md §4.2 and §4.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Card\Layer;
use ChrxDigital\SocialCardStudio\Support\Color;

defined( 'ABSPATH' ) || exit;

/**
 * Draws with ImageMagick.
 *
 * @since 0.1.0
 */
final class ImagickRenderer extends AbstractRenderer {

	/**
	 * The canvas being drawn.
	 *
	 * @var \Imagick|null
	 */
	private ?\Imagick $canvas = null;

	/**
	 * Measurement provider.
	 *
	 * @var ImagickMetrics|null
	 */
	private ?ImagickMetrics $metrics = null;

	/**
	 * Whether Imagick is usable here.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when Imagick with FreeType is present.
	 */
	public function supports(): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}

		try {
			return ! empty( \Imagick::queryFormats( 'TTF' ) ) && ! empty( \Imagick::queryFormats( 'JPEG' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Preferred over GD, per SPEC §4.2.
	 *
	 * @since 0.1.0
	 *
	 * @return int Priority.
	 */
	public function priority(): int {
		return 100;
	}

	/**
	 * Engine identifier with version.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string {
		$version = '';

		try {
			$raw = (string) ( \Imagick::getVersion()['versionString'] ?? '' );

			if ( preg_match( '/ImageMagick (\d+\.\d+\.\d+)/', $raw, $matches ) ) {
				$version = $matches[1];
			}
		} catch ( \Throwable $e ) {
			$version = '';
		}

		return 'imagick' . ( '' !== $version ? '-' . $version : '' );
	}

	/**
	 * Measurement provider.
	 *
	 * @since 0.1.0
	 *
	 * @return MetricsProvider Provider.
	 */
	protected function metrics(): MetricsProvider {
		if ( null === $this->metrics ) {
			$this->metrics = new ImagickMetrics();
		}

		return $this->metrics;
	}

	/**
	 * Creates the canvas.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $width      Canvas width.
	 * @param int   $height     Canvas height.
	 * @param Color $background Background colour.
	 *
	 * @return void
	 */
	protected function create_canvas( int $width, int $height, Color $background ): void {
		$this->canvas = new \Imagick();
		$this->canvas->newImage( $width, $height, new \ImagickPixel( $this->pixel( $background ) ), 'png' );
		$this->canvas->setImageColorspace( \Imagick::COLORSPACE_SRGB );
	}

	/**
	 * Draws an image layer.
	 *
	 * @since 0.1.0
	 *
	 * @param SourceImage   $source  Load plan.
	 * @param Layer         $layer   Layer being drawn.
	 * @param RenderContext $context Render inputs.
	 *
	 * @return void
	 */
	protected function draw_image( SourceImage $source, Layer $layer, RenderContext $context ): void {
		if ( null === $this->canvas ) {
			return;
		}

		$image = new \Imagick();

		try {
			// Ask the decoder for the reduced size up front; for JPEG this uses the
			// DCT scaler and never expands the full-resolution buffer.
			$image->setOption( 'jpeg:size', $source->load_width . 'x' . $source->load_height );
			$image->readImage( $source->path );
			$image->setImageColorspace( \Imagick::COLORSPACE_SRGB );

			$this->apply_orientation( $image, $source->orientation );

			if ( $image->getImageWidth() > $source->load_width || $image->getImageHeight() > $source->load_height ) {
				$image->resizeImage( $source->load_width, $source->load_height, \Imagick::FILTER_LANCZOS, 1, true );
			}

			$box   = $layer->box();
			$rects = $source->rects( $box, (string) $layer->get( 'fit', 'cover' ) );

			if ( $rects['sw'] < $image->getImageWidth() || $rects['sh'] < $image->getImageHeight() ) {
				$image->cropImage( $rects['sw'], $rects['sh'], $rects['sx'], $rects['sy'] );
			}

			$image->resizeImage( max( 1, $rects['dw'] ), max( 1, $rects['dh'] ), \Imagick::FILTER_LANCZOS, 1 );

			$this->apply_effects( $image, (array) $layer->get( 'effects', array() ), $context );
			$this->apply_mask( $image, $layer );

			$opacity = (float) $layer->get( 'opacity', 1.0 );

			if ( $opacity < 1.0 ) {
				$image->setImageAlpha( $opacity );
			}

			$image->stripImage();

			$this->canvas->compositeImage( $image, \Imagick::COMPOSITE_OVER, $rects['dx'], $rects['dy'] );
		} catch ( \Throwable $e ) {
			// A broken source collapses the layer rather than failing the card.
			return;
		} finally {
			$image->clear();
		}
	}

	/**
	 * Draws a gradient by building a one-pixel-wide strip and stretching it.
	 *
	 * Not with ImagickDraw::rectangle per band, which was the obvious approach and is
	 * silently wrong: a rectangle whose two corners share a coordinate is degenerate,
	 * and ImageMagick discards it rather than painting a one-pixel line. The result
	 * was a scrim that drew nothing at all while GD drew it correctly. Building the
	 * strip from raw pixels avoids the degenerate case entirely, and composites once
	 * instead of six hundred times.
	 *
	 * @since 0.1.0
	 *
	 * @param Box     $box      Gradient rectangle.
	 * @param Color[] $bands    One colour per pixel along the axis.
	 * @param bool    $vertical Whether the axis runs top to bottom.
	 *
	 * @return void
	 */
	protected function draw_gradient( Box $box, array $bands, bool $vertical ): void {
		if ( null === $this->canvas || array() === $bands ) {
			return;
		}

		$count  = count( $bands );
		$pixels = array();

		foreach ( $bands as $color ) {
			$pixels[] = $color->r;
			$pixels[] = $color->g;
			$pixels[] = $color->b;
			$pixels[] = (int) round( $color->a * 255 );
		}

		$strip = new \Imagick();

		try {
			$strip->newImage(
				$vertical ? 1 : $count,
				$vertical ? $count : 1,
				new \ImagickPixel( 'transparent' ),
				'png'
			);
			$strip->setImageColorspace( \Imagick::COLORSPACE_SRGB );
			$strip->importImagePixels(
				0,
				0,
				$vertical ? 1 : $count,
				$vertical ? $count : 1,
				'RGBA',
				\Imagick::PIXEL_CHAR,
				$pixels
			);

			$strip->resizeImage( max( 1, $box->w ), max( 1, $box->h ), \Imagick::FILTER_TRIANGLE, 1 );

			$this->canvas->compositeImage( $strip, \Imagick::COMPOSITE_OVER, $box->x, $box->y );
		} catch ( \Throwable $e ) {
			return;
		} finally {
			$strip->clear();
		}
	}

	/**
	 * Draws a shape layer.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer $layer Shape layer.
	 *
	 * @return void
	 */
	protected function draw_shape( Layer $layer ): void {
		if ( null === $this->canvas ) {
			return;
		}

		$box     = $layer->box();
		$fill    = Color::parse( (string) $layer->get( 'fill', '' ) );
		$stroke  = Color::parse( (string) $layer->get( 'stroke', '' ) );
		$width   = (int) $layer->get( 'stroke_width', 0 );
		$radius  = (int) $layer->get( 'radius', 0 );
		$opacity = (float) $layer->get( 'opacity', 1.0 );

		$draw = new \ImagickDraw();

		if ( null !== $fill ) {
			$draw->setFillColor( new \ImagickPixel( $this->pixel( $fill->with_alpha( $fill->a * $opacity ) ) ) );
		} else {
			$draw->setFillOpacity( 0.0 );
		}

		if ( null !== $stroke && $width > 0 ) {
			$draw->setStrokeColor( new \ImagickPixel( $this->pixel( $stroke ) ) );
			$draw->setStrokeWidth( $width );
		}

		switch ( (string) $layer->get( 'shape', 'rect' ) ) {
			case 'circle':
				$radius_x = $box->w / 2;
				$radius_y = $box->h / 2;
				$draw->ellipse( $box->x + $radius_x, $box->y + $radius_y, $radius_x, $radius_y, 0, 360 );
				break;

			case 'rounded-rect':
				$draw->roundRectangle( $box->x, $box->y, $box->right(), $box->bottom(), $radius, $radius );
				break;

			case 'line':
				$draw->line( $box->x, $box->y, $box->right(), $box->bottom() );
				break;

			default:
				$draw->rectangle( $box->x, $box->y, $box->right(), $box->bottom() );
		}

		$this->canvas->drawImage( $draw );
		$draw->destroy();
	}

	/**
	 * Draws one line of text.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Line text.
	 * @param float        $x        Left edge.
	 * @param float        $baseline Baseline Y.
	 * @param float        $size     Font size.
	 * @param Color        $color    Text colour.
	 * @param ResolvedFont $font     Font.
	 * @param float        $tracking Letter spacing.
	 *
	 * @return void
	 */
	protected function draw_text( string $text, float $x, float $baseline, float $size, Color $color, ResolvedFont $font, float $tracking ): void {
		if ( null === $this->canvas || '' === $text ) {
			return;
		}

		$draw = new \ImagickDraw();
		$draw->setFont( $font->path );
		$draw->setFontSize( $size );
		$draw->setFillColor( new \ImagickPixel( $this->pixel( $color ) ) );
		$draw->setTextAntialias( true );

		/*
		 * Annotations are queued onto the draw object and rendered in one pass.
		 * annotateImage() per glyph works but re-enters the rasteriser every time; on
		 * a tracked headline that was most of the render's wall clock.
		 */
		if ( 0.0 === $tracking ) {
			$draw->annotation( $x, $baseline, $text );
		} else {
			foreach ( $this->tracked_positions( $text, $x, $size, $font, $tracking ) as $placement ) {
				$draw->annotation( $placement[1], $baseline, $placement[0] );
			}
		}

		$this->canvas->drawImage( $draw );
		$draw->destroy();
	}

	/**
	 * Draws a text layer's drop shadow.
	 *
	 * @since 0.1.0
	 *
	 * @param LayoutResult         $layout   Laid-out lines.
	 * @param array<string, mixed> $shadow   Shadow settings.
	 * @param ResolvedFont         $font     Font.
	 * @param float                $tracking Letter spacing.
	 *
	 * @return void
	 */
	protected function draw_text_shadow( LayoutResult $layout, array $shadow, ResolvedFont $font, float $tracking ): void {
		if ( null === $this->canvas ) {
			return;
		}

		$color = Color::parse( (string) ( $shadow['color'] ?? '#00000059' ) );

		if ( null === $color ) {
			return;
		}

		$offset_x = (int) ( $shadow['x'] ?? 0 );
		$offset_y = (int) ( $shadow['y'] ?? 0 );
		$blur     = (int) ( $shadow['blur'] ?? 0 );

		$bounds = $this->shadow_bounds(
			$layout,
			$shadow,
			$font,
			$this->canvas->getImageWidth(),
			$this->canvas->getImageHeight()
		);

		if ( $bounds->w < 1 || $bounds->h < 1 ) {
			return;
		}

		$layer = new \Imagick();

		try {
			$layer->newImage( $bounds->w, $bounds->h, new \ImagickPixel( 'transparent' ), 'png' );

			$draw = new \ImagickDraw();
			$draw->setFont( $font->path );
			$draw->setFontSize( $layout->size );
			$draw->setFillColor( new \ImagickPixel( $this->pixel( $color->with_alpha( 1.0 ) ) ) );

			// Coordinates are canvas-absolute; the layer starts at the bounds origin.
			foreach ( $layout->lines as $line ) {
				$baseline = $line['baseline'] - $bounds->y;

				if ( 0.0 === $tracking ) {
					$draw->annotation( $line['x'] - $bounds->x, $baseline, $line['text'] );
				} else {
					foreach ( $this->tracked_positions( $line['text'], $line['x'] - $bounds->x, $layout->size, $font, $tracking ) as $placement ) {
						$draw->annotation( $placement[1], $baseline, $placement[0] );
					}
				}
			}

			$layer->drawImage( $draw );
			$draw->destroy();

			if ( $blur > 0 ) {
				$layer->blurImage( 0, max( 1.0, $blur / 2 ) );
			}

			if ( $color->a < 1.0 ) {
				$layer->evaluateImage( \Imagick::EVALUATE_MULTIPLY, $color->a, \Imagick::CHANNEL_ALPHA );
			}

			$this->canvas->compositeImage(
				$layer,
				\Imagick::COMPOSITE_OVER,
				$bounds->x + $offset_x,
				$bounds->y + $offset_y
			);
		} catch ( \Throwable $e ) {
			return;
		} finally {
			$layer->clear();
		}
	}

	/**
	 * Samples the average colour of a region.
	 *
	 * @since 0.1.0
	 *
	 * @param Box $box Region.
	 *
	 * @return Color Average colour.
	 */
	protected function sample( Box $box ): Color {
		$fallback = Color::parse( '#808080' );

		if ( null === $this->canvas || $box->w < 1 || $box->h < 1 ) {
			return $fallback;
		}

		try {
			$region = clone $this->canvas;
			$region->cropImage(
				min( $box->w, $this->canvas->getImageWidth() ),
				min( $box->h, $this->canvas->getImageHeight() ),
				max( 0, $box->x ),
				max( 0, $box->y )
			);
			// One pixel is the average of the region, computed in C.
			$region->resizeImage( 1, 1, \Imagick::FILTER_BOX, 1 );

			$pixel = $region->getImagePixelColor( 0, 0 );
			$rgb   = $pixel->getColor();
			$region->clear();

			return Color::parse( sprintf( '#%02X%02X%02X', (int) $rgb['r'], (int) $rgb['g'], (int) $rgb['b'] ) ) ?? $fallback;
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	/**
	 * Encodes the canvas as progressive sRGB JPEG with no metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param int $quality JPEG quality.
	 *
	 * @throws RenderException When ImageMagick cannot produce a JPEG.
	 *
	 * @return string Encoded bytes.
	 */
	protected function encode( int $quality ): string {
		if ( null === $this->canvas ) {
			return '';
		}

		$output = clone $this->canvas;

		try {
			// JPEG has no alpha; flattening onto white avoids black fringing.
			$output->setImageBackgroundColor( new \ImagickPixel( '#FFFFFF' ) );
			$output = $output->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );

			$output->setImageFormat( 'jpeg' );
			$output->setImageCompression( \Imagick::COMPRESSION_JPEG );
			$output->setImageCompressionQuality( $quality );
			$output->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
			$output->setSamplingFactors( array( '2x2', '1x1', '1x1' ) );
			$output->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			$output->stripImage();

			return $output->getImageBlob();
		} catch ( \Throwable $e ) {
			throw new RenderException( RenderException::REASON_ENCODE, 'Imagick could not encode the card: ' . $e->getMessage(), $e );
		} finally {
			$output->clear();
		}
	}

	/**
	 * Releases the canvas.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected function destroy(): void {
		if ( null !== $this->canvas ) {
			$this->canvas->clear();
			$this->canvas = null;
		}
	}

	/**
	 * Applies image effects.
	 *
	 * @since 0.1.0
	 *
	 * @param \Imagick             $image   Image to modify.
	 * @param array<string, mixed> $effects Effect settings.
	 * @param RenderContext        $context Render inputs.
	 *
	 * @return void
	 */
	private function apply_effects( \Imagick $image, array $effects, RenderContext $context ): void {
		if ( ! empty( $effects['grayscale'] ) ) {
			$image->modulateImage( 100, 0, 100 );
		}

		$blur = (int) ( $effects['blur'] ?? 0 );

		if ( $blur > 0 ) {
			if ( $context->memory->should_degrade() ) {
				$context->degrade( 'blur_skipped' );
			} else {
				$image->blurImage( 0, max( 1.0, $blur / 2 ) );
			}
		}

		$tint = Color::parse( (string) ( $effects['tint'] ?? '' ) );

		if ( null !== $tint ) {
			$image->colorizeImage(
				new \ImagickPixel( $tint->to_hex() ),
				new \ImagickPixel( sprintf( 'rgba(%1$d,%1$d,%1$d,%1$d)', (int) round( $tint->a * 255 ) ) )
			);
		}
	}

	/**
	 * Applies a circle or rounded-rectangle mask.
	 *
	 * @since 0.1.0
	 *
	 * @param \Imagick $image Image to mask.
	 * @param Layer    $layer Layer being drawn.
	 *
	 * @return void
	 */
	private function apply_mask( \Imagick $image, Layer $layer ): void {
		$shape = (string) $layer->get( 'shape', 'rect' );

		if ( 'rect' === $shape ) {
			return;
		}

		$width  = $image->getImageWidth();
		$height = $image->getImageHeight();

		$mask = new \Imagick();
		$mask->newImage( $width, $height, new \ImagickPixel( 'black' ), 'png' );

		$draw = new \ImagickDraw();
		$draw->setFillColor( new \ImagickPixel( 'white' ) );

		if ( 'circle' === $shape ) {
			$draw->ellipse( $width / 2, $height / 2, $width / 2, $height / 2, 0, 360 );
		} else {
			$radius = (int) $layer->get( 'radius', 0 );
			$draw->roundRectangle( 0, 0, $width - 1, $height - 1, $radius, $radius );
		}

		$mask->drawImage( $draw );
		$draw->destroy();

		$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_SET );
		$image->compositeImage( $mask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0 );
		$mask->clear();
	}

	/**
	 * Applies EXIF orientation.
	 *
	 * @since 0.1.0
	 *
	 * @param \Imagick $image       Image to rotate.
	 * @param int      $orientation EXIF orientation value.
	 *
	 * @return void
	 */
	private function apply_orientation( \Imagick $image, int $orientation ): void {
		$transparent = new \ImagickPixel( 'none' );

		switch ( $orientation ) {
			case 2:
				$image->flopImage();
				break;
			case 3:
				$image->rotateImage( $transparent, 180 );
				break;
			case 4:
				$image->flipImage();
				break;
			case 5:
				$image->flopImage();
				$image->rotateImage( $transparent, 270 );
				break;
			case 6:
				$image->rotateImage( $transparent, 90 );
				break;
			case 7:
				$image->flopImage();
				$image->rotateImage( $transparent, 90 );
				break;
			case 8:
				$image->rotateImage( $transparent, 270 );
				break;
		}

		$image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
	}

	/**
	 * Formats a colour for ImagickPixel.
	 *
	 * @since 0.1.0
	 *
	 * @param Color $color Colour to format.
	 *
	 * @return string rgba() literal.
	 */
	private function pixel( Color $color ): string {
		return sprintf( 'rgba(%d,%d,%d,%.4F)', $color->r, $color->g, $color->b, $color->a );
	}
}
