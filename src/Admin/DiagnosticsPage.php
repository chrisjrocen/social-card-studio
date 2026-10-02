<?php
/**
 * Diagnostics admin screen.
 *
 * Implements SPEC.md §12.4. Checks belonging to later modules (font resolution, SEO
 * detection, AI and the test render) are present as labelled stubs so the screen's
 * shape is stable and each module fills in its own row.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Render\BundledFonts;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\Script;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Support\Env;
use ChrxDigital\SocialCardStudio\Support\EventsTable;
use ChrxDigital\SocialCardStudio\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "why isn't this working" without a support ticket.
 *
 * @since 0.1.0
 */
final class DiagnosticsPage {

	public const SLUG = 'scstudio-diagnostics';

	public const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Env           $env       Capability detection.
	 * @param Settings      $settings  Settings store.
	 * @param CardDirectory $directory Uploads directory.
	 * @param Log           $log       Event log.
	 * @param FontResolver  $fonts     Font resolution chain.
	 */
	public function __construct(
		private readonly Env $env,
		private readonly Settings $settings,
		private readonly CardDirectory $directory,
		private readonly Log $log,
		private readonly FontResolver $fonts
	) {}

	/**
	 * Registers the menu entry and its actions.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_scstudio_flush_env', array( $this, 'handle_flush_env' ) );
	}

	/**
	 * Adds the submenu entry, last under the Design screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			DesignPage::SLUG,
			__( 'Diagnostics', 'social-card-studio' ),
			__( 'Diagnostics', 'social-card-studio' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Clears the cached environment probe.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_flush_env(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-card-studio' ), 403 );
		}

		check_admin_referer( 'scstudio_flush_env' );

		$this->env->flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::SLUG,
					'scstudio_notice' => 'env_flushed',
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

		$env    = $this->env->all();
		$engine = $this->env->engine();
		$guards = $this->directory->guards();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag, no state change.
		$flushed = isset( $_GET['scstudio_notice'] ) && 'env_flushed' === $_GET['scstudio_notice'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Social Card Studio — Diagnostics', 'social-card-studio' ) . '</h1>';

		if ( $flushed ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Environment cache cleared and re-probed.', 'social-card-studio' ) .
				'</p></div>';
		}

		$this->render_engine_summary( $engine );

		$this->open_table( __( 'Image libraries', 'social-card-studio' ) );
		$this->render_gd_rows( (array) $env['gd'] );
		$this->close_table();

		$this->open_table( __( 'Server', 'social-card-studio' ) );
		$this->row( __( 'PHP version', 'social-card-studio' ), (string) $env['php'] );
		$this->row( __( 'WordPress version', 'social-card-studio' ), (string) $env['wp'] );
		$this->row( __( 'PHP memory_limit', 'social-card-studio' ), (string) $env['memory_limit'] );
		$this->row( __( 'WP_MEMORY_LIMIT', 'social-card-studio' ), (string) ( '' !== $env['wp_memory_limit'] ? $env['wp_memory_limit'] : '—' ) );
		$this->row(
			__( 'max_execution_time', 'social-card-studio' ),
			0 === (int) $env['max_execution'] ? __( 'unlimited', 'social-card-studio' ) : (string) $env['max_execution'] . 's'
		);
		$this->row( __( 'upload_max_filesize', 'social-card-studio' ), (string) $env['upload_max'] );
		$this->row(
			__( 'External HTTP requests', 'social-card-studio' ),
			$env['external_http']
				? __( 'allowed', 'social-card-studio' )
				: $this->warn( __( 'blocked by WP_HTTP_BLOCK_EXTERNAL', 'social-card-studio' ) ),
			true
		);
		$this->close_table();

		$this->open_table( __( 'Storage', 'social-card-studio' ) );
		$this->row( __( 'Card directory', 'social-card-studio' ), $this->directory->path() );
		$this->row(
			__( 'Writable', 'social-card-studio' ),
			$this->directory->is_writable()
				? $this->yes( __( 'yes', 'social-card-studio' ) )
				: $this->bad( __( 'no — cards cannot be written', 'social-card-studio' ) ),
			true
		);
		$this->row(
			__( 'Directory guards', 'social-card-studio' ),
			$guards['index'] && $guards['htaccess']
				? $this->yes( __( 'index.php and .htaccess present', 'social-card-studio' ) )
				: $this->warn( __( 'missing — deactivate and reactivate the plugin to recreate them', 'social-card-studio' ) ),
			true
		);
		$this->row(
			__( 'Events table', 'social-card-studio' ),
			EventsTable::exists()
				? $this->yes( EventsTable::name() )
				: $this->bad( __( 'missing', 'social-card-studio' ) ),
			true
		);
		$this->row(
			__( 'Permalinks', 'social-card-studio' ),
			$env['permalink_pretty']
				? $this->yes( __( 'pretty — the card route can be registered', 'social-card-studio' ) )
				: $this->warn( __( 'plain — the lazy card endpoint will use its query-string fallback', 'social-card-studio' ) ),
			true
		);
		$this->row( __( 'Settings fingerprint', 'social-card-studio' ), $this->settings->fingerprint() );
		$this->close_table();

		$this->render_fonts();
		$this->render_stubs();
		$this->render_events();
		$this->render_flush_button();

		echo '</div>';
	}

	/**
	 * Renders the headline engine verdict.
	 *
	 * @since 0.1.0
	 *
	 * @param string $engine Engine identifier.
	 *
	 * @return void
	 */
	private function render_engine_summary( string $engine ): void {
		if ( 'gd' === $engine ) {
			$class   = 'notice-success';
			$message = __( 'Rendering with GD. Every shipped template is designed for it.', 'social-card-studio' );
		} else {
			$class   = 'notice-error';
			$message = __( 'No usable image library. GD with FreeType text support is missing, so no cards can be rendered. Ask your host to enable the GD extension with FreeType.', 'social-card-studio' );
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Renders the GD rows.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $gd GD probe data.
	 *
	 * @return void
	 */
	private function render_gd_rows( array $gd ): void {
		$this->row(
			__( 'GD', 'social-card-studio' ),
			$gd['loaded']
				? sprintf(
					/* translators: %s: GD version string. */
					__( 'present (%s)', 'social-card-studio' ),
					(string) $gd['version']
				)
				: __( 'not installed', 'social-card-studio' )
		);
		$this->row(
			__( 'GD FreeType', 'social-card-studio' ),
			$gd['freetype']
				? $this->yes( __( 'yes', 'social-card-studio' ) )
				: $this->warn( '' !== $gd['reason'] ? (string) $gd['reason'] : __( 'no', 'social-card-studio' ) ),
			true
		);
	}

	/**
	 * Renders the checks that later modules will fill in.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_fonts(): void {
		$this->open_table( __( 'Fonts', 'social-card-studio' ) );

		$bundle = BundledFonts::verify();

		$this->row(
			__( 'Bundled families', 'social-card-studio' ),
			$bundle['ok']
				? $this->yes( __( 'Inter, Source Serif 4 and JetBrains Mono are all present', 'social-card-studio' ) )
				: $this->bad(
					sprintf(
						/* translators: %s: comma-separated list of missing filenames. */
						__( 'incomplete build — missing %s', 'social-card-studio' ),
						implode( ', ', $bundle['missing'] )
					)
				),
			true
		);

		$this->row(
			__( 'Complex-script shaping', 'social-card-studio' ),
			Script::can_shape()
				? $this->yes( __( 'available', 'social-card-studio' ) )
				: $this->warn( __( 'unavailable — a post titled in Arabic, Devanagari or Thai falls back to the site default card rather than rendering broken text', 'social-card-studio' ) ),
			true
		);

		foreach ( $this->fonts->trace() as $role => $font ) {
			$summary = sprintf(
				/* translators: 1: font family, 2: weight, 3: chain step. */
				__( '%1$s %2$d — resolved at the "%3$s" step', 'social-card-studio' ),
				'' !== $font['family'] ? $font['family'] : __( 'none', 'social-card-studio' ),
				$font['weight'],
				$font['step']
			);

			$this->row(
				sprintf(
					/* translators: %s: font role, one of heading, body or mono. */
					__( 'Role: %s', 'social-card-studio' ),
					$role
				),
				$font['usable'] ? $this->yes( $summary ) : $this->bad( $summary ),
				true
			);

			$this->row( '', $font['path'] );

			foreach ( $font['skipped'] as $reason ) {
				$this->row( '', $this->warn( __( 'skipped — ', 'social-card-studio' ) . $reason ), true );
			}
		}

		$this->row( __( 'Font fingerprint', 'social-card-studio' ), $this->fonts->fingerprint() );

		$this->close_table();
	}

	/**
	 * Renders the checks that later modules will fill in.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_stubs(): void {
		$this->open_table( __( 'Not yet implemented', 'social-card-studio' ) );

		$stubs = array(
			__( 'Detected SEO plugin', 'social-card-studio' ) => 'M6',
			__( 'Action Scheduler status', 'social-card-studio' ) => 'M7',
		);

		foreach ( $stubs as $label => $module ) {
			$this->row(
				$label,
				'<em>' . esc_html(
					sprintf(
						/* translators: %s: build module identifier, e.g. M3. */
						__( 'arrives in module %s', 'social-card-studio' ),
						$module
					)
				) . '</em>',
				true
			);
		}

		$this->close_table();
	}

	/**
	 * Renders the recent events table.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_events(): void {
		$events = $this->log->recent( 20 );

		echo '<h2>' . esc_html__( 'Recent events', 'social-card-studio' ) . '</h2>';

		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No events recorded yet.', 'social-card-studio' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When (UTC)', 'social-card-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'social-card-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'social-card-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Post', 'social-card-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'social-card-studio' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $event['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $event['type'] ) . '</td>';
			echo '<td>' . esc_html( (string) $event['status'] ) . '</td>';
			echo '<td>' . esc_html( $event['post_id'] ? (string) $event['post_id'] : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $event['message'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the re-probe button.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_flush_button(): void {
		echo '<h2>' . esc_html__( 'Re-probe the server', 'social-card-studio' ) . '</h2>';
		echo '<p>' . esc_html__( 'Capability detection is cached for a day. Clear it after a host changes your PHP version or installs an image library.', 'social-card-studio' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scstudio_flush_env' );
		echo '<input type="hidden" name="action" value="scstudio_flush_env" />';
		submit_button( __( 'Clear cache and re-probe', 'social-card-studio' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Opens a section table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $title Section heading.
	 *
	 * @return void
	 */
	private function open_table( string $title ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
	}

	/**
	 * Closes a section table.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function close_table(): void {
		echo '</tbody></table>';
	}

	/**
	 * Renders one label/value row.
	 *
	 * @since 0.1.0
	 *
	 * @param string $label    Row label.
	 * @param string $value    Row value.
	 * @param bool   $is_html  Whether $value is pre-escaped markup from yes()/warn()/bad().
	 *
	 * @return void
	 */
	private function row( string $label, string $value, bool $is_html = false ): void {
		echo '<tr><th scope="row" style="width:22em">' . esc_html( $label ) . '</th><td>';

		if ( $is_html ) {
			echo wp_kses(
				$value,
				array(
					'span' => array( 'style' => array() ),
					'em'   => array(),
				)
			);
		} else {
			echo esc_html( $value );
		}

		echo '</td></tr>';
	}

	/**
	 * Formats a positive value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Message.
	 *
	 * @return string Escaped markup.
	 */
	private function yes( string $text ): string {
		return '<span style="color:#008a20">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Formats a cautionary value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Message.
	 *
	 * @return string Escaped markup.
	 */
	private function warn( string $text ): string {
		return '<span style="color:#996800">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Formats a blocking value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Message.
	 *
	 * @return string Escaped markup.
	 */
	private function bad( string $text ): string {
		return '<span style="color:#d63638">' . esc_html( $text ) . '</span>';
	}
}
