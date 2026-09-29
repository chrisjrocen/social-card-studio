<?php
/**
 * Shared SEO integration behaviour.
 *
 * Implements the common half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery\Integrations;

use ChrxDigital\SocialCardStudio\Delivery\ImageResolver;
use ChrxDigital\SocialCardStudio\Delivery\Priority;
use ChrxDigital\SocialCardStudio\Delivery\ResolvedImage;
use ChrxDigital\SocialCardStudio\Delivery\SeoIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the decision logic every adapter needs.
 *
 * Subclasses answer three plugin-specific questions — am I active, where does this
 * plugin keep a manually chosen image, and which filters does it expose — and inherit
 * everything else. Duplicating the priority logic six times is how five of them end
 * up subtly disagreeing.
 *
 * @since 0.1.0
 */
abstract class AbstractIntegration implements SeoIntegration {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param ImageResolver $resolver Fallback chain.
	 * @param Priority      $priority Priority modes.
	 */
	public function __construct(
		protected readonly ImageResolver $resolver,
		protected readonly Priority $priority
	) {}

	/**
	 * The card to supply for the post being rendered, if any.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $incoming_has_image Whether the host plugin already resolved an image.
	 *
	 * @return ResolvedImage|null Our card, or null to leave the host plugin alone.
	 */
	protected function card_for_current( bool $incoming_has_image ): ?ResolvedImage {
		$post_id = $this->current_post_id();

		if ( $post_id <= 0 ) {
			return null;
		}

		if ( ! $this->priority->should_supply( $post_id, $incoming_has_image, $this->has_manual_image( $post_id ) ) ) {
			return null;
		}

		// The SEO plugin's own manual image is excluded from our chain here: if it
		// were allowed, "replace automatic images" could hand the plugin back the very
		// image it already had, which looks like the setting doing nothing.
		$resolved = $this->resolver->resolve( $post_id, false );

		return null !== $resolved && $resolved->is_card() ? $resolved : null;
	}

	/**
	 * The post currently being rendered.
	 *
	 * @since 0.1.0
	 *
	 * @return int Post ID, or 0 when not on a singular view.
	 */
	protected function current_post_id(): int {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		if ( is_front_page() ) {
			return (int) get_option( 'page_on_front' );
		}

		return 0;
	}

	/**
	 * Reads an attachment ID from a post meta key.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 *
	 * @return int Attachment ID, or 0.
	 */
	protected function meta_id( int $post_id, string $key ): int {
		return (int) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Reads a URL from a post meta key.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 *
	 * @return string URL, or empty.
	 */
	protected function meta_url( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Default: no manual image is stored as a bare URL.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string URL, or empty.
	 */
	public function manual_image_url( int $post_id ): string {
		return '';
	}

	/**
	 * Default: no manual image is stored as an attachment ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Attachment ID, or 0.
	 */
	public function manual_image_id( int $post_id ): int {
		return 0;
	}
}
