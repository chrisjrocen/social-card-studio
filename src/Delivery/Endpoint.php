<?php
/**
 * Lazy generation endpoint.
 *
 * Implements SPEC.md §10.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Generation\CardGenerator;
use ChrxDigital\SocialCardStudio\Generation\Lock;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Support\Hash;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The safety net that covers the whole back catalogue.
 *
 * This is the plugin's only public endpoint. It is read-only, derives everything from
 * public post data, and — per SPEC §10.3 step 4 — never returns a 500 or a broken
 * image: every failure redirects to whatever the fallback chain can verify.
 *
 * @since 0.1.0
 */
final class Endpoint {

	public const NAMESPACE = 'scstudio/v1';
	public const REWRITE   = 'social-card';
	public const QUERY_VAR = 'scstudio_card';

	/**
	 * Per-IP rate limit, per SPEC §10.3 step 5.
	 */
	public const RATE_LIMIT  = 60;
	public const RATE_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Inline render budget, per SPEC §10.3 step 3.
	 */
	public const RENDER_BUDGET = 5;

	/**
	 * A year, since the URL is content-addressed.
	 */
	private const CACHE_MAX_AGE = 31536000;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardRepository $repository Card meta storage.
	 * @param CardGenerator  $generator  Card generation.
	 * @param CardDirectory  $directory  Card directory resolver.
	 * @param ImageResolver  $resolver   Fallback chain.
	 * @param Lock           $lock       Stampede protection.
	 */
	public function __construct(
		private readonly CardRepository $repository,
		private readonly CardGenerator $generator,
		private readonly CardDirectory $directory,
		private readonly ImageResolver $resolver,
		private readonly Lock $lock
	) {}

	/**
	 * Registers the route, the rewrite and the query fallback.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
	}

	/**
	 * Registers the REST route.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			'/card/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_rest' ),
				// Public by design: the response is derived entirely from public post
				// data, is read-only, and is rate limited. SPEC §18.3.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Registers the pretty rewrite.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_rewrite(): void {
		add_rewrite_rule(
			'^' . self::REWRITE . '/(\d+)-([a-f0-9]{8})\.jpg$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]&scstudio_v=$matches[2]',
			'top'
		);
	}

	/**
	 * Declares the query variables.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $vars Registered variables.
	 *
	 * @return array<int, string> Variables including ours.
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'scstudio_v';

		return $vars;
	}

	/**
	 * Serves the card when the rewrite or query fallback matched.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		$post_id = (int) get_query_var( self::QUERY_VAR );

		if ( $post_id <= 0 ) {
			return;
		}

		$this->serve( $post_id );
	}

	/**
	 * Handles the REST route.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|null Response, or null when the redirect was already sent.
	 */
	public function handle_rest( WP_REST_Request $request ): ?WP_REST_Response {
		$this->serve( (int) $request->get_param( 'id' ) );

		return null;
	}

	/**
	 * The URL to advertise for a post with no card yet.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string Absolute URL.
	 */
	public function url_for( int $post_id ): string {
		// A version segment derived from the post's modification time, so that editing
		// a post produces a different endpoint URL and platforms refetch.
		$post    = get_post( $post_id );
		$version = Hash::of( $post instanceof WP_Post ? $post->post_modified_gmt : (string) $post_id, 8 );

		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return home_url( sprintf( '/%s/%d-%s.jpg', self::REWRITE, $post_id, $version ) );
		}

		return add_query_arg(
			array(
				self::QUERY_VAR => $post_id,
				'scstudio_v'    => $version,
			),
			home_url( '/' )
		);
	}

	/**
	 * Serves or generates the card, then exits.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private function serve( int $post_id ): void {
		// Step 1: only publicly viewable posts. Draft, private, trashed and
		// password-protected posts are indistinguishable from "no such post" here.
		if ( ! $this->is_public( $post_id ) ) {
			$this->not_found();
		}

		// Step 5: rate limit before doing any work.
		if ( $this->is_rate_limited() ) {
			$this->too_many_requests();
		}

		// Step 2: an existing file is a redirect, no rendering at all.
		$record = $this->repository->get( $post_id );

		if ( null !== $record && $record->exists( $this->directory ) ) {
			$this->redirect( $record->url( $this->directory ) );
		}

		// Step 3: render inline, under a lock, within the budget.
		if ( $this->lock->is_locked( $post_id ) ) {
			// Another request is already rendering this exact card; sending this
			// scraper to the fallback is far better than queueing a second render.
			$this->redirect_to_fallback( $post_id );
		}

		$budget = (int) apply_filters( 'scstudio_lazy_timeout', self::RENDER_BUDGET );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled in safe-mode hosts; the budget check below still applies.
		}

		$result = $this->generator->ensure( $post_id );

		if ( ! $result['ok'] || null === $result['record'] ) {
			$this->redirect_to_fallback( $post_id );
		}

		$this->redirect( $result['record']->url( $this->directory ) );
	}

	/**
	 * Whether a post may be advertised publicly.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when public.
	 */
	private function is_public( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( '' !== $post->post_password ) {
			return false;
		}

		return is_post_type_viewable( $post->post_type );
	}

	/**
	 * Whether this client has exceeded the rate limit.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when limited.
	 */
	private function is_rate_limited(): bool {
		/**
		 * Filters the endpoint rate limits.
		 *
		 * @since 0.1.0
		 *
		 * @param array{limit: int, window: int} $limits Requests allowed per window.
		 */
		$limits = (array) apply_filters(
			'scstudio_rate_limits',
			array(
				'limit'  => self::RATE_LIMIT,
				'window' => self::RATE_WINDOW,
			)
		);

		$limit = (int) ( $limits['limit'] ?? self::RATE_LIMIT );

		if ( $limit <= 0 ) {
			return false;
		}

		$key   = 'scstudio_rl_' . Hash::of( $this->client_ip(), 16 );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, (int) ( $limits['window'] ?? self::RATE_WINDOW ) );

		return false;
	}

	/**
	 * The requesting client's address.
	 *
	 * Only REMOTE_ADDR is trusted. X-Forwarded-For is client-supplied and would let
	 * anyone bypass the limit by varying a header.
	 *
	 * @since 0.1.0
	 *
	 * @return string Address, or a constant when unavailable.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return '' === $ip ? 'unknown' : $ip;
	}

	/**
	 * Redirects to whatever the fallback chain can verify.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private function redirect_to_fallback( int $post_id ): void {
		$image = $this->resolver->resolve( $post_id );

		if ( null === $image ) {
			// Nothing verifiable at all: 404 rather than a broken image.
			$this->not_found();
		}

		$this->redirect( $image->url, false );
	}

	/**
	 * Sends the redirect and stops.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url       Target URL.
	 * @param bool   $immutable Whether the target is content-addressed.
	 *
	 * @return void
	 */
	private function redirect( string $url, bool $immutable = true ): void {
		if ( $immutable ) {
			header( 'Cache-Control: public, max-age=' . self::CACHE_MAX_AGE . ', immutable' );
		} else {
			// A fallback may change as soon as the card is generated.
			header( 'Cache-Control: public, max-age=300' );
		}

		wp_safe_redirect( $url, 302, 'Social Card Studio' );
		$this->finish();
	}

	/**
	 * Sends a 404 and stops.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function not_found(): void {
		status_header( 404 );
		header( 'Cache-Control: public, max-age=300' );
		$this->finish();
	}

	/**
	 * Sends a 429 and stops.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function too_many_requests(): void {
		status_header( 429 );
		header( 'Retry-After: ' . self::RATE_WINDOW );
		$this->finish();
	}

	/**
	 * Ends the request.
	 *
	 * Wrapped so tests can observe the response instead of the process ending.
	 *
	 * @since 0.1.0
	 *
	 * @throws EndpointFinished Under test only, in place of exit().
	 *
	 * @return void
	 */
	private function finish(): void {
		/**
		 * Fires immediately before the endpoint ends the request.
		 *
		 * Exists so the test suite can assert on status and headers without the
		 * process exiting underneath it.
		 *
		 * @since 0.1.0
		 */
		do_action( 'scstudio_endpoint_finished' );

		if ( defined( 'SCSTUDIO_TESTING' ) && SCSTUDIO_TESTING ) {
			throw new EndpointFinished();
		}

		exit;
	}
}
