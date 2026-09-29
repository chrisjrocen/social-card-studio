<?php
/**
 * All in One SEO integration.
 *
 * Implements the AIOSEO half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies cards to All in One SEO.
 *
 * AIOSEO keeps its post settings in its own table rather than post meta, so the
 * manual-image check goes through its API when that is available and falls back to
 * assuming nothing was set. Assuming *nothing* is the safe direction: it makes
 * override_auto behave like gap_fill for that post rather than overwriting a choice
 * we could not read.
 *
 * @since 0.1.0
 */
final class AIOSEO extends AbstractIntegration {

	/**
	 * Identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string {
		return 'aioseo';
	}

	/**
	 * Plugin name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'All in One SEO';
	}

	/**
	 * Whether AIOSEO is present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when active.
	 */
	public function is_active(): bool {
		return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
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
	 * The chosen URL, read from AIOSEO's own post record.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string URL, or empty.
	 */
	public function manual_image_url( int $post_id ): string {
		if ( ! function_exists( 'aioseo' ) ) {
			return '';
		}

		try {
			$aioseo = aioseo();

			if ( ! isset( $aioseo->models ) || ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
				return '';
			}

			$record = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );

			if ( ! is_object( $record ) ) {
				return '';
			}

			// "custom_image" is the only source that means a human picked a file.
			$source = (string) ( $record->og_image_type ?? '' );

			if ( 'custom_image' !== $source ) {
				return '';
			}

			return trim( (string) ( $record->og_image_custom_url ?? '' ) );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Registers AIOSEO's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void {
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_facebook_tags' ), 20, 1 );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_twitter_tags' ), 20, 1 );
	}

	/**
	 * Replaces the Open Graph image tag.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tags Incoming tags.
	 *
	 * @return mixed Filtered tags.
	 */
	public function filter_facebook_tags( $tags ) {
		return $this->replace( $tags, 'og:image', 'og:image:width', 'og:image:height' );
	}

	/**
	 * Replaces the Twitter image tag.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tags Incoming tags.
	 *
	 * @return mixed Filtered tags.
	 */
	public function filter_twitter_tags( $tags ) {
		return $this->replace( $tags, 'twitter:image', '', '' );
	}

	/**
	 * Swaps our card into an AIOSEO tag array.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $tags   Incoming tags.
	 * @param string $image  Image tag name.
	 * @param string $width  Width tag name, or empty.
	 * @param string $height Height tag name, or empty.
	 *
	 * @return mixed Filtered tags.
	 */
	private function replace( $tags, string $image, string $width, string $height ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		$card = $this->card_for_current( '' !== (string) ( $tags[ $image ] ?? '' ) );

		if ( null === $card ) {
			return $tags;
		}

		$tags[ $image ] = $card->url;

		if ( $card->has_dimensions() ) {
			if ( '' !== $width ) {
				$tags[ $width ] = (string) $card->width;
			}

			if ( '' !== $height ) {
				$tags[ $height ] = (string) $card->height;
			}
		}

		return $tags;
	}
}
