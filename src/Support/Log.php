<?php
/**
 * Event log.
 *
 * Implements SPEC.md §5.4 (event table), §12.3 (failure logging) and the audit-trail
 * requirement of §13.8 and §13.9.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads rows in {prefix}scstudio_events.
 *
 * Logging must never be the reason a request fails. Every write is best-effort: if
 * the table is missing because an upgrade half-ran, the plugin carries on without it.
 *
 * @since 0.1.0
 */
final class Log {

	/**
	 * Event types, per SPEC §5.4.
	 */
	public const TYPE_RENDER_OK    = 'render_ok';
	public const TYPE_RENDER_ERROR = 'render_error';
	public const TYPE_DEGRADED     = 'degraded_render';
	public const TYPE_BACKFILL     = 'backfill';

	/**
	 * Status values, per SPEC §5.4.
	 */
	public const STATUS_OK    = 'ok';
	public const STATUS_ERROR = 'error';

	/**
	 * Message length ceiling. Long error messages are truncated rather than refused.
	 */
	private const MAX_MESSAGE = 2000;

	/**
	 * Records an event.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $type   One of the TYPE_* constants.
	 * @param string               $status One of the STATUS_* constants.
	 * @param array<string, mixed> $data   Optional columns: post_id, user_id, mode,
	 *                                     duration_ms, message.
	 *
	 * @return int Inserted row ID, or 0 when the write did not happen.
	 */
	public function record( string $type, string $status, array $data = array() ): int {
		global $wpdb;

		if ( ! EventsTable::exists() ) {
			return 0;
		}

		$message = isset( $data['message'] ) ? (string) $data['message'] : null;

		if ( null !== $message && mb_strlen( $message ) > self::MAX_MESSAGE ) {
			$message = mb_substr( $message, 0, self::MAX_MESSAGE - 1 ) . '…';
		}

		$row = array(
			'blog_id'     => is_multisite() ? get_current_blog_id() : 1,
			'type'        => substr( $type, 0, 32 ),
			'status'      => substr( $status, 0, 16 ),
			'post_id'     => isset( $data['post_id'] ) ? (int) $data['post_id'] : null,
			'user_id'     => isset( $data['user_id'] ) ? (int) $data['user_id'] : null,
			'mode'        => isset( $data['mode'] ) ? substr( (string) $data['mode'], 0, 32 ) : null,
			'duration_ms' => isset( $data['duration_ms'] ) ? (int) $data['duration_ms'] : null,
			'message'     => $message,
			'created_at'  => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no core API.
		$inserted = $wpdb->insert( EventsTable::name(), $row );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Convenience wrapper for failures.
	 *
	 * Post content is never logged, per SPEC §12.3 — only the exception message.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message Failure description.
	 * @param array<string, mixed> $data    Additional columns.
	 *
	 * @return int Inserted row ID, or 0.
	 */
	public function error( string $message, array $data = array() ): int {
		$data['message'] = $message;

		return $this->record( self::TYPE_RENDER_ERROR, self::STATUS_ERROR, $data );
	}

	/**
	 * Returns the most recent events for the current blog.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit Maximum rows, 1-200.
	 *
	 * @return array<int, array<string, mixed>> Rows, newest first.
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;

		if ( ! EventsTable::exists() ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );
		$table = EventsTable::name();
		$blog  = is_multisite() ? get_current_blog_id() : 1;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->base_prefix; every value is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE blog_id = %d ORDER BY id DESC LIMIT %d",
				$blog,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts events of a status since a timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param int    $since  Unix timestamp (UTC).
	 *
	 * @return int Matching row count.
	 */
	public function count_since( string $status, int $since ): int {
		global $wpdb;

		if ( ! EventsTable::exists() ) {
			return 0;
		}

		$table = EventsTable::name();
		$blog  = is_multisite() ? get_current_blog_id() : 1;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->base_prefix; every value is prepared.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND status = %s AND created_at >= %s",
				$blog,
				$status,
				gmdate( 'Y-m-d H:i:s', $since )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Deletes rows older than a cutoff, and trims to the defensive row ceiling.
	 *
	 * @since 0.1.0
	 *
	 * @param int $retain_days Retention window in days. 0 keeps everything.
	 *
	 * @return int Rows deleted.
	 */
	public function prune( int $retain_days ): int {
		global $wpdb;

		if ( ! EventsTable::exists() ) {
			return 0;
		}

		$table   = EventsTable::name();
		$deleted = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->base_prefix; every value is prepared.
		if ( $retain_days > 0 ) {
			$deleted += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s",
					gmdate( 'Y-m-d H:i:s', time() - ( $retain_days * DAY_IN_SECONDS ) )
				)
			);
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $total > EventsTable::MAX_ROWS ) {
			$excess = $total - EventsTable::MAX_ROWS;

			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess ) );

			$this->record(
				self::TYPE_BACKFILL,
				self::STATUS_OK,
				array( 'message' => sprintf( 'Event table exceeded %d rows; trimmed %d oldest.', EventsTable::MAX_ROWS, $excess ) )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $deleted;
	}
}
