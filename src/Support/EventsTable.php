<?php
/**
 * Schema and name resolution for the events table.
 *
 * Implements SPEC.md §5.4.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the {prefix}scstudio_events table.
 *
 * On multisite this is a single network-wide table with a blog_id column (SPEC §16),
 * so it uses base_prefix. On single site base_prefix and prefix are identical, which
 * keeps one code path for both.
 *
 * @since 0.1.0
 */
final class EventsTable {

	/**
	 * Defensive row ceiling from SPEC §5.4.
	 */
	public const MAX_ROWS = 250000;

	/**
	 * Returns the fully-qualified table name.
	 *
	 * Never cache the result across a switch_to_blog(): base_prefix does not change,
	 * but callers that memoise table names tend to memoise blog-specific ones too.
	 *
	 * @since 0.1.0
	 *
	 * @return string Table name.
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'scstudio_events';
	}

	/**
	 * Creates or updates the table via dbDelta.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		/*
		 * dbDelta is whitespace-sensitive to a degree that is not funny: two spaces
		 * after PRIMARY KEY, one space between the field name and its type, and
		 * lowercase "key". Reformatting this block will silently stop it working.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
			type varchar(32) NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			mode varchar(32) DEFAULT NULL,
			status varchar(16) NOT NULL,
			duration_ms int(10) unsigned DEFAULT NULL,
			message text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY blog_created (blog_id, created_at),
			KEY post (post_id),
			KEY user_created (user_id, created_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Reports whether the table exists.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when present.
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, no cache available.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $found === $table;
	}

	/**
	 * Drops the table. Used only by uninstall.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->base_prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
