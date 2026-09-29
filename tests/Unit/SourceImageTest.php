<?php
/**
 * Image fitting tests.
 *
 * Covers the cover/contain/fill geometry used by both renderers, per SPEC.md §4.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Render\SourceImage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Render\SourceImage.
 */
final class SourceImageTest extends TestCase {

	/**
	 * Builds a plan without touching the filesystem.
	 *
	 * @param int $width  Intrinsic width.
	 * @param int $height Intrinsic height.
	 *
	 * @return SourceImage Plan.
	 */
	private function plan( int $width, int $height ): SourceImage {
		$reflection = new \ReflectionClass( SourceImage::class );
		$instance   = $reflection->newInstanceWithoutConstructor();

		foreach ( array(
			'path'        => '/tmp/example.jpg',
			'width'       => $width,
			'height'      => $height,
			'load_width'  => $width,
			'load_height' => $height,
			'orientation' => 1,
		) as $property => $value ) {
			$field = $reflection->getProperty( $property );
			$field->setAccessible( true );
			$field->setValue( $instance, $value );
		}

		return $instance;
	}

	/**
	 * Cover fills the box entirely, cropping the overflowing axis.
	 *
	 * @return void
	 */
	public function test_cover_fills_the_box(): void {
		$rects = $this->plan( 2000, 1333 )->rects( new Box( 0, 0, 1200, 630 ), 'cover' );

		$this->assertSame( 1200, $rects['dw'] );
		$this->assertSame( 630, $rects['dh'] );
		$this->assertSame( 0, $rects['dx'] );
		$this->assertSame( 0, $rects['dy'] );

		// The source is wider than 1200:630, so height is the cropped axis.
		$this->assertLessThan( 1333, $rects['sh'] );
		$this->assertSame( 2000, $rects['sw'] );

		// The crop is centred.
		$this->assertSame( (int) round( ( 1333 - $rects['sh'] ) / 2 ), $rects['sy'] );
	}

	/**
	 * Cover on a tall source crops width instead.
	 *
	 * @return void
	 */
	public function test_cover_crops_the_other_axis_for_a_tall_source(): void {
		$rects = $this->plan( 800, 2000 )->rects( new Box( 0, 0, 1200, 630 ), 'cover' );

		$this->assertSame( 800, $rects['sw'] );
		$this->assertLessThan( 2000, $rects['sh'] );
		$this->assertSame( 1200, $rects['dw'] );
	}

	/**
	 * Contain fits the whole image inside, centred, without cropping.
	 *
	 * @return void
	 */
	public function test_contain_fits_inside_and_centres(): void {
		$rects = $this->plan( 2000, 1000 )->rects( new Box( 0, 0, 1200, 630 ), 'contain' );

		$this->assertSame( 2000, $rects['sw'] );
		$this->assertSame( 1000, $rects['sh'] );
		$this->assertLessThanOrEqual( 1200, $rects['dw'] );
		$this->assertLessThanOrEqual( 630, $rects['dh'] );

		// 2000x1000 into 1200x630 is width-limited: 1200x600, centred vertically.
		$this->assertSame( 1200, $rects['dw'] );
		$this->assertSame( 600, $rects['dh'] );
		$this->assertSame( 15, $rects['dy'] );
		$this->assertSame( 0, $rects['dx'] );
	}

	/**
	 * Fill stretches to the box, ignoring aspect ratio.
	 *
	 * @return void
	 */
	public function test_fill_stretches(): void {
		$rects = $this->plan( 400, 400 )->rects( new Box( 10, 20, 1200, 630 ), 'fill' );

		$this->assertSame( 400, $rects['sw'] );
		$this->assertSame( 400, $rects['sh'] );
		$this->assertSame( 1200, $rects['dw'] );
		$this->assertSame( 630, $rects['dh'] );
		$this->assertSame( 10, $rects['dx'] );
		$this->assertSame( 20, $rects['dy'] );
	}

	/**
	 * Cover respects a box that is not at the origin.
	 *
	 * @return void
	 */
	public function test_cover_honours_box_position(): void {
		$rects = $this->plan( 1000, 1000 )->rects( new Box( 860, 40, 280, 280 ), 'cover' );

		$this->assertSame( 860, $rects['dx'] );
		$this->assertSame( 40, $rects['dy'] );
		$this->assertSame( 280, $rects['dw'] );
		$this->assertSame( 280, $rects['dh'] );
	}

	/**
	 * The minimum inbound size matches SPEC §7.1.
	 *
	 * @return void
	 */
	public function test_minimum_size(): void {
		$this->assertTrue( $this->plan( 600, 315 )->meets_minimum() );
		$this->assertFalse( $this->plan( 599, 315 )->meets_minimum() );
		$this->assertFalse( $this->plan( 600, 314 )->meets_minimum() );
	}

	/**
	 * A crop never asks for more pixels than the source has.
	 *
	 * Asking for a region beyond the image is how both libraries produce black bars.
	 *
	 * @return void
	 */
	public function test_crop_never_exceeds_the_source(): void {
		foreach ( array( array( 700, 400 ), array( 601, 316 ), array( 4000, 300 ), array( 300, 4000 ) ) as $size ) {
			$plan  = $this->plan( $size[0], $size[1] );
			$rects = $plan->rects( new Box( 0, 0, 1200, 630 ), 'cover' );

			$this->assertLessThanOrEqual( $size[0], $rects['sx'] + $rects['sw'], "{$size[0]}x{$size[1]}" );
			$this->assertLessThanOrEqual( $size[1], $rects['sy'] + $rects['sh'], "{$size[0]}x{$size[1]}" );
			$this->assertGreaterThanOrEqual( 0, $rects['sx'] );
			$this->assertGreaterThanOrEqual( 0, $rects['sy'] );
		}
	}

	/**
	 * The downscale cap matches SPEC §4.4.
	 *
	 * @return void
	 */
	public function test_max_scale(): void {
		$this->assertSame( 2, SourceImage::MAX_SCALE );
	}
}
