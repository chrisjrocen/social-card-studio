<?php
/**
 * Opt-in media library mode.
 *
 * Implements SPEC.md §5.3 — the escape hatch for sites whose uploads are offloaded to
 * a CDN that only ever sees attachments.
 *
 * @package ChrxDigital\SocialCardStudio;
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Storage;

use ChrxDigital\SocialCardStudio\Card\CardRecord;
use ChrxDigital\SocialCardStudio\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers card files as attachments, then hides them again.
 *
 * Cards are not media. They are generated, content-addressed and replaced without
 * warning, so surfacing them in the library grid produces support tickets and lets
 * someone "tidy up" a file that live shares depend on. This mode exists only because
 * WP Offload Media and its peers hook attachment metadata and never see anything
 * else — so on those sites a card that is not an attachment stays on origin.
 *
 * @since 0.1.0
 */
final class MediaLibraryBridge {

	/**
	 * Meta key marking an attachment as one of ours.
	 */
	public const MARKER = '_scstudio_card_marker';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings      $settings  Plugin settings.
	 * @param CardDirectory $directory Card directory resolver.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly CardDirectory $directory
	) {}

	/**
	 * Registers the hooks that keep card attachments out of sight.
	 *
	 * These run whether or not the mode is currently on: a site that switches it off
	 * still has attachments from when it was on, and those must stay hidden.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'skip_image_sizes' ), 10, 3 );
		add_filter( 'ajax_query_attachments_args', array( $this, 'hide_from_grid' ) );
		add_action( 'pre_get_posts', array( $this, 'hide_from_queries' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_from_sitemap' ), 10, 2 );
	}

	/**
	 * Whether the mode is currently enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when cards should be attachments.
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'media_library_mode', false );
	}

	/**
	 * Creates or updates the attachment for a card.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $post_id Post the card belongs to.
	 * @param CardRecord $record  Card record.
	 *
	 * @return int Attachment ID, or 0 when the mode is off or the file is missing.
	 */
	public function attach( int $post_id, CardRecord $record ): int {
		if ( ! $this->is_enabled() ) {
			return 0;
		}

		$path = $record->path( $this->directory );

		if ( '' === $path || ! is_readable( $path ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $record->get( 'mime', 'image/jpeg' ),
				'post_title'     => sprintf(
					/* translators: %d: post ID the card belongs to. */
					__( 'Social card for post %d', 'social-card-studio' ),
					$post_id
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$path,
			0
		);

		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return 0;
		}

		update_post_meta( $attachment_id, self::MARKER, $post_id );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $record->alt() );

		// Metadata without the size set: skip_image_sizes() below returns none.
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

		return $attachment_id;
	}

	/**
	 * Deletes the attachment for a card, leaving the file alone.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public function detach( int $attachment_id ): void {
		if ( $attachment_id <= 0 || ! $this->is_card_attachment( $attachment_id ) ) {
			return;
		}

		// false: the file is the plugin's, and CardStore decides its lifetime.
		wp_delete_attachment( $attachment_id, false );
	}

	/**
	 * Reports whether an attachment is one of ours.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool True when the marker is present.
	 */
	public function is_card_attachment( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::MARKER, true );
	}

	/**
	 * Generates no intermediate sizes for card attachments.
	 *
	 * A site with twelve registered sizes would otherwise write thirteen files per
	 * card, for a picture nobody will ever insert into a post.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $sizes         Sizes to generate.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 *
	 * @return array<string, mixed> Sizes, empty for our attachments.
	 */
	public function skip_image_sizes( array $sizes, array $metadata = array(), int $attachment_id = 0 ): array {
		return $attachment_id > 0 && $this->is_card_attachment( $attachment_id ) ? array() : $sizes;
	}

	/**
	 * Hides card attachments from the media modal.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array<string, mixed> Filtered arguments.
	 */
	public function hide_from_grid( array $args ): array {
		$args['meta_query'] = $this->exclusion_clause( (array) ( $args['meta_query'] ?? array() ) );

		return $args;
	}

	/**
	 * Hides card attachments from attachment queries.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Query $query The query being prepared.
	 *
	 * @return void
	 */
	public function hide_from_queries( \WP_Query $query ): void {
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		// A deliberate lookup by marker is left alone; only browsing is filtered.
		$existing = (array) $query->get( 'meta_query' );

		foreach ( $existing as $clause ) {
			if ( is_array( $clause ) && self::MARKER === ( $clause['key'] ?? '' ) ) {
				return;
			}
		}

		$query->set( 'meta_query', $this->exclusion_clause( $existing ) );
	}

	/**
	 * Excludes card attachments from the core sitemap.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string               $post_type Post type being listed.
	 *
	 * @return array<string, mixed> Filtered arguments.
	 */
	public function exclude_from_sitemap( array $args, string $post_type = '' ): array {
		if ( 'attachment' !== $post_type ) {
			return $args;
		}

		$args['meta_query'] = $this->exclusion_clause( (array) ( $args['meta_query'] ?? array() ) );

		return $args;
	}

	/**
	 * Builds the "not one of ours" meta clause.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $existing Existing meta query.
	 *
	 * @return array<mixed> Meta query including the exclusion.
	 */
	private function exclusion_clause( array $existing ): array {
		$existing[] = array(
			'key'     => self::MARKER,
			'compare' => 'NOT EXISTS',
		);

		return $existing;
	}
}
