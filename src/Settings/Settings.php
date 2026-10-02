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

		/*
		 * brand and output are hashed in their pre-DB-version-2 shape, with the
		 * removed keys pinned to the values every site had (none had a UI to change
		 * them). Hashing the trimmed maps instead would mark every card on every
		 * site stale on upgrade, for a change that alters no pixels.
		 */
		$brand  = array_merge(
			array(
				'logo_id'       => 0,
				'logo_position' => 'top-left',
			),
			(array) ( $settings['brand'] ?? array() )
		);
		$output = array_merge(
			array(
				'format'       => 'jpeg',
				'target_bytes' => 600000,
				'max_bytes'    => 1048576,
			),
			(array) ( $settings['output'] ?? array() )
		);

		return Hash::of(
			array(
				'brand'             => $brand,
				'typography'        => $settings['typography'] ?? array(),
				'output'            => $output,
				'default_template'  => $settings['default_template'] ?? '',
				'per_type_template' => $settings['per_type_template'] ?? array(),
				'alt_text_pattern'  => $settings['alt_text_pattern'] ?? '',
			)
		);
	}

	/**
	 * Runs a callback with unsaved settings in effect.
	 *
	 * The overrides are validated exactly as a save would be, swapped into the
	 * in-request cache, and discarded afterwards whether or not the callback throws.
	 * Nothing is written to the database.
	 *
	 * @since 0.2.0
	 *
	 * @template T
	 *
	 * @param array<string, mixed> $overrides Partial settings to apply.
	 * @param callable(): T        $callback  Work to do with the overrides in effect.
	 *
	 * @return T|array{ok: false, errors: string[]} The callback's result, or the validation errors.
	 */
	public function preview( array $overrides, callable $callback ): mixed {
		$merged = $this->deep_merge( $this->all(), $overrides );

		// An open map: the form sends the whole thing, and a dropped key means "use the default".
		if ( isset( $overrides['per_type_template'] ) ) {
			$merged['per_type_template'] = (array) $overrides['per_type_template'];
		}

		$result = $this->sanitize( $merged );

		if ( ! $result['ok'] ) {
			return array(
				'ok'     => false,
				'errors' => $result['errors'],
			);
		}

		$blog = is_multisite() ? get_current_blog_id() : 1;

		$this->cache[ $blog ] = $result['value'];

		try {
			return $callback();
		} finally {
			$this->flush();
		}
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
		return $this->deep_merge( SettingsSchema::defaults(), $input, true );
	}

	/**
	 * Merges two arrays, preferring the override for scalars and lists.
	 *
	 * Lists are replaced rather than merged: a user who deselects every post type
	 * must end up with none, not with the defaults reinstated.
	 *
	 * Keys absent from the base map are dropped when $strict is set. The schema
	 * rejects unknown keys, so without this a stored option still carrying a key
	 * removed in a later version would make every save fail. An empty array in the
	 * base (per_type_template) is an open map and is taken whole.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $base     Defaults.
	 * @param array<string, mixed> $override Incoming values.
	 * @param bool                 $strict   Drop keys the base does not have.
	 *
	 * @return array<string, mixed> Merged array.
	 */
	private function deep_merge( array $base, array $override, bool $strict = false ): array {
		foreach ( $override as $key => $value ) {
			if ( $strict && ! array_key_exists( $key, $base ) ) {
				continue;
			}

			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && array() !== $base[ $key ] && ! array_is_list( $value ) ) {
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value, $strict );

				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
