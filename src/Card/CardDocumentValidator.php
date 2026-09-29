<?php
/**
 * Card Document validation.
 *
 * Implements SPEC.md §18.3 against the structure of SPEC.md §2.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Turns untrusted JSON into a validated document array, or a list of errors.
 *
 * Deny-by-default throughout. Anything this class waves through is treated as safe by
 * every renderer downstream, so it is the last place a hostile document can be
 * stopped.
 *
 * @since 0.1.0
 */
final class CardDocumentValidator {

	/**
	 * Depth ceiling for the incoming structure.
	 *
	 * A deeply nested array is a cheap way to blow the stack in a recursive
	 * validator. The legitimate maximum is four levels; sixteen is generous.
	 */
	private const MAX_DEPTH = 16;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $allowed_roots Absolute directories an image path may live in.
	 */
	public function __construct( private readonly array $allowed_roots = array() ) {}

	/**
	 * Validates a decoded document.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Decoded JSON, expected to be an array.
	 *
	 * @return array{ok: bool, value: array<string, mixed>, errors: string[]} Result.
	 */
	public function validate( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return $this->failure( array( 'The card document must be an object.' ) );
		}

		if ( $this->depth_of( $raw ) > self::MAX_DEPTH ) {
			return $this->failure(
				array( sprintf( 'The card document nests deeper than %d levels.', self::MAX_DEPTH ) )
			);
		}

		$validator = new Schema();
		$top       = $validator->validate( $raw, CardDocumentSchema::document() );

		if ( ! $top['ok'] ) {
			return $this->failure( $top['errors'] );
		}

		$document = (array) $top['value'];
		$layers   = $this->validate_layers( (array) ( $document['layers'] ?? array() ) );

		if ( ! $layers['ok'] ) {
			return $this->failure( $layers['errors'] );
		}

		$document['layers'] = $layers['value'];

		return array(
			'ok'     => true,
			'value'  => $document,
			'errors' => array(),
		);
	}

	/**
	 * Validates a JSON string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $json Raw JSON.
	 *
	 * @return array{ok: bool, value: array<string, mixed>, errors: string[]} Result.
	 */
	public function validate_json( string $json ): array {
		$decoded = json_decode( $json, true, self::MAX_DEPTH + 2 );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->failure(
				array( sprintf( 'The card document is not valid JSON (%s).', json_last_error_msg() ) )
			);
		}

		return $this->validate( $decoded );
	}

	/**
	 * Validates the layer list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $layers Raw layers.
	 *
	 * @return array{ok: bool, value: array<int, array<string, mixed>>, errors: string[]} Result.
	 */
	private function validate_layers( array $layers ): array {
		$errors    = array();
		$validated = array();
		$seen_ids  = array();
		$validator = new Schema();

		foreach ( array_values( $layers ) as $index => $layer ) {
			$path = sprintf( 'layers.%d', $index );

			if ( ! is_array( $layer ) ) {
				$errors[] = $path . ' must be an object';

				continue;
			}

			$type = isset( $layer['type'] ) && is_string( $layer['type'] ) ? $layer['type'] : '';

			if ( ! in_array( $type, Layer::TYPES, true ) ) {
				$errors[] = sprintf(
					'%s.type must be one of: %s',
					$path,
					implode( ', ', Layer::TYPES )
				);

				continue;
			}

			$result = $validator->validate( $layer, CardDocumentSchema::layer( $type ) );

			if ( ! $result['ok'] ) {
				foreach ( $result['errors'] as $error ) {
					$errors[] = $path . '.' . $error;
				}

				continue;
			}

			$value = (array) $result['value'];
			$id    = (string) $value['id'];

			if ( isset( $seen_ids[ $id ] ) ) {
				$errors[] = sprintf( '%s.id "%s" is already used by another layer', $path, $id );

				continue;
			}

			$seen_ids[ $id ] = true;

			foreach ( $this->extra_rules( $value, $type, $path ) as $error ) {
				$errors[] = $error;
			}

			$validated[] = $value;
		}

		return array(
			'ok'     => array() === $errors,
			'value'  => $validated,
			'errors' => $errors,
		);
	}

	/**
	 * Applies the rules the generic schema cannot express.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $layer Validated layer.
	 * @param string               $type  Layer type.
	 * @param string               $path  Dotted path for messages.
	 *
	 * @return string[] Errors, empty when the layer passes.
	 */
	private function extra_rules( array $layer, string $type, string $path ): array {
		$errors = array();

		if ( Layer::TYPE_IMAGE === $type ) {
			foreach ( array( 'source', 'fallback' ) as $key ) {
				$value = (string) ( $layer[ $key ] ?? '' );

				if ( '' === $value && 'fallback' === $key ) {
					continue;
				}

				$classified = ImageSource::classify( $value, $this->allowed_roots );

				if ( ImageSource::KIND_INVALID === $classified['kind'] ) {
					$errors[] = sprintf( '%s.%s %s', $path, $key, $classified['reason'] );
				}
			}
		}

		if ( Layer::TYPE_TEXT === $type ) {
			$size = (array) ( $layer['size'] ?? array() );
			$min  = (int) ( $size['min'] ?? 0 );
			$max  = (int) ( $size['max'] ?? 0 );

			if ( $min > $max ) {
				$errors[] = sprintf( '%s.size.min (%d) is above size.max (%d)', $path, $min, $max );
			}
		}

		if ( Layer::TYPE_GRADIENT === $type ) {
			$stops = (array) ( $layer['stops'] ?? array() );

			if ( count( $stops ) < 2 ) {
				$errors[] = $path . '.stops needs at least two stops';
			}

			$previous = -1.0;

			foreach ( $stops as $position => $stop ) {
				$at = (float) ( ( (array) $stop )['at'] ?? 0.0 );

				if ( $at < $previous ) {
					$errors[] = sprintf( '%s.stops.%d.at is out of order', $path, (int) $position );

					break;
				}

				$previous = $at;
			}
		}

		return $errors;
	}

	/**
	 * Measures nesting depth without recursing into it.
	 *
	 * Uses an explicit stack: a recursive depth check on a hostile document is itself
	 * the stack overflow it is meant to prevent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $data Structure to measure.
	 *
	 * @return int Maximum depth.
	 */
	private function depth_of( array $data ): int {
		$max   = 1;
		$stack = array( array( $data, 1 ) );

		while ( array() !== $stack ) {
			list( $node, $depth ) = array_pop( $stack );

			if ( $depth > $max ) {
				$max = $depth;
			}

			if ( $depth > self::MAX_DEPTH ) {
				return $depth;
			}

			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$stack[] = array( $child, $depth + 1 );
				}
			}
		}

		return $max;
	}

	/**
	 * Builds a failure result.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $errors Failure messages.
	 *
	 * @return array{ok: bool, value: array<string, mixed>, errors: string[]} Result.
	 */
	private function failure( array $errors ): array {
		return array(
			'ok'     => false,
			'value'  => array(),
			'errors' => $errors,
		);
	}
}
