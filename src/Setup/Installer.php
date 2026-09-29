<?php
/**
 * Activation, per-site install and schema upgrades.
 *
 * Implements SPEC.md §5 (data model), §16 (multisite) and §18.1 (lifecycle).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Setup;

use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Settings\SettingsSchema;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Storage\Lifecycle;
use ChrxDigital\SocialCardStudio\Support\EventsTable;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Brings a site's storage up to the current schema.
 *
 * Network activation deliberately does not loop every site. On a network of any size
 * that request would time out, and a half-finished loop leaves sites in unknown
 * states. Instead each site installs itself lazily on its first load via
 * maybe_upgrade(), which is idempotent and costs one option read when up to date.
 *
 * @since 0.1.0
 */
final class Installer {

	public const VERSION_OPTION = 'scstudio_db_version';

	/**
	 * Current storage schema version.
	 */
	public const DB_VERSION = 1;

	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $network_wide Whether the plugin was network activated.
	 *
	 * @return void
	 */
	public static function activate( bool $network_wide ): void {
		// The events table is network-wide (SPEC §5.4), so it is created once either way.
		EventsTable::install();

		if ( $network_wide && is_multisite() ) {
			// Per-site setup happens lazily; see the class docblock.
			return;
		}

		self::install_site();
	}

	/**
	 * Installs a newly created multisite site.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Site $site The new site.
	 *
	 * @return void
	 */
	public static function on_new_site( WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( SCSTUDIO_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Installs or upgrades the current site if its schema version is behind.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		if ( ! EventsTable::exists() ) {
			EventsTable::install();
		}

		self::install_site();
	}

	/**
	 * Provisions storage and seeds defaults for the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function install_site(): void {
		( new CardDirectory() )->provision();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, SettingsSchema::defaults(), '', true );
		}

		Lifecycle::schedule();

		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
	}
}
