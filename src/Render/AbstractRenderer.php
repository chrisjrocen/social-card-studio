<?php
/**
 * Shared render pipeline.
 *
 * Implements SPEC.md §4.2: both renderers consume the identical Card Document and the
 * identical TextLayout results, and only the drawing primitives differ.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Card\CardDocument;
use ChrxDigital\SocialCardStudio\Card\Layer;
use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Support\Color;
use ChrxDigital\SocialCardStudio\Support\Schema;
use ChrxDigital\SocialCardStudio\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the layer list once, delegating each primitive to the engine.
 *
 * Everything that decides *what* a card looks like lives here, so the two engines
 * cannot drift apart on layer ordering, token resolution, autofit, collapsing or the
 * legibility guard. The subclasses only answer "how do I draw this".
 *
 * @since 0.1.0
 */
abstract class AbstractRenderer implements Renderer {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenResolver $tokens     Token substitution.
	 * @param Optimizer     $optimizer  Output size budget.
	 * @param Legibility    $legibility Contrast guard.
	 * @param string[]      $roots      Directories an image path source may live in.
	 */
	public function __construct(
		protected readonly TokenResolver $tokens,
		protected readonly Optimizer $optimizer,
		protected readonly Legibility $legibility,
		protected readonly array $roots = array()
	) {}

	// -------------------------------------------------------------- primitives

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
	abstract protected function create_canvas( int $width, int $height, Color $background ): void;

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
	abstract protected function draw_image( SourceImage $source, Layer $layer, RenderContext $context ): void;

	/**
	 * Draws a gradient.
	 *
	 * The engine is handed the fully-resolved colour of every band along the
	 * projection axis and composites them however it does that best. Interpolation
	 * happens once, above; only the painting differs.
	 *
	 * @since 0.1.0
	 *
	 * @param Box     $box      Gradient rectangle.
	 * @param Color[] $bands    One colour per pixel along the axis.
	 * @param bool    $vertical Whether the axis runs top to bottom.
	 *
	 * @return void
	 */
	abstract protected function draw_gradient( Box $box, array $bands, bool $vertical ): void;

	/**
	 * Draws a shape layer.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer $layer Shape layer.
	 *
	 * @return void
	 */
	abstract protected function draw_shape( Layer $layer ): void;

	/**
	 * Draws one laid-out line of text.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Line text.
	 * @param float        $x        Left edge.
	 * @param float        $baseline Baseline Y.
	 * @param float        $size     Font size in pixels.
	 * @param Color        $color    Text colour.
	 * @param ResolvedFont $font     Font to draw with.
	 * @param float        $tracking Letter spacing.
	 *
	 * @return void
	 */
	abstract protected function draw_text( string $text, float $x, float $baseline, float $size, Color $color, ResolvedFont $font, float $tracking ): void;

	/**
	 * Draws a text layer's drop shadow.
	 *
	 * @since 0.1.0
	 *
	 * @param LayoutResult         $layout   Laid-out lines.
	 * @param array<string, mixed> $shadow Shadow settings.
	 * @param ResolvedFont         $font     Font to draw with.
	 * @param float                $tracking Letter spacing.
	 *
	 * @return void
	 */
	abstract protected function draw_text_shadow( LayoutResult $layout, array $shadow, ResolvedFont $font, float $tracking ): void;

	/**
	 * Samples the average colour of a region of the canvas.
	 *
	 * @since 0.1.0
	 *
	 * @param Box $box Region to sample.
	 *
	 * @return Color Average colour.
	 */
	abstract protected function sample( Box $box ): Color;

	/**
	 * Encodes the canvas.
	 *
	 * @since 0.1.0
	 *
	 * @param int $quality JPEG quality.
	 *
	 * @return string Encoded bytes.
	 */
	abstract protected function encode( int $quality ): string;

	/**
	 * Releases the canvas.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	abstract protected function destroy(): void;

	/**
	 * Provides metrics for the layout engine.
	 *
	 * @since 0.1.0
	 *
	 * @return MetricsProvider Measurement source.
	 */
	abstract protected function metrics(): MetricsProvider;

	// ---------------------------------------------------------------- pipeline

	/**
	 * Draws the document.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDocument  $document Validated card document.
	 * @param RenderContext $context  Render inputs.
	 *
	 * @throws RenderException When the card cannot be drawn.
	 *
	 * @return RenderResult Encoded image and its cost.
	 */
	public function render( CardDocument $document, RenderContext $context ): RenderResult {
		$started = microtime( true );
		$canvas  = $document->canvas();

		if ( ! $context->memory->can_afford( $canvas->w, $canvas->h ) ) {
			throw new RenderException(
				RenderException::REASON_MEMORY,
				sprintf( 'Not enough memory to allocate a %dx%d canvas.', $canvas->w, $canvas->h )
			);
		}

		try {
			$this->create_canvas(
				$canvas->w,
				$canvas->h,
				$this->color( $canvas->bg, $context ) ?? Color::parse( '#000000' )
			);

			foreach ( $document->layers() as $layer ) {
				if ( (bool) $layer->get( 'hidden', false ) ) {
					continue;
				}

				$this->draw_layer( $layer, $document, $context );
			}

			$optimized = $this->optimizer->optimize(
				fn ( int $quality ): string => $this->encode( $quality ),
				$context->quality
			);

			return new RenderResult(
				$optimized['bytes'],
				$canvas->w,
				$canvas->h,
				Optimizer::MIME_JPEG,
				$this->id(),
				$optimized['quality'],
				( microtime( true ) - $started ) * 1000,
				memory_get_peak_usage( true ),
				$context->degradations()
			);
		} finally {
			$this->destroy();
		}
	}

	/**
	 * Draws one layer.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer         $layer    Layer to draw.
	 * @param CardDocument  $document Document being drawn.
	 * @param RenderContext $context  Render inputs.
	 *
	 * @return void
	 */
	private function draw_layer( Layer $layer, CardDocument $document, RenderContext $context ): void {
		switch ( $layer->type ) {
			case Layer::TYPE_IMAGE:
				$this->render_image_layer( $layer, $context );
				break;

			case Layer::TYPE_GRADIENT:
				$this->render_gradient_layer( $layer, $document, $context );
				break;

			case Layer::TYPE_TEXT:
				$this->render_text_layer( $layer, $context );
				break;

			case Layer::TYPE_SHAPE:
				$this->draw_shape( $this->resolve_shape_colors( $layer, $context ) );
				break;
		}
	}

	/**
	 * Draws an image layer, falling back to its declared fallback source.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer         $layer   Image layer.
	 * @param RenderContext $context Render inputs.
	 *
	 * @return void
	 */
	private function render_image_layer( Layer $layer, RenderContext $context ): void {
		foreach ( array( $layer->source(), (string) $layer->get( 'fallback', '' ) ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$resolved = $this->tokens->apply( $candidate, $context->tokens );

			if ( '' === $resolved ) {
				continue;
			}

			$source = SourceImage::plan( $resolved, $layer->box(), $context->memory, $this->roots );

			if ( null === $source ) {
				continue;
			}

			if ( $source->is_downscaled() ) {
				$context->degrade( 'source_downscaled' );
			}

			$this->draw_image( $source, $layer, $context );

			return;
		}

		// No usable image: the layer collapses and whatever is beneath shows through.
	}

	/**
	 * Draws a gradient layer, deepening it first when it guards a text layer.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer         $layer    Gradient layer.
	 * @param CardDocument  $document Document being drawn.
	 * @param RenderContext $context  Render inputs.
	 *
	 * @return void
	 */
	private function render_gradient_layer( Layer $layer, CardDocument $document, RenderContext $context ): void {
		$stops = $this->stops( $layer, $context );

		if ( count( $stops ) < 2 ) {
			return;
		}

		$stops = $this->apply_auto_contrast( $layer, $stops, $document, $context );
		$box   = $layer->box();
		$angle = (int) $layer->get( 'angle', 90 );

		// Axis-aligned bands. An arbitrary angle snaps to the nearest quarter turn:
		// the shipped presets are vertical, and a rotated compositing pass costs a
		// full extra canvas for a effect no preset uses.
		$vertical = in_array( $this->snap_angle( $angle ), array( 90, 270 ), true );
		$reversed = in_array( $this->snap_angle( $angle ), array( 180, 270 ), true );
		$length   = $vertical ? $box->h : $box->w;

		if ( $length < 1 ) {
			return;
		}

		$bands = array();

		for ( $offset = 0; $offset < $length; $offset++ ) {
			$position = $length > 1 ? $offset / ( $length - 1 ) : 0.0;

			if ( $reversed ) {
				$position = 1.0 - $position;
			}

			$bands[] = $this->interpolate( $stops, $position );
		}

		$this->draw_gradient( $box, $bands, $vertical );
	}

	/**
	 * Deepens a scrim until the text it guards is readable.
	 *
	 * Measured where the text actually sits, not stop by stop. A scrim is a gradient
	 * precisely so that it is heavy behind the headline and light elsewhere; asking
	 * "is each stop dark enough on its own" answers the wrong question and, when it
	 * decides the answer is no, floods the whole card — including the part meant to
	 * show the photograph. So the gradient is sampled at the headline's own position,
	 * composited over what is already on the canvas there, and only the shortfall is
	 * applied, as the same delta across every stop so the gradient keeps its shape.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer                                      $layer    Gradient layer.
	 * @param array<int, array{at: float, color: Color}> $stops    Parsed stops.
	 * @param CardDocument                               $document Document being drawn.
	 * @param RenderContext                              $context  Render inputs.
	 *
	 * @return array<int, array{at: float, color: Color}> Possibly deepened stops.
	 */
	private function apply_auto_contrast( Layer $layer, array $stops, CardDocument $document, RenderContext $context ): array {
		$settings = (array) $layer->get( 'auto_contrast', array() );
		$against  = (string) ( $settings['against'] ?? '' );

		if ( '' === $against ) {
			return $stops;
		}

		$target_layer = $document->layer( $against );

		if ( null === $target_layer || Layer::TYPE_TEXT !== $target_layer->type ) {
			return $stops;
		}

		$text_color = $this->color( (string) $target_layer->get( 'color', '#FFFFFF' ), $context );

		if ( null === $text_color ) {
			return $stops;
		}

		$backdrop = $this->sample( $target_layer->box() );
		$target   = (float) ( $settings['target'] ?? Legibility::TARGET );

		// Where the guarded text sits along the gradient's axis.
		$box      = $layer->box();
		$text_box = $target_layer->box();
		$vertical = in_array( $this->snap_angle( (int) $layer->get( 'angle', 90 ) ), array( 90, 270 ), true );

		$centre = $vertical
			? ( $text_box->y + ( $text_box->h / 2 ) - $box->y ) / max( 1, $box->h )
			: ( $text_box->x + ( $text_box->w / 2 ) - $box->x ) / max( 1, $box->w );
		$centre = max( 0.0, min( 1.0, $centre ) );

		if ( in_array( $this->snap_angle( (int) $layer->get( 'angle', 90 ) ), array( 180, 270 ), true ) ) {
			$centre = 1.0 - $centre;
		}

		$at_text = $this->interpolate( $stops, $centre );
		$result  = $this->legibility->deepen( $backdrop, $text_color, $at_text, $target );

		$delta = $result['scrim']->a - $at_text->a;

		if ( $delta <= 0.001 ) {
			return $stops;
		}

		$context->degrade( 'scrim_deepened' );

		$deepened = array();

		foreach ( $stops as $stop ) {
			$deepened[] = array(
				'at'    => $stop['at'],
				'color' => $stop['color']->with_alpha( min( Legibility::MAX_ALPHA, $stop['color']->a + $delta ) ),
			);
		}

		return $deepened;
	}

	/**
	 * Lays out and draws a text layer.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer         $layer   Text layer.
	 * @param RenderContext $context Render inputs.
	 *
	 * @return void
	 */
	private function render_text_layer( Layer $layer, RenderContext $context ): void {
		$text = $this->tokens->apply( $layer->content(), $context->tokens );

		// SPEC §6.3 step 5: an empty layer is collapsed, never drawn.
		if ( '' === $text ) {
			return;
		}

		$declared = (array) $layer->get( 'font', array() );

		$font = $context->fonts->resolve(
			(string) ( $declared['role'] ?? ResolvedFont::ROLE_BODY ),
			(int) ( $declared['weight'] ?? 400 ),
			(string) ( $declared['family'] ?? '' )
		);

		$engine = new TextLayout( $this->metrics() );
		$layout = $engine->layout( $text, LayoutSpec::from_layer( $layer ), $font );

		if ( $layout->collapsed ) {
			return;
		}

		$color    = $this->color( (string) $layer->get( 'color', '#FFFFFF' ), $context ) ?? Color::parse( '#FFFFFF' );
		$tracking = (float) $layer->get( 'tracking', 0.0 );
		$shadow   = (array) $layer->get( 'shadow', array() );

		if ( $this->shadow_is_visible( $shadow ) ) {
			if ( $context->memory->should_degrade() ) {
				$context->degrade( 'shadow_skipped' );
			} else {
				$this->draw_text_shadow( $layout, $shadow, $font, $tracking );
			}
		}

		foreach ( $layout->lines as $line ) {
			$this->draw_text(
				$line['text'],
				$line['x'],
				$line['baseline'],
				$layout->size,
				$color,
				$font,
				$tracking
			);
		}
	}

	/**
	 * Returns a shape layer with its colour tokens already resolved.
	 *
	 * The engines parse `fill` and `stroke` themselves, so the substitution happens
	 * once here rather than being duplicated in both of them.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer         $layer   Shape layer.
	 * @param RenderContext $context Render inputs.
	 *
	 * @return Layer Layer with literal colours.
	 */
	private function resolve_shape_colors( Layer $layer, RenderContext $context ): Layer {
		$data    = $layer->to_array();
		$changed = false;

		foreach ( array( 'fill', 'stroke' ) as $key ) {
			$value = (string) ( $data[ $key ] ?? '' );

			if ( '' === $value || ! Schema::is_color_token( $value ) ) {
				continue;
			}

			$color        = $this->color( $value, $context );
			$data[ $key ] = null === $color ? '' : $color->to_hexa();
			$changed      = true;
		}

		return $changed ? Layer::from_array( $data ) : $layer;
	}

	/**
	 * Parses a colour that may be a literal or a token.
	 *
	 * Presets ship as static JSON, so a template that wants the site's brand colour
	 * writes `{{brand_accent}}` and it is resolved here, at draw time, against the
	 * same token map the text layers use.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $value   Colour literal or token.
	 * @param RenderContext $context Render inputs.
	 *
	 * @return Color|null Parsed colour, or null when unusable.
	 */
	protected function color( string $value, RenderContext $context ): ?Color {
		if ( '' === $value ) {
			return null;
		}

		if ( Schema::is_color_token( $value ) ) {
			$value = $this->tokens->apply( $value, $context->tokens );
		}

		return Color::parse( $value );
	}

	/**
	 * Parses a gradient's stops, sorted by position.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer         $layer   Gradient layer.
	 * @param RenderContext $context Render inputs.
	 *
	 * @return array<int, array{at: float, color: Color}> Parsed stops.
	 */
	private function stops( Layer $layer, RenderContext $context ): array {
		$stops = array();

		foreach ( (array) $layer->get( 'stops', array() ) as $stop ) {
			$stop  = (array) $stop;
			$color = $this->color( (string) ( $stop['color'] ?? '' ), $context );

			if ( null === $color ) {
				continue;
			}

			$stops[] = array(
				'at'    => (float) ( $stop['at'] ?? 0.0 ),
				'color' => $color,
			);
		}

		usort( $stops, static fn ( array $a, array $b ): int => $a['at'] <=> $b['at'] );

		return $stops;
	}

	/**
	 * Interpolates a gradient colour at a position.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{at: float, color: Color}> $stops    Sorted stops.
	 * @param float                                      $position Position, 0.0-1.0.
	 *
	 * @return Color Interpolated colour.
	 */
	private function interpolate( array $stops, float $position ): Color {
		$first = $stops[0];
		$last  = $stops[ count( $stops ) - 1 ];

		if ( $position <= $first['at'] ) {
			return $first['color'];
		}

		if ( $position >= $last['at'] ) {
			return $last['color'];
		}

		$last_index = count( $stops ) - 1;

		for ( $i = 0; $i < $last_index; $i++ ) {
			$from = $stops[ $i ];
			$to   = $stops[ $i + 1 ];

			if ( $position < $from['at'] || $position > $to['at'] ) {
				continue;
			}

			$span = $to['at'] - $from['at'];
			$t    = $span > 0.0 ? ( $position - $from['at'] ) / $span : 0.0;

			return Color::mix( $from['color'], $to['color'], $t );
		}

		return $last['color'];
	}

	/**
	 * Snaps an arbitrary angle to the nearest quarter turn.
	 *
	 * @since 0.1.0
	 *
	 * @param int $angle Degrees.
	 *
	 * @return int One of 0, 90, 180, 270.
	 */
	private function snap_angle( int $angle ): int {
		$angle = ( ( $angle % 360 ) + 360 ) % 360;

		return (int) ( round( $angle / 90 ) * 90 ) % 360;
	}

	/**
	 * Whether a shadow would actually be visible.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $shadow Shadow settings.
	 *
	 * @return bool True when it should be drawn.
	 */
	private function shadow_is_visible( array $shadow ): bool {
		if ( array() === $shadow ) {
			return false;
		}

		$color = Color::parse( (string) ( $shadow['color'] ?? '' ) );

		if ( null === $color || $color->a <= 0.01 ) {
			return false;
		}

		return 0 !== (int) ( $shadow['x'] ?? 0 )
			|| 0 !== (int) ( $shadow['y'] ?? 0 )
			|| 0 !== (int) ( $shadow['blur'] ?? 0 );
	}

	/**
	 * The smallest region a text layer's shadow can be drawn into.
	 *
	 * A drop shadow is a blur, and blurring is priced per pixel. Building the shadow
	 * on a full-canvas layer costs the same whether the text covers the whole card or
	 * one line of it — profiling put it at the majority of a render's time, by far
	 * the single largest cost. Confining it to the text's own bounds plus the blur's
	 * reach cuts that to the area that can actually be non-transparent.
	 *
	 * @since 0.1.0
	 *
	 * @param LayoutResult         $layout   Laid-out lines.
	 * @param array<string, mixed> $shadow   Shadow settings.
	 * @param ResolvedFont         $font     Font.
	 * @param int                  $width    Canvas width.
	 * @param int                  $height   Canvas height.
	 *
	 * @return Box Region to allocate, clamped to the canvas.
	 */
	protected function shadow_bounds( LayoutResult $layout, array $shadow, ResolvedFont $font, int $width, int $height ): Box {
		if ( array() === $layout->lines ) {
			return new Box( 0, 0, 0, 0 );
		}

		$vertical = $this->metrics()->vertical( $font, $layout->size );

		$left   = PHP_INT_MAX;
		$right  = -PHP_INT_MAX;
		$top    = PHP_INT_MAX;
		$bottom = -PHP_INT_MAX;

		foreach ( $layout->lines as $line ) {
			$left   = min( $left, (int) floor( $line['x'] ) );
			$right  = max( $right, (int) ceil( $line['x'] + $line['width'] ) );
			$top    = min( $top, (int) floor( $line['baseline'] - $vertical['ascender'] ) );
			$bottom = max( $bottom, (int) ceil( $line['baseline'] + $vertical['descender'] ) );
		}

		// A gaussian reaches roughly three times its radius; the offset moves the
		// whole layer, so it widens the region in both directions.
		$blur   = (int) ( $shadow['blur'] ?? 0 );
		$pad    = ( $blur * 3 ) + max( abs( (int) ( $shadow['x'] ?? 0 ) ), abs( (int) ( $shadow['y'] ?? 0 ) ) ) + 4;
		$left   = max( 0, $left - $pad );
		$top    = max( 0, $top - $pad );
		$right  = min( $width, $right + $pad );
		$bottom = min( $height, $bottom + $pad );

		return new Box( $left, $top, max( 0, $right - $left ), max( 0, $bottom - $top ) );
	}

	/**
	 * Per-cluster x positions for tracked text.
	 *
	 * Tracking is drawn cluster by cluster, but each cluster is placed using the
	 * measured width of everything before it, so the font's own kerning survives. The
	 * naive approach — summing individual cluster widths — silently discards kerning
	 * and makes tracked headlines look loose and amateurish.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text     Line text.
	 * @param float        $x        Left edge.
	 * @param float        $size     Font size.
	 * @param ResolvedFont $font     Font.
	 * @param float        $tracking Letter spacing.
	 *
	 * @return array<int, array{0: string, 1: float}> Cluster and its x position.
	 */
	protected function tracked_positions( string $text, float $x, float $size, ResolvedFont $font, float $tracking ): array {
		$clusters = Str::graphemes( $text );
		$metrics  = $this->metrics();
		$out      = array();
		$prefix   = '';

		foreach ( $clusters as $index => $cluster ) {
			$advance = '' === $prefix ? 0.0 : $metrics->width( $prefix, $font, $size, 0.0 );
			$out[]   = array( $cluster, $x + $advance + ( $index * $size * $tracking ) );
			$prefix .= $cluster;
		}

		return $out;
	}
}
