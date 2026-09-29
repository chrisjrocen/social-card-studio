<?php
/**
 * Input hashing.
 *
 * Implements SPEC.md §9.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Hash;
use ChrxDigital\SocialCardStudio\Support\Str;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the hash that decides whether a card is current.
 *
 * The whole staleness model rests on this being exactly as sensitive as it should
 * be. Too sensitive and every unrelated settings save regenerates thousands of cards;
 * not sensitive enough and an edited headline ships the old image forever.
 *
 * @since 0.1.0
 */
final class InputHasher {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings           $settings Plugin settings.
	 * @param FontResolver|null  $fonts    Font resolver, for the font fingerprint.
	 * @param RenderProfile|null $profile  Supplies template, engine and font context.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ?FontResolver $fonts = null,
		private readonly ?RenderProfile $profile = null
	) {}

	/**
	 * Hashes the render inputs for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post              $post    Post being rendered.
	 * @param array<string, mixed> $context Render context. Recognised keys:
	 *                                      template_id, template_version, engine_id,
	 *                                      font_fingerprint, size_slug, locale.
	 *
	 * @return string Sixteen-character hash.
	 */
	public function for_post( WP_Post $post, array $context = array() ): string {
		/*
		 * The caller may override any of these, but must not have to supply them:
		 * SPEC §9.1 lists template version, engine and fonts as part of the hash, and
		 * a caller that omits them would compute a different hash for the same card.
		 */
		if ( null !== $this->profile ) {
			$context = array_merge( $this->profile->context( $post->ID ), $context );
		}

		$overrides = get_post_meta( $post->ID, CardRepository::META_OVERRIDES, true );
		$overrides = is_array( $overrides ) ? $overrides : array();

		$thumbnail = (int) get_post_thumbnail_id( $post );

		return $this->hash(
			array_merge(
				$this->context_defaults( $context ),
				array(
					'normalized_headline'     => Str::normalize(
						(string) ( $overrides['headline'] ?? get_the_title( $post ) )
					),
					'normalized_subhead'      => Str::normalize( (string) ( $overrides['subhead'] ?? '' ) ),
					'primary_term_id'         => $this->primary_term_id( $post ),
					'author_id'               => (int) $post->post_author,
					'featured_image_id'       => $thumbnail,
					'featured_image_modified' => $this->attachment_modified( $thumbnail ),
				)
			)
		);
	}

	/**
	 * Hashes an explicit set of inputs.
	 *
	 * Used by the homepage card and by tests, neither of which has a WP_Post.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $inputs Full input set.
	 *
	 * @return string Sixteen-character hash.
	 */
	public function hash( array $inputs ): string {
		$inputs['settings_fingerprint'] = $inputs['settings_fingerprint'] ?? $this->settings->fingerprint();
		$inputs['schema_version']       = $inputs['schema_version'] ?? CardDocumentSchema::SCHEMA;

		ksort( $inputs, SORT_STRING );

		return Hash::of( $inputs, 16 );
	}

	/**
	 * The eight-character form used in card filenames.
	 *
	 * Per SPEC §7.2 this is what busts platform caches: a regenerated card gets a new
	 * URL, so Facebook and LinkedIn refetch instead of serving what they cached.
	 *
	 * @since 0.1.0
	 *
	 * @param string $input_hash Full input hash.
	 *
	 * @return string First eight characters.
	 */
	public function short( string $input_hash ): string {
		return substr( $input_hash, 0, 8 );
	}

	/**
	 * Fills in the render-context half of the input set.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $context Caller-supplied context.
	 *
	 * @return array<string, mixed> Complete context.
	 */
	private function context_defaults( array $context ): array {
		return array(
			'schema_version'       => CardDocumentSchema::SCHEMA,
			'template_id'          => (string) ( $context['template_id'] ?? $this->settings->get( 'default_template', '' ) ),
			'template_version'     => (int) ( $context['template_version'] ?? 0 ),
			'settings_fingerprint' => (string) ( $context['settings_fingerprint'] ?? $this->settings->fingerprint() ),

			/*
			 * SPEC §9.1: this is what makes a theme switch invalidate affected cards
			 * without any special-case hook, because switching themes changes which
			 * font file the chain in §6.1 resolves to.
			 */
			'font_fingerprint'     => (string) ( $context['font_fingerprint'] ?? ( $this->fonts?->fingerprint() ?? '' ) ),
			'engine_id'            => (string) ( $context['engine_id'] ?? '' ),
			'locale'               => (string) ( $context['locale'] ?? get_locale() ),
			'size_slug'            => (string) ( $context['size_slug'] ?? 'og' ),
		);
	}

	/**
	 * Primary term ID, or 0.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being rendered.
	 *
	 * @return int Term ID.
	 */
	private function primary_term_id( WP_Post $post ): int {
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || ! $taxonomy->hierarchical ) {
				continue;
			}

			foreach ( array( '_yoast_wpseo_primary_' . $taxonomy->name, 'rank_math_primary_' . $taxonomy->name ) as $key ) {
				$primary = (int) get_post_meta( $post->ID, $key, true );

				if ( $primary > 0 ) {
					return $primary;
				}
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				return (int) $terms[0]->term_id;
			}
		}

		return 0;
	}

	/**
	 * Modification time of an attachment.
	 *
	 * Included so that re-uploading a replacement image under the same attachment ID
	 * still invalidates the card. Without it, "replace media" silently keeps the old
	 * card forever.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string Modification timestamp, or empty.
	 */
	private function attachment_modified( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$post = get_post( $attachment_id );

		return $post instanceof WP_Post ? (string) $post->post_modified_gmt : '';
	}
}
