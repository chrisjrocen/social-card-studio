<?php
/**
 * SEO plugin integration contract.
 *
 * Implements the interface declared in SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies our card to whichever SEO plugin is emitting the tags.
 *
 * `has_manual_image()` is the load-bearing method. It reads the host plugin's own
 * meta key to answer "did a human choose this picture, or did the plugin derive it?"
 * — and that distinction is the entire difference between the priority rule doing
 * what a site owner expects and it overwriting a deliberate editorial choice.
 *
 * @since 0.1.0
 */
interface SeoIntegration {

	/**
	 * Identifier used in settings and diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier.
	 */
	public function id(): string;

	/**
	 * Human-readable plugin name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Name.
	 */
	public function name(): string;

	/**
	 * Whether the host plugin is active.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when present.
	 */
	public function is_active(): bool;

	/**
	 * Whether a human explicitly set a social image on this post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when the host plugin holds a manual choice.
	 */
	public function has_manual_image( int $post_id ): bool;

	/**
	 * The attachment ID of that manual choice, when it is an attachment.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Attachment ID, or 0.
	 */
	public function manual_image_id( int $post_id ): int;

	/**
	 * The URL of that manual choice, when it is stored as a URL.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string URL, or empty.
	 */
	public function manual_image_url( int $post_id ): string;

	/**
	 * Registers this integration's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void;
}
