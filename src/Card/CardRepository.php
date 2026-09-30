<?php
/**
 * Card meta storage and staleness.
 *
 * Implements SPEC.md §2.2, §5.2 and §9.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's post meta.
 *
 * @since 0.1.0
 */
final class CardRepository {

	public const META_CARD      = '_scstudio_card';
	public const META_STALE     = '_scstudio_stale';
	public const META_DISABLED  = '_scstudio_disabled';
	public const META_OVERRIDES = '_scstudio_overrides';

	/**
	 * The override fields an author can set, with their length limits.
	 *
	 * One list, because it is simultaneously the editor panel's field set, the classic
	 * metabox's field set and the sanitiser's allow-list. When those were three lists,
	 * `alt` was in two of them and hand-written alt text vanished on save without a
	 * word — the sanitiser dropping an unknown key looks exactly like the author having
	 * typed nothing. Adding a field here is what makes it savable.
	 *
	 * @var array<string, int>
	 */
	public const FIELD_LIMITS = array(
		'headline' => 300,
		'subhead'  => 300,
		'template' => 64,
		'alt'      => 300,
	);

	/**
	 * The fields the editor UI offers, in the order it offers them.
	 *
	 * @var array<int, string>
	 */
	public const EDITABLE_FIELDS = array( 'headline', 'subhead', 'template', 'alt' );

	public const STATUS_CURRENT  = 'current';
	public const STATUS_STALE    = 'stale';
	public const STATUS_NONE     = 'none';
	public const STATUS_DISABLED = 'disabled';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings      $settings  Plugin settings.
	 * @param InputHasher   $hasher    Input hasher.
	 * @param CardDirectory $directory Card directory resolver.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly InputHasher $hasher,
		private readonly CardDirectory $directory
	) {}

	/**
	 * Registers every post meta key.
	 *
	 * Per SPEC §5.2 only the two keys the editor writes are exposed to REST. The rest
	 * are server-written, so they are readable through the REST schema but refuse
	 * writes: an author must not be able to point `_scstudio_card.file` at an
	 * arbitrary path by PATCHing a post.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$post_types = (array) $this->settings->get( 'enabled_post_types', array() );

		foreach ( $post_types as $post_type ) {
			$post_type = (string) $post_type;

			register_post_meta(
				$post_type,
				self::META_OVERRIDES,
				array(
					'type'              => 'object',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => $this->override_schema(),
						),
					),
					'sanitize_callback' => array( $this, 'sanitize_overrides' ),
					'auth_callback'     => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				)
			);

			register_post_meta(
				$post_type,
				self::META_DISABLED,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				)
			);

			register_post_meta(
				$post_type,
				self::META_CARD,
				array(
					'type'          => 'object',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				)
			);

			register_post_meta(
				$post_type,
				self::META_STALE,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * The REST schema for the overrides object, built from the one field list.
	 *
	 * Kept in step with sanitize_overrides() by construction: a schema that permits a
	 * key the sanitiser drops accepts a write and then loses it, which is the same
	 * silent failure from the other direction.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{type: string, maxLength: int}> Property schemas.
	 */
	private function override_schema(): array {
		$properties = array();

		foreach ( self::FIELD_LIMITS as $key => $limit ) {
			$properties[ $key ] = array(
				'type'      => 'string',
				'maxLength' => $limit,
			);
		}

		return $properties;
	}

	/**
	 * Sanitises the overrides object.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Incoming value.
	 *
	 * @return array<string, string> Clean overrides.
	 */
	public function sanitize_overrides( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::FIELD_LIMITS as $key => $limit ) {
			if ( ! isset( $value[ $key ] ) ) {
				continue;
			}

			$clean[ $key ] = mb_substr( sanitize_text_field( (string) $value[ $key ] ), 0, $limit );
		}

		return $clean;
	}

	/**
	 * Reads a post's card record.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return CardRecord|null Record, or null when none is stored.
	 */
	public function get( int $post_id ): ?CardRecord {
		$raw = get_post_meta( $post_id, self::META_CARD, true );

		if ( ! is_array( $raw ) || array() === $raw ) {
			return null;
		}

		return CardRecord::from_array( $raw );
	}

	/**
	 * Writes a post's card record and clears its stale flag.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $post_id Post ID.
	 * @param CardRecord $record  Record to store.
	 *
	 * @return void
	 */
	public function save( int $post_id, CardRecord $record ): void {
		update_post_meta( $post_id, self::META_CARD, $record->to_array() );
		delete_post_meta( $post_id, self::META_STALE );
	}

	/**
	 * Deletes every plugin meta key for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function delete( int $post_id ): void {
		foreach ( array( self::META_CARD, self::META_STALE ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Reads a post's overrides.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, string> Overrides.
	 */
	public function overrides( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_OVERRIDES, true );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Whether card generation is switched off for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when disabled.
	 */
	public function is_disabled( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::META_DISABLED, true );
	}

	/**
	 * Marks a post's card stale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function mark_stale( int $post_id ): void {
		if ( null === $this->get( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_STALE, true );
	}

	/**
	 * Whether a post's stored card is out of date.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post              $post    Post to check.
	 * @param array<string, mixed> $context Render context, as accepted by InputHasher.
	 *
	 * @return bool True when the card no longer matches its inputs.
	 */
	public function is_stale( WP_Post $post, array $context = array() ): bool {
		$record = $this->get( $post->ID );

		if ( null === $record ) {
			return false;
		}

		if ( (bool) get_post_meta( $post->ID, self::META_STALE, true ) ) {
			return true;
		}

		return $record->input_hash() !== $this->hasher->for_post( $post, $context );
	}

	/**
	 * The status shown in the post list column and the editor panel.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post              $post    Post to check.
	 * @param array<string, mixed> $context Render context.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status( WP_Post $post, array $context = array() ): string {
		if ( $this->is_disabled( $post->ID ) ) {
			return self::STATUS_DISABLED;
		}

		$record = $this->get( $post->ID );

		// A record whose file has gone missing is "none", not "current" — SPEC §12.1
		// requires each fallback step to be verified rather than assumed.
		if ( null === $record || ! $record->exists( $this->directory ) ) {
			return self::STATUS_NONE;
		}

		return $this->is_stale( $post, $context ) ? self::STATUS_STALE : self::STATUS_CURRENT;
	}
}
