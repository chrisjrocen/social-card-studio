<?php
/**
 * PSR-4 fallback autoloader.
 *
 * Used only when vendor/autoload.php is absent. Composer's autoloader is the one that
 * ships; this exists so the plugin boots from a clean checkout without a Composer run.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ChrxDigital\\SocialCardStudio\\';

		if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = SCSTUDIO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
