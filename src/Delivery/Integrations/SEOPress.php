<?php
/**
 * SEOPress integration.
 *
 * Implements the SEOPress half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies cards to SEOPress.
 *
 * @since 0.1.0
 */
final class SEOPress extends AbstractIntegration {

	private const META_URL         = '_seopress_social_fb_img';
	private const META_ID          = '_seopress_social_fb_img_attachment_id';
	private const TWITTER_META_URL = '_seopress_social_twitter_img';
	private const TWITTER_META_ID  = '_seopress_social_twitter_img_attachment_id';

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string {
		return 'seopress';
	}

	/**
	 * Plugin name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'SEOPress';
	}

	/**
	 * Whether SEOPress is present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when active.
	 */
	public function is_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' );
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
		return $this->manual_image_id( $post_id ) > 0 || '' !== $this->manual_image_url( $post_id );
	}

	/**
	 * The chosen attachment.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Attachment ID, or 0.
	 */
	public function manual_image_id( int $post_id ): int {
		$id = $this->meta_id( $post_id, self::META_ID );

		return $id > 0 ? $id : $this->meta_id( $post_id, self::TWITTER_META_ID );
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
		$url = $this->meta_url( $post_id, self::META_URL );

		return '' !== $url ? $url : $this->meta_url( $post_id, self::TWITTER_META_URL );
	}

	/**
	 * Registers SEOPress's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void {
		add_filter( 'seopress_social_og_thumb', array( $this, 'filter_image' ), 20, 1 );
		add_filter( 'seopress_social_twitter_card_thumb', array( $this, 'filter_image' ), 20, 1 );
	}

	/**
	 * Replaces the image URL.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $image Incoming image URL.
	 *
	 * @return mixed Our card URL, or the incoming value.
	 */
	public function filter_image( $image ) {
		$card = $this->card_for_current( '' !== (string) $image );

		return null === $card ? $image : $card->url;
	}
}
