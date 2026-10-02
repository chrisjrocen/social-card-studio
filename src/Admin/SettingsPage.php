<?php
/**
 * Settings screen.
 *
 * Implements the post type and trigger parts of the Settings screen in SPEC.md §15.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an admin choose which post types get cards and how they are generated.
 *
 * @since 0.1.0
 */
final class SettingsPage {

	public const SLUG = 'scstudio-settings';

	public const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Registers the screen and its save action.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_scstudio_settings', array( $this, 'handle' ) );
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
			__( 'Settings', 'social-card-studio' ),
			__( 'Settings', 'social-card-studio' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Saves the form.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-card-studio' ), 403 );
		}

		check_admin_referer( 'scstudio_settings' );

		$types = isset( $_POST['enabled_post_types'] ) ? (array) wp_unslash( $_POST['enabled_post_types'] ) : array();
		$types = array_values( array_intersect( array_map( 'sanitize_key', $types ), array_keys( $this->available_types() ) ) );

		// Saved over the stored settings, not the defaults, so keys this screen does
		// not show are kept as they are.
		$input = array_merge(
			$this->settings->all(),
			array(
				'enabled_post_types' => $types,
				'triggers'           => array(
					'on_publish' => ! empty( $_POST['trigger_on_publish'] ),
					'lazy'       => ! empty( $_POST['trigger_lazy'] ),
				),
			)
		);

		$result = $this->settings->save( $input );

		if ( ! $result['ok'] ) {
			set_transient( 'scstudio_settings_errors', $result['errors'], MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::SLUG,
					'scstudio_notice' => $result['ok'] ? 'saved' : 'error',
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
		echo '<h1>' . esc_html__( 'Social card settings', 'social-card-studio' ) . '</h1>';

		$this->render_notice();
		$this->render_form();

		echo '</div>';
	}

	/**
	 * Renders whichever notice the last save produced.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect flag; the save itself was nonce-checked.
		$notice = isset( $_GET['scstudio_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['scstudio_notice'] ) ) : '';

		if ( 'saved' === $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved.', 'social-card-studio' ) );

			return;
		}

		if ( 'error' === $notice ) {
			$errors = get_transient( 'scstudio_settings_errors' );
			$errors = is_array( $errors ) && array() !== $errors ? $errors : array( __( 'The settings could not be saved.', 'social-card-studio' ) );

			echo '<div class="notice notice-error"><ul>';

			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( (string) $error ) . '</li>';
			}

			echo '</ul></div>';
		}
	}

	/**
	 * Renders the form.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_form(): void {
		$enabled = (array) $this->settings->get( 'enabled_post_types', array() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scstudio_settings' );
		echo '<input type="hidden" name="action" value="scstudio_settings" />';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'social-card-studio' ) . '</th><td>';

		foreach ( $this->available_types() as $name => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="enabled_post_types[]" value="%s"%s /> %s</label>',
				esc_attr( $name ),
				checked( in_array( $name, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">' . esc_html__( 'Only these post types get a social card.', 'social-card-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Generate cards', 'social-card-studio' ) . '</th><td>';

		printf(
			'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="trigger_on_publish" value="1"%s /> %s</label>',
			checked( (bool) $this->settings->get( 'triggers.on_publish', true ), true, false ),
			esc_html__( 'In the background when a post is published or updated', 'social-card-studio' )
		);

		printf(
			'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="trigger_lazy" value="1"%s /> %s</label>',
			checked( (bool) $this->settings->get( 'triggers.lazy', true ), true, false ),
			esc_html__( 'On demand, the first time a social network asks for a post\'s image', 'social-card-studio' )
		);

		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'social-card-studio' ) );

		echo '</form>';
	}

	/**
	 * Public post types a card can be made for, keyed by name.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Labels keyed by post type name.
	 */
	private function available_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			// Attachments are images already; they do not get a card of their own.
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$types[ $type->name ] = (string) $type->labels->name;
		}

		return $types;
	}
}
