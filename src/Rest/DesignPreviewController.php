<?php
/**
 * Design screen preview route.
 *
 * Renders the sitewide post card and site card with unsaved Design screen values.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Rest;

use ChrxDigital\SocialCardStudio\Card\RenderProfile;
use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Previews what a Design screen change would look like before it is saved.
 *
 * The overrides are applied through Settings::preview(), so the render, the accent
 * token and the alt text all read the unsaved values through their usual paths and
 * nothing is written. A post's own editor-chosen template is ignored on purpose:
 * this screen shows the design being edited, not the exception to it.
 *
 * @since 0.2.0
 */
final class DesignPreviewController {

	public const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param EditorController $editor   Shared preview renderer.
	 * @param TokenResolver    $tokens   Token substitution.
	 * @param RenderProfile    $profile  Template resolution.
	 * @param Settings         $settings Plugin settings.
	 */
	public function __construct(
		private readonly EditorController $editor,
		private readonly TokenResolver $tokens,
		private readonly RenderProfile $profile,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the route.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares the route.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			EditorController::NAMESPACE,
			'/design-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => static fn (): bool => current_user_can( self::CAPABILITY ),
				'args'                => array(
					'card'              => array(
						'type'    => 'string',
						'enum'    => array( 'post', 'site' ),
						'default' => 'post',
					),
					'post_id'           => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'template'          => array( 'sanitize_callback' => 'sanitize_key' ),
					'default_template'  => array( 'sanitize_callback' => 'sanitize_key' ),
					'per_type_template' => array( 'type' => 'object' ),
					'site_template'     => array( 'sanitize_callback' => 'sanitize_key' ),
					'site_headline'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'accent'            => array( 'sanitize_callback' => 'sanitize_hex_color' ),
					'quality'           => array( 'sanitize_callback' => 'absint' ),
					'alt_text_pattern'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Renders the preview.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error Preview payload.
	 */
	public function preview( WP_REST_Request $request ) {
		$card    = 'site' === $request->get_param( 'card' ) ? 'site' : 'post';
		$post_id = 'site' === $card ? 0 : (int) $request->get_param( 'post_id' );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post_id > 0 && ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) ) {
			return new WP_Error( 'scstudio_no_post', __( 'That post does not exist.', 'social-card-studio' ), array( 'status' => 404 ) );
		}

		$result = $this->settings->preview(
			$this->overrides( $request ),
			function () use ( $request, $card, $post ) {
				$post_id = $post instanceof WP_Post ? $post->ID : 0;

				if ( 'site' === $card ) {
					$template = $this->profile->site_template();
					$tokens   = $this->site_tokens();
				} else {
					// An explicit template is a gallery thumbnail; otherwise show what
					// this post type gets.
					$requested = (string) $request->get_param( 'template' );
					$template  = '' !== $requested
						? $requested
						: $this->profile->sitewide_template_for( $post instanceof WP_Post ? $post->post_type : 'post' );
					$tokens    = $post instanceof WP_Post ? $this->tokens->for_post( $post_id ) : $this->sample_tokens();
				}

				return $this->editor->render_preview( $template, $tokens, $post_id );
			}
		);

		if ( is_array( $result ) ) {
			return rest_ensure_response(
				array(
					'ok'    => false,
					'error' => implode( ' ', (array) ( $result['errors'] ?? array() ) ),
				)
			);
		}

		return $result;
	}

	/**
	 * The unsaved settings carried by the request.
	 *
	 * Only parameters actually sent are applied, so a thumbnail request that leaves
	 * something out falls back to the saved value rather than the default.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed> Partial settings.
	 */
	private function overrides( WP_REST_Request $request ): array {
		$overrides = array();
		$params    = $request->get_params();

		if ( '' !== (string) ( $params['default_template'] ?? '' ) ) {
			$overrides['default_template'] = (string) $params['default_template'];
		}

		if ( isset( $params['per_type_template'] ) && is_array( $params['per_type_template'] ) ) {
			$per_type = array();

			foreach ( $params['per_type_template'] as $type => $template ) {
				$template = sanitize_key( (string) $template );

				if ( '' !== $template ) {
					$per_type[ sanitize_key( (string) $type ) ] = $template;
				}
			}

			$overrides['per_type_template'] = $per_type;
		}

		if ( '' !== (string) ( $params['site_template'] ?? '' ) ) {
			$overrides['homepage_card']['template'] = (string) $params['site_template'];
		}

		if ( isset( $params['site_headline'] ) ) {
			$overrides['homepage_card']['headline'] = (string) $params['site_headline'];
		}

		if ( '' !== (string) ( $params['accent'] ?? '' ) ) {
			$overrides['brand']['accent'] = (string) $params['accent'];
		}

		if ( ! empty( $params['quality'] ) ) {
			$overrides['output']['quality'] = (int) $params['quality'];
		}

		if ( isset( $params['alt_text_pattern'] ) ) {
			$overrides['alt_text_pattern'] = (string) $params['alt_text_pattern'];
		}

		return $overrides;
	}

	/**
	 * Site-level tokens with the site card headline applied, as ensure_home() does.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Token map.
	 */
	private function site_tokens(): array {
		$tokens   = $this->tokens->for_post( 0 );
		$headline = (string) $this->settings->get( 'homepage_card.headline', '' );

		if ( '' !== $headline ) {
			$tokens['share_headline'] = $headline;
			$tokens['headline']       = $headline;
		}

		return $tokens;
	}

	/**
	 * Stand-in post tokens for a site with nothing published yet.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Token map.
	 */
	private function sample_tokens(): array {
		$headline = __( 'Your post title appears here, wrapped across lines when it runs long', 'social-card-studio' );

		return array_merge(
			$this->tokens->for_post( 0 ),
			array(
				'title'           => $headline,
				'share_headline'  => $headline,
				'headline'        => $headline,
				'subhead'         => '',
				'excerpt'         => __( 'The opening lines of the post sit here as a short summary.', 'social-card-studio' ),
				'primary_term'    => __( 'Category', 'social-card-studio' ),
				'date'            => (string) wp_date( (string) get_option( 'date_format' ) ),
				'reading_time'    => sprintf(
					/* translators: %s: number of minutes, already localised. */
					_n( '%s min read', '%s min read', 4, 'social-card-studio' ),
					number_format_i18n( 4 )
				),
				'author_name'     => __( 'Author Name', 'social-card-studio' ),
				'post_type_label' => __( 'Post', 'social-card-studio' ),
			)
		);
	}
}
