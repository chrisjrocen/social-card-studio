<?php
/**
 * Lifecycle hooks.
 *
 * Implements SPEC.md §18.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Storage;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Support\EventsTable;
use ChrxDigital\SocialCardStudio\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps stored cards consistent with the site around them.
 *
 * The rule running through all of it: destructive cleanup only happens on permanent
 * deletion. Trashing a post keeps its card, because a post in the trash may come
 * back and the card is expensive to make.
 *
 * @since 0.1.0
 */
final class Lifecycle {

	/**
	 * Daily maintenance hook.
	 */
	public const CRON_HOOK = 'scstudio_daily_cleanup';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardStore          $store      Card file storage.
	 * @param CardRepository     $repository Card meta storage.
	 * @param MediaLibraryBridge $bridge     Attachment bridge.
	 * @param Log                $log        Event log.
	 */
	public function __construct(
		private readonly CardStore $store,
		private readonly CardRepository $repository,
		private readonly MediaLibraryBridge $bridge,
		private readonly Log $log
	) {}

	/**
	 * Registers the hooks in SPEC §18.1.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Scheduling on activation alone is not enough: a site that was already
		 * running before this task existed, or whose cron array has been cleared,
		 * would silently never purge retired files. The check is one read of an
		 * already-loaded option.
		 */
		self::schedule();

		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleted' ) );
		add_action( 'switch_theme', array( $this, 'on_theme_switched' ) );
		add_action( 'update_option_scstudio_settings', array( $this, 'on_settings_saved' ), 10, 0 );
		add_action( self::CRON_HOOK, array( $this, 'run_daily_cleanup' ) );
	}

	/**
	 * Schedules the daily maintenance task.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Cancels the daily maintenance task.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Removes everything belonging to a permanently deleted post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being deleted.
	 *
	 * @return void
	 */
	public function on_post_deleted( int $post_id ): void {
		$record = $this->repository->get( $post_id );

		if ( null !== $record ) {
			$attachment = (int) $record->get( 'attachment_id', 0 );

			if ( $attachment > 0 ) {
				$this->bridge->detach( $attachment );
			}
		}

		$this->store->delete_for_post( $post_id );
		$this->repository->delete( $post_id );
		$this->delete_events( $post_id );
	}

	/**
	 * Marks cards stale when an image they used is deleted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment being deleted.
	 *
	 * @return void
	 */
	public function on_attachment_deleted( int $attachment_id ): void {
		// One of our own card attachments carries no card of its own.
		if ( $this->bridge->is_card_attachment( $attachment_id ) ) {
			return;
		}

		$affected = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A bounded one-off cleanup on an explicit deletion, not a page-load query.
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_thumbnail_id',
				'meta_value'     => (string) $attachment_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A one-off cleanup on an explicit user action, not a page-load query.
			)
		);

		foreach ( (array) $affected as $post_id ) {
			$this->repository->mark_stale( (int) $post_id );
		}

		$logo = (int) get_theme_mod( 'custom_logo' );

		if ( $logo === $attachment_id ) {
			// The site logo appears on most presets; every card is now out of date.
			$this->mark_all_stale();
		}
	}

	/**
	 * Handles a theme switch.
	 *
	 * The font fingerprint feeds the input hash (SPEC §9.1), so affected cards go
	 * stale on their own. All that is needed here is to drop the cached probe so the
	 * new theme's fonts are actually looked at.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_theme_switched(): void {
		delete_transient( 'scstudio_env' );

		$this->log->record(
			Log::TYPE_BACKFILL,
			Log::STATUS_OK,
			array( 'message' => 'Theme switched; card hashes will be recomputed against the new fonts.' )
		);
	}

	/**
	 * Handles a settings save.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_settings_saved(): void {
		// The settings fingerprint is derived, not stored, so nothing to recompute
		// here; the site default card is re-rendered by the generation module.
		do_action( 'scstudio_settings_changed' );
	}

	/**
	 * Daily maintenance.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run_daily_cleanup(): void {
		$purged = $this->store->purge_retired();
		$this->prune_cache();

		$retention = (int) apply_filters( 'scstudio_event_retention_days', 90 );
		$trimmed   = $this->log->prune( $retention );

		if ( $purged > 0 || $trimmed > 0 ) {
			$this->log->record(
				Log::TYPE_BACKFILL,
				Log::STATUS_OK,
				array(
					'message' => sprintf(
						'Daily cleanup: %d retired card files deleted, %d event rows trimmed.',
						$purged,
						$trimmed
					),
				)
			);
		}
	}

	/**
	 * Marks every stored card stale.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function mark_all_stale(): void {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A bounded one-off cleanup after the site logo is deleted.
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => CardRepository::META_CARD,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A one-off cleanup on an explicit user action.
			)
		);

		foreach ( (array) $posts as $post_id ) {
			$this->repository->mark_stale( (int) $post_id );
		}
	}

	/**
	 * Deletes event rows referencing a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private function delete_events( int $post_id ): void {
		global $wpdb;

		if ( ! EventsTable::exists() ) {
			return;
		}

		$table = EventsTable::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->base_prefix; the value is prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) );
	}

	/**
	 * Removes stale render artefacts from the cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function prune_cache(): void {
		$directory = new CardDirectory();
		$cache     = $directory->path( 'cache' );

		if ( ! is_dir( $cache ) ) {
			return;
		}

		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		$files  = glob( $cache . '/*' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_file( $file ) && 'index.php' !== basename( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}
}
