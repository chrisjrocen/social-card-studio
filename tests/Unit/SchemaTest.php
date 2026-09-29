<?php
/**
 * Schema validator tests.
 *
 * Covers the deny-by-default requirement of SPEC.md §18.3. These are the tests that
 * stand between a hostile Card Document and the renderer.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Support\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Support\Schema.
 */
final class SchemaTest extends TestCase {

	/**
	 * Validator under test.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Sets up the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Schema();
	}

	/**
	 * A well-formed map validates.
	 *
	 * @return void
	 */
	public function test_valid_map_passes(): void {
		$result = $this->schema->validate(
			array(
				'name'  => 'editorial-left',
				'width' => 1200,
			),
			$this->box_schema()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1200, $result['value']['width'] );
	}

	/**
	 * An unknown key is rejected rather than dropped.
	 *
	 * A permissive validator here would let a crafted Card Document smuggle keys past
	 * the renderer, which is exactly what SPEC §18.3 forbids.
	 *
	 * @return void
	 */
	public function test_unknown_key_is_rejected(): void {
		$result = $this->schema->validate(
			array(
				'name'    => 'x',
				'width'   => 100,
				'onerror' => 'alert(1)',
			),
			$this->box_schema()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'onerror', implode( ' ', $result['errors'] ) );
	}

	/**
	 * A missing required key fails.
	 *
	 * @return void
	 */
	public function test_missing_required_key_fails(): void {
		$result = $this->schema->validate( array( 'width' => 10 ), $this->box_schema() );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'name is required', implode( ' ', $result['errors'] ) );
	}

	/**
	 * Defaults fill absent optional keys.
	 *
	 * @return void
	 */
	public function test_default_is_applied(): void {
		$result = $this->schema->validate(
			array(
				'name'  => 'x',
				'width' => 10,
			),
			$this->box_schema()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '#000000', $result['value']['color'] );
	}

	/**
	 * Out-of-range numerics fail when clamping is off.
	 *
	 * @return void
	 */
	public function test_out_of_range_fails_without_clamp(): void {
		$result = $this->schema->validate(
			array(
				'name'  => 'x',
				'width' => -50,
			),
			$this->box_schema()
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Clamped numerics are pulled into range instead of failing.
	 *
	 * @return void
	 */
	public function test_clamped_numeric_is_bounded(): void {
		$result = $this->schema->validate(
			array( 'quality' => 5000 ),
			array(
				'type'  => 'map',
				'shape' => array(
					'quality' => array(
						'type'  => 'int',
						'min'   => 40,
						'max'   => 100,
						'clamp' => true,
					),
				),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 100, $result['value']['quality'] );
	}

	/**
	 * Colour formats are enforced, including an injection attempt.
	 *
	 * @return void
	 */
	public function test_color_format_is_enforced(): void {
		$result = $this->schema->validate(
			array(
				'name'  => 'x',
				'width' => 10,
				'color' => '#fff;background:url(javascript:alert(1))',
			),
			$this->box_schema()
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Enum values outside the list are rejected.
	 *
	 * @return void
	 */
	public function test_enum_is_enforced(): void {
		$result = $this->schema->validate(
			array( 'fit' => 'drop-table' ),
			array(
				'type'  => 'map',
				'shape' => array(
					'fit' => array(
						'type'   => 'enum',
						'values' => array( 'cover', 'contain' ),
					),
				),
			)
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * An oversized list is rejected — the 10,000-layer document from SPEC §18.3.
	 *
	 * @return void
	 */
	public function test_oversized_list_is_rejected(): void {
		$result = $this->schema->validate(
			array( 'layers' => array_fill( 0, 10000, 'x' ) ),
			array(
				'type'  => 'map',
				'shape' => array(
					'layers' => array(
						'type'     => 'array',
						'maxitems' => 50,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Over-long strings are rejected.
	 *
	 * @return void
	 */
	public function test_maxlength_is_enforced(): void {
		$result = $this->schema->validate(
			array(
				'name'  => str_repeat( 'a', 500 ),
				'width' => 10,
			),
			$this->box_schema()
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A scalar where a map is expected fails without a PHP warning.
	 *
	 * @return void
	 */
	public function test_scalar_for_map_fails_cleanly(): void {
		$result = $this->schema->validate( 'not-a-map', $this->box_schema() );

		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Nested maps report a dotted path so the error names the offending key.
	 *
	 * @return void
	 */
	public function test_nested_errors_report_a_path(): void {
		$result = $this->schema->validate(
			array( 'brand' => array( 'accent' => 'nope' ) ),
			array(
				'type'  => 'map',
				'shape' => array(
					'brand' => array(
						'type'  => 'map',
						'shape' => array(
							'accent' => array(
								'type'   => 'string',
								'format' => 'color',
							),
						),
					),
				),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'brand.accent', implode( ' ', $result['errors'] ) );
	}

	/**
	 * A reusable schema for the tests above.
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private function box_schema(): array {
		return array(
			'type'  => 'map',
			'shape' => array(
				'name'  => array(
					'type'      => 'string',
					'required'  => true,
					'maxlength' => 64,
				),
				'width' => array(
					'type' => 'int',
					'min'  => 0,
					'max'  => 4000,
				),
				'color' => array(
					'type'    => 'string',
					'format'  => 'color',
					'default' => '#000000',
				),
			),
		);
	}
}
