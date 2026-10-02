<?php
/**
 * Tests for Card\RenderProfile template resolution.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\RenderProfile;
use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\Legibility;
use ChrxDigital\SocialCardStudio\Render\Optimizer;
use ChrxDigital\SocialCardStudio\Render\RendererFactory;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Settings\SettingsSchema;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The one resolver every caller uses for "which design does this post type get".
 */
final class RenderProfileTest extends TestCase {

	/**
	 * Builds a profile over the given stored settings.
	 *
	 * @param array<string, mixed> $stored Partial option value.
	 *
	 * @return RenderProfile Profile.
	 */
	private function profile( array $stored ): RenderProfile {
		$GLOBALS['scstudio_test_options'] = array( Settings::OPTION => array_merge( SettingsSchema::defaults(), $stored ) );

		$settings  = new Settings();
		$directory = new CardDirectory();

		return new RenderProfile(
			$settings,
			new TemplateRegistry( $directory ),
			new FontResolver( $settings ),
			new RendererFactory( new TokenResolver( $settings ), new Optimizer(), new Legibility(), $directory )
		);
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
	 * A per-type override wins over the default for that type only.
	 *
	 * @return void
	 */
	public function test_per_type_override_applies_to_its_type(): void {
		$profile = $this->profile(
			array(
				'default_template'  => 'editorial-left',
				'per_type_template' => array( 'product' => 'split-frame' ),
			)
		);

		$this->assertSame( 'split-frame', $profile->sitewide_template_for( 'product' ) );
		$this->assertSame( 'editorial-left', $profile->sitewide_template_for( 'post' ) );
	}

	/**
	 * An empty override means "use the default".
	 *
	 * @return void
	 */
	public function test_empty_override_falls_back_to_default(): void {
		$profile = $this->profile(
			array(
				'default_template'  => 'minimal-serif',
				'per_type_template' => array( 'page' => '' ),
			)
		);

		$this->assertSame( 'minimal-serif', $profile->sitewide_template_for( 'page' ) );
	}

	/**
	 * The site card has its own setting, independent of the post default.
	 *
	 * @return void
	 */
	public function test_site_template_is_independent(): void {
		$profile = $this->profile(
			array(
				'default_template' => 'editorial-left',
				'homepage_card'    => array(
					'headline' => '',
					'template' => 'bold-statement',
				),
			)
		);

		$this->assertSame( 'bold-statement', $profile->site_template() );
	}
}
