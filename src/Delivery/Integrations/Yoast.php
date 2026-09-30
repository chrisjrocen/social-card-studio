<?php
/**
 * Yoast SEO integration.
 *
 * Implements the Yoast half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies cards to Yoast SEO.
 *
 * @since 0.1.0
 */
final class Yoast extends AbstractIntegration {

	/**
	 * Where Yoast stores a hand-picked Open Graph image.
	 */
	private const META_URL = '_yoast_wpseo_opengraph-image';
	private const META_ID  = '_yoast_wpseo_opengraph-image-id';

	/**
	 * Twitter has its own field, and a post may set only that one.
	 */
	private const TWITTER_URL = '_yoast_wpseo_twitter-image';
	private const TWITTER_ID  = '_yoast_wpseo_twitter-image-id';

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string {
		return 'yoast';
	}

	/**
	 * Plugin name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'Yoast SEO';
	}

	/**
	 * Whether Yoast is present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when active.
	 */
	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( '\WPSEO_Options' );
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

		return $id > 0 ? $id : $this->meta_id( $post_id, self::TWITTER_ID );
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

		return '' !== $url ? $url : $this->meta_url( $post_id, self::TWITTER_URL );
	}

	/**
	 * Registers Yoast's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void {
		add_filter( 'wpseo_opengraph_image', array( $this, 'filter_image' ), 20, 1 );
		add_filter( 'wpseo_twitter_image', array( $this, 'filter_image' ), 20, 1 );
		add_filter( 'wpseo_opengraph_image_size', array( $this, 'filter_size' ), 20, 1 );
		add_filter( 'wpseo_opengraph_image_width', array( $this, 'filter_width' ), 20, 1 );
		add_filter( 'wpseo_opengraph_image_height', array( $this, 'filter_height' ), 20, 1 );
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
		$card = $this->card_for_current();

		return null === $card ? $image : $card->url;
	}

	/**
	 * Stops Yoast resizing our card to a registered image size.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $size Incoming size name.
	 *
	 * @return mixed "full" when we supplied the image.
	 */
	public function filter_size( $size ) {
		return null === $this->card_for_current() ? $size : 'full';
	}

	/**
	 * Reports our card's width.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $width Incoming width.
	 *
	 * @return mixed Our width, or the incoming value.
	 */
	public function filter_width( $width ) {
		$card = $this->card_for_current();

		return null === $card || ! $card->has_dimensions() ? $width : $card->width;
	}

	/**
	 * Reports our card's height.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $height Incoming height.
	 *
	 * @return mixed Our height, or the incoming value.
	 */
	public function filter_height( $height ) {
		$card = $this->card_for_current();

		return null === $card || ! $card->has_dimensions() ? $height : $card->height;
	}
}
