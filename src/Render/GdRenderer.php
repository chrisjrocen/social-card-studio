<?php
/**
 * GD renderer.
 *
 * Implements the engine of SPEC.md §4.2 and §4.3. GD is the only shipped engine and
 * every preset is designed for it.
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
 * Draws with GD.
 *
 * @since 0.1.0
 */
final class GdRenderer extends AbstractRenderer {

	/**
	 * Points per pixel; GD sizes are points at 96 DPI. See GdMetrics.
	 */
	private const POINTS_PER_PIXEL = 0.75;

	/**
	 * The canvas being drawn.
	 *
	 * @var \GdImage|null
	 */
	private ?\GdImage $canvas = null;

	/**
	 * Measurement provider.
	 *
	 * @var GdMetrics|null
	 */
	private ?GdMetrics $metrics = null;

	/**
	 * Whether GD is usable here.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when GD with FreeType is present.
	 */
	public function supports(): bool {
		return extension_loaded( 'gd' )
			&& function_exists( 'imagettftext' )
			&& function_exists( 'imagejpeg' )
			&& function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * Default priority; a renderer added through `scstudio_renderers` can outrank it.
	 *
	 * @since 0.1.0
	 *
	 * @return int Priority.
	 */
	public function priority(): int {
		return 50;
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

		if ( function_exists( 'gd_info' ) ) {
			$info = gd_info();

			if ( preg_match( '/(\d+\.\d+\.\d+)/', (string) ( $info['GD Version'] ?? '' ), $matches ) ) {
				$version = $matches[1];
			}
		}

		return 'gd' . ( '' !== $version ? '-' . $version : '' );
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
			$this->metrics = new GdMetrics();
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
	 * @throws RenderException When GD cannot allocate the canvas.
	 *
	 * @return void
	 */
	protected function create_canvas( int $width, int $height, Color $background ): void {
		$canvas = imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			throw new RenderException( RenderException::REASON_MEMORY, 'GD could not allocate the canvas.' );
		}

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $this->allocate( $canvas, $background ) );
		imagealphablending( $canvas, true );

		$this->canvas = $canvas;
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

		/*
		 * GD has no scaled decode. Imagick can ask libjpeg for a reduced-size image
		 * through jpeg:size, but imagecreatefromstring always expands the full
		 * original — a 6000x4000 photo is 96 MB of raw pixels before a single line of
		 * our code runs. So the affordability check has to happen against the
		 * *intrinsic* size here, not the planned load size, and a source we cannot
		 * afford is skipped rather than attempted. A card without its photograph is a
		 * card; a fatal is not.
		 */
		if ( ! $context->memory->can_afford( $source->width, $source->height ) ) {
			$context->degrade( 'source_too_large_for_gd' );

			return;
		}

		$image = $this->load( $source );

		if ( null === $image ) {
			return;
		}

		try {
			$box   = $layer->box();
			$rects = $source->rects( $box, (string) $layer->get( 'fit', 'cover' ) );

			$scaled = imagecreatetruecolor( max( 1, $rects['dw'] ), max( 1, $rects['dh'] ) );

			if ( false === $scaled ) {
				return;
			}

			imagealphablending( $scaled, false );
			imagesavealpha( $scaled, true );

			imagecopyresampled(
				$scaled,
				$image,
				0,
				0,
				$rects['sx'],
				$rects['sy'],
				max( 1, $rects['dw'] ),
				max( 1, $rects['dh'] ),
				max( 1, $rects['sw'] ),
				max( 1, $rects['sh'] )
			);

			$this->apply_effects( $scaled, (array) $layer->get( 'effects', array() ), $context );
			$this->apply_mask( $scaled, $layer );

			imagealphablending( $this->canvas, true );

			$opacity = (float) $layer->get( 'opacity', 1.0 );

			if ( $opacity < 1.0 ) {
				imagecopymerge( $this->canvas, $scaled, $rects['dx'], $rects['dy'], 0, 0, imagesx( $scaled ), imagesy( $scaled ), (int) round( $opacity * 100 ) );
			} else {
				imagecopy( $this->canvas, $scaled, $rects['dx'], $rects['dy'], 0, 0, imagesx( $scaled ), imagesy( $scaled ) );
			}

			imagedestroy( $scaled );
		} finally {
			imagedestroy( $image );
		}
	}

	/**
	 * Draws a gradient as one filled rectangle per band.
	 *
	 * GD composites each band in C, so a few hundred calls are cheap, and unlike
	 * ImageMagick it paints a rectangle whose corners share a coordinate.
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

		imagealphablending( $this->canvas, true );

		foreach ( $bands as $offset => $color ) {
			if ( $color->a <= 0.0 ) {
				continue;
			}

			$index = $this->allocate( $this->canvas, $color );

			if ( $vertical ) {
				imagefilledrectangle( $this->canvas, $box->x, $box->y + $offset, $box->right() - 1, $box->y + $offset, $index );
			} else {
				imagefilledrectangle( $this->canvas, $box->x + $offset, $box->y, $box->x + $offset, $box->bottom() - 1, $index );
			}
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
		$shape   = (string) $layer->get( 'shape', 'rect' );

		imagealphablending( $this->canvas, true );

		if ( null !== $fill ) {
			$color = $this->allocate( $this->canvas, $fill->with_alpha( $fill->a * $opacity ) );

			switch ( $shape ) {
				case 'circle':
					imagefilledellipse( $this->canvas, $box->x + intdiv( $box->w, 2 ), $box->y + intdiv( $box->h, 2 ), $box->w, $box->h, $color );
					break;

				case 'rounded-rect':
					$this->filled_rounded_rect( $box, $radius, $color );
					break;

				case 'line':
					imagesetthickness( $this->canvas, max( 1, $width ) );
					imageline( $this->canvas, $box->x, $box->y, $box->right(), $box->bottom(), $color );
					imagesetthickness( $this->canvas, 1 );
					break;

				default:
					imagefilledrectangle( $this->canvas, $box->x, $box->y, $box->right(), $box->bottom(), $color );
			}
		}

		if ( null !== $stroke && $width > 0 && 'line' !== $shape ) {
			$color = $this->allocate( $this->canvas, $stroke );
			imagesetthickness( $this->canvas, $width );

			if ( 'circle' === $shape ) {
				imageellipse( $this->canvas, $box->x + intdiv( $box->w, 2 ), $box->y + intdiv( $box->h, 2 ), $box->w, $box->h, $color );
			} else {
				imagerectangle( $this->canvas, $box->x, $box->y, $box->right(), $box->bottom(), $color );
			}

			imagesetthickness( $this->canvas, 1 );
		}
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

		$this->write( $this->canvas, $text, $x, $baseline, $size, $color, $font, $tracking );
	}

	/**
	 * Draws a text layer's drop shadow.
	 *
	 * GD has no shadow primitive, so this is the offset blurred copy from SPEC §4.3:
	 * the text is drawn into a transparent layer, blurred three times, and composited
	 * beneath the real text.
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

		$bounds = $this->shadow_bounds( $layout, $shadow, $font, imagesx( $this->canvas ), imagesy( $this->canvas ) );

		if ( $bounds->w < 1 || $bounds->h < 1 ) {
			return;
		}

		$width  = $bounds->w;
		$height = $bounds->h;
		$layer  = imagecreatetruecolor( $width, $height );

		if ( false === $layer ) {
			return;
		}

		try {
			imagealphablending( $layer, false );
			imagesavealpha( $layer, true );
			imagefilledrectangle( $layer, 0, 0, $width, $height, imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
			imagealphablending( $layer, true );

			// Coordinates are canvas-absolute; the layer starts at the bounds origin.
			foreach ( $layout->lines as $line ) {
				$this->write( $layer, $line['text'], $line['x'] - $bounds->x, $line['baseline'] - $bounds->y, $layout->size, $color, $font, $tracking );
			}

			$blur = (int) ( $shadow['blur'] ?? 0 );

			if ( $blur > 0 && function_exists( 'imagefilter' ) ) {
				$passes = min( 3, max( 1, (int) ceil( $blur / 4 ) ) );

				for ( $pass = 0; $pass < $passes; $pass++ ) {
					imagefilter( $layer, IMG_FILTER_GAUSSIAN_BLUR );
				}
			}

			imagealphablending( $this->canvas, true );
			imagecopy(
				$this->canvas,
				$layer,
				$bounds->x + (int) ( $shadow['x'] ?? 0 ),
				$bounds->y + (int) ( $shadow['y'] ?? 0 ),
				0,
				0,
				$width,
				$height
			);
		} finally {
			imagedestroy( $layer );
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

		// Sample on a grid rather than every pixel: a 760x220 region is 167,000
		// reads in PHP, and the average is indistinguishable.
		$samples = array();
		$steps   = 12;

		for ( $row = 0; $row < $steps; $row++ ) {
			for ( $column = 0; $column < $steps; $column++ ) {
				$x = $box->x + (int) round( ( $box->w - 1 ) * ( $column / max( 1, $steps - 1 ) ) );
				$y = $box->y + (int) round( ( $box->h - 1 ) * ( $row / max( 1, $steps - 1 ) ) );

				if ( $x < 0 || $y < 0 || $x >= imagesx( $this->canvas ) || $y >= imagesy( $this->canvas ) ) {
					continue;
				}

				$rgb = imagecolorat( $this->canvas, $x, $y );

				$samples[] = array(
					( $rgb >> 16 ) & 0xFF,
					( $rgb >> 8 ) & 0xFF,
					$rgb & 0xFF,
				);
			}
		}

		return $this->legibility->average( $samples );
	}

	/**
	 * Encodes the canvas as progressive JPEG with no metadata.
	 *
	 * GD writes no EXIF or colour profile of its own, so "strip metadata" is
	 * satisfied by construction.
	 *
	 * @since 0.1.0
	 *
	 * @param int $quality JPEG quality.
	 *
	 * @throws RenderException When GD cannot produce a JPEG.
	 *
	 * @return string Encoded bytes.
	 */
	protected function encode( int $quality ): string {
		if ( null === $this->canvas ) {
			return '';
		}

		$width  = imagesx( $this->canvas );
		$height = imagesy( $this->canvas );
		$flat   = imagecreatetruecolor( $width, $height );

		if ( false === $flat ) {
			throw new RenderException( RenderException::REASON_ENCODE, 'GD could not allocate the output image.' );
		}

		try {
			// JPEG has no alpha; flatten onto white to avoid black fringing.
			imagefilledrectangle( $flat, 0, 0, $width, $height, imagecolorallocate( $flat, 255, 255, 255 ) );
			imagealphablending( $flat, true );
			imagecopy( $flat, $this->canvas, 0, 0, 0, 0, $width, $height );

			imageinterlace( $flat, true );

			ob_start();
			$ok  = imagejpeg( $flat, null, $quality );
			$out = (string) ob_get_clean();

			if ( ! $ok || '' === $out ) {
				throw new RenderException( RenderException::REASON_ENCODE, 'GD could not encode the card.' );
			}

			return $out;
		} finally {
			imagedestroy( $flat );
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
			imagedestroy( $this->canvas );
			$this->canvas = null;
		}
	}

	/**
	 * Writes text onto an image.
	 *
	 * @since 0.1.0
	 *
	 * @param \GdImage     $target   Image to draw on.
	 * @param string       $text     Line text.
	 * @param float        $x        Left edge.
	 * @param float        $baseline Baseline Y.
	 * @param float        $size     Font size in pixels.
	 * @param Color        $color    Text colour.
	 * @param ResolvedFont $font     Font.
	 * @param float        $tracking Letter spacing.
	 *
	 * @return void
	 */
	private function write( \GdImage $target, string $text, float $x, float $baseline, float $size, Color $color, ResolvedFont $font, float $tracking ): void {
		$points = $size * self::POINTS_PER_PIXEL;
		$index  = $this->allocate( $target, $color );

		if ( 0.0 === $tracking ) {
			imagettftext( $target, $points, 0.0, (int) round( $x ), (int) round( $baseline ), $index, $font->path, $text );

			return;
		}

		foreach ( $this->tracked_positions( $text, $x, $size, $font, $tracking ) as $placement ) {
			imagettftext( $target, $points, 0.0, (int) round( $placement[1] ), (int) round( $baseline ), $index, $font->path, $placement[0] );
		}
	}

	/**
	 * Loads a source image at its planned size.
	 *
	 * @since 0.1.0
	 *
	 * @param SourceImage $source Load plan.
	 *
	 * @return \GdImage|null Loaded image, or null.
	 */
	private function load( SourceImage $source ): ?\GdImage {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A malformed image emits a warning and returns false, which is the answer; the file is a validated local image GD must read as bytes.
		$image = @imagecreatefromstring( (string) file_get_contents( $source->path ) );

		if ( false === $image ) {
			return null;
		}

		$image = $this->apply_orientation( $image, $source->orientation );

		if ( imagesx( $image ) <= $source->load_width && imagesy( $image ) <= $source->load_height ) {
			return $image;
		}

		$scaled = imagescale( $image, $source->load_width, $source->load_height );

		imagedestroy( $image );

		return false === $scaled ? null : $scaled;
	}

	/**
	 * Applies image effects.
	 *
	 * @since 0.1.0
	 *
	 * @param \GdImage             $image   Image to modify.
	 * @param array<string, mixed> $effects Effect settings.
	 * @param RenderContext        $context Render inputs.
	 *
	 * @return void
	 */
	private function apply_effects( \GdImage $image, array $effects, RenderContext $context ): void {
		if ( ! function_exists( 'imagefilter' ) ) {
			return;
		}

		if ( ! empty( $effects['grayscale'] ) ) {
			imagefilter( $image, IMG_FILTER_GRAYSCALE );
		}

		$blur = (int) ( $effects['blur'] ?? 0 );

		if ( $blur > 0 ) {
			if ( $context->memory->should_degrade() ) {
				$context->degrade( 'blur_skipped' );
			} else {
				// Three passes approximate the box blur named in SPEC §4.3.
				$passes = min( 3, max( 1, (int) ceil( $blur / 4 ) ) );

				for ( $pass = 0; $pass < $passes; $pass++ ) {
					imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
				}
			}
		}

		$tint = Color::parse( (string) ( $effects['tint'] ?? '' ) );

		if ( null !== $tint ) {
			imagefilter( $image, IMG_FILTER_COLORIZE, $tint->r - 128, $tint->g - 128, $tint->b - 128 );
		}
	}

	/**
	 * Applies a circle or rounded-rectangle mask.
	 *
	 * GD has no clip path, so the mask is punched into the alpha channel directly.
	 *
	 * @since 0.1.0
	 *
	 * @param \GdImage $image Image to mask.
	 * @param Layer    $layer Layer being drawn.
	 *
	 * @return void
	 */
	private function apply_mask( \GdImage $image, Layer $layer ): void {
		$shape = (string) $layer->get( 'shape', 'rect' );

		if ( 'rect' === $shape ) {
			return;
		}

		$width       = imagesx( $image );
		$height      = imagesy( $image );
		$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$radius_x = $width / 2;
		$radius_y = $height / 2;
		$radius   = (int) $layer->get( 'radius', 0 );

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$outside = 'circle' === $shape
					? ( ( ( ( $x + 0.5 - $radius_x ) ** 2 ) / ( $radius_x ** 2 ) ) + ( ( ( $y + 0.5 - $radius_y ) ** 2 ) / ( $radius_y ** 2 ) ) ) > 1.0
					: $this->outside_rounded_rect( $x, $y, $width, $height, $radius );

				if ( $outside ) {
					imagesetpixel( $image, $x, $y, $transparent );
				}
			}
		}

		imagealphablending( $image, true );
	}

	/**
	 * Whether a point falls outside a rounded rectangle's corners.
	 *
	 * @since 0.1.0
	 *
	 * @param int $x      Point X.
	 * @param int $y      Point Y.
	 * @param int $width  Rectangle width.
	 * @param int $height Rectangle height.
	 * @param int $radius Corner radius.
	 *
	 * @return bool True when outside.
	 */
	private function outside_rounded_rect( int $x, int $y, int $width, int $height, int $radius ): bool {
		if ( $radius <= 0 ) {
			return false;
		}

		$radius = min( $radius, intdiv( $width, 2 ), intdiv( $height, 2 ) );

		$corner_x = null;
		$corner_y = null;

		if ( $x < $radius && $y < $radius ) {
			$corner_x = $radius;
			$corner_y = $radius;
		} elseif ( $x >= $width - $radius && $y < $radius ) {
			$corner_x = $width - $radius - 1;
			$corner_y = $radius;
		} elseif ( $x < $radius && $y >= $height - $radius ) {
			$corner_x = $radius;
			$corner_y = $height - $radius - 1;
		} elseif ( $x >= $width - $radius && $y >= $height - $radius ) {
			$corner_x = $width - $radius - 1;
			$corner_y = $height - $radius - 1;
		}

		if ( null === $corner_x || null === $corner_y ) {
			return false;
		}

		return ( ( ( $x - $corner_x ) ** 2 ) + ( ( $y - $corner_y ) ** 2 ) ) > ( $radius ** 2 );
	}

	/**
	 * Draws a filled rounded rectangle.
	 *
	 * @since 0.1.0
	 *
	 * @param Box $box    Rectangle.
	 * @param int $radius Corner radius.
	 * @param int $color  Allocated colour index.
	 *
	 * @return void
	 */
	private function filled_rounded_rect( Box $box, int $radius, int $color ): void {
		if ( null === $this->canvas ) {
			return;
		}

		$radius = max( 0, min( $radius, intdiv( $box->w, 2 ), intdiv( $box->h, 2 ) ) );

		if ( 0 === $radius ) {
			imagefilledrectangle( $this->canvas, $box->x, $box->y, $box->right(), $box->bottom(), $color );

			return;
		}

		imagefilledrectangle( $this->canvas, $box->x + $radius, $box->y, $box->right() - $radius, $box->bottom(), $color );
		imagefilledrectangle( $this->canvas, $box->x, $box->y + $radius, $box->right(), $box->bottom() - $radius, $color );

		$diameter = $radius * 2;

		imagefilledellipse( $this->canvas, $box->x + $radius, $box->y + $radius, $diameter, $diameter, $color );
		imagefilledellipse( $this->canvas, $box->right() - $radius, $box->y + $radius, $diameter, $diameter, $color );
		imagefilledellipse( $this->canvas, $box->x + $radius, $box->bottom() - $radius, $diameter, $diameter, $color );
		imagefilledellipse( $this->canvas, $box->right() - $radius, $box->bottom() - $radius, $diameter, $diameter, $color );
	}

	/**
	 * Applies EXIF orientation.
	 *
	 * @since 0.1.0
	 *
	 * @param \GdImage $image       Image to rotate.
	 * @param int      $orientation EXIF orientation value.
	 *
	 * @return \GdImage Reoriented image.
	 */
	private function apply_orientation( \GdImage $image, int $orientation ): \GdImage {
		$rotate = static function ( \GdImage $source, float $angle ): \GdImage {
			$rotated = imagerotate( $source, $angle, 0 );

			if ( false === $rotated ) {
				return $source;
			}

			imagedestroy( $source );

			return $rotated;
		};

		switch ( $orientation ) {
			case 2:
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				break;
			case 3:
				$image = $rotate( $image, 180 );
				break;
			case 4:
				imageflip( $image, IMG_FLIP_VERTICAL );
				break;
			case 5:
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				$image = $rotate( $image, 90 );
				break;
			case 6:
				$image = $rotate( $image, -90 );
				break;
			case 7:
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				$image = $rotate( $image, -90 );
				break;
			case 8:
				$image = $rotate( $image, 90 );
				break;
		}

		return $image;
	}

	/**
	 * Allocates a colour, converting alpha to GD's inverted 0-127 scale.
	 *
	 * @since 0.1.0
	 *
	 * @param \GdImage $target Image to allocate on.
	 * @param Color    $color  Colour to allocate.
	 *
	 * @return int Colour index.
	 */
	private function allocate( \GdImage $target, Color $color ): int {
		$alpha = (int) round( ( 1.0 - $color->a ) * 127 );

		$index = imagecolorallocatealpha( $target, $color->r, $color->g, $color->b, $alpha );

		return false === $index ? 0 : $index;
	}
}
