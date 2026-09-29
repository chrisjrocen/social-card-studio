<?php
/**
 * Script detection tests.
 *
 * Covers SPEC.md §6.2 — the rule is "correct or fallback, never mangled", and this is
 * what decides which of the two happens.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Render\Script;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Render\Script.
 */
final class ScriptTest extends TestCase {

	/**
	 * Scripts are identified.
	 *
	 * @dataProvider provide_scripts
	 *
	 * @param string $text     Sample text.
	 * @param string $expected Expected script.
	 *
	 * @return void
	 */
	public function test_detects_script( string $text, string $expected ): void {
		$this->assertSame( $expected, Script::detect( $text ) );
	}

	/**
	 * Representative samples.
	 *
	 * @return array<int, array{0: string, 1: string}> Test cases.
	 */
	public static function provide_scripts(): array {
		return array(
			array( 'Why your backups are lying', Script::LATIN ),
			array( 'Lúgandá ná Kiswahíli', Script::LATIN ),
			array( 'Резервные копии', Script::CYRILLIC ),
			array( 'Αντίγραφα ασφαλείας', Script::GREEK ),
			array( 'שלום עולם', Script::HEBREW ),
			array( 'لماذا تكذب نسخك', Script::ARABIC ),
			array( 'आपका बैकअप', Script::DEVANAGARI ),
			array( 'የተሻለ ምትኬ', Script::ETHIOPIC ),
			array( '备份在撒谎', Script::HAN ),
			array( '', Script::COMMON ),
			array( '— · 123 !?', Script::COMMON ),
		);
	}

	/**
	 * Detection is by majority, not by first character.
	 *
	 * A Latin headline containing one Greek symbol must not be diverted into the
	 * fallback chain over a single glyph.
	 *
	 * @return void
	 */
	public function test_detection_is_by_majority(): void {
		$this->assertSame( Script::LATIN, Script::detect( 'The Ω constant explained in detail' ) );
	}

	/**
	 * Shaping detection asks "any", not "dominant".
	 *
	 * A mostly-Latin headline with one Arabic word still cannot be drawn correctly,
	 * so it must not slip through.
	 *
	 * @return void
	 */
	public function test_shaping_detection_catches_a_minority_script(): void {
		$this->assertSame( Script::LATIN, Script::detect( 'A post about the word مرحبا today' ) );
		$this->assertTrue( Script::needs_shaping( 'A post about the word مرحبا today' ) );
	}

	/**
	 * Scripts needing contextual shaping are flagged.
	 *
	 * @return void
	 */
	public function test_shaping_requirements(): void {
		$this->assertTrue( Script::needs_shaping( 'لماذا تكذب' ) );
		$this->assertTrue( Script::needs_shaping( 'आपका बैकअप' ) );
		$this->assertTrue( Script::needs_shaping( 'ไทย' ) );

		$this->assertFalse( Script::needs_shaping( 'Plain Latin' ) );
		$this->assertFalse( Script::needs_shaping( 'שלום עולם' ) );
		$this->assertFalse( Script::needs_shaping( 'የተሻለ ምትኬ' ) );
		$this->assertFalse( Script::needs_shaping( 'Резервные копии' ) );
	}

	/**
	 * Right-to-left scripts are identified.
	 *
	 * @return void
	 */
	public function test_rtl_detection(): void {
		$this->assertTrue( Script::is_rtl( 'שלום עולם' ) );
		$this->assertTrue( Script::is_rtl( 'لماذا تكذب' ) );
		$this->assertFalse( Script::is_rtl( 'Plain Latin' ) );
		$this->assertFalse( Script::is_rtl( 'የተሻለ ምትኬ' ) );
	}

	/**
	 * This environment cannot shape, so complex scripts must fall back.
	 *
	 * @return void
	 */
	public function test_shaping_is_unavailable_in_php(): void {
		$this->assertFalse( Script::can_shape() );
	}

	/**
	 * Reversal keeps grapheme clusters intact.
	 *
	 * @return void
	 */
	public function test_reverse_preserves_clusters(): void {
		$this->assertSame( 'cba', Script::reverse_logical( 'abc' ) );

		$reversed = Script::reverse_logical( "e\u{0301}x" );

		$this->assertSame( "xe\u{0301}", $reversed );
	}

	/**
	 * Counting ignores punctuation and digits.
	 *
	 * @return void
	 */
	public function test_counts_ignore_common_characters(): void {
		$counts = Script::counts( 'abc — 123 !?' );

		$this->assertSame( array( Script::LATIN => 3 ), $counts );
	}
}
