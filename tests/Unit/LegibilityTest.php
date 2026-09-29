<?php
/**
 * Legibility guard tests.
 *
 * Covers the auto-contrast scrim of SPEC.md §2.1 and the guard AI backgrounds depend
 * on in §13.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Render\Legibility;
use ChrxDigital\SocialCardStudio\Support\Color;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Render\Legibility.
 */
final class LegibilityTest extends TestCase {

	/**
	 * Guard under test.
	 *
	 * @var Legibility
	 */
	private Legibility $legibility;

	/**
	 * Sets up the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->legibility = new Legibility();
	}

	/**
	 * Parses a colour, failing the test if it is malformed.
	 *
	 * @param string $literal Colour literal.
	 *
	 * @return Color Parsed colour.
	 */
	private function color( string $literal ): Color {
		$color = Color::parse( $literal );

		$this->assertNotNull( $color, $literal . ' should parse' );

		return $color;
	}

	/**
	 * White text on a bright photo is deepened until it is readable.
	 *
	 * This is the case the guard exists for: a template that looks fine over a dark
	 * photograph is unreadable over a bright one, and nobody chose the photograph.
	 *
	 * @return void
	 */
	public function test_bright_backdrop_is_deepened(): void {
		$result = $this->legibility->deepen(
			$this->color( '#F2F0E8' ),
			$this->color( '#FFFFFF' ),
			$this->color( '#0F172A33' )
		);

		$this->assertTrue( $result['reached'], 'contrast reached only ' . $result['contrast'] );
		$this->assertGreaterThanOrEqual( Legibility::TARGET, $result['contrast'] );
		$this->assertGreaterThan( 0.2, $result['scrim']->a );
		$this->assertGreaterThan( 0, $result['steps'] );
	}

	/**
	 * A scrim that already passes is left alone.
	 *
	 * @return void
	 */
	public function test_sufficient_contrast_is_not_touched(): void {
		$result = $this->legibility->deepen(
			$this->color( '#0B1020' ),
			$this->color( '#FFFFFF' ),
			$this->color( '#0F172A33' )
		);

		$this->assertTrue( $result['reached'] );
		$this->assertSame( 0, $result['steps'] );
		$this->assertEqualsWithDelta( 0.2, $result['scrim']->a, 0.01 );
	}

	/**
	 * Deepening stops at the cap rather than producing a black rectangle.
	 *
	 * @return void
	 */
	public function test_alpha_is_capped(): void {
		$result = $this->legibility->deepen(
			$this->color( '#FFFFFF' ),
			$this->color( '#F8F8F8' ),
			$this->color( '#FFFFFF00' )
		);

		$this->assertLessThanOrEqual( Legibility::MAX_ALPHA, $result['scrim']->a );
		$this->assertFalse( $result['reached'] );
	}

	/**
	 * Deepening always terminates.
	 *
	 * @dataProvider provide_pairs
	 *
	 * @param string $backdrop Backdrop colour.
	 * @param string $text     Text colour.
	 * @param string $scrim    Scrim colour.
	 *
	 * @return void
	 */
	public function test_deepening_terminates( string $backdrop, string $text, string $scrim ): void {
		$result = $this->legibility->deepen(
			$this->color( $backdrop ),
			$this->color( $text ),
			$this->color( $scrim )
		);

		$this->assertLessThan( 100, $result['steps'] );
		$this->assertLessThanOrEqual( Legibility::MAX_ALPHA, $result['scrim']->a );
	}

	/**
	 * Colour combinations, including impossible ones.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}> Test cases.
	 */
	public static function provide_pairs(): array {
		return array(
			array( '#FFFFFF', '#FFFFFF', '#00000000' ),
			array( '#000000', '#000000', '#FFFFFF00' ),
			array( '#808080', '#FFFFFF', '#0F172A33' ),
			array( '#2563EB', '#FFFFFF', '#00000040' ),
			array( '#F5F5F5', '#111111', '#FFFFFF00' ),
		);
	}

	/**
	 * The legibility test agrees with WCAG.
	 *
	 * @return void
	 */
	public function test_is_legible(): void {
		$this->assertTrue( $this->legibility->is_legible( $this->color( '#000000' ), $this->color( '#FFFFFF' ) ) );
		$this->assertFalse( $this->legibility->is_legible( $this->color( '#DDDDDD' ), $this->color( '#FFFFFF' ) ) );
	}

	/**
	 * Averaging is by region, so one bright speck cannot force an opaque scrim.
	 *
	 * @return void
	 */
	public function test_average_is_not_dominated_by_one_sample(): void {
		$samples   = array_fill( 0, 99, array( 20, 20, 20 ) );
		$samples[] = array( 255, 255, 255 );

		$average = $this->legibility->average( $samples );

		$this->assertLessThan( 45, $average->r );
	}

	/**
	 * Averaging an empty region yields a neutral mid grey rather than failing.
	 *
	 * @return void
	 */
	public function test_average_of_nothing(): void {
		$this->assertSame( '#808080', $this->legibility->average( array() )->to_hex() );
	}

	/**
	 * The target matches WCAG AA, as named in SPEC §2.1.
	 *
	 * @return void
	 */
	public function test_target_is_wcag_aa(): void {
		$this->assertSame( 4.5, Legibility::TARGET );
	}
}
