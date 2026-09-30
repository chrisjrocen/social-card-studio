<?php
/**
 * Editor REST routes.
 *
 * Implements the preview and commit endpoints of SPEC.md §11 and §14.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Rest;

use ChrxDigital\SocialCardStudio\Card\AltText;
use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Generation\CardGenerator;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\MemoryGuard;
use ChrxDigital\SocialCardStudio\Render\RenderContext;
use ChrxDigital\SocialCardStudio\Render\RendererFactory;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the editor panel.
 *
 * Two routes, deliberately separate. `preview` renders and returns bytes without
 * touching the database, so an author can try five headlines and change nothing.
 * `commit` writes the overrides and the card immediately — not on the next post save
 * — so a card is never lost to a closed tab (SPEC §14).
 *
 * @since 0.1.0
 */
final class EditorController {

	public const NAMESPACE = 'scstudio/v1';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param RendererFactory  $factory    Renderer selection.
	 * @param TemplateRegistry $templates  Preset templates.
	 * @param TokenResolver    $tokens     Token substitution.
	 * @param FontResolver     $fonts      Font resolution.
	 * @param MemoryGuard      $memory     Memory guard.
	 * @param CardRepository   $repository Card meta storage.
	 * @param CardGenerator    $generator  Card generation.
	 * @param CardDirectory    $directory  Card directory resolver.
	 * @param AltText          $alt        Alt text generation.
	 * @param Settings         $settings   Plugin settings.
	 */
	public function __construct(
		private readonly RendererFactory $factory,
		private readonly TemplateRegistry $templates,
		private readonly TokenResolver $tokens,
		private readonly FontResolver $fonts,
		private readonly MemoryGuard $memory,
		private readonly CardRepository $repository,
		private readonly CardGenerator $generator,
		private readonly CardDirectory $directory,
		private readonly AltText $alt,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares the routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$args = array(
			'post_id'  => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'headline' => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'subhead'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'template' => array( 'sanitize_callback' => 'sanitize_key' ),
			'alt'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/commit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'commit' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array_merge(
					$args,
					array(
						'disabled' => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/state/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'state' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may edit this post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error True when permitted.
	 */
	public function can_edit( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( $post_id <= 0 || ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error( 'scstudio_no_post', __( 'That post does not exist.', 'social-card-studio' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scstudio_forbidden', __( 'You cannot edit this post.', 'social-card-studio' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Renders a card without storing anything.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error Preview payload.
	 */
	public function preview( WP_REST_Request $request ) {
		$post_id  = (int) $request->get_param( 'post_id' );
		$document = $this->templates->get_or_fallback( $this->requested_template( $request, $post_id ) );

		if ( null === $document ) {
			return new WP_Error( 'scstudio_no_template', __( 'No usable template is available.', 'social-card-studio' ), array( 'status' => 500 ) );
		}

		$tokens = $this->tokens_for( $request, $post_id );

		try {
			$result = $this->factory->create()->render(
				$document,
				new RenderContext(
					$tokens,
					$this->fonts,
					$this->memory,
					$post_id,
					(int) $this->settings->get( 'output.quality', 82 )
				)
			);
		} catch ( \Throwable $e ) {
			// A preview failure is information, not a server error: the panel shows
			// the reason and the author carries on writing.
			return rest_ensure_response(
				array(
					'ok'    => false,
					'error' => $e->getMessage(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'error'    => '',
				'image'    => 'data:' . $result->mime . ';base64,' . base64_encode( $result->bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Data URI for an inline preview that is deliberately never written to disk.
				'width'    => $result->width,
				'height'   => $result->height,
				'bytes'    => $result->size(),
				'engine'   => $result->engine,
				'duration' => (int) round( $result->duration_ms ),
				'alt'      => $this->alt->generate( $tokens, (string) $request->get_param( 'alt' ) ),
			)
		);
	}

	/**
	 * Stores the overrides and the card immediately.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error Result payload.
	 */
	public function commit( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		$overrides = $this->repository->overrides( $post_id );

		foreach ( CardRepository::EDITABLE_FIELDS as $field ) {
			$value = $request->get_param( $field );

			if ( null === $value ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value ) {
				unset( $overrides[ $field ] );
			} else {
				$overrides[ $field ] = $value;
			}
		}

		// Written before rendering: if the render then fails, the author's typing is
		// still saved, which is the half they cannot reproduce.
		update_post_meta( $post_id, CardRepository::META_OVERRIDES, $this->repository->sanitize_overrides( $overrides ) );

		$disabled = $request->get_param( 'disabled' );

		if ( null !== $disabled ) {
			if ( $disabled ) {
				update_post_meta( $post_id, CardRepository::META_DISABLED, true );
			} else {
				delete_post_meta( $post_id, CardRepository::META_DISABLED );
			}
		}

		if ( $this->repository->is_disabled( $post_id ) ) {
			return rest_ensure_response( $this->state_for( $post_id ) );
		}

		$result = $this->generator->ensure( $post_id, true );

		$state          = $this->state_for( $post_id );
		$state['ok']    = $result['ok'];
		$state['error'] = $result['error'];

		return rest_ensure_response( $state );
	}

	/**
	 * Returns the current card state for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response State payload.
	 */
	public function state( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( $this->state_for( (int) $request->get_param( 'post_id' ) ) );
	}

	/**
	 * Builds the state payload.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, mixed> State.
	 */
	public function state_for( int $post_id ): array {
		$post   = get_post( $post_id );
		$record = $this->repository->get( $post_id );

		return array(
			'ok'        => true,
			'error'     => '',
			'status'    => $post instanceof WP_Post ? $this->repository->status( $post ) : CardRepository::STATUS_NONE,
			'url'       => null !== $record && $record->exists( $this->directory ) ? $record->url( $this->directory ) : '',
			'alt'       => null !== $record ? $record->alt() : '',
			'width'     => null !== $record ? (int) $record->get( 'w', 0 ) : 0,
			'height'    => null !== $record ? (int) $record->get( 'h', 0 ) : 0,
			'generated' => null !== $record ? (int) $record->get( 'generated_at', 0 ) : 0,
			'template'  => null !== $record ? (string) $record->get( 'template_id', '' ) : '',
			'disabled'  => $this->repository->is_disabled( $post_id ),
			'overrides' => $this->repository->overrides( $post_id ),
		);
	}

	/**
	 * The template the request asked for.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @param int             $post_id Post ID.
	 *
	 * @return string Template identifier.
	 */
	private function requested_template( WP_REST_Request $request, int $post_id ): string {
		$requested = (string) $request->get_param( 'template' );

		if ( '' !== $requested && $this->templates->exists( $requested ) ) {
			return $requested;
		}

		$overrides = $this->repository->overrides( $post_id );

		if ( '' !== (string) ( $overrides['template'] ?? '' ) ) {
			return (string) $overrides['template'];
		}

		return (string) $this->settings->get( 'default_template', 'editorial-left' );
	}

	/**
	 * Tokens for a preview, with the request's unsaved values applied.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @param int             $post_id Post ID.
	 *
	 * @return array<string, string> Token map.
	 */
	private function tokens_for( WP_REST_Request $request, int $post_id ): array {
		$tokens = $this->tokens->for_post( $post_id );

		$headline = trim( (string) $request->get_param( 'headline' ) );
		$subhead  = trim( (string) $request->get_param( 'subhead' ) );

		if ( '' !== $headline ) {
			$tokens['share_headline'] = $headline;
			$tokens['headline']       = $headline;
		}

		if ( '' !== $subhead ) {
			$tokens['subhead'] = $subhead;
		}

		return $tokens;
	}
}
