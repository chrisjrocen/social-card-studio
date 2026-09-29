<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * Implements SPEC.md §5.1 (options), §9.1 (settings fingerprint) and the "one
 * validator, two entry points" rule of §15.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Settings;

use ChrxDigital\SocialCardStudio\Support\Hash;
use ChrxDigital\SocialCardStudio\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes and validates scstudio_settings.
 *
 * @since 0.1.0
 */
final class Settings {

	public const OPTION = 'scstudio_settings';

	/**
	 * Autoload ceiling from SPEC §5.1. Exceeding it is a bug, not a limit to raise.
	 */
	public const MAX_BYTES = 32768;

	/**
	 * In-request cache, keyed by blog ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cache = array();

	/**
	 * Returns the full settings array, defaults merged in.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Current settings.
	 */
	public function all(): array {
		$blog = is_multisite() ? get_current_blog_id() : 1;

		if ( isset( $this->cache[ $blog ] ) ) {
			return $this->cache[ $blog ];
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$this->cache[ $blog ] = $this->merge_defaults( $stored );

		return $this->cache[ $blog ];
	}

	/**
	 * Returns one setting by dotted path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Dotted path, e.g. 'brand.accent'.
	 * @param mixed  $fallback Returned when the path is absent.
	 *
	 * @return mixed Setting value.
	 */
	public function get( string $path, mixed $fallback = null ): mixed {
		$value = $this->all();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $fallback;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Validates and persists settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Raw settings, typically from the settings form.
	 *
	 * @return array{ok: bool, errors: string[]} Result of the write.
	 */
	public function save( array $input ): array {
		$result = $this->sanitize( $input );

		if ( ! $result['ok'] ) {
			return array(
				'ok'     => false,
				'errors' => $result['errors'],
			);
		}

		update_option( self::OPTION, $result['value'], true );
		$this->flush();

		return array(
			'ok'     => true,
			'errors' => array(),
		);
	}

	/**
	 * Validates settings without persisting them.
	 *
	 * This is the single sanitise callback referenced by SPEC §15: the Settings API
	 * and the REST layer both route through it, so they cannot drift apart.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Raw settings.
	 *
	 * @return array{ok: bool, value: array<string, mixed>, errors: string[]} Validation result.
	 */
	public function sanitize( array $input ): array {
		$merged    = $this->merge_defaults( $input );
		$validator = new Schema();
		$result    = $validator->validate( $merged, SettingsSchema::schema() );

		if ( ! $result['ok'] ) {
			return array(
				'ok'     => false,
				'value'  => array(),
				'errors' => $result['errors'],
			);
		}

		$value = (array) $result['value'];
		$size  = strlen( (string) wp_json_encode( $value ) );

		if ( $size > self::MAX_BYTES ) {
			return array(
				'ok'     => false,
				'value'  => array(),
				'errors' => array(
					sprintf(
						/* translators: 1: encoded size in bytes, 2: maximum size in bytes. */
						__( 'Settings are %1$d bytes, above the %2$d byte limit for an autoloaded option.', 'social-card-studio' ),
						$size,
						self::MAX_BYTES
					),
				),
			);
		}

		return array(
			'ok'     => true,
			'value'  => $value,
			'errors' => array(),
		);
	}

	/**
	 * Hash of only the render-affecting settings.
	 *
	 * Per SPEC §9.1 this deliberately excludes settings that do not change pixels, so
	 * that toggling an unrelated option does not invalidate every card on the site.
	 *
	 * @since 0.1.0
	 *
	 * @return string Sixteen-character fingerprint.
	 */
	public function fingerprint(): string {
		$settings = $this->all();

		return Hash::of(
			array(
				'brand'             => $settings['brand'] ?? array(),
				'typography'        => $settings['typography'] ?? array(),
				'output'            => $settings['output'] ?? array(),
				'emoji'             => $settings['emoji'] ?? '',
				'default_template'  => $settings['default_template'] ?? '',
				'per_type_template' => $settings['per_type_template'] ?? array(),
				'alt_text_pattern'  => $settings['alt_text_pattern'] ?? '',
			)
		);
	}

	/**
	 * Discards the in-request cache.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Recursively fills gaps from the defaults.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Partial settings.
	 *
	 * @return array<string, mixed> Complete settings.
	 */
	private function merge_defaults( array $input ): array {
		return $this->deep_merge( SettingsSchema::defaults(), $input );
	}

	/**
	 * Merges two arrays, preferring the override for scalars and lists.
	 *
	 * Lists are replaced rather than merged: a user who deselects every post type
	 * must end up with none, not with the defaults reinstated.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $base     Defaults.
	 * @param array<string, mixed> $override Incoming values.
	 *
	 * @return array<string, mixed> Merged array.
	 */
	private function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) ) {
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value );

				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
