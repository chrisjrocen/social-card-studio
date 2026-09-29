<?php
/**
 * Editor asset loading.
 *
 * Implements the build registration of SPEC.md §14 and the i18n rule of §17.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Rest\EditorController;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\ResolvedFont;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the panel and hands it everything it needs to draw a preview.
 *
 * The DOM preview has to reproduce the server's layout, so it is given the same three
 * inputs the renderer works from: the template documents, the resolved tokens, and the
 * fonts. The fonts are registered as @font-face against the same files the server
 * rasterises, because the shared algorithm only agrees when both sides measure the
 * same typeface (SPEC §2.3).
 *
 * @since 0.1.0
 */
final class EditorAssets {

	public const HANDLE = 'scstudio-editor';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TemplateRegistry $templates  Preset templates.
	 * @param TokenResolver    $tokens     Token substitution.
	 * @param FontResolver     $fonts      Font resolution.
	 * @param EditorController $controller Editor REST routes.
	 * @param Settings         $settings   Plugin settings.
	 */
	public function __construct(
		private readonly TemplateRegistry $templates,
		private readonly TokenResolver $tokens,
		private readonly FontResolver $fonts,
		private readonly EditorController $controller,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$post_id = (int) get_the_ID();

		if ( $post_id <= 0 || ! $this->is_enabled_type( $post_id ) ) {
			return;
		}

		$asset_file = rtrim( SCSTUDIO_DIR, '/' ) . '/build/index.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			// The build has not been run. Saying so beats a silently missing panel.
			add_action( 'admin_notices', array( $this, 'render_build_notice' ) );

			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			SCSTUDIO_URL . 'build/index.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? SCSTUDIO_VERSION ),
			true
		);

		wp_set_script_translations( self::HANDLE, 'social-card-studio', rtrim( SCSTUDIO_DIR, '/' ) . '/languages' );

		// wp-scripts names the stylesheet after its entry, and generates the RTL
		// build alongside it; wp_style_add_data tells WordPress to swap them.
		if ( is_readable( rtrim( SCSTUDIO_DIR, '/' ) . '/build/style-index.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				SCSTUDIO_URL . 'build/style-index.css',
				array(),
				(string) ( $asset['version'] ?? SCSTUDIO_VERSION )
			);
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}

		wp_add_inline_style( self::HANDLE, $this->font_faces() );

		wp_localize_script( self::HANDLE, 'scstudioEditor', $this->data( $post_id ) );
	}

	/**
	 * Tells the admin the build is missing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_build_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Social Card Studio: the editor panel has not been built. Run "npm install && npm run build" in the plugin\'s editor directory.', 'social-card-studio' )
		);
	}

	/**
	 * The data the panel needs.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being edited.
	 *
	 * @return array<string, mixed> Localised data.
	 */
	private function data( int $post_id ): array {
		$documents = array();
		$choices   = array();

		foreach ( $this->templates->all() as $id => $document ) {
			$documents[ $id ] = $document->to_array();
			$choices[]        = array(
				'value' => $id,
				'label' => $this->label_for( (string) $id ),
			);
		}

		return array(
			'postId'    => $post_id,
			'state'     => $this->controller->state_for( $post_id ),
			'templates' => $choices,
			'documents' => $documents,
			'tokens'    => $this->tokens->for_post( $post_id ),
			'images'    => $this->image_urls( $post_id ),
			'fonts'     => $this->font_map(),
			'defaults'  => array(
				'template'          => (string) $this->settings->get( 'default_template', 'editorial-left' ),
				'headlineWarnAt'    => 70,
				'previewDebounceMs' => 250,
			),
			'canvas'    => array(
				'width'  => 1200,
				'height' => 630,
				'scale'  => 0.5,
			),
		);
	}

	/**
	 * Resolves the token-referenced images to URLs for the DOM preview.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being edited.
	 *
	 * @return array<string, string> Token name to URL.
	 */
	private function image_urls( int $post_id ): array {
		$urls   = array();
		$tokens = $this->tokens->for_post( $post_id );

		foreach ( array( 'featured_image', 'site_logo' ) as $token ) {
			$value = (string) ( $tokens[ $token ] ?? '' );

			if ( '' === $value || ! ctype_digit( $value ) ) {
				continue;
			}

			$source = wp_get_attachment_image_src( (int) $value, 'large' );

			if ( is_array( $source ) && '' !== (string) ( $source[0] ?? '' ) ) {
				$urls[ $token ] = (string) $source[0];
			}
		}

		$avatar = (string) ( $tokens['author_avatar'] ?? '' );

		if ( '' !== $avatar ) {
			$urls['author_avatar'] = $this->path_to_url( $avatar );
		}

		return $urls;
	}

	/**
	 * The resolved fonts, as CSS families the preview can use.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{family: string, weight: int, url: string}> Fonts by role.
	 */
	private function font_map(): array {
		$map = array();

		foreach ( ResolvedFont::ROLES as $role ) {
			foreach ( array( 400, 600, 700 ) as $weight ) {
				$font = $this->fonts->resolve( $role, $weight );
				$key  = $role . '-' . $weight;

				$map[ $key ] = array(
					'family' => 'scstudio-' . $role . '-' . $weight,
					'weight' => $font->weight,
					'url'    => $this->path_to_url( $font->path ),
				);
			}
		}

		return $map;
	}

	/**
	 * The @font-face rules for the resolved fonts.
	 *
	 * @since 0.1.0
	 *
	 * @return string CSS.
	 */
	private function font_faces(): string {
		$css = '';

		foreach ( $this->font_map() as $font ) {
			if ( '' === $font['url'] ) {
				continue;
			}

			$css .= sprintf(
				"@font-face{font-family:'%s';src:url('%s') format('truetype');font-weight:%d;font-style:normal;font-display:block;}\n",
				esc_attr( $font['family'] ),
				esc_url( $font['url'] ),
				(int) $font['weight']
			);
		}

		return $css;
	}

	/**
	 * Maps a local file path to a URL, when it is inside a directory we serve.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path.
	 *
	 * @return string URL, or empty when the file is not web-accessible.
	 */
	private function path_to_url( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );

		$roots = array(
			rtrim( SCSTUDIO_DIR, '/' )   => rtrim( SCSTUDIO_URL, '/' ),
			(string) $uploads['basedir'] => (string) $uploads['baseurl'],
			get_stylesheet_directory()   => get_stylesheet_directory_uri(),
			get_template_directory()     => get_template_directory_uri(),
		);

		foreach ( $roots as $directory => $url ) {
			if ( '' !== $directory && str_starts_with( $path, $directory ) ) {
				return $url . str_replace( '\\', '/', substr( $path, strlen( $directory ) ) );
			}
		}

		// A font outside every served directory cannot be shown in the browser; the
		// preview falls back to a generic stack rather than showing the wrong one.
		return '';
	}

	/**
	 * A human-readable template name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Template identifier.
	 *
	 * @return string Label.
	 */
	private function label_for( string $id ): string {
		$labels = array(
			'editorial-left'  => __( 'Editorial — photo with headline', 'social-card-studio' ),
			'bold-statement'  => __( 'Bold statement — brand colour', 'social-card-studio' ),
			'split-frame'     => __( 'Split frame — panel and photo', 'social-card-studio' ),
			'minimal-serif'   => __( 'Minimal serif — light and spacious', 'social-card-studio' ),
			'author-feature'  => __( 'Author feature — avatar and byline', 'social-card-studio' ),
			'brand-statement' => __( 'Brand statement — site name', 'social-card-studio' ),
		);

		return $labels[ $id ] ?? $id;
	}

	/**
	 * Whether this post's type has cards enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when enabled.
	 */
	private function is_enabled_type( int $post_id ): bool {
		return in_array(
			(string) get_post_type( $post_id ),
			(array) $this->settings->get( 'enabled_post_types', array() ),
			true
		);
	}
}
