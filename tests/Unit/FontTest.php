<?php
/**
 * Bundled font and font-signature tests.
 *
 * Covers SPEC.md §6.1 — the bundle that guarantees the chain always terminates, and
 * the signature check that keeps a C font parser from being handed arbitrary bytes.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Render\BundledFonts;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\GdMetrics;
use ChrxDigital\SocialCardStudio\Render\ResolvedFont;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the font layer.
 */
final class FontTest extends TestCase {

	/**
	 * Every bundled file listed in the catalogue is actually shipped.
	 *
	 * The chain in SPEC §6.1 treats the bundle as the step that cannot fail. If a
	 * build drops these files that assumption is silently false.
	 *
	 * @return void
	 */
	public function test_bundle_is_complete(): void {
		$result = BundledFonts::verify();

		$this->assertTrue( $result['ok'], 'missing: ' . implode( ', ', $result['missing'] ) );
	}

	/**
	 * Each bundled family ships its OFL licence.
	 *
	 * @return void
	 */
	public function test_licences_are_committed(): void {
		foreach ( array( 'Inter-OFL.txt', 'SourceSerif4-OFL.txt', 'JetBrainsMono-OFL.txt' ) as $licence ) {
			$path = BundledFonts::directory() . '/' . $licence;

			$this->assertFileExists( $path );
			$this->assertStringContainsString( 'SIL OPEN FONT LICENSE', strtoupper( (string) file_get_contents( $path ) ) );
		}
	}

	/**
	 * Every bundled file is a real sfnt container.
	 *
	 * @return void
	 */
	public function test_bundled_files_have_font_magic(): void {
		$paths = glob( BundledFonts::directory() . '/*.ttf' );

		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			$this->assertTrue(
				FontResolver::has_font_magic( (string) file_get_contents( $path, false, null, 0, 4 ) ),
				basename( $path ) . ' is not a valid font'
			);
		}
	}

	/**
	 * Each role resolves to a bundled default.
	 *
	 * @return void
	 */
	public function test_every_role_has_a_bundled_default(): void {
		foreach ( ResolvedFont::ROLES as $role ) {
			$font = BundledFonts::default_for( $role );

			$this->assertInstanceOf( ResolvedFont::class, $font, $role . ' has no default' );
			$this->assertTrue( $font->is_usable() );
			$this->assertSame( $role, $font->role );
		}
	}

	/**
	 * An unavailable weight falls to the nearest one rather than failing.
	 *
	 * @return void
	 */
	public function test_weight_falls_to_the_nearest_available(): void {
		// Source Serif 4 ships 400 and 700; 600 should land on 700.
		$font = BundledFonts::get( 'source-serif-4', ResolvedFont::ROLE_HEADING, 600 );

		$this->assertInstanceOf( ResolvedFont::class, $font );
		$this->assertSame( 700, $font->weight );
	}

	/**
	 * An exact weight is preferred.
	 *
	 * @return void
	 */
	public function test_exact_weight_wins(): void {
		$font = BundledFonts::get( 'inter', ResolvedFont::ROLE_HEADING, 600 );

		$this->assertInstanceOf( ResolvedFont::class, $font );
		$this->assertSame( 600, $font->weight );
	}

	/**
	 * An unknown family yields null rather than a broken font.
	 *
	 * @return void
	 */
	public function test_unknown_family_returns_null(): void {
		$this->assertNull( BundledFonts::get( 'comic-sans', ResolvedFont::ROLE_HEADING ) );
	}

	/**
	 * The bundled fonts can be opened by GD.
	 *
	 * @return void
	 */
	public function test_bundled_fonts_are_readable_by_gd(): void {
		$path = BundledFonts::directory() . '/Inter-Regular.ttf';

		$this->assertTrue(
			GdMetrics::can_read( $path ),
			'GD could not open the bundled font'
		);
	}

	/**
	 * A font identity changes when the file changes.
	 *
	 * This is what makes a re-uploaded font invalidate cards through the input hash.
	 *
	 * @return void
	 */
	public function test_identity_reflects_the_file(): void {
		$font = BundledFonts::default_for( ResolvedFont::ROLE_HEADING );

		$this->assertInstanceOf( ResolvedFont::class, $font );

		$identity = $font->identity();

		$this->assertGreaterThan( 0, $identity['size'] );
		$this->assertGreaterThan( 0, $identity['modified'] );
		$this->assertSame( 'Inter', $identity['family'] );
	}

	// ----------------------------------------------------------------- magic

	/**
	 * Every accepted signature is recognised.
	 *
	 * @dataProvider provide_valid_magic
	 *
	 * @param string $bytes Leading bytes.
	 *
	 * @return void
	 */
	public function test_valid_font_magic( string $bytes ): void {
		$this->assertTrue( FontResolver::has_font_magic( $bytes ) );
	}

	/**
	 * The four sfnt signatures from SPEC §6.1.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_valid_magic(): array {
		return array(
			array( "\x00\x01\x00\x00rest" ),
			array( 'OTTOrest' ),
			array( 'truerest' ),
			array( 'ttcfrest' ),
		);
	}

	/**
	 * Web-only and hostile formats are refused.
	 *
	 * @dataProvider provide_invalid_magic
	 *
	 * @param string $bytes Leading bytes.
	 *
	 * @return void
	 */
	public function test_invalid_font_magic( string $bytes ): void {
		$this->assertFalse( FontResolver::has_font_magic( $bytes ) );
	}

	/**
	 * Signatures that must never be accepted.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_invalid_magic(): array {
		return array(
			array( 'wOFF' ),                 // WOFF, per SPEC §6.1.
			array( 'wOF2' ),                 // WOFF2.
			array( '<?php phpinfo();' ),     // A script renamed to .ttf.
			array( "\x89PNG\r\n\x1a\n" ),    // An image renamed to .ttf.
			array( 'GIF89a' ),
			array( "\x7fELF" ),              // An executable.
			array( '' ),
			array( 'PK' ),                   // A zip.
		);
	}
}
