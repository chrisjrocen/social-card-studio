<?php
/**
 * Image source validation.
 *
 * Implements the source restriction of SPEC.md §18.3: "image sources restricted to
 * local attachments, plugin-managed files, or an explicit host allowlist".
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an image layer's source may be drawn.
 *
 * Three forms are accepted and nothing else:
 *   - a token, e.g. `{{featured_image}}` — resolved later, never a path
 *   - an attachment ID, e.g. `44` — resolved through WordPress
 *   - an absolute path inside an allowed root, e.g. the plugin's assets directory
 *
 * Everything else is refused, including every URL scheme. A Card Document can be
 * authored by anyone who can edit a template, so treating its `source` as a file path
 * without constraint would turn template editing into arbitrary file disclosure.
 *
 * @since 0.1.0
 */
final class ImageSource {

	public const KIND_TOKEN      = 'token';
	public const KIND_ATTACHMENT = 'attachment';
	public const KIND_PATH       = 'path';
	public const KIND_INVALID    = 'invalid';

	/**
	 * A single token, optionally with fallback filters: {{a}} or {{a|b}}.
	 */
	private const TOKEN_PATTERN = '/^\{\{[a-z][a-z0-9_]*(\|[a-z][a-z0-9_]*)*\}\}$/';

	/**
	 * File extensions the renderers will open.
	 */
	private const EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * Classifies a source string.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $source        Raw source value.
	 * @param string[] $allowed_roots Absolute directories a path source may live in.
	 *
	 * @return array{kind: string, value: string, reason: string} Classification.
	 */
	public static function classify( string $source, array $allowed_roots = array() ): array {
		$source = trim( $source );

		if ( '' === $source ) {
			return self::invalid( 'is empty' );
		}

		if ( preg_match( self::TOKEN_PATTERN, $source ) ) {
			return array(
				'kind'   => self::KIND_TOKEN,
				'value'  => $source,
				'reason' => '',
			);
		}

		if ( preg_match( '/^\d+$/', $source ) ) {
			return array(
				'kind'   => self::KIND_ATTACHMENT,
				'value'  => $source,
				'reason' => '',
			);
		}

		// Any scheme at all is refused, including http, https, file, data, phar and php.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $source ) || str_starts_with( $source, '//' ) ) {
			return self::invalid( 'must not be a URL — use an attachment ID or a token' );
		}

		return self::classify_path( $source, $allowed_roots );
	}

	/**
	 * Reports whether a source is acceptable.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $source        Raw source value.
	 * @param string[] $allowed_roots Absolute directories a path source may live in.
	 *
	 * @return bool True when the source may be used.
	 */
	public static function is_valid( string $source, array $allowed_roots = array() ): bool {
		return self::KIND_INVALID !== self::classify( $source, $allowed_roots )['kind'];
	}

	/**
	 * Validates a filesystem source.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $source        Candidate path.
	 * @param string[] $allowed_roots Absolute directories the path may live in.
	 *
	 * @return array{kind: string, value: string, reason: string} Classification.
	 */
	private static function classify_path( string $source, array $allowed_roots ): array {
		if ( str_contains( $source, "\0" ) ) {
			return self::invalid( 'contains a null byte' );
		}

		/*
		 * Traversal is rejected on the literal string before any filesystem call.
		 * realpath() returns false for a path that does not exist yet, which would
		 * otherwise let "../../wp-config.php" through on the technicality that the
		 * resolved path could not be computed.
		 */
		foreach ( explode( '/', str_replace( '\\', '/', $source ) ) as $segment ) {
			if ( '..' === $segment ) {
				return self::invalid( 'must not contain a path traversal segment' );
			}
		}

		if ( array() === $allowed_roots ) {
			return self::invalid( 'is a file path, but no allowed root is configured' );
		}

		$extension = strtolower( (string) pathinfo( $source, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			return self::invalid(
				sprintf( 'must be one of: %s', implode( ', ', self::EXTENSIONS ) )
			);
		}

		$real = realpath( $source );
		$path = false === $real ? $source : $real;

		foreach ( $allowed_roots as $root ) {
			$root_real = realpath( $root );
			$root_path = false === $root_real ? $root : $root_real;
			$root_path = rtrim( str_replace( '\\', '/', $root_path ), '/' ) . '/';

			if ( str_starts_with( str_replace( '\\', '/', $path ), $root_path ) ) {
				return array(
					'kind'   => self::KIND_PATH,
					'value'  => $path,
					'reason' => '',
				);
			}
		}

		return self::invalid( 'resolves outside every allowed directory' );
	}

	/**
	 * Builds a rejection.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Why the source was refused.
	 *
	 * @return array{kind: string, value: string, reason: string} Classification.
	 */
	private static function invalid( string $reason ): array {
		return array(
			'kind'   => self::KIND_INVALID,
			'value'  => '',
			'reason' => $reason,
		);
	}
}
