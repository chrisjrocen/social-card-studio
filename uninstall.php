<?php
/**
 * Uninstall handler.
 *
 * Implements SPEC.md §18.1: options, meta, table and files are removed only when
 * delete_data_on_uninstall is on. It defaults to off, because a plugin that destroys
 * a site's cards when it is briefly removed for debugging is a plugin nobody trusts
 * twice.
 *
 * @package ChrxDigital\SocialCardStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every trace of the plugin from the current site.
 *
 * @return void
 */
function scstudio_uninstall_site() {
	$settings = get_option( 'scstudio_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	global $wpdb;

	delete_option( 'scstudio_settings' );
	delete_option( 'scstudio_db_version' );
	delete_transient( 'scstudio_env' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk meta cleanup; no core API for delete-by-key-prefix.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_scstudio\\_%'" );

	scstudio_uninstall_rmdir( scstudio_uninstall_card_dir() );
}

/**
 * Resolves this site's card directory.
 *
 * @return string Absolute path.
 */
function scstudio_uninstall_card_dir() {
	$uploads = wp_upload_dir( null, false );

	return trailingslashit( $uploads['basedir'] ) . 'social-card-studio';
}

/**
 * Recursively removes a directory.
 *
 * @param string $dir Absolute path.
 *
 * @return void
 */
function scstudio_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = scandir( $dir );

	if ( false === $items ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . '/' . $item;

		if ( is_dir( $path ) ) {
			scstudio_uninstall_rmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is not initialised during uninstall; a non-empty directory is left in place rather than fataling.
	@rmdir( $dir );
}

if ( is_multisite() ) {
	$scstudio_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	/*
	 * The events table is network-wide, so it is dropped once, and only if at least
	 * one site opted in to data deletion. Each site's opt-in is read before that
	 * site is cleaned, because cleaning deletes the setting that records it.
	 */
	$scstudio_drop_table = false;

	foreach ( $scstudio_sites as $scstudio_site_id ) {
		switch_to_blog( (int) $scstudio_site_id );
		$scstudio_settings = get_option( 'scstudio_settings', array() );

		if ( is_array( $scstudio_settings ) && ! empty( $scstudio_settings['delete_data_on_uninstall'] ) ) {
			$scstudio_drop_table = true;
		}

		scstudio_uninstall_site();
		restore_current_blog();
	}

	if ( $scstudio_drop_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
		$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS {$GLOBALS['wpdb']->base_prefix}scstudio_events" );
	}
} else {
	$scstudio_settings = get_option( 'scstudio_settings', array() );

	scstudio_uninstall_site();

	if ( is_array( $scstudio_settings ) && ! empty( $scstudio_settings['delete_data_on_uninstall'] ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
		$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS {$GLOBALS['wpdb']->base_prefix}scstudio_events" );
	}
}
