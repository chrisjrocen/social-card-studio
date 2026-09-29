<?php
/**
 * Text normalisation tests.
 *
 * Covers SPEC.md §6.3 step 1 and the emoji policy of §6.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Support\Str.
 */
final class StrTest extends TestCase {

	/**
	 * Shortcodes, tags and entities are all removed.
	 *
	 * @return void
	 */
	public function test_normalize_strips_markup_and_entities(): void {
		$this->assertSame(
			'Backups & restores',
			Str::normalize( '[caption]<strong>Backups</strong> &amp; restores[/caption]' )
		);
	}

	/**
	 * Whitespace runs collapse to single spaces.
	 *
	 * @return void
	 */
	public function test_whitespace_collapses(): void {
		$this->assertSame( 'a b c', Str::collapse_whitespace( "a\n\n  b\t\tc  " ) );
	}

	/**
	 * Non-breaking and zero-width spaces are normalised too.
	 *
	 * @return void
	 */
	public function test_exotic_spaces_collapse(): void {
		$this->assertSame( 'a b', Str::collapse_whitespace( "a\xC2\xA0\xE2\x80\x8Bb" ) );
	}

	/**
	 * Emoji are stripped by default, per the GD constraint in SPEC §6.2.
	 *
	 * @return void
	 */
	public function test_emoji_are_stripped_by_default(): void {
		$this->assertSame( 'Ship it', Str::normalize( 'Ship it 🚀' ) );
	}

	/**
	 * Emoji survive when the caller opts in.
	 *
	 * @return void
	 */
	public function test_emoji_can_be_kept(): void {
		$this->assertStringContainsString( '🚀', Str::normalize( 'Ship it 🚀', true ) );
	}

	/**
	 * Stripping emoji does not damage accented Latin or Amharic text.
	 *
	 * @return void
	 */
	public function test_stripping_emoji_preserves_other_scripts(): void {
		$this->assertSame( 'Lúgandá', Str::normalize( 'Lúgandá 😀' ) );
		$this->assertSame( 'የተሻለ', Str::normalize( 'የተሻለ 🎉' ) );
	}

	/**
	 * Truncation lands on a word boundary.
	 *
	 * @return void
	 */
	public function test_truncate_respects_word_boundaries(): void {
		$result = Str::truncate_words( 'Why your WordPress backups are lying to you', 20 );

		$this->assertStringEndsWith( '…', $result );
		$this->assertStringNotContainsString( 'backu…', $result );
	}

	/**
	 * Short strings are returned untouched.
	 *
	 * @return void
	 */
	public function test_truncate_leaves_short_strings_alone(): void {
		$this->assertSame( 'Short', Str::truncate_words( 'Short', 40 ) );
	}

	/**
	 * Grapheme splitting keeps combining marks attached, so the layout engine can
	 * never break mid-cluster (SPEC §6.3 step 2b).
	 *
	 * @return void
	 */
	public function test_graphemes_do_not_split_combining_marks(): void {
		$clusters = Str::graphemes( "e\u{0301}t" );

		$this->assertCount( 2, $clusters );
		$this->assertSame( "e\u{0301}", $clusters[0] );
	}

	/**
	 * An empty string yields no clusters.
	 *
	 * @return void
	 */
	public function test_graphemes_of_empty_string(): void {
		$this->assertSame( array(), Str::graphemes( '' ) );
	}
}
