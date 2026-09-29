<?php
/**
 * Post list column.
 *
 * Implements the staleness surfacing of SPEC.md §9.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Generation\Scheduler;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Shows each post's card status where the posts already are.
 *
 * A separate screen listing "posts without cards" is a screen nobody opens. The column
 * puts the answer in the list an editor is already looking at, and the filter turns
 * "which of these need attention" into one click.
 *
 * @since 0.1.0
 */
final class PostListColumn {

	public const COLUMN = 'scstudio_card';

	public const BULK_ACTION = 'scstudio_regenerate';

	public const FILTER_VAR = 'scstudio_status';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardRepository $repository Card meta storage.
	 * @param CardDirectory  $directory  Card directory resolver.
	 * @param Scheduler      $scheduler  Background queue.
	 * @param Settings       $settings   Plugin settings.
	 */
	public function __construct(
		private readonly CardRepository $repository,
		private readonly CardDirectory $directory,
		private readonly Scheduler $scheduler,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the column on every enabled post type.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( (array) $this->settings->get( 'enabled_post_types', array() ) as $type ) {
			$type = (string) $type;

			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'make_sortable' ) );
			add_filter( "bulk_actions-edit-{$type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}

		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Adds the column.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string> Columns including ours.
	 */
	public function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Social card', 'social-card-studio' );

		return $columns;
	}

	/**
	 * Renders one cell.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column  Column being rendered.
	 * @param int    $post_id Post ID.
	 *
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$status = $this->repository->status( $post );
		$record = $this->repository->get( $post_id );

		if ( null !== $record && $record->exists( $this->directory ) ) {
			printf(
				'<img src="%s" alt="" width="80" height="42" style="display:block;margin-bottom:4px;border:1px solid #dcdcde" loading="lazy" />',
				esc_url( $record->url( $this->directory ) )
			);
		}

		$badge = $this->badge( $status );

		printf(
			'<span style="display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;background:%s;color:%s">%s</span>',
			esc_attr( $badge['background'] ),
			esc_attr( $badge['color'] ),
			esc_html( $badge['label'] )
		);
	}

	/**
	 * Declares the column sortable.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $columns Sortable columns.
	 *
	 * @return array<string, string> Columns including ours.
	 */
	public function make_sortable( array $columns ): array {
		$columns[ self::COLUMN ] = self::COLUMN;

		return $columns;
	}

	/**
	 * Renders the status filter.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_filter(): void {
		$screen = get_current_screen();

		if ( null === $screen || 'edit' !== $screen->base ) {
			return;
		}

		if ( ! in_array( $screen->post_type, (array) $this->settings->get( 'enabled_post_types', array() ), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a list-table filter, which changes nothing.
		$current = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_VAR ] ) ) : '';

		$options = array(
			''                              => __( 'All social cards', 'social-card-studio' ),
			CardRepository::STATUS_NONE     => __( 'No card yet', 'social-card-studio' ),
			CardRepository::STATUS_STALE    => __( 'Needs regenerating', 'social-card-studio' ),
			CardRepository::STATUS_CURRENT  => __( 'Up to date', 'social-card-studio' ),
			CardRepository::STATUS_DISABLED => __( 'Switched off', 'social-card-studio' ),
		);

		echo '<select name="' . esc_attr( self::FILTER_VAR ) . '">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Applies the status filter.
	 *
	 * Filtering happens in PHP rather than SQL because "stale" is a hash comparison,
	 * not a stored flag — there is no column to query. The list table's own paging
	 * bounds how many posts are examined.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Query $query The query being prepared.
	 *
	 * @return void
	 */
	public function apply_filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a list-table filter, which changes nothing.
		$wanted = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_VAR ] ) ) : '';

		if ( '' === $wanted ) {
			return;
		}

		$ids = array();

		foreach ( (array) get_posts(
			array(
				'post_type'      => (string) $query->get( 'post_type' ),
				'post_status'    => 'any',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded admin filter over one post type.
				'posts_per_page' => 1000,
				'fields'         => 'ids',
			)
		) as $post_id ) {
			$post = get_post( (int) $post_id );

			if ( $post instanceof WP_Post && $wanted === $this->repository->status( $post ) ) {
				$ids[] = (int) $post_id;
			}
		}

		// An empty result must still be empty, not unfiltered.
		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
	}

	/**
	 * Adds the bulk action.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $actions Existing actions.
	 *
	 * @return array<string, string> Actions including ours.
	 */
	public function add_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Regenerate social card', 'social-card-studio' );

		return $actions;
	}

	/**
	 * Queues the selected posts.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $redirect Redirect URL.
	 * @param string     $action   Action being handled.
	 * @param array<int> $post_ids Selected post IDs.
	 *
	 * @return string Redirect URL.
	 */
	public function handle_bulk_action( string $redirect, string $action, array $post_ids ): string {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect;
		}

		$queued = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
				continue;
			}

			// Forced: the whole point of asking is to replace what is there.
			if ( $this->scheduler->enqueue_generate( (int) $post_id, true ) ) {
				++$queued;
			}
		}

		return add_query_arg( 'scstudio_queued', $queued, $redirect );
	}

	/**
	 * Reports how many cards were queued.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect flag, which changes nothing.
		if ( ! isset( $_GET['scstudio_queued'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect flag, which changes nothing.
		$queued = (int) $_GET['scstudio_queued'];

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of cards queued. */
					_n(
						'%d social card queued. It will be drawn in the background shortly.',
						'%d social cards queued. They will be drawn in the background shortly.',
						$queued,
						'social-card-studio'
					),
					$queued
				)
			)
		);
	}

	/**
	 * The badge for a status.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status One of the CardRepository::STATUS_* constants.
	 *
	 * @return array{label: string, background: string, color: string} Badge.
	 */
	private function badge( string $status ): array {
		return match ( $status ) {
			CardRepository::STATUS_CURRENT  => array(
				'label'      => __( 'Up to date', 'social-card-studio' ),
				'background' => '#edfaef',
				'color'      => '#00450c',
			),
			CardRepository::STATUS_STALE    => array(
				'label'      => __( 'Needs regenerating', 'social-card-studio' ),
				'background' => '#fcf9e8',
				'color'      => '#674600',
			),
			CardRepository::STATUS_DISABLED => array(
				'label'      => __( 'Switched off', 'social-card-studio' ),
				'background' => '#f0f0f1',
				'color'      => '#50575e',
			),
			default                         => array(
				'label'      => __( 'No card yet', 'social-card-studio' ),
				'background' => '#f0f6fc',
				'color'      => '#043959',
			),
		};
	}
}
