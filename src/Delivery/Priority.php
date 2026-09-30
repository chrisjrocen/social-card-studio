<?php
/**
 * Priority modes.
 *
 * Implements the `priority` setting of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

use ChrxDigital\SocialCardStudio\Card\CardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether our card should replace what an SEO plugin already has.
 *
 * One rule, the one SPEC §10.2 calls "the setting most sites actually want": replace
 * images the SEO plugin derived on its own (featured image, first content image, site
 * default), but never one a human chose by hand in its social tab. A per-post opt-out
 * always wins.
 *
 * @since 0.1.0
 */
final class Priority {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardRepository $repository Card meta storage.
	 */
	public function __construct(
		private readonly CardRepository $repository
	) {}

	/**
	 * Whether our card should be supplied for this post.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id        Post being rendered.
	 * @param bool $seo_has_manual Whether the SEO plugin's image was chosen by a human.
	 *
	 * @return bool True when our card should win.
	 */
	public function should_supply( int $post_id, bool $seo_has_manual ): bool {
		if ( $post_id > 0 && $this->repository->is_disabled( $post_id ) ) {
			return false;
		}

		/**
		 * Filters the priority decision for one post.
		 *
		 * Documented in SPEC §21 as the per-post priority override.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $supply         Whether our card should be supplied.
		 * @param int  $post_id        Post being rendered.
		 * @param bool $seo_has_manual Whether a human set an image in the SEO plugin.
		 */
		return (bool) apply_filters( 'scstudio_priority_for_post', ! $seo_has_manual, $post_id, $seo_has_manual );
	}
}
