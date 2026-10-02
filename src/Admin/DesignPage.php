<?php
/**
 * Design screen.
 *
 * The sitewide design half of the Settings screen in SPEC.md §15: default and
 * per-type templates, the site card, brand accent, alt text and output quality,
 * each with a live preview.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Card\RenderProfile;
use ChrxDigital\SocialCardStudio\Generation\Backfill;
use ChrxDigital\SocialCardStudio\Generation\CardGenerator;
use ChrxDigital\SocialCardStudio\Rest\EditorController;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Env;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an admin pick and preview the design every card is drawn with.
 *
 * It owns the top-level menu, so the plugin opens on the thing most admins came to
 * change rather than on a server report.
 *
 * @since 0.2.0
 */
final class DesignPage {

	public const SLUG = 'scstudio-design';

	public const CAPABILITY = 'manage_options';

	public const HANDLE = 'scstudio-design';

	/**
	 * How many recent posts the "Preview with" list offers.
	 */
	private const PREVIEW_POSTS = 20;

	/**
	 * Hook suffix of the screen, for scoping the assets.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Memoised preview_posts() result; the markup and the script both need it.
	 *
	 * @var array<int, array{id: int, title: string, type: string, typeLabel: string, ownTemplate: string}>|null
	 */
	private ?array $posts = null;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param Settings         $settings  Plugin settings.
	 * @param TemplateRegistry $templates Preset templates.
	 * @param RenderProfile    $profile   Template resolution.
	 * @param Backfill         $backfill  Backfill engine, for the stale count.
	 * @param CardGenerator    $generator Card generation, for the site card.
	 * @param Env              $env       Capability detection.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly TemplateRegistry $templates,
		private readonly RenderProfile $profile,
		private readonly Backfill $backfill,
		private readonly CardGenerator $generator,
		private readonly Env $env
	) {}

	/**
	 * Registers the screen, its save action and its assets.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_scstudio_design', array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the top-level menu and its first submenu entry.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Social Card Studio', 'social-card-studio' ),
			__( 'Social Cards', 'social-card-studio' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-format-image',
			81
		);

		// Without this the first submenu entry repeats the top-level label.
		add_submenu_page(
			self::SLUG,
			__( 'Design', 'social-card-studio' ),
			__( 'Design', 'social-card-studio' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues the preview script on this screen only.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix Current admin page.
	 *
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( self::HANDLE, SCSTUDIO_URL . 'assets/admin/design.css', array(), SCSTUDIO_VERSION );

		wp_enqueue_script(
			self::HANDLE,
			SCSTUDIO_URL . 'assets/admin/design.js',
			array( 'jquery', 'wp-color-picker', 'wp-api-fetch', 'wp-i18n' ),
			SCSTUDIO_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'social-card-studio', rtrim( SCSTUDIO_DIR, '/' ) . '/languages' );
		wp_localize_script( self::HANDLE, 'scstudioDesign', $this->script_data() );
	}

	/**
	 * Saves the form.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-card-studio' ), 403 );
		}

		check_admin_referer( 'scstudio_design' );

		$before = $this->settings->fingerprint();
		$stored = $this->settings->all();

		$default = $this->posted_template( 'default_template', (string) $this->settings->get( 'default_template', 'editorial-left' ) );
		$site    = $this->posted_template( 'site_template', $this->profile->site_template() );

		// Saved over the stored settings, not the defaults, so keys this screen does
		// not show are kept as they are.
		$input = array_merge(
			$stored,
			array(
				'default_template'  => $default,
				'per_type_template' => $this->posted_per_type(),
				'homepage_card'     => array(
					'headline' => isset( $_POST['site_headline'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_headline'] ) ) : '',
					'template' => $site,
				),
				'brand'             => array(
					'accent' => isset( $_POST['accent'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['accent'] ) ) : '',
				),
				'output'            => array(
					'quality' => isset( $_POST['quality'] ) ? (int) $_POST['quality'] : 82,
				),
				'alt_text_pattern'  => isset( $_POST['alt_text_pattern'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['alt_text_pattern'] ) ) : '',
			)
		);

		$result = $this->settings->save( $input );
		$notice = 'saved';

		if ( ! $result['ok'] ) {
			set_transient( 'scstudio_design_errors', $result['errors'], MINUTE_IN_SECONDS );
			$notice = 'error';
		} else {
			if ( $this->settings->fingerprint() !== $before ) {
				$notice = 'design_changed';
			}

			// The site card is the last-resort fallback, so it is redrawn now rather
			// than on the next request. It compares its own hash, so this is cheap
			// when nothing it depends on changed.
			$home = $this->generator->ensure_home();

			if ( ! $home['ok'] ) {
				set_transient( 'scstudio_design_home_error', $home['error'], MINUTE_IN_SECONDS );
			}
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
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'social-card-studio' ), 403 );
		}

		echo '<div class="wrap scstudio-design">';
		echo '<h1>' . esc_html__( 'Social card design', 'social-card-studio' ) . '</h1>';

		$this->render_notice();
		$this->render_engine_notice();

		echo '<form id="scstudio-design-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scstudio_design' );
		echo '<input type="hidden" name="action" value="scstudio_design" />';

		$this->render_preview_with();
		$this->render_post_card();
		$this->render_site_card();
		$this->render_brand();

		submit_button( __( 'Save design', 'social-card-studio' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders whichever notice the last save produced.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect flag; the save itself was nonce-checked.
		$notice = isset( $_GET['scstudio_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['scstudio_notice'] ) ) : '';

		if ( 'error' === $notice ) {
			$errors = get_transient( 'scstudio_design_errors' );
			$errors = is_array( $errors ) && array() !== $errors ? $errors : array( __( 'The design could not be saved.', 'social-card-studio' ) );

			echo '<div class="notice notice-error"><ul>';

			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( (string) $error ) . '</li>';
			}

			echo '</ul></div>';

			return;
		}

		if ( 'saved' === $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Design saved.', 'social-card-studio' ) );
		}

		if ( 'design_changed' === $notice ) {
			$this->render_stale_notice();
		}

		$home_error = get_transient( 'scstudio_design_home_error' );

		if ( '' !== $notice && is_string( $home_error ) && '' !== $home_error ) {
			delete_transient( 'scstudio_design_home_error' );

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message. */
						__( 'The site card could not be redrawn: %s', 'social-card-studio' ),
						$home_error
					)
				)
			);
		}
	}

	/**
	 * Renders "N cards are now out of date" with a one-click regenerate.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_stale_notice(): void {
		$counts = $this->backfill->needs_attention();
		$stale  = (int) $counts['stale'];

		echo '<div class="notice notice-success"><p>';

		if ( 0 === $stale ) {
			echo esc_html__( 'Design saved.', 'social-card-studio' ) . '</p></div>';

			return;
		}

		echo esc_html(
			sprintf(
				/* translators: %d: number of cards. */
				_n(
					'Design saved. %d card is now out of date. It will be redrawn the next time it is requested, or you can redraw everything now.',
					'Design saved. %d cards are now out of date. They will be redrawn the next time they are requested, or you can redraw everything now.',
					$stale,
					'social-card-studio'
				),
				$stale
			)
		);

		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 8px">';
		wp_nonce_field( 'scstudio_backfill' );
		echo '<input type="hidden" name="action" value="scstudio_backfill" />';
		echo '<input type="hidden" name="scstudio_action" value="start" />';
		echo '<input type="hidden" name="scope" value="' . esc_attr( Backfill::SCOPE_STALE ) . '" />';

		foreach ( array_keys( $this->enabled_types() ) as $type ) {
			echo '<input type="hidden" name="post_types[]" value="' . esc_attr( $type ) . '" />';
		}

		submit_button( __( 'Regenerate now', 'social-card-studio' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Warns when no renderer is available, since every preview would fail.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_engine_notice(): void {
		if ( 'gd' === $this->env->engine() ) {
			return;
		}

		printf(
			'<div class="notice notice-error inline"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'No usable image library, so cards cannot be drawn or previewed. You can still save a design.', 'social-card-studio' ),
			esc_url( admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG ) ),
			esc_html__( 'See Diagnostics', 'social-card-studio' )
		);
	}

	/**
	 * Renders the "Preview with" post picker.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_preview_with(): void {
		$posts = $this->preview_posts();

		echo '<div class="scstudio-design__preview-with">';
		echo '<label for="scstudio-preview-post"><strong>' . esc_html__( 'Preview with', 'social-card-studio' ) . '</strong></label> ';
		echo '<select id="scstudio-preview-post">';

		foreach ( $posts as $post ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $post['id'],
				esc_html( sprintf( '%s (%s)', $post['title'], $post['typeLabel'] ) )
			);
		}

		echo '<option value="0">' . esc_html__( 'Sample content', 'social-card-studio' ) . '</option>';
		echo '</select>';
		echo '</div>';
	}

	/**
	 * Renders the post card section: preview, gallery and per-type overrides.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_post_card(): void {
		$default = (string) $this->settings->get( 'default_template', 'editorial-left' );

		echo '<h2>' . esc_html__( 'Post card', 'social-card-studio' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The design every enabled post type uses, unless a post type or a single post picks another.', 'social-card-studio' ) . '</p>';

		$this->render_preview_slot( 'post' );

		if ( ! $this->templates->exists( $default ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: template identifier. */
						__( 'The saved design "%s" no longer exists, so cards fall back to the first available one. Pick a design and save.', 'social-card-studio' ),
						$default
					)
				)
			);
		}

		echo '<fieldset class="scstudio-gallery"><legend class="screen-reader-text">' . esc_html__( 'Default design', 'social-card-studio' ) . '</legend>';

		foreach ( $this->template_choices() as $id => $label ) {
			printf(
				'<label class="scstudio-gallery__item"><input type="radio" name="default_template" value="%1$s"%2$s /><span class="scstudio-gallery__thumb" data-template="%1$s"></span><span class="scstudio-gallery__label">%3$s</span></label>',
				esc_attr( $id ),
				checked( $default, $id, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		$this->render_per_type();
	}

	/**
	 * Renders the per-post-type override table.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_per_type(): void {
		$types = $this->enabled_types();

		if ( count( $types ) < 2 ) {
			// One enabled type has nothing to differ from; the gallery is its design.
			foreach ( array_keys( $types ) as $type ) {
				echo '<input type="hidden" name="per_type_template[' . esc_attr( $type ) . ']" value="" />';
			}

			return;
		}

		$per_type = (array) $this->settings->get( 'per_type_template', array() );

		echo '<h3>' . esc_html__( 'Per post type', 'social-card-studio' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $types as $type => $label ) {
			$current = (string) ( $per_type[ $type ] ?? '' );

			echo '<tr><th scope="row"><label for="scstudio-type-' . esc_attr( $type ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<select id="scstudio-type-' . esc_attr( $type ) . '" name="per_type_template[' . esc_attr( $type ) . ']" data-post-type="' . esc_attr( $type ) . '">';
			echo '<option value="">' . esc_html__( 'Use the default design', 'social-card-studio' ) . '</option>';

			foreach ( $this->template_choices() as $id => $template_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $current, $id, false ), esc_html( $template_label ) );
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the site card section.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_site_card(): void {
		$template = $this->profile->site_template();
		$headline = (string) $this->settings->get( 'homepage_card.headline', '' );

		echo '<h2>' . esc_html__( 'Site card', 'social-card-studio' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shown for the homepage, and for any page that has no card of its own.', 'social-card-studio' ) . '</p>';

		$this->render_preview_slot( 'site' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="scstudio-site-template">' . esc_html__( 'Design', 'social-card-studio' ) . '</label></th><td>';
		echo '<select id="scstudio-site-template" name="site_template">';

		foreach ( $this->template_choices() as $id => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $template, $id, false ), esc_html( $label ) );
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="scstudio-site-headline">' . esc_html__( 'Headline', 'social-card-studio' ) . '</label></th><td>';
		printf(
			'<input type="text" id="scstudio-site-headline" name="site_headline" class="regular-text" maxlength="200" value="%s" placeholder="%s" />',
			esc_attr( $headline ),
			esc_attr( (string) get_bloginfo( 'description' ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to use the site tagline.', 'social-card-studio' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Renders accent, alt text and quality.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function render_brand(): void {
		echo '<h2>' . esc_html__( 'Brand and output', 'social-card-studio' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="scstudio-accent">' . esc_html__( 'Accent colour', 'social-card-studio' ) . '</label></th><td>';
		printf(
			'<input type="text" id="scstudio-accent" name="accent" class="scstudio-color" value="%s" data-default-color="#2563EB" />',
			esc_attr( (string) $this->settings->get( 'brand.accent', '#2563EB' ) )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="scstudio-alt">' . esc_html__( 'Alt text', 'social-card-studio' ) . '</label></th><td>';
		printf(
			'<input type="text" id="scstudio-alt" name="alt_text_pattern" class="regular-text" maxlength="200" value="%s" />',
			esc_attr( (string) $this->settings->get( 'alt_text_pattern', '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Tokens: {{headline}}, {{site_name}}, {{author_name}}, {{primary_term}}, {{excerpt}}. Authors can still write their own per post.', 'social-card-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="scstudio-quality">' . esc_html__( 'Image quality', 'social-card-studio' ) . '</label></th><td>';
		printf(
			'<input type="number" id="scstudio-quality" name="quality" min="40" max="100" step="1" class="small-text" value="%d" />',
			(int) $this->settings->get( 'output.quality', 82 )
		);
		echo '<p class="description">' . esc_html__( 'JPEG quality, 40–100. Higher looks sharper and makes a larger file; 82 suits most sites.', 'social-card-studio' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Renders an empty preview frame the script fills in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $card Either 'post' or 'site'.
	 *
	 * @return void
	 */
	private function render_preview_slot( string $card ): void {
		printf(
			'<figure class="scstudio-preview" data-card="%1$s"><div class="scstudio-preview__frame"><img alt="" hidden /><span class="scstudio-preview__status">%2$s</span></div><figcaption><span class="scstudio-preview__badge" hidden></span><span class="scstudio-preview__note" hidden></span><span class="scstudio-preview__alt"></span></figcaption></figure>',
			esc_attr( $card ),
			esc_html__( 'Preview loads with JavaScript enabled.', 'social-card-studio' )
		);
	}

	/**
	 * Reads one posted template, keeping the stored one when the post is invalid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $field    Form field name.
	 * @param string $fallback Value to keep.
	 *
	 * @return string Template identifier.
	 */
	private function posted_template( string $field, string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle().
		$value = isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $field ] ) ) : '';

		return '' !== $value && $this->templates->exists( $value ) ? $value : $fallback;
	}

	/**
	 * Reads the per-type overrides.
	 *
	 * Only enabled types and known templates are kept, and "use the default" is
	 * stored as absence, so disabling a post type drops its override on the next save.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Template identifiers keyed by post type.
	 */
	private function posted_per_type(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle().
		$raw      = isset( $_POST['per_type_template'] ) ? (array) wp_unslash( $_POST['per_type_template'] ) : array();
		$enabled  = $this->enabled_types();
		$per_type = array();

		foreach ( $raw as $type => $template ) {
			$type     = sanitize_key( (string) $type );
			$template = sanitize_key( (string) $template );

			if ( isset( $enabled[ $type ] ) && '' !== $template && $this->templates->exists( $template ) ) {
				$per_type[ $type ] = $template;
			}
		}

		return $per_type;
	}

	/**
	 * Presets in gallery order, labelled.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Labels keyed by template identifier.
	 */
	private function template_choices(): array {
		$choices = array();

		foreach ( array_keys( $this->templates->all() ) as $id ) {
			$choices[ (string) $id ] = EditorAssets::label_for( (string) $id );
		}

		return $choices;
	}

	/**
	 * Enabled post types that still exist, keyed by name.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Plural labels keyed by post type name.
	 */
	private function enabled_types(): array {
		$types = array();

		foreach ( (array) $this->settings->get( 'enabled_post_types', array() ) as $name ) {
			$object = get_post_type_object( (string) $name );

			if ( null !== $object ) {
				$types[ (string) $name ] = (string) $object->labels->name;
			}
		}

		return $types;
	}

	/**
	 * Recent published posts to preview with.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array{id: int, title: string, type: string, typeLabel: string, ownTemplate: string}> Posts, newest first.
	 */
	private function preview_posts(): array {
		if ( null !== $this->posts ) {
			return $this->posts;
		}

		$types = array_keys( $this->enabled_types() );

		if ( array() === $types ) {
			$this->posts = array();

			return $this->posts;
		}

		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => self::PREVIEW_POSTS,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$posts = array();

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$type   = (string) get_post_type( $id );
			$object = get_post_type_object( $type );
			$title  = trim( wp_strip_all_tags( get_the_title( $id ) ) );

			$posts[] = array(
				'id'          => $id,
				'title'       => '' !== $title ? $title : __( '(no title)', 'social-card-studio' ),
				'type'        => $type,
				'typeLabel'   => null !== $object ? (string) $object->labels->singular_name : $type,
				'ownTemplate' => $this->profile->post_template( $id ),
			);
		}

		$this->posts = $posts;

		return $this->posts;
	}

	/**
	 * Data the preview script needs.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> Localised data.
	 */
	private function script_data(): array {
		return array(
			'path'      => '/' . EditorController::NAMESPACE . '/design-preview',
			'canRender' => 'gd' === $this->env->engine(),
			'posts'     => $this->preview_posts(),
			'types'     => $this->enabled_types(),
			'templates' => $this->template_choices(),
		);
	}
}
