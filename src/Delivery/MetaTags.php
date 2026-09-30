<?php
/**
 * Own meta tag output.
 *
 * Implements SPEC.md §10.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Str;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Emits og: and twitter: tags when no SEO plugin is doing it.
 *
 * @since 0.1.0
 */
final class MetaTags {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param ImageResolver  $resolver   Fallback chain.
	 * @param SeoDetector    $seo        SEO plugin detection.
	 * @param Priority       $priority   Priority modes.
	 * @param CardRepository $repository Card meta storage.
	 * @param Settings       $settings   Plugin settings.
	 * @param Endpoint       $endpoint   Lazy endpoint, for ungenerated posts.
	 */
	public function __construct(
		private readonly ImageResolver $resolver,
		private readonly SeoDetector $seo,
		private readonly Priority $priority,
		private readonly CardRepository $repository,
		private readonly Settings $settings,
		private readonly Endpoint $endpoint
	) {}

	/**
	 * Registers the output hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 5 );
	}

	/**
	 * Emits the tags.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->should_output() ) {
			return;
		}

		$post_id = $this->current_post_id();
		$image   = $this->image_for( $post_id );

		$title       = $this->title( $post_id );
		$description = $this->description( $post_id );
		$url         = $this->url( $post_id );

		echo "\n<!-- Social Card Studio -->\n";

		$this->tag( 'og:type', $post_id > 0 && is_singular() ? 'article' : 'website' );
		$this->tag( 'og:title', $title );

		if ( '' !== $description ) {
			$this->tag( 'og:description', $description );
		}

		$this->tag( 'og:url', $url, true );
		$this->tag( 'og:site_name', (string) get_bloginfo( 'name' ) );
		$this->tag( 'og:locale', str_replace( '-', '_', (string) get_locale() ) );

		if ( null !== $image ) {
			$this->tag( 'og:image', $image->url, true );

			if ( str_starts_with( $image->url, 'https://' ) ) {
				$this->tag( 'og:image:secure_url', $image->url, true );
			}

			if ( $image->has_dimensions() ) {
				$this->tag( 'og:image:width', (string) $image->width );
				$this->tag( 'og:image:height', (string) $image->height );
			}

			$this->tag( 'og:image:type', $image->mime );

			if ( '' !== $image->alt ) {
				$this->tag( 'og:image:alt', $image->alt );
			}
		}

		$this->name_tag( 'twitter:card', null !== $image ? 'summary_large_image' : 'summary' );
		$this->name_tag( 'twitter:title', $title );

		if ( '' !== $description ) {
			$this->name_tag( 'twitter:description', $description );
		}

		if ( null !== $image ) {
			$this->name_tag( 'twitter:image', $image->url, true );

			if ( '' !== $image->alt ) {
				$this->name_tag( 'twitter:image:alt', $image->alt );
			}
		}

		echo "<!-- /Social Card Studio -->\n";
	}

	/**
	 * Whether tags should be emitted at all.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when we should output.
	 */
	private function should_output(): bool {
		if ( ! is_singular() && ! is_front_page() ) {
			return false;
		}

		// An SEO plugin is already emitting these; ours would duplicate them.
		if ( $this->seo->has_active() ) {
			return false;
		}

		/**
		 * Filters whether the plugin emits its own meta tags.
		 *
		 * Documented in SPEC §10.1 so a theme that already outputs Open Graph tags can
		 * suppress ours rather than fighting them.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $output Whether to emit.
		 */
		return (bool) apply_filters( 'scstudio_output_own_tags', true );
	}

	/**
	 * The image to advertise.
	 *
	 * Per the subtlety in SPEC §10.3, a post that already has a card advertises the
	 * static file. The endpoint URL is only used for a post with no card yet, so the
	 * endpoint carries almost no production traffic and scrapers get a direct image
	 * URL wherever possible.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being rendered.
	 *
	 * @return ResolvedImage|null Image, or null to emit none.
	 */
	private function image_for( int $post_id ): ?ResolvedImage {
		$resolved = $this->resolver->resolve( $post_id );

		/*
		 * Only the post's OWN card short-circuits. The site default card is also a
		 * card, but advertising it for a post that has never been generated would mean
		 * the lazy endpoint is never reached and the back catalogue never fills in —
		 * every un-generated post would share one identical image forever.
		 */
		if ( null !== $resolved
			&& in_array( $resolved->step, array( ResolvedImage::STEP_FRESH, ResolvedImage::STEP_STALE ), true )
		) {
			return $resolved;
		}

		if ( $this->should_offer_endpoint( $post_id ) ) {
			return new ResolvedImage(
				$this->endpoint->url_for( $post_id ),
				1200,
				630,
				'image/jpeg',
				'',
				ResolvedImage::STEP_ENDPOINT
			);
		}

		return $resolved;
	}

	/**
	 * Whether the lazy endpoint should be advertised for this post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being rendered.
	 *
	 * @return bool True when the endpoint URL should be emitted.
	 */
	private function should_offer_endpoint( int $post_id ): bool {
		if ( $post_id <= 0 || ! (bool) $this->settings->get( 'triggers.lazy', true ) ) {
			return false;
		}

		if ( $this->repository->is_disabled( $post_id ) || null !== $this->repository->get( $post_id ) ) {
			return false;
		}

		if ( ! $this->priority->should_supply( $post_id, false ) ) {
			return false;
		}

		$types = (array) $this->settings->get( 'enabled_post_types', array() );

		return in_array( (string) get_post_type( $post_id ), $types, true );
	}

	/**
	 * The post being rendered.
	 *
	 * @since 0.1.0
	 *
	 * @return int Post ID, 0 for a non-page front page.
	 */
	private function current_post_id(): int {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		return is_front_page() ? (int) get_option( 'page_on_front' ) : 0;
	}

	/**
	 * The title to advertise.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being rendered.
	 *
	 * @return string Title.
	 */
	private function title( int $post_id ): string {
		if ( $post_id > 0 && is_singular() ) {
			return Str::normalize( (string) get_the_title( $post_id ) );
		}

		return Str::normalize( (string) get_bloginfo( 'name' ) );
	}

	/**
	 * The description to advertise.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being rendered.
	 *
	 * @return string Description.
	 */
	private function description( int $post_id ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof WP_Post && is_singular() ) {
			$excerpt = Str::normalize( (string) get_the_excerpt( $post ) );

			if ( '' !== $excerpt ) {
				return Str::truncate_words( $excerpt, 200 );
			}
		}

		return Str::normalize( (string) get_bloginfo( 'description' ) );
	}

	/**
	 * The canonical URL to advertise.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being rendered.
	 *
	 * @return string URL.
	 */
	private function url( int $post_id ): string {
		if ( $post_id > 0 && is_singular() ) {
			return (string) get_permalink( $post_id );
		}

		return (string) home_url( '/' );
	}

	/**
	 * Emits a property-based meta tag.
	 *
	 * @since 0.1.0
	 *
	 * @param string $property Property name.
	 * @param string $content  Tag content.
	 * @param bool   $is_url   Whether the content is a URL.
	 *
	 * @return void
	 */
	private function tag( string $property, string $content, bool $is_url = false ): void {
		if ( '' === $content ) {
			return;
		}

		printf(
			'<meta property="%s" content="%s" />' . "\n",
			esc_attr( $property ),
			$is_url ? esc_url( $content ) : esc_attr( $content )
		);
	}

	/**
	 * Emits a name-based meta tag.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Tag name.
	 * @param string $content Tag content.
	 * @param bool   $is_url  Whether the content is a URL.
	 *
	 * @return void
	 */
	private function name_tag( string $name, string $content, bool $is_url = false ): void {
		if ( '' === $content ) {
			return;
		}

		printf(
			'<meta name="%s" content="%s" />' . "\n",
			esc_attr( $name ),
			$is_url ? esc_url( $content ) : esc_attr( $content )
		);
	}
}
