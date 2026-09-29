<?php
/**
 * Plugin uploads directory.
 *
 * Implements SPEC.md §5.3 (canonical file storage) and the directory hardening of
 * §18.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and provisions uploads/social-card-studio/.
 *
 * Paths are always derived from wp_upload_dir() at call time and never cached across
 * a request. Multisite resolves to uploads/sites/{blog_id}/ automatically, and a
 * cached path would survive a switch_to_blog() and write one site's cards into
 * another's directory (SPEC §16). A migration between domains needs no search-replace
 * for the same reason (SPEC §18.2).
 *
 * @since 0.1.0
 */
final class CardDirectory {

	public const FOLDER = 'social-card-studio';

	/**
	 * Absolute path to the plugin's uploads directory, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sub Optional subdirectory, e.g. 'fonts' or '2026/09'.
	 *
	 * @return string Filesystem path.
	 */
	public function path( string $sub = '' ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] ) . self::FOLDER;

		/**
		 * Filters the card storage directory.
		 *
		 * Documented in SPEC §5.3 as the offload extension point.
		 *
		 * @since 0.1.0
		 *
		 * @param string $base Absolute path without a trailing slash.
		 */
		$base = (string) apply_filters( 'scstudio_card_dir', $base );

		return '' === $sub ? $base : trailingslashit( $base ) . trim( $sub, '/\\' );
	}

	/**
	 * Public URL of the plugin's uploads directory, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sub Optional subdirectory.
	 *
	 * @return string URL.
	 */
	public function url( string $sub = '' ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['baseurl'] ) . self::FOLDER;

		/**
		 * Filters the card storage URL.
		 *
		 * Documented in SPEC §5.3 as the offload extension point.
		 *
		 * @since 0.1.0
		 *
		 * @param string $base URL without a trailing slash.
		 */
		$base = (string) apply_filters( 'scstudio_card_url', $base );

		return '' === $sub ? $base : trailingslashit( $base ) . trim( $sub, '/\\' );
	}

	/**
	 * Creates the directory tree and its guard files.
	 *
	 * Safe to call repeatedly; it only writes what is missing.
	 *
	 * @since 0.1.0
	 *
	 * @return array{ok: bool, errors: string[]} Provisioning result.
	 */
	public function provision(): array {
		$errors = array();

		foreach ( array( '', 'fonts', 'cache' ) as $sub ) {
			$dir = $this->path( $sub );

			if ( ! wp_mkdir_p( $dir ) ) {
				$errors[] = sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the directory %s.', 'social-card-studio' ),
					$dir
				);

				continue;
			}

			$this->write_if_missing( trailingslashit( $dir ) . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		$this->write_if_missing( trailingslashit( $this->path() ) . '.htaccess', $this->htaccess() );

		return array(
			'ok'     => array() === $errors,
			'errors' => $errors,
		);
	}

	/**
	 * Reports whether the directory exists and is writable.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when cards can be written.
	 */
	public function is_writable(): bool {
		$dir = $this->path();

		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Reports whether the hardening files are in place.
	 *
	 * @since 0.1.0
	 *
	 * @return array{index: bool, htaccess: bool} Presence of each guard file.
	 */
	public function guards(): array {
		$base = trailingslashit( $this->path() );

		return array(
			'index'    => file_exists( $base . 'index.php' ),
			'htaccess' => file_exists( $base . '.htaccess' ),
		);
	}

	/**
	 * Contents of the .htaccess guard.
	 *
	 * Denies execution of anything but images, per SPEC §5.3. Apache-only by nature;
	 * nginx sites get the index.php guard and are told in Diagnostics that the
	 * .htaccess does nothing for them.
	 *
	 * @since 0.1.0
	 *
	 * @return string File contents.
	 */
	private function htaccess(): string {
		return <<<'HTACCESS'
			# Social Card Studio — deny execution of anything but images.
			<IfModule mod_authz_core.c>
				Require all denied
			</IfModule>
			<IfModule !mod_authz_core.c>
				Order deny,allow
				Deny from all
			</IfModule>

			<FilesMatch "\.(jpe?g|png|gif|webp|ttf|otf)$">
				<IfModule mod_authz_core.c>
					Require all granted
				</IfModule>
				<IfModule !mod_authz_core.c>
					Order allow,deny
					Allow from all
				</IfModule>
			</FilesMatch>

			<IfModule mod_php.c>
				php_flag engine off
			</IfModule>
			HTACCESS;
	}

	/**
	 * Writes a file only when it does not already exist.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 *
	 * @return void
	 */
	private function write_if_missing( string $path, string $contents ): void {
		if ( file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem needs a credentials round-trip that activation cannot do; a read-only uploads directory is reported by provision() rather than fataling here.
		@file_put_contents( $path, $contents );
	}
}
