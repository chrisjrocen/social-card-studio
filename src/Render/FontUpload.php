<?php
/**
 * Font upload handling.
 *
 * Implements the upload rules of SPEC.md §6.1 and the validation requirements of
 * §18.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Storage\CardDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and stores admin-uploaded fonts.
 *
 * A font file is parsed by a C library running in the web process, so an upload path
 * that trusts the extension is a real attack surface. Type is decided by magic bytes;
 * the filename is only ever used to build a sanitised destination.
 *
 * @since 0.1.0
 */
final class FontUpload {

	/**
	 * Size ceiling from SPEC §6.1.
	 */
	public const MAX_BYTES = 5 * 1024 * 1024;

	/**
	 * Accepted extensions.
	 */
	public const EXTENSIONS = array( 'ttf', 'otf' );

	/**
	 * Magic byte sequences for the sfnt container formats, per SPEC §6.1.
	 */
	private const MAGIC = array(
		"\x00\x01\x00\x00", // TrueType outlines.
		'OTTO',             // CFF outlines.
		'true',             // Legacy Apple TrueType.
		'ttcf',             // TrueType collection.
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDirectory $directory Card directory resolver.
	 */
	public function __construct( private readonly CardDirectory $directory ) {}

	/**
	 * Reports whether a byte string starts with a font signature.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bytes At least the first four bytes of a candidate file.
	 *
	 * @return bool True when the signature matches a usable format.
	 */
	public static function has_font_magic( string $bytes ): bool {
		foreach ( self::MAGIC as $magic ) {
			if ( str_starts_with( $bytes, $magic ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates and stores an uploaded font.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $file One entry from $_FILES.
	 *
	 * @return array{ok: bool, path: string, error: string} Result.
	 */
	public function store( array $file ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( __( 'You do not have permission to upload fonts.', 'social-card-studio' ) );
		}

		if ( ! isset( $file['tmp_name'], $file['name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return $this->error( __( 'No font file was received.', 'social-card-studio' ) );
		}

		$tmp       = (string) $file['tmp_name'];
		$name      = sanitize_file_name( (string) $file['name'] );
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'woff', 'woff2' ), true ) ) {
			return $this->error(
				__( 'WOFF and WOFF2 fonts cannot be used. They are compressed web formats, and the PHP image libraries that draw your cards can only read TrueType (.ttf) and OpenType (.otf). Convert the file first — the FontSquirrel Webfont Generator and the fontTools "woff2_decompress" command both do this — then upload the result.', 'social-card-studio' )
			);
		}

		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			return $this->error(
				__( 'Only .ttf and .otf font files can be uploaded.', 'social-card-studio' )
			);
		}

		$size = (int) ( $file['size'] ?? filesize( $tmp ) );

		if ( $size > self::MAX_BYTES ) {
			return $this->error(
				sprintf(
					/* translators: %s: maximum size, already formatted. */
					__( 'The font is larger than the %s limit.', 'social-card-studio' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading four magic bytes of the uploaded temp file, which WP_Filesystem cannot address.
		$magic = (string) file_get_contents( $tmp, false, null, 0, 4 );

		if ( ! self::has_font_magic( $magic ) ) {
			return $this->error(
				__( 'That file is not a TrueType or OpenType font, whatever its name suggests.', 'social-card-studio' )
			);
		}

		$destination = $this->directory->path( 'fonts/' . $name );

		if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
			return $this->error( __( 'The font directory could not be created.', 'social-card-studio' ) );
		}

		if ( ! move_uploaded_file( $tmp, $destination ) ) {
			return $this->error( __( 'The font could not be saved.', 'social-card-studio' ) );
		}

		// A font that neither engine can open is worse than no font: delete it now
		// rather than discovering it when a card renders blank.
		if ( ! GdMetrics::can_read( $destination ) && ! ImagickMetrics::can_read( $destination ) ) {
			wp_delete_file( $destination );

			return $this->error(
				__( 'The font uploaded, but neither image library on this server could open it, so it has been removed.', 'social-card-studio' )
			);
		}

		return array(
			'ok'    => true,
			'path'  => $destination,
			'error' => '',
		);
	}

	/**
	 * Lists stored uploads.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Filenames.
	 */
	public function all(): array {
		$found = glob( $this->directory->path( 'fonts' ) . '/*.{ttf,otf}', GLOB_BRACE );

		return is_array( $found ) ? array_map( 'basename', $found ) : array();
	}

	/**
	 * Deletes a stored upload.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Filename, without a path.
	 *
	 * @return bool True when removed.
	 */
	public function delete( string $name ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$path = $this->directory->path( 'fonts/' . sanitize_file_name( basename( $name ) ) );

		if ( ! is_readable( $path ) ) {
			return false;
		}

		wp_delete_file( $path );

		return true;
	}

	/**
	 * Builds a failure result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Human-readable reason.
	 *
	 * @return array{ok: bool, path: string, error: string} Result.
	 */
	private function error( string $message ): array {
		return array(
			'ok'    => false,
			'path'  => '',
			'error' => $message,
		);
	}
}
