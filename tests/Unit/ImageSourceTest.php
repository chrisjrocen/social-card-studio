<?php
/**
 * Image source classification tests.
 *
 * Covers the source restriction of SPEC.md §18.3 directly, rather than only through
 * a whole document.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\ImageSource;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Card\ImageSource.
 */
final class ImageSourceTest extends TestCase {

	/**
	 * Tokens are recognised.
	 *
	 * @return void
	 */
	public function test_tokens_are_accepted(): void {
		$this->assertSame( ImageSource::KIND_TOKEN, ImageSource::classify( '{{featured_image}}' )['kind'] );
		$this->assertSame( ImageSource::KIND_TOKEN, ImageSource::classify( '{{featured_image|site_logo}}' )['kind'] );
	}

	/**
	 * Attachment IDs are recognised.
	 *
	 * @return void
	 */
	public function test_attachment_ids_are_accepted(): void {
		$this->assertSame( ImageSource::KIND_ATTACHMENT, ImageSource::classify( '44' )['kind'] );
	}

	/**
	 * A file inside an allowed root is accepted.
	 *
	 * @return void
	 */
	public function test_path_inside_allowed_root_is_accepted(): void {
		$root = sys_get_temp_dir() . '/scstudio-test-root';

		if ( ! is_dir( $root ) ) {
			mkdir( $root, 0777, true );
		}

		$file = $root . '/bg.jpg';
		file_put_contents( $file, 'x' );

		$result = ImageSource::classify( $file, array( $root ) );

		unlink( $file );
		rmdir( $root );

		$this->assertSame( ImageSource::KIND_PATH, $result['kind'], $result['reason'] );
	}

	/**
	 * A file outside every allowed root is refused.
	 *
	 * @return void
	 */
	public function test_path_outside_allowed_root_is_rejected(): void {
		$result = ImageSource::classify( '/etc/hosts.jpg', array( sys_get_temp_dir() ) );

		$this->assertSame( ImageSource::KIND_INVALID, $result['kind'] );
		$this->assertStringContainsString( 'outside', $result['reason'] );
	}

	/**
	 * Traversal is caught on the literal string, before any filesystem call.
	 *
	 * A non-existent path makes realpath() return false, so a check that relied on it
	 * would let this through on a technicality.
	 *
	 * @dataProvider provide_traversals
	 *
	 * @param string $source Traversal attempt.
	 *
	 * @return void
	 */
	public function test_traversal_is_rejected( string $source ): void {
		$result = ImageSource::classify( $source, array( sys_get_temp_dir() ) );

		$this->assertSame( ImageSource::KIND_INVALID, $result['kind'], $source . ' should be refused' );
		$this->assertStringContainsString( 'traversal', $result['reason'] );
	}

	/**
	 * Traversal attempts.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_traversals(): array {
		return array(
			array( '../../wp-config.php' ),
			array( '/tmp/../etc/passwd.jpg' ),
			array( 'images/../../../../etc/shadow.png' ),
			array( '..\\..\\wp-config.php' ),
		);
	}

	/**
	 * A null byte is refused.
	 *
	 * @return void
	 */
	public function test_null_byte_is_rejected(): void {
		$result = ImageSource::classify( "/tmp/ok.jpg\0.php", array( sys_get_temp_dir() ) );

		$this->assertSame( ImageSource::KIND_INVALID, $result['kind'] );
	}

	/**
	 * A non-image extension is refused even inside an allowed root.
	 *
	 * @return void
	 */
	public function test_non_image_extension_is_rejected(): void {
		$result = ImageSource::classify( sys_get_temp_dir() . '/payload.php', array( sys_get_temp_dir() ) );

		$this->assertSame( ImageSource::KIND_INVALID, $result['kind'] );
	}

	/**
	 * A path is refused outright when no root is configured.
	 *
	 * @return void
	 */
	public function test_path_without_configured_root_is_rejected(): void {
		$this->assertFalse( ImageSource::is_valid( '/tmp/anything.jpg' ) );
	}

	/**
	 * An empty source is refused.
	 *
	 * @return void
	 */
	public function test_empty_source_is_rejected(): void {
		$this->assertFalse( ImageSource::is_valid( '' ) );
		$this->assertFalse( ImageSource::is_valid( '   ' ) );
	}

	/**
	 * A malformed token is not treated as a token.
	 *
	 * @return void
	 */
	public function test_malformed_token_is_rejected(): void {
		$this->assertFalse( ImageSource::is_valid( '{{ featured image }}' ) );
		$this->assertFalse( ImageSource::is_valid( '{{featured_image' ) );
	}
}
