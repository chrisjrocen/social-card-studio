<?php
/**
 * Alt text generation.
 *
 * Implements SPEC.md §7.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the alt text stored on every card record.
 *
 * A generated image with no alt text is an accessibility failure that ships to every
 * timeline, so this always produces something: the pattern first, the headline alone
 * if the pattern resolves empty, and the site name as the last resort.
 *
 * @since 0.1.0
 */
final class AltText {

	/**
	 * Length ceiling.
	 *
	 * Screen readers announce alt text in full, and platforms truncate around this
	 * anyway.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings      $settings Plugin settings.
	 * @param TokenResolver $tokens   Token substitution.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly TokenResolver $tokens
	) {}

	/**
	 * Generates alt text from a token map.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $token_map Resolved tokens.
	 * @param string                $override  Author-supplied alt text, if any.
	 *
	 * @return string Alt text, never empty when the site has a name.
	 */
	public function generate( array $token_map, string $override = '' ): string {
		$override = Str::collapse_whitespace( $override );

		if ( '' !== $override ) {
			return Str::truncate_words( $override, self::MAX_LENGTH );
		}

		$pattern = (string) $this->settings->get( 'alt_text_pattern', '{{headline}} — {{site_name}}' );
		$alt     = $this->tokens->apply( $pattern, $token_map );

		// A pattern whose tokens all resolved empty leaves punctuation behind.
		$alt = trim( $alt, " \t\n\r\0\x0B—–-·|" );
		$alt = Str::collapse_whitespace( $alt );

		if ( '' === $alt ) {
			$alt = (string) ( $token_map['headline'] ?? $token_map['share_headline'] ?? $token_map['title'] ?? '' );
		}

		if ( '' === $alt ) {
			$alt = (string) ( $token_map['site_name'] ?? '' );
		}

		/**
		 * Filters the generated alt text.
		 *
		 * @since 0.1.0
		 *
		 * @param string                $alt       Generated alt text.
		 * @param array<string, string> $token_map Resolved tokens.
		 */
		$alt = (string) apply_filters( 'scstudio_card_alt_text', $alt, $token_map );

		return Str::truncate_words( Str::collapse_whitespace( $alt ), self::MAX_LENGTH );
	}
}
