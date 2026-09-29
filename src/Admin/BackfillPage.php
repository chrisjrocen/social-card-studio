<?php
/**
 * Bulk generate screen.
 *
 * Implements the backfill admin screen of SPEC.md §11 and the "Needs attention"
 * summary of §9.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Generation\Backfill;
use ChrxDigital\SocialCardStudio\Generation\Scheduler;
use ChrxDigital\SocialCardStudio\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an admin fill in an existing archive.
 *
 * @since 0.1.0
 */
final class BackfillPage {

	public const SLUG = 'scstudio-backfill';

	public const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Backfill  $backfill  Backfill engine.
	 * @param Scheduler $scheduler Background queue.
	 * @param Settings  $settings  Plugin settings.
	 */
	public function __construct(
		private readonly Backfill $backfill,
		private readonly Scheduler $scheduler,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the screen and its actions.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_scstudio_backfill', array( $this, 'handle' ) );
	}

	/**
	 * Adds the submenu entry.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			DiagnosticsPage::SLUG,
			__( 'Bulk generate', 'social-card-studio' ),
			__( 'Bulk generate', 'social-card-studio' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handles start, dry run, cancel, resume and clear.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-card-studio' ), 403 );
		}

		check_admin_referer( 'scstudio_backfill' );

		$action  = isset( $_POST['scstudio_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['scstudio_action'] ) ) : '';
		$filters = $this->filters_from_request();
		$notice  = '';

		switch ( $action ) {
			case 'dry_run':
				$counts = $this->backfill->dry_run( $filters );
				set_transient( 'scstudio_dry_run', $counts, 5 * MINUTE_IN_SECONDS );
				$notice = 'dry_run';
				break;

			case 'start':
				$result = $this->backfill->start( $filters );
				$notice = $result['ok'] ? 'started' : 'error';

				if ( ! $result['ok'] ) {
					set_transient( 'scstudio_backfill_error', $result['error'], MINUTE_IN_SECONDS );
				}
				break;

			case 'cancel':
				$this->backfill->cancel();
				$notice = 'cancelled';
				break;

			case 'resume':
				$this->backfill->resume();
				$notice = 'resumed';
				break;

			case 'clear':
				$this->backfill->clear();
				$notice = 'cleared';
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::SLUG,
					'scstudio_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'social-card-studio' ), 403 );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bulk generate social cards', 'social-card-studio' ) . '</h1>';

		$this->render_notice();
		$this->render_attention();
		$this->render_progress();
		$this->render_form();

		echo '</div>';
	}

	/**
	 * Renders whichever notice the last action produced.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect flag; the action itself was nonce-checked.
		$notice = isset( $_GET['scstudio_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['scstudio_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		if ( 'dry_run' === $notice ) {
			$counts = get_transient( 'scstudio_dry_run' );

			if ( is_array( $counts ) ) {
				printf(
					'<div class="notice notice-info"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: total posts, 2: missing, 3: stale, 4: current, 5: switched off. */
							__( 'Dry run: %1$d posts match. %2$d have no card, %3$d need regenerating, %4$d are already up to date, %5$d are switched off. Nothing has been changed.', 'social-card-studio' ),
							(int) $counts['total'],
							(int) $counts['missing'],
							(int) $counts['stale'],
							(int) $counts['current'],
							(int) $counts['disabled']
						)
					)
				);
			}

			return;
		}

		if ( 'error' === $notice ) {
			$error = (string) get_transient( 'scstudio_backfill_error' );

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( '' !== $error ? $error : __( 'That did not work.', 'social-card-studio' ) )
			);

			return;
		}

		$messages = array(
			'started'   => __( 'Backfill started. Cards are being drawn in the background.', 'social-card-studio' ),
			'cancelled' => __( 'Backfill cancelled. You can resume it from where it stopped.', 'social-card-studio' ),
			'resumed'   => __( 'Backfill resumed.', 'social-card-studio' ),
			'cleared'   => __( 'Backfill cleared.', 'social-card-studio' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	/**
	 * Renders the "Needs attention" summary.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_attention(): void {
		$counts = $this->backfill->needs_attention();

		echo '<h2>' . esc_html__( 'Needs attention', 'social-card-studio' ) . '</h2>';

		if ( 0 === $counts['missing'] && 0 === $counts['stale'] && 0 === $counts['failed'] ) {
			echo '<p>' . esc_html__( 'Every published post has an up-to-date card.', 'social-card-studio' ) . '</p>';

			return;
		}

		echo '<ul style="list-style:disc;margin-left:2em">';

		if ( $counts['missing'] > 0 ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: %d: number of posts. */
						_n( '%d published post has no card yet.', '%d published posts have no card yet.', $counts['missing'], 'social-card-studio' ),
						$counts['missing']
					)
				)
			);
		}

		if ( $counts['stale'] > 0 ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: %d: number of posts. */
						_n( '%d card needs regenerating.', '%d cards need regenerating.', $counts['stale'], 'social-card-studio' ),
						$counts['stale']
					)
				)
			);
		}

		if ( $counts['failed'] > 0 ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: %d: number of failures. */
						_n( '%d card failed to draw in the last day.', '%d cards failed to draw in the last day.', $counts['failed'], 'social-card-studio' ),
						$counts['failed']
					)
				)
			);
		}

		echo '</ul>';
	}

	/**
	 * Renders progress for a run in flight.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_progress(): void {
		$run = $this->backfill->run();

		if ( array() === $run ) {
			return;
		}

		$progress = $this->backfill->progress();
		$done     = ! empty( $run['finished'] );

		echo '<h2>' . esc_html__( 'Current run', 'social-card-studio' ) . '</h2>';

		printf(
			'<div style="max-width:520px;background:#f0f0f1;border-radius:3px;height:20px;overflow:hidden"><div style="width:%d%%;background:#2271b1;height:100%%"></div></div>',
			(int) $progress
		);

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: processed, 2: total, 3: generated, 4: already current, 5: failed. */
					__( '%1$d of %2$d processed — %3$d drawn, %4$d already up to date, %5$d failed.', 'social-card-studio' ),
					(int) $run['offset'],
					(int) $run['total'],
					(int) $run['generated'],
					(int) $run['skipped'],
					(int) $run['failed']
				)
			)
		);

		if ( ! empty( $run['cancelled'] ) ) {
			echo '<p>' . esc_html__( 'This run was cancelled. Resuming picks up where it stopped.', 'social-card-studio' ) . '</p>';
		}

		if ( ! empty( $run['messages'] ) ) {
			echo '<details><summary>' . esc_html__( 'Results log', 'social-card-studio' ) . '</summary><ul style="list-style:disc;margin-left:2em">';

			foreach ( (array) $run['messages'] as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}

			echo '</ul></details>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		wp_nonce_field( 'scstudio_backfill' );
		echo '<input type="hidden" name="action" value="scstudio_backfill" />';

		if ( ! $done && empty( $run['cancelled'] ) ) {
			echo '<button type="submit" name="scstudio_action" value="cancel" class="button">' . esc_html__( 'Cancel', 'social-card-studio' ) . '</button> ';
		}

		if ( ! empty( $run['cancelled'] ) && (int) $run['offset'] < (int) $run['total'] ) {
			echo '<button type="submit" name="scstudio_action" value="resume" class="button button-primary">' . esc_html__( 'Resume', 'social-card-studio' ) . '</button> ';
		}

		echo '<button type="submit" name="scstudio_action" value="clear" class="button">' . esc_html__( 'Clear this run', 'social-card-studio' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Renders the filters and buttons.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_form(): void {
		$enabled = (array) $this->settings->get( 'enabled_post_types', array() );
		$status  = $this->scheduler->status();

		echo '<h2>' . esc_html__( 'Start a run', 'social-card-studio' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: batch size, 2: interval in seconds, 3: queue engine. */
					__( 'Cards are drawn %1$d at a time, one batch every %2$d seconds, using %3$s. You can leave this page; the run continues in the background.', 'social-card-studio' ),
					Backfill::BATCH_SIZE,
					Backfill::BATCH_INTERVAL,
					'action-scheduler' === $status['engine'] ? 'Action Scheduler' : 'WP-Cron'
				)
			)
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scstudio_backfill' );
		echo '<input type="hidden" name="action" value="scstudio_backfill" />';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'social-card-studio' ) . '</th><td>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			printf(
				'<label style="margin-right:12px"><input type="checkbox" name="post_types[]" value="%s"%s /> %s</label>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, $enabled, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}

		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Published between', 'social-card-studio' ) . '</th><td>';
		echo '<input type="date" name="after" /> ' . esc_html__( 'and', 'social-card-studio' ) . ' <input type="date" name="before" />';
		echo '<p class="description">' . esc_html__( 'Leave both empty to cover everything.', 'social-card-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Which posts', 'social-card-studio' ) . '</th><td>';

		$scopes = array(
			Backfill::SCOPE_MISSING => __( 'Only posts with no card yet', 'social-card-studio' ),
			Backfill::SCOPE_STALE   => __( 'Posts with no card, plus cards that need regenerating', 'social-card-studio' ),
			Backfill::SCOPE_ALL     => __( 'Every matching post, redrawing cards that are already up to date', 'social-card-studio' ),
		);

		foreach ( $scopes as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="radio" name="scope" value="%s"%s /> %s</label>',
				esc_attr( $value ),
				checked( Backfill::SCOPE_MISSING === $value, true, false ),
				esc_html( $label )
			);
		}

		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<p>';
		echo '<button type="submit" name="scstudio_action" value="dry_run" class="button">' . esc_html__( 'Dry run', 'social-card-studio' ) . '</button> ';
		echo '<button type="submit" name="scstudio_action" value="start" class="button button-primary">' . esc_html__( 'Start generating', 'social-card-studio' ) . '</button>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'A dry run counts what would happen and changes nothing.', 'social-card-studio' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Reads the filters from the request.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Filters.
	 */
	private function filters_from_request(): array {
		// Nonce verified by the caller.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$types = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array();

		return array(
			'post_types' => array_values( array_filter( array_map( 'sanitize_key', $types ) ) ),
			'after'      => isset( $_POST['after'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['after'] ) ) : '',
			'before'     => isset( $_POST['before'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['before'] ) ) : '',
			'scope'      => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope'] ) ) : Backfill::SCOPE_MISSING,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
