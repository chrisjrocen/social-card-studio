<?php
/**
 * Text layout behaviour tests.
 *
 * Covers SPEC.md §6.3 step by step, and the M3 acceptance criteria for awkward
 * titles.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Render\LayoutSpec;
use ChrxDigital\SocialCardStudio\Render\ResolvedFont;
use ChrxDigital\SocialCardStudio\Render\TableMetrics;
use ChrxDigital\SocialCardStudio\Render\TextLayout;
use ChrxDigital\SocialCardStudio\Render\UnsupportedScriptException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Render\TextLayout.
 */
final class TextLayoutTest extends TestCase {

	/**
	 * Metrics with a uniform half-em advance, so widths are trivial to reason about.
	 *
	 * @var TableMetrics
	 */
	private TableMetrics $metrics;

	/**
	 * Engine under test.
	 *
	 * @var TextLayout
	 */
	private TextLayout $layout;

	/**
	 * Font stand-in; TableMetrics ignores it.
	 *
	 * @var ResolvedFont
	 */
	private ResolvedFont $font;

	/**
	 * Sets up a predictable engine.
	 *
	 * Every printable character advances 500/1000 em, so a 10-character string at
	 * size 20 is exactly 100px wide. That makes the assertions below about real
	 * behaviour rather than about a particular font's quirks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$advances = array();

		for ( $codepoint = 0x20; $codepoint <= 0x2FFF; $codepoint++ ) {
			$advances[ $codepoint ] = 500;
		}

		$this->metrics = new TableMetrics( $advances, 1000, 500 );
		$this->layout  = new TextLayout( $this->metrics );
		$this->font    = new ResolvedFont( ResolvedFont::ROLE_HEADING, 'Test', '', 400 );
	}

	/**
	 * Builds a spec.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return LayoutSpec Spec.
	 */
	private function spec( array $overrides = array() ): LayoutSpec {
		$defaults = array(
			'box'            => new Box( 0, 0, 400, 200 ),
			'size_min'       => 10.0,
			'size_max'       => 60.0,
			'autofit'        => true,
			'line_height'    => 1.2,
			'tracking'       => 0.0,
			'align'          => 'left',
			'vertical_align' => 'top',
			'max_lines'      => 3,
			'overflow'       => LayoutSpec::OVERFLOW_ELLIPSIS,
			'transform'      => 'none',
			'flexible'       => false,
		);

		$merged = array_merge( $defaults, $overrides );

		return new LayoutSpec(
			$merged['box'],
			(float) $merged['size_min'],
			(float) $merged['size_max'],
			(bool) $merged['autofit'],
			(float) $merged['line_height'],
			(float) $merged['tracking'],
			(string) $merged['align'],
			(string) $merged['vertical_align'],
			(int) $merged['max_lines'],
			(string) $merged['overflow'],
			(string) $merged['transform'],
			(bool) $merged['flexible']
		);
	}

	// ------------------------------------------------------------- normalise

	/**
	 * Empty and whitespace-only content collapses, per step 5.
	 *
	 * @return void
	 */
	public function test_empty_text_collapses(): void {
		$this->assertTrue( $this->layout->layout( '', $this->spec(), $this->font )->collapsed );
		$this->assertTrue( $this->layout->layout( '    ', $this->spec(), $this->font )->collapsed );
		$this->assertTrue( $this->layout->layout( '<p></p>', $this->spec(), $this->font )->collapsed );
	}

	/**
	 * HTML entities are decoded before layout, not drawn literally.
	 *
	 * @return void
	 */
	public function test_html_entities_are_decoded(): void {
		$result = $this->layout->layout( 'Backups &amp; restores', $this->spec(), $this->font );

		$this->assertStringContainsString( '&', implode( ' ', $result->texts() ) );
		$this->assertStringNotContainsString( '&amp;', implode( ' ', $result->texts() ) );
	}

	/**
	 * Shortcodes and tags are stripped.
	 *
	 * @return void
	 */
	public function test_markup_is_stripped(): void {
		$result = $this->layout->layout( '[caption]<b>Real</b> text[/caption]', $this->spec(), $this->font );

		$this->assertSame( 'Real text', implode( ' ', $result->texts() ) );
	}

	/**
	 * The uppercase transform is applied.
	 *
	 * @return void
	 */
	public function test_uppercase_transform(): void {
		$result = $this->layout->layout( 'ship it', $this->spec( array( 'transform' => 'uppercase' ) ), $this->font );

		$this->assertSame( 'SHIP IT', implode( ' ', $result->texts() ) );
	}

	// --------------------------------------------------------------- autofit

	/**
	 * Short text takes the maximum size.
	 *
	 * @return void
	 */
	public function test_short_text_uses_max_size(): void {
		$result = $this->layout->layout( 'Hi', $this->spec(), $this->font );

		$this->assertSame( 60.0, $result->size );
		$this->assertSame( 1, $result->line_count() );
	}

	/**
	 * A long title is shrunk rather than overflowing.
	 *
	 * @return void
	 */
	public function test_forty_word_title_shrinks_to_fit(): void {
		$text   = implode( ' ', array_fill( 0, 40, 'word' ) );
		$result = $this->layout->layout( $text, $this->spec(), $this->font );

		$this->assertLessThan( 60.0, $result->size );
		$this->assertLessThanOrEqual( 3, $result->line_count() );
		$this->assertGreaterThanOrEqual( 10.0, $result->size );
	}

	/**
	 * Chosen sizes always land on the 0.5px grid.
	 *
	 * @return void
	 */
	public function test_chosen_size_is_on_the_half_pixel_grid(): void {
		foreach ( array( 'One', 'A somewhat longer headline here', implode( ' ', array_fill( 0, 25, 'word' ) ) ) as $text ) {
			$size = $this->layout->layout( $text, $this->spec(), $this->font )->size;

			$this->assertSame( 0.0, fmod( $size * 2, 1.0 ), 'size ' . $size . ' is off the grid' );
		}
	}

	/**
	 * Autofit off pins the size to the maximum.
	 *
	 * @return void
	 */
	public function test_autofit_disabled_uses_max(): void {
		$result = $this->layout->layout(
			'A long headline that would otherwise be shrunk down',
			$this->spec(
				array(
					'autofit'   => false,
					'max_lines' => 20,
				)
			),
			$this->font
		);

		$this->assertSame( 60.0, $result->size );
	}

	// ------------------------------------------------------------------ wrap

	/**
	 * Wrapping is greedy: as many words per line as fit.
	 *
	 * At 20px with a 0.5em advance every character is 10px, so a 400px box holds 40
	 * characters. Eight four-letter words plus their seven spaces is 39 characters,
	 * which fits; a ninth would be 44, which does not.
	 *
	 * @return void
	 */
	public function test_greedy_wrap_fills_each_line(): void {
		$spec   = $this->spec(
			array(
				'size_min'  => 20.0,
				'size_max'  => 20.0,
				'autofit'   => false,
				'max_lines' => 10,
			)
		);
		$result = $this->layout->layout( 'aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii jjjj', $spec, $this->font );

		$this->assertSame( 'aaaa bbbb cccc dddd eeee ffff gggg hhhh', $result->texts()[0] );
		$this->assertSame( 'iiii jjjj', $result->texts()[1] );
		$this->assertEqualsWithDelta( 390.0, $result->lines[0]['width'], 0.001 );
	}

	/**
	 * A single word wider than the box is broken rather than overflowing.
	 *
	 * @return void
	 */
	public function test_unbroken_word_is_broken(): void {
		$spec   = $this->spec(
			array(
				'size_min'  => 20.0,
				'size_max'  => 20.0,
				'autofit'   => false,
				'max_lines' => 10,
			)
		);
		$result = $this->layout->layout( str_repeat( 'A', 100 ), $spec, $this->font );

		$this->assertGreaterThan( 1, $result->line_count() );

		foreach ( $result->texts() as $line ) {
			$this->assertLessThanOrEqual( 400.0, $this->metrics->width( $line, $this->font, 20.0 ) );
		}
	}

	/**
	 * Words are never broken mid-grapheme.
	 *
	 * @return void
	 */
	public function test_breaking_respects_grapheme_clusters(): void {
		$word   = str_repeat( "e\u{0301}", 60 );
		$spec   = $this->spec(
			array(
				'size_min'  => 20.0,
				'size_max'  => 20.0,
				'autofit'   => false,
				'max_lines' => 20,
			)
		);
		$result = $this->layout->layout( $word, $spec, $this->font );

		foreach ( $result->texts() as $line ) {
			// A combining acute may never begin a line: that would mean the cluster
			// was split from its base character.
			$this->assertStringStartsNotWith( "\u{0301}", $line );
		}
	}

	// -------------------------------------------------------------- overflow

	/**
	 * Text beyond the line ceiling is ellipsised.
	 *
	 * @return void
	 */
	public function test_overflow_ellipsis(): void {
		$spec   = $this->spec(
			array(
				'size_min'  => 30.0,
				'size_max'  => 30.0,
				'autofit'   => false,
				'max_lines' => 2,
			)
		);
		$result = $this->layout->layout( implode( ' ', array_fill( 0, 40, 'word' ) ), $spec, $this->font );

		$this->assertTrue( $result->truncated );
		$this->assertSame( 2, $result->line_count() );
		$this->assertStringEndsWith( TextLayout::ELLIPSIS, $result->texts()[1] );
	}

	/**
	 * The ellipsised line still fits inside the box.
	 *
	 * @return void
	 */
	public function test_ellipsised_line_fits(): void {
		$spec   = $this->spec(
			array(
				'size_min'  => 30.0,
				'size_max'  => 30.0,
				'autofit'   => false,
				'max_lines' => 1,
			)
		);
		$result = $this->layout->layout( implode( ' ', array_fill( 0, 40, 'word' ) ), $spec, $this->font );

		$this->assertLessThanOrEqual(
			400.0,
			$this->metrics->width( $result->texts()[0], $this->font, 30.0 )
		);
	}

	/**
	 * Clip overflow drops surplus lines without adding an ellipsis.
	 *
	 * @return void
	 */
	public function test_overflow_clip(): void {
		$spec   = $this->spec(
			array(
				'size_min'  => 30.0,
				'size_max'  => 30.0,
				'autofit'   => false,
				'max_lines' => 2,
				'overflow'  => LayoutSpec::OVERFLOW_CLIP,
			)
		);
		$result = $this->layout->layout( implode( ' ', array_fill( 0, 40, 'word' ) ), $spec, $this->font );

		$this->assertSame( 2, $result->line_count() );
		$this->assertStringEndsNotWith( TextLayout::ELLIPSIS, $result->texts()[1] );
	}

	// ------------------------------------------------------------- placement

	/**
	 * Baselines advance by exactly one line height.
	 *
	 * @return void
	 */
	public function test_baselines_are_evenly_spaced(): void {
		$spec   = $this->spec(
			array(
				'size_min'    => 20.0,
				'size_max'    => 20.0,
				'autofit'     => false,
				'max_lines'   => 5,
				'line_height' => 1.5,
			)
		);
		$result = $this->layout->layout( 'aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii', $spec, $this->font );

		$this->assertGreaterThan( 1, $result->line_count() );

		$gap = $result->lines[1]['baseline'] - $result->lines[0]['baseline'];

		$this->assertEqualsWithDelta( 20.0 * 1.5, $gap, 0.001 );
	}

	/**
	 * Text with and without descenders shares a baseline.
	 *
	 * This is the point of measuring from the ascender rather than the bounding box:
	 * two cards whose headlines differ only in descenders must not sit at different
	 * heights.
	 *
	 * @return void
	 */
	public function test_descenders_do_not_move_the_baseline(): void {
		$spec = $this->spec(
			array(
				'size_min' => 30.0,
				'size_max' => 30.0,
				'autofit'  => false,
			)
		);

		$with    = $this->layout->layout( 'gypsy', $spec, $this->font );
		$without = $this->layout->layout( 'HELLO', $spec, $this->font );

		$this->assertSame( $without->lines[0]['baseline'], $with->lines[0]['baseline'] );
	}

	/**
	 * Alignment positions lines within the box.
	 *
	 * @return void
	 */
	public function test_alignment(): void {
		$spec_left   = $this->spec(
			array(
				'size_min' => 20.0,
				'size_max' => 20.0,
				'autofit'  => false,
			)
		);
		$spec_centre = $this->spec(
			array(
				'size_min' => 20.0,
				'size_max' => 20.0,
				'autofit'  => false,
				'align'    => 'center',
			)
		);
		$spec_right  = $this->spec(
			array(
				'size_min' => 20.0,
				'size_max' => 20.0,
				'autofit'  => false,
				'align'    => 'right',
			)
		);

		$left   = $this->layout->layout( 'abcd', $spec_left, $this->font )->lines[0];
		$centre = $this->layout->layout( 'abcd', $spec_centre, $this->font )->lines[0];
		$right  = $this->layout->layout( 'abcd', $spec_right, $this->font )->lines[0];

		$this->assertSame( 0.0, $left['x'] );
		$this->assertEqualsWithDelta( ( 400.0 - 40.0 ) / 2, $centre['x'], 0.001 );
		$this->assertEqualsWithDelta( 400.0 - 40.0, $right['x'], 0.001 );
	}

	/**
	 * Vertical alignment positions the block within the box.
	 *
	 * @return void
	 */
	public function test_vertical_alignment(): void {
		$top    = $this->layout->layout(
			'abcd',
			$this->spec(
				array(
					'size_min' => 20.0,
					'size_max' => 20.0,
					'autofit'  => false,
				)
			),
			$this->font
		);
		$bottom = $this->layout->layout(
			'abcd',
			$this->spec(
				array(
					'size_min'       => 20.0,
					'size_max'       => 20.0,
					'autofit'        => false,
					'vertical_align' => 'bottom',
				)
			),
			$this->font
		);

		$this->assertGreaterThan( $top->lines[0]['baseline'], $bottom->lines[0]['baseline'] );
	}

	// -------------------------------------------------------------- tracking

	/**
	 * Positive tracking widens a line; negative narrows it.
	 *
	 * @return void
	 */
	public function test_tracking_changes_width(): void {
		$plain = $this->metrics->width( 'abcdef', $this->font, 20.0, 0.0 );
		$loose = $this->metrics->width( 'abcdef', $this->font, 20.0, 0.1 );
		$tight = $this->metrics->width( 'abcdef', $this->font, 20.0, -0.05 );

		$this->assertGreaterThan( $plain, $loose );
		$this->assertLessThan( $plain, $tight );
		// Five gaps between six glyphs.
		$this->assertEqualsWithDelta( $plain + ( 5 * 20.0 * 0.1 ), $loose, 0.001 );
	}

	/**
	 * A single glyph gains no tracking, because there is no gap.
	 *
	 * @return void
	 */
	public function test_tracking_needs_two_glyphs(): void {
		$this->assertSame( 0.0, TextLayout::tracking_width( 'a', 40.0, 0.5 ) );
		$this->assertSame( 0.0, TextLayout::tracking_width( '', 40.0, 0.5 ) );
	}

	// ---------------------------------------------------------------- script

	/**
	 * Arabic raises rather than rendering disconnected glyphs.
	 *
	 * @return void
	 */
	public function test_arabic_raises_unsupported_script(): void {
		$this->expectException( UnsupportedScriptException::class );

		$this->layout->layout( 'لماذا تكذب نسخك الاحتياطية', $this->spec(), $this->font );
	}

	/**
	 * Devanagari raises too.
	 *
	 * @return void
	 */
	public function test_devanagari_raises_unsupported_script(): void {
		$this->expectException( UnsupportedScriptException::class );

		$this->layout->layout( 'आपका बैकअप झूठ बोल रहा है', $this->spec(), $this->font );
	}

	/**
	 * The exception names the script, so the admin notice can be specific.
	 *
	 * @return void
	 */
	public function test_exception_carries_the_script(): void {
		try {
			$this->layout->layout( 'لماذا تكذب', $this->spec(), $this->font );
			$this->fail( 'expected UnsupportedScriptException' );
		} catch ( UnsupportedScriptException $e ) {
			$this->assertSame( 'arabic', $e->script );
		}
	}

	/**
	 * Hebrew lays out, reversed, because it needs no contextual shaping.
	 *
	 * @return void
	 */
	public function test_hebrew_lays_out_reversed(): void {
		$result = $this->layout->layout( 'שלום עולם', $this->spec(), $this->font );

		$this->assertFalse( $result->collapsed );
		$this->assertTrue( $result->rtl );
	}

	/**
	 * Latin, Amharic and Cyrillic all lay out without raising.
	 *
	 * @return void
	 */
	public function test_non_shaping_scripts_lay_out(): void {
		foreach ( array( 'Lúgandá ná Kiswahíli', 'የተሻለ ምትኬ', 'Резервные копии' ) as $text ) {
			$this->assertFalse( $this->layout->layout( $text, $this->spec(), $this->font )->collapsed, $text );
		}
	}

	/**
	 * Emoji are stripped by default and kept when the policy allows.
	 *
	 * @return void
	 */
	public function test_emoji_policy(): void {
		$stripped = $this->layout->layout( 'Ship it 🚀', $this->spec(), $this->font, false );
		$kept     = $this->layout->layout( 'Ship it 🚀', $this->spec(), $this->font, true );

		$this->assertSame( 'Ship it', implode( ' ', $stripped->texts() ) );
		$this->assertStringContainsString( '🚀', implode( ' ', $kept->texts() ) );
	}
}
