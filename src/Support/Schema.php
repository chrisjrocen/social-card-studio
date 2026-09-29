<?php
/**
 * Generic allowlist schema validator.
 *
 * Implements the validation primitive required by SPEC.md §18.3 ("Card Documents
 * validated against a strict allowlist schema — unknown keys rejected, numerics
 * clamped, colours matched") and reused by the settings sanitiser (§15) so that one
 * validator serves both entry points.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validates structures against a declarative allowlist.
 *
 * Deny-by-default: a key absent from the schema is an error, never a passthrough. A
 * permissive validator on a Card Document is a rendering-side injection vector, and
 * on settings it is a way to grow unbounded autoloaded data.
 *
 * Supported node keys:
 *   type      string|int|float|bool|array|map|enum  (required)
 *   required  bool                                   (default false)
 *   default   mixed                                  (used when absent)
 *   min|max   int|float   numeric bounds; clamp=true clamps instead of failing
 *   clamp     bool
 *   maxlength int         string length in characters
 *   pattern   string      preg pattern for strings
 *   values    array       permitted values for type=enum
 *   items     array       node schema for each element of type=array
 *   maxitems  int         element ceiling for type=array
 *   shape     array       key => node schema for type=map
 *   format    string      'color'|'hexcolor' — validated via Color
 *
 * @since 0.1.0
 */
final class Schema {

	/**
	 * Collected error messages from the last run.
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Validates a value against a schema.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 *
	 * @return array{ok: bool, value: mixed, errors: string[]} Result. `value` is only meaningful when `ok`.
	 */
	public function validate( mixed $value, array $schema ): array {
		$this->errors = array();

		$result = $this->node( $value, $schema, '' );

		return array(
			'ok'     => array() === $this->errors,
			'value'  => $result,
			'errors' => $this->errors,
		);
	}

	/**
	 * Validates one node.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Dotted path for error messages.
	 *
	 * @return mixed Coerced value, or null when invalid.
	 */
	private function node( mixed $value, array $schema, string $path ): mixed {
		$type = (string) ( $schema['type'] ?? '' );

		return match ( $type ) {
			'string' => $this->string_node( $value, $schema, $path ),
			'int'    => $this->number_node( $value, $schema, $path, true ),
			'float'  => $this->number_node( $value, $schema, $path, false ),
			'bool'   => $this->bool_node( $value ),
			'enum'   => $this->enum_node( $value, $schema, $path ),
			'array'  => $this->array_node( $value, $schema, $path ),
			'map'    => $this->map_node( $value, $schema, $path ),
			default  => $this->fail( $path, sprintf( 'unknown schema type "%s"', $type ) ),
		};
	}

	/**
	 * Validates a string.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Dotted path.
	 *
	 * @return string|null Validated string, or null on failure.
	 */
	private function string_node( mixed $value, array $schema, string $path ): ?string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return $this->fail( $path, 'expected a string' );
		}

		$value = (string) $value;

		if ( isset( $schema['maxlength'] ) && mb_strlen( $value ) > (int) $schema['maxlength'] ) {
			return $this->fail( $path, sprintf( 'longer than %d characters', (int) $schema['maxlength'] ) );
		}

		if ( isset( $schema['pattern'] ) && ! preg_match( (string) $schema['pattern'], $value ) ) {
			return $this->fail( $path, 'does not match the required format' );
		}

		$format = (string) ( $schema['format'] ?? '' );

		/*
		 * An empty string means "no colour" — an absent stroke, an absent tint — and
		 * is the declared default for several optional colour fields. Rejecting it
		 * would make those defaults fail the very schema that declares them.
		 */
		if ( 'color' === $format && '' !== $value && ! Color::is_valid( $value ) && ! self::is_color_token( $value ) ) {
			return $this->fail( $path, 'is not an accepted colour literal or token' );
		}

		if ( 'hexcolor' === $format && ! Color::is_valid( $value ) ) {
			return $this->fail( $path, 'is not an accepted colour literal' );
		}

		return $value;
	}

	/**
	 * Whether a value is a single colour token.
	 *
	 * Presets ship as static JSON but have to pick up the site's brand colour, so a
	 * colour field accepts `{{brand_accent}}` as well as a literal. The pattern is
	 * deliberately strict — one token, nothing around it — so a colour can never
	 * become a place to smuggle arbitrary text into a drawing call.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Candidate value.
	 *
	 * @return bool True when the value is a lone token.
	 */
	public static function is_color_token( string $value ): bool {
		return 1 === preg_match( '/^\{\{[a-z][a-z0-9_]*(\|[a-z][a-z0-9_]*)*\}\}$/', $value );
	}

	/**
	 * Validates an int or float.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value    Value to validate.
	 * @param array<string, mixed> $schema   Schema node.
	 * @param string               $path     Dotted path.
	 * @param bool                 $integer  Whether to coerce to int.
	 *
	 * @return int|float|null Validated number, or null on failure.
	 */
	private function number_node( mixed $value, array $schema, string $path, bool $integer ): int|float|null {
		if ( ! is_numeric( $value ) ) {
			return $this->fail( $path, 'expected a number' );
		}

		$number = $integer ? (int) $value : (float) $value;
		$clamp  = ! empty( $schema['clamp'] );

		if ( isset( $schema['min'] ) && $number < $schema['min'] ) {
			if ( ! $clamp ) {
				return $this->fail( $path, sprintf( 'is below the minimum of %s', (string) $schema['min'] ) );
			}

			$number = $integer ? (int) $schema['min'] : (float) $schema['min'];
		}

		if ( isset( $schema['max'] ) && $number > $schema['max'] ) {
			if ( ! $clamp ) {
				return $this->fail( $path, sprintf( 'is above the maximum of %s', (string) $schema['max'] ) );
			}

			$number = $integer ? (int) $schema['max'] : (float) $schema['max'];
		}

		return $number;
	}

	/**
	 * Coerces a boolean.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Value to coerce.
	 *
	 * @return bool Boolean value.
	 */
	private function bool_node( mixed $value ): bool {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Validates against a fixed value list.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Dotted path.
	 *
	 * @return mixed Validated value, or null on failure.
	 */
	private function enum_node( mixed $value, array $schema, string $path ): mixed {
		$values = (array) ( $schema['values'] ?? array() );

		if ( ! in_array( $value, $values, true ) ) {
			return $this->fail(
				$path,
				sprintf( 'must be one of: %s', implode( ', ', array_map( 'strval', $values ) ) )
			);
		}

		return $value;
	}

	/**
	 * Validates a list.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Dotted path.
	 *
	 * @return array<int, mixed>|null Validated list, or null on failure.
	 */
	private function array_node( mixed $value, array $schema, string $path ): ?array {
		if ( ! is_array( $value ) ) {
			return $this->fail( $path, 'expected a list' );
		}

		$max = (int) ( $schema['maxitems'] ?? 0 );

		if ( $max > 0 && count( $value ) > $max ) {
			return $this->fail( $path, sprintf( 'has more than %d items', $max ) );
		}

		$items = (array) ( $schema['items'] ?? array() );

		if ( array() === $items ) {
			return array_values( $value );
		}

		$out = array();

		foreach ( array_values( $value ) as $index => $item ) {
			$out[] = $this->node( $item, $items, $this->join( $path, (string) $index ) );
		}

		return $out;
	}

	/**
	 * Validates a keyed structure, rejecting unknown keys.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Dotted path.
	 *
	 * @return array<string, mixed>|null Validated map, or null on failure.
	 */
	private function map_node( mixed $value, array $schema, string $path ): ?array {
		if ( ! is_array( $value ) ) {
			return $this->fail( $path, 'expected an object' );
		}

		$shape = (array) ( $schema['shape'] ?? array() );
		$out   = array();

		foreach ( array_keys( $value ) as $key ) {
			if ( ! isset( $shape[ $key ] ) ) {
				$this->fail( $this->join( $path, (string) $key ), 'is not a recognised key' );
			}
		}

		foreach ( $shape as $key => $child ) {
			$child     = (array) $child;
			$child_key = (string) $key;
			$has       = array_key_exists( $child_key, $value );

			if ( ! $has ) {
				if ( ! empty( $child['required'] ) ) {
					$this->fail( $this->join( $path, $child_key ), 'is required' );

					continue;
				}

				if ( array_key_exists( 'default', $child ) ) {
					$out[ $child_key ] = $child['default'];
				}

				continue;
			}

			$out[ $child_key ] = $this->node( $value[ $child_key ], $child, $this->join( $path, $child_key ) );
		}

		return $out;
	}

	/**
	 * Records an error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path    Dotted path.
	 * @param string $message Failure description.
	 *
	 * @return null Always null, so callers can return it directly.
	 */
	private function fail( string $path, string $message ): mixed {
		$this->errors[] = ( '' === $path ? 'value' : $path ) . ' ' . $message;

		return null;
	}

	/**
	 * Joins a dotted path segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Existing path.
	 * @param string $key  Segment to append.
	 *
	 * @return string Combined path.
	 */
	private function join( string $path, string $key ): string {
		return '' === $path ? $key : $path . '.' . $key;
	}
}
