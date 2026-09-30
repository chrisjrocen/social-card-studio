<?php
/**
 * Slim SEO integration.
 *
 * Implements the Slim SEO half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies cards to Slim SEO.
 *
 * @since 0.1.0
 */
final class SlimSEO extends AbstractIntegration {

	/**
	 * Slim SEO keeps every post field in one serialised meta value.
	 */
	private const META = 'slim_seo';

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string {
		return 'slim-seo';
	}

	/**
	 * Plugin name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'Slim SEO';
	}

	/**
	 * Whether Slim SEO is present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when active.
	 */
	public function is_active(): bool {
		return defined( 'SLIM_SEO_VER' ) || class_exists( '\SlimSEO\Plugin' );
	}

	/**
	 * Whether a human chose an image on this post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when set.
	 */
	public function has_manual_image( int $post_id ): bool {
		return '' !== $this->manual_image_url( $post_id );
	}

	/**
	 * The chosen URL.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string URL, or empty.
	 */
	public function manual_image_url( int $post_id ): string {
		$data = get_post_meta( $post_id, self::META, true );

		if ( ! is_array( $data ) ) {
			return '';
		}

		return trim( (string) ( $data['facebook_image'] ?? $data['twitter_image'] ?? '' ) );
	}

	/**
	 * Registers Slim SEO's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void {
		add_filter( 'slim_seo_open_graph_tags', array( $this, 'filter_tags' ), 20, 1 );
	}

	/**
	 * Replaces the image tags.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tags Incoming tags.
	 *
	 * @return mixed Filtered tags.
	 */
	public function filter_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		$card = $this->card_for_current();

		if ( null === $card ) {
			return $tags;
		}

		$tags['og:image'] = $card->url;

		if ( $card->has_dimensions() ) {
			$tags['og:image:width']  = $card->width;
			$tags['og:image:height'] = $card->height;
		}

		if ( isset( $tags['twitter:image'] ) ) {
			$tags['twitter:image'] = $card->url;
		}

		return $tags;
	}
}
