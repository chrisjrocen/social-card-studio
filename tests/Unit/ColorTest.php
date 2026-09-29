<?php
/**
 * Colour parsing and contrast tests.
 *
 * Covers the colour allowlist of SPEC.md §18.3 and the 4.5:1 contrast target used by
 * the auto-contrast scrim (§2.1) and the AI legibility guard (§13.1).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Support\Color;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Support\Color.
 */
final class ColorTest extends TestCase {

	/**
	 * Accepted literal forms parse.
	 *
	 * @dataProvider provide_valid_colors
	 *
	 * @param string $literal Colour literal.
	 *
	 * @return void
	 */
	public function test_valid_colors_parse( string $literal ): void {
		$this->assertTrue( Color::is_valid( $literal ), $literal . ' should parse' );
	}

	/**
	 * Valid colour literals.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_valid_colors(): array {
		return array(
			array( '#fff' ),
			array( '#FFFFFF' ),
			array( '#0F172AE6' ),
			array( 'rgb(15, 23, 42)' ),
			array( 'rgba(15, 23, 42, 0.9)' ),
			array( 'rgba(15,23,42,1)' ),
		);
	}

	/**
	 * Hostile and malformed literals are rejected.
	 *
	 * @dataProvider provide_invalid_colors
	 *
	 * @param string $literal Colour literal.
	 *
	 * @return void
	 */
	public function test_invalid_colors_are_rejected( string $literal ): void {
		$this->assertFalse( Color::is_valid( $literal ), $literal . ' should be rejected' );
		$this->assertNull( Color::parse( $literal ) );
	}

	/**
	 * Invalid colour literals, including the injection attempts from SPEC §18.3.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_invalid_colors(): array {
		return array(
			array( 'red' ),
			array( '#ff' ),
			array( '#fffff' ),
			array( 'javascript:alert(1)' ),
			array( 'url(https://example.com/x.png)' ),
			array( '#fff;background:url(x)' ),
			array( 'rgb(15, 23)' ),
			array( 'expression(alert(1))' ),
			array( '' ),
		);
	}

	/**
	 * Shorthand hex expands correctly.
	 *
	 * @return void
	 */
	public function test_shorthand_hex_expands(): void {
		$color = Color::parse( '#f0a' );

		$this->assertNotNull( $color );
		$this->assertSame( '#FF00AA', $color->to_hex() );
	}

	/**
	 * Eight-digit hex carries alpha through.
	 *
	 * @return void
	 */
	public function test_hex_alpha_is_parsed(): void {
		$color = Color::parse( '#0F172A80' );

		$this->assertNotNull( $color );
		$this->assertEqualsWithDelta( 0.502, $color->a, 0.01 );
	}

	/**
	 * Black on white is the maximum WCAG ratio.
	 *
	 * @return void
	 */
	public function test_contrast_extremes(): void {
		$black = Color::parse( '#000000' );
		$white = Color::parse( '#FFFFFF' );

		$this->assertNotNull( $black );
		$this->assertNotNull( $white );
		$this->assertEqualsWithDelta( 21.0, $black->contrast( $white ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, $white->contrast( $white ), 0.01 );
	}

	/**
	 * Contrast is symmetric, so argument order cannot change a legibility verdict.
	 *
	 * @return void
	 */
	public function test_contrast_is_symmetric(): void {
		$a = Color::parse( '#2563EB' );
		$b = Color::parse( '#FFFFFF' );

		$this->assertNotNull( $a );
		$this->assertNotNull( $b );
		$this->assertEqualsWithDelta( $a->contrast( $b ), $b->contrast( $a ), 0.0001 );
	}

	/**
	 * A half-opaque black scrim over white lands midway.
	 *
	 * @return void
	 */
	public function test_compositing_blends_towards_the_backdrop(): void {
		$scrim = Color::parse( '#00000080' );
		$white = Color::parse( '#FFFFFF' );

		$this->assertNotNull( $scrim );
		$this->assertNotNull( $white );

		$blended = $scrim->over( $white );

		$this->assertSame( 1.0, $blended->a );
		$this->assertGreaterThan( 120, $blended->r );
		$this->assertLessThan( 135, $blended->r );
	}

	/**
	 * Deepening a scrim raises contrast against white text — the loop the legibility
	 * guard in SPEC §13.1 relies on terminating.
	 *
	 * @return void
	 */
	public function test_deepening_a_scrim_increases_contrast(): void {
		$white = Color::parse( '#FFFFFF' );
		$base  = Color::parse( '#0F172A' );
		$scrim = Color::parse( '#0F172A' );

		$this->assertNotNull( $white );
		$this->assertNotNull( $base );
		$this->assertNotNull( $scrim );

		$light = $scrim->with_alpha( 0.2 )->over( Color::parse( '#CCCCCC' ) );
		$heavy = $scrim->with_alpha( 0.9 )->over( Color::parse( '#CCCCCC' ) );

		$this->assertGreaterThan( $white->contrast( $light ), $white->contrast( $heavy ) );
	}
}
