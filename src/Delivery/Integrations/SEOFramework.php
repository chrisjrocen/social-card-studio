<?php
/**
 * The SEO Framework integration.
 *
 * Implements the TSF half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies cards to The SEO Framework.
 *
 * @since 0.1.0
 */
final class SEOFramework extends AbstractIntegration {

	private const META_URL = '_social_image_url';
	private const META_ID  = '_social_image_id';

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string {
		return 'seo-framework';
	}

	/**
	 * Plugin name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'The SEO Framework';
	}

	/**
	 * Whether TSF is present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when active.
	 */
	public function is_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'tsf' ) || function_exists( 'the_seo_framework' );
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
		return $this->meta_id( $post_id, self::META_ID );
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
		return $this->meta_url( $post_id, self::META_URL );
	}

	/**
	 * Registers TSF's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void {
		add_filter( 'the_seo_framework_og_image_args', array( $this, 'filter_args' ), 20, 1 );
	}

	/**
	 * Replaces the image argument array.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args Incoming arguments.
	 *
	 * @return mixed Filtered arguments.
	 */
	public function filter_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$card = $this->card_for_current();

		if ( null === $card ) {
			return $args;
		}

		$args['image'] = $card->url;

		// TSF derives dimensions from an attachment ID; ours is a plain file, so the
		// ID is cleared to stop it looking one up and finding nothing.
		$args['id'] = 0;

		return $args;
	}
}
