<?php
/**
 * Plugin Name:       Social Card Studio
 * Plugin URI:        https://wp-fundi.com/social-card-studio
 * Description:       Generates a custom, branded share image (og:image) for posts, custom post types and the homepage.
 * Version:           0.1.0-alpha
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Chris Rwakabubu — Chrx Digital Solutions
 * Author URI:        https://wp-fundi.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-card-studio
 * Domain Path:       /languages
 *
 * Social Card Studio is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @package ChrxDigital\SocialCardStudio
 */

/*
 * IMPORTANT: this file must remain parseable by PHP 7.0.
 *
 * It runs the requirements gate (SPEC.md §3), and a gate that fatals on the very
 * PHP version it is meant to be reporting is worse than no gate at all. No typed
 * properties, no union types, no arrow functions, no enums in this file. Everything
 * under src/ is free to use PHP 8.1+ because nothing there loads until the gate
 * has passed.
 */

defined( 'ABSPATH' ) || exit;

define( 'SCSTUDIO_VERSION', '0.1.0-alpha' );
define( 'SCSTUDIO_FILE', __FILE__ );
define( 'SCSTUDIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCSTUDIO_URL', plugin_dir_url( __FILE__ ) );
define( 'SCSTUDIO_MIN_PHP', '8.1' );
define( 'SCSTUDIO_MIN_WP', '6.4' );

/**
 * Collects unmet requirements.
 *
 * Implements SPEC.md §3 (minimums) and §12.2 (never break the front end). Returns an
 * empty array when the environment can run the plugin.
 *
 * @since 0.1.0
 *
 * @return string[] List of human-readable failure reasons, already translated.
 */
function scstudio_requirement_failures() {
	$failures = array();

	if ( version_compare( PHP_VERSION, SCSTUDIO_MIN_PHP, '<' ) ) {
		$failures[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'PHP %1$s or higher is required. This site is running PHP %2$s.', 'social-card-studio' ),
			SCSTUDIO_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), SCSTUDIO_MIN_WP, '<' ) ) {
		$failures[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'WordPress %1$s or higher is required. This site is running WordPress %2$s.', 'social-card-studio' ),
			SCSTUDIO_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	if ( ! scstudio_has_usable_image_library() ) {
		$failures[] = __( 'The GD image library with FreeType support is required, and this server does not provide it. Ask your host to enable the GD extension with FreeType support.', 'social-card-studio' );
	}

	return $failures;
}

/**
 * Reports whether the server can rasterise text into an image.
 *
 * FreeType is the part that matters: GD can exist without it, and a card renderer
 * that cannot draw text is of no use. See SPEC.md §4.3.
 *
 * @since 0.1.0
 *
 * @return bool True when GD can draw TrueType text.
 */
function scstudio_has_usable_image_library() {
	return extension_loaded( 'gd' ) && function_exists( 'imagettftext' );
}

/**
 * Prints the requirements notice.
 *
 * @since 0.1.0
 *
 * @return void
 */
function scstudio_render_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$failures = scstudio_requirement_failures();

	if ( empty( $failures ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>';
	echo esc_html__( 'Social Card Studio could not start.', 'social-card-studio' );
	echo '</strong></p><ul style="list-style:disc;margin-left:2em">';

	foreach ( $failures as $failure ) {
		echo '<li>' . esc_html( $failure ) . '</li>';
	}

	echo '</ul></div>';
}

/**
 * Boots the plugin once requirements are met.
 *
 * @since 0.1.0
 *
 * @return void
 */
function scstudio_bootstrap() {
	if ( scstudio_requirement_failures() ) {
		add_action( 'admin_notices', 'scstudio_render_requirements_notice' );
		add_action( 'network_admin_notices', 'scstudio_render_requirements_notice' );

		return;
	}

	$autoload = SCSTUDIO_DIR . 'vendor/autoload.php';

	if ( is_readable( $autoload ) ) {
		require_once $autoload;
	} else {
		require_once SCSTUDIO_DIR . 'src/autoload.php';
	}

	/*
	 * Action Scheduler keeps a registry of every copy present on a site and only the
	 * newest initialises, so including ours alongside WooCommerce's is safe — and is
	 * how the library is meant to be bundled. It has to be included before
	 * plugins_loaded finishes for its own setup to run.
	 */
	\ChrxDigital\SocialCardStudio\Generation\Scheduler::load();

	\ChrxDigital\SocialCardStudio\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'scstudio_bootstrap', 5 );

/*
 * Activation and uninstall are registered unconditionally: WordPress calls them
 * whether or not the requirements gate passed, and both must behave when it did not.
 */
register_activation_hook(
	__FILE__,
	/**
	 * Runs the installer on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 *
	 * @return void
	 */
	function ( $network_wide ) {
		if ( scstudio_requirement_failures() ) {
			return;
		}

		$autoload = SCSTUDIO_DIR . 'vendor/autoload.php';

		if ( is_readable( $autoload ) ) {
			require_once $autoload;
		} else {
			require_once SCSTUDIO_DIR . 'src/autoload.php';
		}

		\ChrxDigital\SocialCardStudio\Setup\Installer::activate( (bool) $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	/**
	 * Clears the daily maintenance task.
	 *
	 * A scheduled event left behind by a deactivated plugin fires forever against a
	 * hook nothing listens to.
	 *
	 * @return void
	 */
	function () {
		$timestamp = wp_next_scheduled( 'scstudio_daily_cleanup' );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'scstudio_daily_cleanup' );
		}
	}
);
