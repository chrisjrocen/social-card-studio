<?php
/**
 * Output budget tests.
 *
 * Covers SPEC.md §7.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Render\Optimizer;
use ChrxDigital\SocialCardStudio\Render\RenderException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Render\Optimizer.
 */
final class OptimizerTest extends TestCase {

	/**
	 * Optimiser under test.
	 *
	 * @var Optimizer
	 */
	private Optimizer $optimizer;

	/**
	 * Sets up the optimiser.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->optimizer = new Optimizer();
	}

	/**
	 * Builds an encoder whose output shrinks as quality drops.
	 *
	 * @param int   $at82   Size produced at quality 82.
	 * @param int[] $calls  Collected qualities, by reference.
	 *
	 * @return callable(int): string Encoder.
	 */
	private function encoder( int $at82, array &$calls ): callable {
		return static function ( int $quality ) use ( $at82, &$calls ): string {
			$calls[] = $quality;

			// Roughly linear in quality, which is close enough to how JPEG behaves.
			return str_repeat( 'x', (int) round( $at82 * ( $quality / 82 ) ) );
		};
	}

	/**
	 * A card already under target encodes once and stops.
	 *
	 * @return void
	 */
	public function test_small_card_encodes_once(): void {
		$calls  = array();
		$result = $this->optimizer->optimize( $this->encoder( 200 * 1024, $calls ), 82 );

		$this->assertSame( array( 82 ), $calls );
		$this->assertSame( 82, $result['quality'] );
		$this->assertSame( 1, $result['steps'] );
	}

	/**
	 * A large card walks the ladder until it fits.
	 *
	 * @return void
	 */
	public function test_ladder_is_walked_until_the_target_is_met(): void {
		$calls  = array();
		$result = $this->optimizer->optimize( $this->encoder( 700 * 1024, $calls ), 82 );

		$this->assertSame( 82, $calls[0] );
		$this->assertGreaterThan( 1, count( $calls ) );
		$this->assertLessThanOrEqual( Optimizer::TARGET_BYTES, strlen( $result['bytes'] ) );
		$this->assertLessThan( 82, $result['quality'] );
	}

	/**
	 * The ladder is exactly the one in SPEC §7.2, in order.
	 *
	 * @return void
	 */
	public function test_ladder_matches_the_spec(): void {
		$this->assertSame( array( 82, 74, 66, 58 ), Optimizer::LADDER );

		$calls = array();
		$this->optimizer->optimize( $this->encoder( 5000 * 1024, $calls ), 82 );

		$this->assertSame( array( 82, 74, 66, 58 ), $calls );
	}

	/**
	 * A card that never fits still ships, as long as it is under the discard size.
	 *
	 * A slightly heavy card that unfurls beats no card at all.
	 *
	 * @return void
	 */
	public function test_card_over_ceiling_but_under_discard_still_ships(): void {
		$calls  = array();
		$result = $this->optimizer->optimize( $this->encoder( 1900 * 1024, $calls ), 82 );

		$this->assertSame( 58, $result['quality'] );
		$this->assertGreaterThan( Optimizer::MAX_BYTES, strlen( $result['bytes'] ) );
		$this->assertTrue( $this->optimizer->is_oversize( strlen( $result['bytes'] ) ) );
	}

	/**
	 * A card above the discard threshold raises instead of shipping.
	 *
	 * @return void
	 */
	public function test_absurdly_large_card_is_discarded(): void {
		$calls = array();

		$this->expectException( RenderException::class );

		$this->optimizer->optimize( $this->encoder( 9000 * 1024, $calls ), 82 );
	}

	/**
	 * The discard failure is typed so the caller can fall back.
	 *
	 * @return void
	 */
	public function test_discard_reason_is_typed(): void {
		$calls = array();

		try {
			$this->optimizer->optimize( $this->encoder( 9000 * 1024, $calls ), 82 );
			$this->fail( 'expected RenderException' );
		} catch ( RenderException $e ) {
			$this->assertSame( RenderException::REASON_OVERSIZE, $e->reason );
		}
	}

	/**
	 * A lower starting quality skips the rungs above it.
	 *
	 * @return void
	 */
	public function test_lower_start_skips_higher_rungs(): void {
		$calls = array();
		$this->optimizer->optimize( $this->encoder( 5000 * 1024, $calls ), 66 );

		$this->assertSame( array( 66, 58 ), $calls );
	}

	/**
	 * A higher starting quality is honoured as a first attempt.
	 *
	 * @return void
	 */
	public function test_higher_start_is_tried_first(): void {
		$calls = array();
		$this->optimizer->optimize( $this->encoder( 5000 * 1024, $calls ), 95 );

		$this->assertSame( 95, $calls[0] );
	}

	/**
	 * The budgets match SPEC §7.2 exactly.
	 *
	 * @return void
	 */
	public function test_budgets_match_the_spec(): void {
		$this->assertSame( 600 * 1024, Optimizer::TARGET_BYTES );
		$this->assertSame( 1024 * 1024, Optimizer::MAX_BYTES );
		$this->assertSame( 5 * 1024 * 1024, Optimizer::DISCARD_BYTES );
	}

	/**
	 * Only JPEG and PNG are namable output formats.
	 *
	 * WebP and AVIF are excluded from the social image by SPEC §7.2, and there is no
	 * constant here that could be used to produce one.
	 *
	 * @return void
	 */
	public function test_no_webp_constant_exists(): void {
		$constants = ( new \ReflectionClass( Optimizer::class ) )->getConstants();

		foreach ( $constants as $value ) {
			if ( is_string( $value ) ) {
				$this->assertStringNotContainsString( 'webp', strtolower( $value ) );
				$this->assertStringNotContainsString( 'avif', strtolower( $value ) );
			}
		}
	}
}
