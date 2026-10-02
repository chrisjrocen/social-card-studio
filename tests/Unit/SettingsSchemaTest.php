<?php
/**
 * Settings defaults/schema consistency tests.
 *
 * Covers SPEC.md §5.1. The defaults and the schema are declared separately and it is
 * entirely possible to change one and forget the other; this catches that.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Settings\SettingsSchema;
use ChrxDigital\SocialCardStudio\Support\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Settings\SettingsSchema.
 */
final class SettingsSchemaTest extends TestCase {

	/**
	 * The shipped defaults validate against the shipped schema.
	 *
	 * @return void
	 */
	public function test_defaults_validate(): void {
		$result = ( new Schema() )->validate( SettingsSchema::defaults(), SettingsSchema::schema() );

		$this->assertTrue( $result['ok'], 'Defaults failed validation: ' . implode( '; ', $result['errors'] ) );
	}

	/**
	 * Defaults stay under the autoloaded-option budget from SPEC §5.1.
	 *
	 * @return void
	 */
	public function test_defaults_are_small(): void {
		$this->assertLessThan( 32768, strlen( (string) wp_json_encode( SettingsSchema::defaults() ) ) );
	}

	/**
	 * Every default key is described by the schema, and vice versa.
	 *
	 * @return void
	 */
	public function test_defaults_and_schema_cover_the_same_keys(): void {
		$defaults = array_keys( SettingsSchema::defaults() );
		$schema   = array_keys( SettingsSchema::schema()['shape'] );

		sort( $defaults );
		sort( $schema );

		$this->assertSame( $schema, $defaults );
	}

	/**
	 * Output format is not a setting: JPEG is the only format, and WebP and AVIF
	 * stay excluded per SPEC §7.2.
	 *
	 * @return void
	 */
	public function test_output_format_is_not_configurable(): void {
		$this->assertSame( array( 'quality' ), array_keys( SettingsSchema::schema()['shape']['output']['shape'] ) );
	}

	/**
	 * The keys removed in DB version 2 are gone from defaults and schema alike.
	 *
	 * @return void
	 */
	public function test_removed_keys_are_absent(): void {
		$defaults = SettingsSchema::defaults();
		$shape    = SettingsSchema::schema()['shape'];

		$this->assertSame( array( 'accent' ), array_keys( $defaults['brand'] ) );
		$this->assertSame( array( 'accent' ), array_keys( $shape['brand']['shape'] ) );
		$this->assertArrayNotHasKey( 'enabled', $defaults['homepage_card'] );
		$this->assertArrayNotHasKey( 'enabled', $shape['homepage_card']['shape'] );
	}

	/**
	 * An unknown top-level key is rejected rather than stored.
	 *
	 * @return void
	 */
	public function test_unknown_setting_is_rejected(): void {
		$input                 = SettingsSchema::defaults();
		$input['evil_setting'] = 'x';

		$result = ( new Schema() )->validate( $input, SettingsSchema::schema() );

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A hostile brand colour is rejected.
	 *
	 * @return void
	 */
	public function test_hostile_accent_colour_is_rejected(): void {
		$input                    = SettingsSchema::defaults();
		$input['brand']['accent'] = 'url(javascript:alert(1))';

		$result = ( new Schema() )->validate( $input, SettingsSchema::schema() );

		$this->assertFalse( $result['ok'] );
	}
}
