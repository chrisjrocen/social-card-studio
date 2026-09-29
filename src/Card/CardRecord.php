<?php
/**
 * Card Record value object.
 *
 * Implements SPEC.md §2.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Storage\CardDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * The result of rendering a Card Document for one post.
 *
 * @since 0.1.0
 */
final class CardRecord {

	public const SCHEMA = 1;

	public const SOURCE_TEMPLATE      = 'template';
	public const SOURCE_AI_COPY       = 'ai_copy';
	public const SOURCE_AI_BACKGROUND = 'ai_background';
	public const SOURCE_AI_FULL       = 'ai_full';

	/**
	 * Sources whose output a human approved and paid for.
	 *
	 * Per SPEC §9.2 these are never regenerated silently under the default
	 * regeneration mode.
	 */
	public const AI_SOURCES = array(
		self::SOURCE_AI_COPY,
		self::SOURCE_AI_BACKGROUND,
		self::SOURCE_AI_FULL,
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data Record fields.
	 */
	private function __construct( private readonly array $data ) {}

	/**
	 * Builds from stored meta.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $raw Stored record.
	 *
	 * @return self New record.
	 */
	public static function from_array( array $raw ): self {
		return new self(
			array(
				'schema'        => (int) ( $raw['schema'] ?? self::SCHEMA ),
				'input_hash'    => (string) ( $raw['input_hash'] ?? '' ),
				'template_id'   => (string) ( $raw['template_id'] ?? '' ),
				'template_ver'  => (int) ( $raw['template_ver'] ?? 0 ),
				'source'        => (string) ( $raw['source'] ?? self::SOURCE_TEMPLATE ),
				'engine'        => (string) ( $raw['engine'] ?? '' ),
				'file'          => (string) ( $raw['file'] ?? '' ),
				'w'             => (int) ( $raw['w'] ?? 0 ),
				'h'             => (int) ( $raw['h'] ?? 0 ),
				'bytes'         => (int) ( $raw['bytes'] ?? 0 ),
				'mime'          => (string) ( $raw['mime'] ?? '' ),
				'alt'           => (string) ( $raw['alt'] ?? '' ),
				'attachment_id' => isset( $raw['attachment_id'] ) ? (int) $raw['attachment_id'] : null,
				'generated_at'  => (int) ( $raw['generated_at'] ?? 0 ),
				'generated_by'  => (int) ( $raw['generated_by'] ?? 0 ),
				'variants'      => (array) ( $raw['variants'] ?? array() ),
			)
		);
	}

	/**
	 * Returns one field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key      Field name.
	 * @param mixed  $fallback Returned when absent.
	 *
	 * @return mixed Field value.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	/**
	 * The hash of the inputs this card was rendered from.
	 *
	 * @since 0.1.0
	 *
	 * @return string Input hash.
	 */
	public function input_hash(): string {
		return (string) $this->data['input_hash'];
	}

	/**
	 * Path relative to the card directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string Relative path, e.g. `2026/09/card-1482-a1b2c3d4.jpg`.
	 */
	public function file(): string {
		return (string) $this->data['file'];
	}

	/**
	 * Alt text.
	 *
	 * @since 0.1.0
	 *
	 * @return string Alt text.
	 */
	public function alt(): string {
		return (string) $this->data['alt'];
	}

	/**
	 * Whether the card came from an AI mode.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for AI-sourced cards.
	 */
	public function is_ai(): bool {
		return in_array( (string) $this->data['source'], self::AI_SOURCES, true );
	}

	/**
	 * Absolute path on disk.
	 *
	 * Re-derived from wp_upload_dir() every time, per SPEC §18.2, so a domain change
	 * or a Local-to-production migration needs no search-replace.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDirectory $directory Card directory resolver.
	 *
	 * @return string Absolute path, or empty when the record has no file.
	 */
	public function path( CardDirectory $directory ): string {
		$file = $this->file();

		return '' === $file ? '' : $directory->path( $file );
	}

	/**
	 * Public URL.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDirectory $directory Card directory resolver.
	 *
	 * @return string URL, or empty when the record has no file.
	 */
	public function url( CardDirectory $directory ): string {
		$file = $this->file();

		return '' === $file ? '' : $directory->url( $file );
	}

	/**
	 * Whether the file this record points at is actually present.
	 *
	 * The fallback chain in SPEC §12.1 requires every step to be verified before use,
	 * so a record is never trusted to imply a file.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDirectory $directory Card directory resolver.
	 *
	 * @return bool True when the file exists.
	 */
	public function exists( CardDirectory $directory ): bool {
		$path = $this->path( $directory );

		return '' !== $path && is_readable( $path );
	}

	/**
	 * Serialises for storage.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Record fields.
	 */
	public function to_array(): array {
		return $this->data;
	}
}
