<?php
/**
 * Action Scheduler wrapper.
 *
 * Implements the scheduling half of SPEC.md §11 and §3 (Action Scheduler is the only
 * runtime dependency).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

defined( 'ABSPATH' ) || exit;

/**
 * Queues background work.
 *
 * Action Scheduler is bundled but not loaded blindly: it maintains a registry of every
 * copy on the site and only the newest one initialises. Loading ours alongside
 * WooCommerce's is therefore safe and is how the library is designed to be shipped —
 * what is not safe is assuming it is there, so every call degrades to WP-Cron when it
 * is not. A site whose vendor directory was stripped still generates cards; it just
 * does so on a less reliable queue.
 *
 * @since 0.1.0
 */
final class Scheduler {

	public const GROUP = 'social-card-studio';

	public const HOOK_GENERATE = 'scstudio_generate_card';
	public const HOOK_BACKFILL = 'scstudio_backfill_batch';
	public const HOOK_HOME     = 'scstudio_generate_home_card';

	/**
	 * Loads the bundled copy of Action Scheduler, if nothing else has.
	 *
	 * Called from the plugin bootstrap. Action Scheduler must be included before
	 * `plugins_loaded` for its own initialisation to run.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function load(): void {
		$bundled = rtrim( SCSTUDIO_DIR, '/' ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

		if ( is_readable( $bundled ) ) {
			require_once $bundled;
		}
	}

	/**
	 * Whether Action Scheduler is usable.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when its functions are available.
	 */
	public function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Reports the scheduler in use, for diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @return array{engine: string, version: string, pending: int} Status.
	 */
	public function status(): array {
		if ( ! $this->is_available() ) {
			return array(
				'engine'  => 'wp-cron',
				'version' => '',
				'pending' => 0,
			);
		}

		$version = '';

		if ( class_exists( '\ActionScheduler_Versions' ) ) {
			$versions = \ActionScheduler_Versions::instance();

			if ( method_exists( $versions, 'latest_version' ) ) {
				$version = (string) $versions->latest_version();
			}
		}

		return array(
			'engine'  => 'action-scheduler',
			'version' => $version,
			'pending' => $this->pending_count(),
		);
	}

	/**
	 * Queues a card generation as soon as possible.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id Post to generate for.
	 * @param bool $force   Whether to render even when the hash matches.
	 *
	 * @return bool True when something was queued.
	 */
	public function enqueue_generate( int $post_id, bool $force = false ): bool {
		$args = array(
			'post_id' => $post_id,
			'force'   => $force,
		);

		if ( ! $this->is_available() ) {
			return $this->schedule_cron( self::HOOK_GENERATE, $args, 0 );
		}

		// Already queued for this post: queueing again would render it twice.
		if ( as_has_scheduled_action( self::HOOK_GENERATE, $args, self::GROUP ) ) {
			return false;
		}

		return as_enqueue_async_action( self::HOOK_GENERATE, $args, self::GROUP ) > 0;
	}

	/**
	 * Queues the homepage card.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when something was queued.
	 */
	public function enqueue_home(): bool {
		if ( ! $this->is_available() ) {
			return $this->schedule_cron( self::HOOK_HOME, array(), 0 );
		}

		if ( as_has_scheduled_action( self::HOOK_HOME, array(), self::GROUP ) ) {
			return false;
		}

		return as_enqueue_async_action( self::HOOK_HOME, array(), self::GROUP ) > 0;
	}

	/**
	 * Schedules a backfill batch.
	 *
	 * @since 0.1.0
	 *
	 * @param string $run_id Backfill run identifier.
	 * @param int    $offset Batch offset.
	 * @param int    $delay  Seconds from now.
	 *
	 * @return bool True when something was scheduled.
	 */
	public function schedule_batch( string $run_id, int $offset, int $delay = 60 ): bool {
		$args = array(
			'run_id' => $run_id,
			'offset' => $offset,
		);

		if ( ! $this->is_available() ) {
			return $this->schedule_cron( self::HOOK_BACKFILL, $args, $delay );
		}

		if ( as_has_scheduled_action( self::HOOK_BACKFILL, $args, self::GROUP ) ) {
			return false;
		}

		return as_schedule_single_action( time() + $delay, self::HOOK_BACKFILL, $args, self::GROUP ) > 0;
	}

	/**
	 * Cancels every queued backfill batch.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function cancel_backfill(): void {
		if ( $this->is_available() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BACKFILL, array(), self::GROUP );

			return;
		}

		$this->clear_cron( self::HOOK_BACKFILL );
	}

	/**
	 * Cancels everything this plugin has queued.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function cancel_all(): void {
		if ( $this->is_available() && function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( self::HOOK_GENERATE, self::HOOK_BACKFILL, self::HOOK_HOME ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}

			return;
		}

		foreach ( array( self::HOOK_GENERATE, self::HOOK_BACKFILL, self::HOOK_HOME ) as $hook ) {
			$this->clear_cron( $hook );
		}
	}

	/**
	 * How many of our actions are waiting.
	 *
	 * @since 0.1.0
	 *
	 * @return int Pending count.
	 */
	public function pending_count(): int {
		if ( ! $this->is_available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 500,
			),
			'ids'
		);

		return is_array( $actions ) ? count( $actions ) : 0;
	}

	/**
	 * Schedules through WP-Cron.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $hook  Hook name.
	 * @param array<string, mixed> $args  Hook arguments.
	 * @param int                  $delay Seconds from now.
	 *
	 * @return bool True when scheduled.
	 */
	private function schedule_cron( string $hook, array $args, int $delay ): bool {
		// WP-Cron matches events on their argument list, so this is also the
		// duplicate guard.
		if ( wp_next_scheduled( $hook, array( $args ) ) ) {
			return false;
		}

		return false !== wp_schedule_single_event( time() + max( 1, $delay ), $hook, array( $args ) );
	}

	/**
	 * Clears every WP-Cron event for a hook.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Hook name.
	 *
	 * @return void
	 */
	private function clear_cron( string $hook ): void {
		wp_clear_scheduled_hook( $hook );
	}
}
