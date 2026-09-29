<?php
/**
 * Token substitution tests.
 *
 * Covers the substitution and collapse rules of SPEC.md §8. The WordPress-dependent
 * half of TokenResolver (for_post) is exercised by the M2 integration harness against
 * a real database, since stubbing thirty core functions would test the stubs.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Card\TokenResolver.
 */
final class TokenResolverTest extends TestCase {

	/**
	 * Resolver under test.
	 *
	 * @var TokenResolver
	 */
	private TokenResolver $resolver;

	/**
	 * A representative token map.
	 *
	 * @var array<string, string>
	 */
	private array $tokens;

	/**
	 * Sets up the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resolver = new TokenResolver( new Settings() );
		$this->tokens   = array(
			'title'          => 'Why your WordPress backups are lying to you',
			'share_headline' => 'Your backups are lying',
			'subhead'        => '',
			'site_name'      => 'WP Fundi',
			'author_name'    => 'Chris',
			'primary_term'   => 'Security',
			'reading_time'   => '6 min read',
		);
	}

	/**
	 * A single token is substituted.
	 *
	 * @return void
	 */
	public function test_single_token(): void {
		$this->assertSame( 'WP Fundi', $this->resolver->apply( '{{site_name}}', $this->tokens ) );
	}

	/**
	 * Several tokens and literal text combine.
	 *
	 * @return void
	 */
	public function test_mixed_template(): void {
		$this->assertSame(
			'Chris · 6 min read',
			$this->resolver->apply( '{{author_name}} · {{reading_time}}', $this->tokens )
		);
	}

	/**
	 * The alt-text pattern from SPEC §5.1 resolves.
	 *
	 * @return void
	 */
	public function test_alt_text_pattern(): void {
		$this->assertSame(
			'Your backups are lying — WP Fundi',
			$this->resolver->apply( '{{share_headline}} — {{site_name}}', $this->tokens )
		);
	}

	/**
	 * A pipe list takes the first token that has a value.
	 *
	 * @return void
	 */
	public function test_fallback_chain_takes_first_non_empty(): void {
		$this->assertSame(
			'Your backups are lying',
			$this->resolver->apply( '{{share_headline|title}}', $this->tokens )
		);

		$this->assertSame(
			'Why your WordPress backups are lying to you',
			$this->resolver->apply( '{{subhead|title}}', $this->tokens )
		);
	}

	/**
	 * A missing token resolves to an empty string, not to literal braces.
	 *
	 * @return void
	 */
	public function test_missing_token_resolves_empty(): void {
		$this->assertSame( '', $this->resolver->apply( '{{nonexistent}}', $this->tokens ) );
		$this->assertSame( 'A B', $this->resolver->apply( 'A {{nonexistent}} B', $this->tokens ) );
	}

	/**
	 * An empty token resolves to an empty string.
	 *
	 * @return void
	 */
	public function test_empty_token_resolves_empty(): void {
		$this->assertSame( '', $this->resolver->apply( '{{subhead}}', $this->tokens ) );
	}

	/**
	 * Whitespace inside the braces is tolerated.
	 *
	 * @return void
	 */
	public function test_whitespace_inside_braces(): void {
		$this->assertSame( 'WP Fundi', $this->resolver->apply( '{{  site_name  }}', $this->tokens ) );
	}

	/**
	 * Leftover separators collapse rather than leaving ragged punctuation.
	 *
	 * @return void
	 */
	public function test_whitespace_collapses_around_missing_tokens(): void {
		$this->assertSame(
			'Chris ·',
			$this->resolver->apply( '{{author_name}} · {{nonexistent}}', $this->tokens )
		);
	}

	// ------------------------------------------------------------- collapsing

	/**
	 * A layer whose content resolves to nothing collapses.
	 *
	 * @return void
	 */
	public function test_collapses_when_everything_is_empty(): void {
		$this->assertTrue( $this->resolver->collapses( '{{subhead}}', $this->tokens ) );
		$this->assertTrue( $this->resolver->collapses( '{{nonexistent}}', $this->tokens ) );
		$this->assertTrue( $this->resolver->collapses( '', $this->tokens ) );
		$this->assertTrue( $this->resolver->collapses( '   ', $this->tokens ) );
	}

	/**
	 * A layer with any resolved content does not collapse.
	 *
	 * @return void
	 */
	public function test_does_not_collapse_with_content(): void {
		$this->assertFalse( $this->resolver->collapses( '{{title}}', $this->tokens ) );
		$this->assertFalse( $this->resolver->collapses( '{{subhead|title}}', $this->tokens ) );
	}

	/**
	 * A template of only literal punctuation does not collapse.
	 *
	 * The layer still draws something, so dropping it would change a design silently.
	 *
	 * @return void
	 */
	public function test_literal_only_template_does_not_collapse(): void {
		$this->assertFalse( $this->resolver->collapses( '—', $this->tokens ) );
	}

	// -------------------------------------------------------------- hostile

	/**
	 * A token name containing markup is not treated as a token.
	 *
	 * @return void
	 */
	public function test_malformed_token_is_left_alone(): void {
		$this->assertSame(
			'{{<script>alert(1)</script>}}',
			$this->resolver->apply( '{{<script>alert(1)</script>}}', $this->tokens )
		);
	}

	/**
	 * A token value carrying braces is not re-expanded.
	 *
	 * Recursive expansion would let a post title reach another token's value.
	 *
	 * @return void
	 */
	public function test_token_values_are_not_recursively_expanded(): void {
		$tokens = array(
			'title'  => '{{secret}}',
			'secret' => 'must not appear',
		);

		$this->assertSame( '{{secret}}', $this->resolver->apply( '{{title}}', $tokens ) );
	}

	/**
	 * A template with no tokens is returned as-is.
	 *
	 * @return void
	 */
	public function test_template_without_tokens(): void {
		$this->assertSame( 'Plain text', $this->resolver->apply( 'Plain text', $this->tokens ) );
	}
}
