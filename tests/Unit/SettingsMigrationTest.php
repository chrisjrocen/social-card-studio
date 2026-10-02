<?php
/**
 * Tests for the DB version 2 settings cleanup.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Hash;
use PHPUnit\Framework\TestCase;

/**
 * Removing dead keys must neither break saves nor invalidate existing cards.
 */
final class SettingsMigrationTest extends TestCase {

	/**
	 * A stored option as a version 1 site has it.
	 *
	 * @return array<string, mixed> Option value.
	 */
	private function legacy_option(): array {
		return array(
			'enabled_post_types'       => array( 'post', 'page' ),
			'default_template'         => 'split-frame',
			'per_type_template'        => array( 'page' => 'minimal-serif' ),
			'triggers'                 => array(
				'on_publish' => true,
				'lazy'       => false,
			),
			'brand'                    => array(
				'accent'        => '#112233',
				'logo_id'       => 0,
				'logo_position' => 'top-left',
			),
			'typography'               => array(
				'source'  => 'theme',
				'heading' => '',
				'body'    => '',
			),
			'homepage_card'            => array(
				'enabled'  => true,
				'headline' => 'Hello',
				'template' => 'brand-statement',
			),
			'output'                   => array(
				'format'       => 'jpeg',
				'quality'      => 75,
				'target_bytes' => 600000,
				'max_bytes'    => 1048576,
			),
			'media_library_mode'       => false,
			'alt_text_pattern'         => '{{headline}}',
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Seeds the in-memory option store.
	 *
	 * @param array<string, mixed> $value Option value.
	 *
	 * @return Settings Fresh settings reader.
	 */
	private function settings_with( array $value ): Settings {
		$GLOBALS['scstudio_test_options'] = array( Settings::OPTION => $value );

		return new Settings();
	}

	/**
	 * Clears the option store.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['scstudio_test_options'] );
	}

	/**
	 * A stored option still carrying removed keys reads without them and still
	 * validates, so the next save does not fail on "unknown key".
	 *
	 * @return void
	 */
	public function test_legacy_option_reads_clean_and_validates(): void {
		$settings = $this->settings_with( $this->legacy_option() );
		$all      = $settings->all();

		$this->assertSame( array( 'accent' => '#112233' ), $all['brand'] );
		$this->assertSame( array( 'quality' => 75 ), $all['output'] );
		$this->assertArrayNotHasKey( 'enabled', $all['homepage_card'] );
		$this->assertSame( array( 'page' => 'minimal-serif' ), $all['per_type_template'] );

		$result = $settings->sanitize( $this->legacy_option() );

		$this->assertTrue( $result['ok'], implode( '; ', $result['errors'] ) );
	}

	/**
	 * The fingerprint after the upgrade equals the one version 1 computed, so no
	 * card goes stale because of the cleanup.
	 *
	 * @return void
	 */
	public function test_fingerprint_is_unchanged_by_the_upgrade(): void {
		$legacy = $this->legacy_option();

		// What version 1's fingerprint() hashed.
		$expected = Hash::of(
			array(
				'brand'             => $legacy['brand'],
				'typography'        => $legacy['typography'],
				'output'            => $legacy['output'],
				'default_template'  => $legacy['default_template'],
				'per_type_template' => $legacy['per_type_template'],
				'alt_text_pattern'  => $legacy['alt_text_pattern'],
			)
		);

		$migrated = $legacy;
		unset( $migrated['brand']['logo_id'], $migrated['brand']['logo_position'], $migrated['homepage_card']['enabled'] );
		unset( $migrated['output']['format'], $migrated['output']['target_bytes'], $migrated['output']['max_bytes'] );

		$this->assertSame( $expected, $this->settings_with( $legacy )->fingerprint() );
		$this->assertSame( $expected, $this->settings_with( $migrated )->fingerprint() );
	}

	/**
	 * A real design change still changes the fingerprint.
	 *
	 * @return void
	 */
	public function test_fingerprint_follows_design_changes(): void {
		$before = $this->settings_with( $this->legacy_option() )->fingerprint();

		$changed                    = $this->legacy_option();
		$changed['brand']['accent'] = '#445566';

		$this->assertNotSame( $before, $this->settings_with( $changed )->fingerprint() );
	}

	/**
	 * Preview overrides apply inside the callback and are gone afterwards.
	 *
	 * @return void
	 */
	public function test_preview_overrides_are_temporary(): void {
		$settings = $this->settings_with( $this->legacy_option() );

		$inside = $settings->preview(
			array(
				'brand'             => array( 'accent' => '#abcdef' ),
				'per_type_template' => array(),
			),
			static fn (): array => array( $settings->get( 'brand.accent' ), $settings->get( 'per_type_template' ) )
		);

		$this->assertSame( array( '#abcdef', array() ), $inside );
		$this->assertSame( '#112233', $settings->get( 'brand.accent' ) );
		$this->assertSame( array( 'page' => 'minimal-serif' ), $settings->get( 'per_type_template' ) );
	}

	/**
	 * An invalid preview override is reported, not rendered.
	 *
	 * @return void
	 */
	public function test_invalid_preview_override_is_rejected(): void {
		$settings = $this->settings_with( $this->legacy_option() );
		$called   = false;

		$result = $settings->preview(
			array( 'brand' => array( 'accent' => 'url(javascript:alert(1))' ) ),
			static function () use ( &$called ): bool {
				$called = true;

				return true;
			}
		);

		$this->assertFalse( $called );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['ok'] );
	}
}
