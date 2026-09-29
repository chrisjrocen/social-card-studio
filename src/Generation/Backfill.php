<?php
/**
 * Bulk backfill.
 *
 * Implements the "bulk backfill" row of SPEC.md §11.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Render\MemoryGuard;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Log;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Generates cards for an existing archive, a batch at a time.
 *
 * State lives in one option rather than in the queue, so a run survives the queue
 * being cleared, can be resumed after a cancel, and can be reported on without asking
 * Action Scheduler what it is holding.
 *
 * @since 0.1.0
 */
final class Backfill {

	/**
	 * Option holding the current run.
	 */
	public const RUN_OPTION = 'scstudio_backfill_run';

	/**
	 * Posts per batch, per SPEC §11.
	 */
	public const BATCH_SIZE = 10;

	/**
	 * Seconds between batches, per SPEC §11.
	 */
	public const BATCH_INTERVAL = 60;

	public const SCOPE_MISSING = 'missing';
	public const SCOPE_STALE   = 'stale';
	public const SCOPE_ALL     = 'all';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Scheduler      $scheduler  Background queue.
	 * @param CardGenerator  $generator  Card generation.
	 * @param CardRepository $repository Card meta storage.
	 * @param MemoryGuard    $memory     Memory guard.
	 * @param Settings       $settings   Plugin settings.
	 * @param Log            $log        Event log.
	 */
	public function __construct(
		private readonly Scheduler $scheduler,
		private readonly CardGenerator $generator,
		private readonly CardRepository $repository,
		private readonly MemoryGuard $memory,
		private readonly Settings $settings,
		private readonly Log $log
	) {}

	/**
	 * Registers the batch handler.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::HOOK_BACKFILL, array( $this, 'run_batch' ), 10, 1 );
	}

	/**
	 * Counts what a run would do, without doing it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters Run filters.
	 *
	 * @return array{total: int, missing: int, stale: int, current: int, disabled: int} Counts.
	 */
	public function dry_run( array $filters = array() ): array {
		$ids = $this->find_posts( $filters, self::SCOPE_ALL );

		$counts = array(
			'total'    => 0,
			'missing'  => 0,
			'stale'    => 0,
			'current'  => 0,
			'disabled' => 0,
		);

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			++$counts['total'];

			$status = $this->repository->status( $post );

			if ( CardRepository::STATUS_DISABLED === $status ) {
				++$counts['disabled'];
			} elseif ( CardRepository::STATUS_NONE === $status ) {
				++$counts['missing'];
			} elseif ( CardRepository::STATUS_STALE === $status ) {
				++$counts['stale'];
			} else {
				++$counts['current'];
			}
		}

		return $counts;
	}

	/**
	 * Starts a run.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters Run filters: post_types, after, before, scope.
	 *
	 * @return array{ok: bool, run_id: string, queued: int, error: string} Outcome.
	 */
	public function start( array $filters = array() ): array {
		if ( $this->is_running() ) {
			return array(
				'ok'     => false,
				'run_id' => '',
				'queued' => 0,
				'error'  => __( 'A backfill is already running.', 'social-card-studio' ),
			);
		}

		$scope = (string) ( $filters['scope'] ?? self::SCOPE_MISSING );
		$ids   = $this->find_posts( $filters, $scope );

		if ( array() === $ids ) {
			return array(
				'ok'     => false,
				'run_id' => '',
				'queued' => 0,
				'error'  => __( 'Nothing matched those filters.', 'social-card-studio' ),
			);
		}

		$run = array(
			'id'        => uniqid( 'bf', true ),
			'ids'       => array_values( $ids ),
			'total'     => count( $ids ),
			'done'      => 0,
			'generated' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'offset'    => 0,
			'started'   => time(),
			'finished'  => 0,
			'cancelled' => false,
			'filters'   => $filters,
			'messages'  => array(),
		);

		update_option( self::RUN_OPTION, $run, false );

		// The first batch runs immediately; the rest are paced.
		$this->scheduler->schedule_batch( $run['id'], 0, 1 );

		$this->log->record(
			Log::TYPE_BACKFILL,
			Log::STATUS_OK,
			array( 'message' => sprintf( 'Backfill started: %d posts, scope "%s".', count( $ids ), $scope ) )
		);

		return array(
			'ok'     => true,
			'run_id' => (string) $run['id'],
			'queued' => count( $ids ),
			'error'  => '',
		);
	}

	/**
	 * Runs one batch.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Batch arguments: run_id, offset.
	 *
	 * @return void
	 */
	public function run_batch( $args = array() ): void {
		$args   = is_array( $args ) ? $args : array();
		$run    = $this->run();
		$run_id = (string) ( $args['run_id'] ?? '' );

		if ( array() === $run || (string) $run['id'] !== $run_id || ! empty( $run['cancelled'] ) ) {
			return;
		}

		$offset = (int) ( $args['offset'] ?? 0 );
		$batch  = array_slice( (array) $run['ids'], $offset, self::BATCH_SIZE );

		if ( array() === $batch ) {
			$this->finish( $run );

			return;
		}

		foreach ( $batch as $post_id ) {
			// A batch that starts to run out of memory stops cleanly and reschedules;
			// dying mid-batch would lose the progress for the whole run.
			if ( ! $this->memory->can_afford( 1200, 630 ) ) {
				$run['messages'][] = sprintf( 'Batch at offset %d stopped early: not enough memory.', $offset );
				break;
			}

			$result = $this->generator->ensure( (int) $post_id );

			++$run['done'];

			if ( ! $result['ok'] ) {
				++$run['failed'];

				if ( count( $run['messages'] ) < 50 ) {
					$run['messages'][] = sprintf( '#%d: %s', (int) $post_id, $result['error'] );
				}
			} elseif ( $result['rendered'] ) {
				++$run['generated'];
			} else {
				++$run['skipped'];
			}
		}

		$run['offset'] = $offset + count( $batch );

		update_option( self::RUN_OPTION, $run, false );

		if ( $run['offset'] >= $run['total'] ) {
			$this->finish( $run );

			return;
		}

		$this->scheduler->schedule_batch( $run_id, $run['offset'], self::BATCH_INTERVAL );
	}

	/**
	 * Cancels the current run.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a run was cancelled.
	 */
	public function cancel(): bool {
		$run = $this->run();

		if ( array() === $run || ! empty( $run['finished'] ) ) {
			return false;
		}

		$run['cancelled'] = true;
		$run['finished']  = time();

		update_option( self::RUN_OPTION, $run, false );
		$this->scheduler->cancel_backfill();

		$this->log->record(
			Log::TYPE_BACKFILL,
			Log::STATUS_OK,
			array( 'message' => sprintf( 'Backfill cancelled after %d of %d posts.', (int) $run['done'], (int) $run['total'] ) )
		);

		return true;
	}

	/**
	 * Resumes a cancelled run from where it stopped.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a run was resumed.
	 */
	public function resume(): bool {
		$run = $this->run();

		if ( array() === $run || empty( $run['cancelled'] ) || (int) $run['offset'] >= (int) $run['total'] ) {
			return false;
		}

		$run['cancelled'] = false;
		$run['finished']  = 0;

		update_option( self::RUN_OPTION, $run, false );

		$this->scheduler->schedule_batch( (string) $run['id'], (int) $run['offset'], 1 );

		return true;
	}

	/**
	 * The current run, if any.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Run state, empty when none exists.
	 */
	public function run(): array {
		$run = get_option( self::RUN_OPTION, array() );

		return is_array( $run ) ? $run : array();
	}

	/**
	 * Whether a run is in progress.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when running.
	 */
	public function is_running(): bool {
		$run = $this->run();

		return array() !== $run && empty( $run['finished'] ) && empty( $run['cancelled'] );
	}

	/**
	 * Progress as a percentage.
	 *
	 * @since 0.1.0
	 *
	 * @return int Percentage, 0-100.
	 */
	public function progress(): int {
		$run = $this->run();

		if ( array() === $run || (int) $run['total'] <= 0 ) {
			return 0;
		}

		return (int) min( 100, round( ( (int) $run['offset'] / (int) $run['total'] ) * 100 ) );
	}

	/**
	 * Clears the stored run.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::RUN_OPTION );
		$this->scheduler->cancel_backfill();
	}

	/**
	 * A summary of what needs attention across the site.
	 *
	 * Implements the "Needs attention" panel of SPEC §9.3.
	 *
	 * @since 0.1.0
	 *
	 * @return array{missing: int, stale: int, failed: int} Counts.
	 */
	public function needs_attention(): array {
		$counts = $this->dry_run( array() );

		return array(
			'missing' => (int) $counts['missing'],
			'stale'   => (int) $counts['stale'],
			'failed'  => $this->log->count_since( Log::STATUS_ERROR, time() - DAY_IN_SECONDS ),
		);
	}

	/**
	 * Marks a run finished.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $run Run state.
	 *
	 * @return void
	 */
	private function finish( array $run ): void {
		$run['finished'] = time();

		update_option( self::RUN_OPTION, $run, false );

		$this->log->record(
			Log::TYPE_BACKFILL,
			Log::STATUS_OK,
			array(
				'message' => sprintf(
					'Backfill finished: %d generated, %d already current, %d failed, of %d posts.',
					(int) $run['generated'],
					(int) $run['skipped'],
					(int) $run['failed'],
					(int) $run['total']
				),
			)
		);
	}

	/**
	 * Finds the posts a run should cover.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters Run filters.
	 * @param string               $scope   One of the SCOPE_* constants.
	 *
	 * @return int[] Post IDs.
	 */
	private function find_posts( array $filters, string $scope ): array {
		$types = (array) ( $filters['post_types'] ?? $this->settings->get( 'enabled_post_types', array() ) );
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );

		if ( array() === $types ) {
			return array();
		}

		$args = array(
			'post_type'              => $types,
			'post_status'            => 'publish',
			'posts_per_page'         => (int) apply_filters( 'scstudio_backfill_limit', 5000 ),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A deliberate bulk operation an admin started, bounded by a filterable ceiling.
		);

		$after  = (string) ( $filters['after'] ?? '' );
		$before = (string) ( $filters['before'] ?? '' );

		if ( '' !== $after || '' !== $before ) {
			$args['date_query'] = array(
				array_filter(
					array(
						'after'     => '' !== $after ? $after : null,
						'before'    => '' !== $before ? $before : null,
						'inclusive' => true,
					)
				),
			);
		}

		$ids = get_posts( $args );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

		if ( self::SCOPE_ALL === $scope ) {
			return $ids;
		}

		return array_values(
			array_filter(
				$ids,
				function ( int $post_id ) use ( $scope ): bool {
					$post = get_post( $post_id );

					if ( ! $post instanceof WP_Post ) {
						return false;
					}

					$status = $this->repository->status( $post );

					if ( CardRepository::STATUS_DISABLED === $status ) {
						return false;
					}

					if ( self::SCOPE_MISSING === $scope ) {
						return CardRepository::STATUS_NONE === $status;
					}

					return in_array( $status, array( CardRepository::STATUS_NONE, CardRepository::STATUS_STALE ), true );
				}
			)
		);
	}
}
