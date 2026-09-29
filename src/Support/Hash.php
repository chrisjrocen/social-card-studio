<?php
/**
 * Hashing helpers.
 *
 * Implements the primitives behind SPEC.md §9.1 (input hash) and §7.2 (the 8-character
 * filename hash that busts platform caches).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic hashes.
 *
 * Every hash here must be stable across requests, servers and PHP versions: a hash
 * that changes for the same inputs would silently regenerate every card on the site.
 * That rules out serialize() and anything involving float formatting or key order.
 *
 * @since 0.1.0
 */
final class Hash {

	/**
	 * Hashes an arbitrary structure.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data   Structure to hash.
	 * @param int   $length Characters to return, 1-40.
	 *
	 * @return string Lowercase hex digest.
	 */
	public static function of( mixed $data, int $length = 16 ): string {
		$length = max( 1, min( 40, $length ) );

		return substr( sha1( self::canonical( $data ) ), 0, $length );
	}

	/**
	 * The 8-character hash used in card filenames.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data Structure to hash.
	 *
	 * @return string Eight hex characters.
	 */
	public static function short( mixed $data ): string {
		return self::of( $data, 8 );
	}

	/**
	 * Serialises a structure to a canonical string.
	 *
	 * Arrays are sorted by key so that two structurally identical inputs built in a
	 * different order hash identically. Floats are formatted at fixed precision
	 * because json_encode's float output varies with serialize_precision.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data Structure to canonicalise.
	 *
	 * @return string Canonical representation.
	 */
	public static function canonical( mixed $data ): string {
		if ( is_array( $data ) ) {
			$normalised = array();

			foreach ( $data as $key => $value ) {
				$normalised[ (string) $key ] = self::canonical( $value );
			}

			ksort( $normalised, SORT_STRING );

			$parts = array();

			foreach ( $normalised as $key => $value ) {
				$parts[] = $key . ':' . $value;
			}

			return '{' . implode( ',', $parts ) . '}';
		}

		if ( is_bool( $data ) ) {
			return $data ? 'true' : 'false';
		}

		if ( null === $data ) {
			return 'null';
		}

		if ( is_float( $data ) ) {
			return rtrim( rtrim( number_format( $data, 6, '.', '' ), '0' ), '.' );
		}

		if ( is_object( $data ) ) {
			return self::canonical( get_object_vars( $data ) );
		}

		return (string) $data;
	}
}
