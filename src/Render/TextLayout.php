<?php
/**
 * Shared text layout engine.
 *
 * Implements SPEC.md §6.3 exactly. The JavaScript twin in editor/src/layout.js must
 * stay step-for-step identical; the parity fixtures in tests/fixtures/ are what
 * enforce that.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps, autofits and positions text.
 *
 * Two rules drive every decision here. First, the algorithm is specified once in
 * SPEC §6.3 and implemented twice, so anything that could differ between PHP and
 * JavaScript — integer division, rounding, float comparison — is pinned rather than
 * left to language defaults. Second, tracking, line height and autofit are what make
 * a card look designed rather than generated, so none of them is optional.
 *
 * @since 0.1.0
 */
final class TextLayout {

	/**
	 * Autofit search granularity in pixels, per SPEC §6.3 step 2.
	 */
	public const SIZE_STEP = 0.5;

	/**
	 * The ellipsis appended by overflow handling.
	 */
	public const ELLIPSIS = '…';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param MetricsProvider $metrics Measurement source.
	 */
	public function __construct( private readonly MetricsProvider $metrics ) {}

	/**
	 * Extra width contributed by letter spacing.
	 *
	 * Tracking is a fraction of the font size applied between glyphs, so a run of n
	 * grapheme clusters gains (n - 1) gaps. Shared by every MetricsProvider so the
	 * rule cannot drift between them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text     Text being measured.
	 * @param float  $size     Font size in pixels.
	 * @param float  $tracking Letter spacing as a fraction of the font size.
	 *
	 * @return float Additional width in pixels.
	 */
	public static function tracking_width( string $text, float $size, float $tracking ): float {
		if ( 0.0 === $tracking || '' === $text ) {
			return 0.0;
		}

		$count = count( Str::graphemes( $text ) );

		return $count > 1 ? ( $count - 1 ) * $size * $tracking : 0.0;
	}

	/**
	 * Lays out a text layer.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text  Resolved text, before normalisation.
	 * @param LayoutSpec   $spec  Layout inputs.
	 * @param ResolvedFont $font  Font to lay out with.
	 * @param bool         $emoji Whether the emoji policy keeps emoji.
	 *
	 * @throws UnsupportedScriptException When the text needs shaping this server cannot do.
	 *
	 * @return LayoutResult Positioned lines.
	 */
	public function layout( string $text, LayoutSpec $spec, ResolvedFont $font, bool $emoji = false ): LayoutResult {
		// Step 1: normalise.
		$text = $this->normalize( $text, $spec, $emoji );

		// Step 5: never render an empty layer.
		if ( '' === $text ) {
			return LayoutResult::collapsed();
		}

		// SPEC §6.2: correct or fallback, never mangled.
		if ( Script::needs_shaping( $text ) && ! Script::can_shape() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The script name is a class constant, and this message is caught and logged, never printed.
			throw new UnsupportedScriptException( Script::detect( $text ) );
		}

		$rtl = Script::is_rtl( $text );

		// Steps 2 and 3: autofit, then wrap at the chosen size.
		$size  = $this->choose_size( $text, $spec, $font );
		$lines = $this->wrap( $text, $spec, $font, $size );

		$truncated = false;

		if ( count( $lines ) > $spec->max_lines ) {
			$lines     = $this->apply_overflow( $lines, $spec, $font, $size );
			$truncated = true;
		}

		if ( $rtl ) {
			$lines = array_map( array( Script::class, 'reverse_logical' ), $lines );
		}

		// Step 4: place from the ascender, not the bounding box.
		return $this->position( $lines, $spec, $font, $size, $truncated, $rtl );
	}

	/**
	 * Step 1 of SPEC §6.3.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $text  Raw text.
	 * @param LayoutSpec $spec  Layout inputs.
	 * @param bool       $emoji Whether emoji survive.
	 *
	 * @return string Normalised text.
	 */
	private function normalize( string $text, LayoutSpec $spec, bool $emoji ): string {
		$text = Str::normalize( $text, $emoji );

		if ( 'uppercase' === $spec->transform ) {
			$text = mb_strtoupper( $text, 'UTF-8' );
		} elseif ( 'lowercase' === $spec->transform ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		}

		return $text;
	}

	/**
	 * Step 2 of SPEC §6.3: binary search for the largest fitting size.
	 *
	 * The search runs over integer step indices rather than floats. Two
	 * implementations comparing floats with different rounding would drift; comparing
	 * integers cannot.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text Normalised text.
	 * @param LayoutSpec   $spec Layout inputs.
	 * @param ResolvedFont $font Font to measure with.
	 *
	 * @return float Chosen size in pixels.
	 */
	private function choose_size( string $text, LayoutSpec $spec, ResolvedFont $font ): float {
		if ( ! $spec->autofit || $spec->size_max <= $spec->size_min ) {
			return $spec->size_max <= $spec->size_min ? $spec->size_min : $spec->size_max;
		}

		$steps = (int) floor( ( $spec->size_max - $spec->size_min ) / self::SIZE_STEP );
		$low   = 0;
		$high  = $steps;
		$best  = -1;

		while ( $low <= $high ) {
			$mid  = intdiv( $low + $high, 2 );
			$size = $spec->size_min + ( $mid * self::SIZE_STEP );

			if ( $this->fits( $text, $spec, $font, $size ) ) {
				$best = $mid;
				$low  = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}

		// Step 3: if nothing fits, use the minimum and let overflow handle it.
		return $best < 0 ? $spec->size_min : $spec->size_min + ( $best * self::SIZE_STEP );
	}

	/**
	 * Step 2c of SPEC §6.3.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text Normalised text.
	 * @param LayoutSpec   $spec Layout inputs.
	 * @param ResolvedFont $font Font to measure with.
	 * @param float        $size Candidate size.
	 *
	 * @return bool True when the text fits at this size.
	 */
	private function fits( string $text, LayoutSpec $spec, ResolvedFont $font, float $size ): bool {
		$lines = $this->wrap( $text, $spec, $font, $size );

		if ( count( $lines ) > $spec->max_lines ) {
			return false;
		}

		return ( count( $lines ) * $size * $spec->line_height ) <= (float) $spec->box->h;
	}

	/**
	 * Step 2a and 2b of SPEC §6.3: greedy word wrap with grapheme-safe breaking.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text Normalised text.
	 * @param LayoutSpec   $spec Layout inputs.
	 * @param ResolvedFont $font Font to measure with.
	 * @param float        $size Font size.
	 *
	 * @return string[] Wrapped lines.
	 */
	private function wrap( string $text, LayoutSpec $spec, ResolvedFont $font, float $size ): array {
		$max_width = (float) $spec->box->w;
		$lines     = array();
		$current   = '';

		foreach ( explode( ' ', $text ) as $word ) {
			if ( '' === $word ) {
				continue;
			}

			$candidate = '' === $current ? $word : $current . ' ' . $word;

			if ( $this->width( $candidate, $font, $size, $spec ) <= $max_width ) {
				$current = $candidate;

				continue;
			}

			if ( '' !== $current ) {
				$lines[] = $current;
				$current = '';
			}

			// A single word wider than the box is broken by grapheme cluster.
			if ( $this->width( $word, $font, $size, $spec ) > $max_width ) {
				$pieces = $this->break_word( $word, $spec, $font, $size );
				$last   = array_pop( $pieces );

				foreach ( $pieces as $piece ) {
					$lines[] = $piece;
				}

				$current = (string) $last;

				continue;
			}

			$current = $word;
		}

		if ( '' !== $current ) {
			$lines[] = $current;
		}

		return array() === $lines ? array( '' ) : $lines;
	}

	/**
	 * Step 2b of SPEC §6.3: break an over-long word, never mid-cluster.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $word Word to break.
	 * @param LayoutSpec   $spec Layout inputs.
	 * @param ResolvedFont $font Font to measure with.
	 * @param float        $size Font size.
	 *
	 * @return string[] Pieces, at least one.
	 */
	private function break_word( string $word, LayoutSpec $spec, ResolvedFont $font, float $size ): array {
		$max_width = (float) $spec->box->w;
		$pieces    = array();
		$current   = '';

		foreach ( Str::graphemes( $word ) as $grapheme ) {
			$candidate = $current . $grapheme;

			if ( '' !== $current && $this->width( $candidate, $font, $size, $spec ) > $max_width ) {
				$pieces[] = $current;
				$current  = $grapheme;

				continue;
			}

			$current = $candidate;
		}

		if ( '' !== $current ) {
			$pieces[] = $current;
		}

		return array() === $pieces ? array( $word ) : $pieces;
	}

	/**
	 * Step 3 of SPEC §6.3: overflow handling.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]     $lines Wrapped lines.
	 * @param LayoutSpec   $spec  Layout inputs.
	 * @param ResolvedFont $font  Font to measure with.
	 * @param float        $size  Font size.
	 *
	 * @return string[] Lines within the ceiling.
	 */
	private function apply_overflow( array $lines, LayoutSpec $spec, ResolvedFont $font, float $size ): array {
		$kept = array_slice( $lines, 0, max( 1, $spec->max_lines ) );

		if ( LayoutSpec::OVERFLOW_CLIP === $spec->overflow ) {
			return $kept;
		}

		$last = (string) array_pop( $kept );

		// Truncate at the last grapheme boundary that leaves room for the ellipsis.
		$clusters = Str::graphemes( $last );

		while ( array() !== $clusters ) {
			$candidate = rtrim( implode( '', $clusters ) ) . self::ELLIPSIS;

			if ( $this->width( $candidate, $font, $size, $spec ) <= (float) $spec->box->w ) {
				$kept[] = $candidate;

				return $kept;
			}

			array_pop( $clusters );
		}

		$kept[] = self::ELLIPSIS;

		return $kept;
	}

	/**
	 * Step 4 of SPEC §6.3: vertical and horizontal placement.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]     $lines     Final lines.
	 * @param LayoutSpec   $spec      Layout inputs.
	 * @param ResolvedFont $font      Font to measure with.
	 * @param float        $size      Font size.
	 * @param bool         $truncated Whether overflow removed text.
	 * @param bool         $rtl       Whether the text is right to left.
	 *
	 * @return LayoutResult Positioned lines.
	 */
	private function position(
		array $lines,
		LayoutSpec $spec,
		ResolvedFont $font,
		float $size,
		bool $truncated,
		bool $rtl
	): LayoutResult {
		$leading  = $size * $spec->line_height;
		$total    = count( $lines ) * $leading;
		$vertical = $this->metrics->vertical( $font, $size );

		$top = match ( $spec->vertical_align ) {
			'middle' => $spec->box->y + ( ( $spec->box->h - $total ) / 2 ),
			'bottom' => $spec->box->y + ( $spec->box->h - $total ),
			default  => (float) $spec->box->y,
		};

		/*
		 * The baseline sits an ascender below the line's top edge, with the leftover
		 * leading split above and below. Deriving it from the ascender rather than
		 * from each line's bounding box is what keeps a headline with descenders
		 * aligned with one without — SPEC §6.3 step 4.
		 */
		$half_leading = ( $leading - ( $vertical['ascender'] + $vertical['descender'] ) ) / 2;

		$positioned = array();

		foreach ( array_values( $lines ) as $index => $line ) {
			$width = $this->width( $line, $font, $size, $spec );

			$x = match ( $spec->align ) {
				'center' => $spec->box->x + ( ( $spec->box->w - $width ) / 2 ),
				'right'  => $spec->box->x + ( $spec->box->w - $width ),
				default  => (float) $spec->box->x,
			};

			$positioned[] = array(
				'text'     => $line,
				'x'        => $x,
				'baseline' => $top + ( $index * $leading ) + $half_leading + $vertical['ascender'],
				'width'    => $width,
			);
		}

		return new LayoutResult( $size, $positioned, false, $truncated, $total, $rtl );
	}

	/**
	 * Measures a line through the provider.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $text Text to measure.
	 * @param ResolvedFont $font Font to measure with.
	 * @param float        $size Font size.
	 * @param LayoutSpec   $spec Layout inputs, for tracking.
	 *
	 * @return float Width in pixels.
	 */
	private function width( string $text, ResolvedFont $font, float $size, LayoutSpec $spec ): float {
		return $this->metrics->width( $text, $font, $size, $spec->tracking );
	}
}
