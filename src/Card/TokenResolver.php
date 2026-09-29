<?php
/**
 * Token resolution.
 *
 * Implements SPEC.md §8.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Support\Hash;
use ChrxDigital\SocialCardStudio\Support\Str;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns `{{token}}` templates into real values.
 *
 * Two halves, deliberately separated: apply() is pure string work over a token map,
 * and for_post() is the WordPress-dependent half that builds the map. That split is
 * what makes the substitution and collapse rules testable without a database.
 *
 * @since 0.1.0
 */
final class TokenResolver {

	/**
	 * Matches `{{name}}` and `{{name|fallback|fallback}}`.
	 */
	private const PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\s*\|\s*[a-z][a-z0-9_]*)*)\s*\}\}/';

	/**
	 * Excerpt ceiling from SPEC §8.
	 */
	private const EXCERPT_CHARS = 160;

	/**
	 * Words per minute used for reading time, per SPEC §8.
	 */
	private const WORDS_PER_MINUTE = 220;

	/**
	 * Avatar cache lifetime, per SPEC §8.
	 */
	private const AVATAR_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings           $settings  Plugin settings.
	 * @param CardDirectory|null $directory Card directory, for the avatar cache.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ?CardDirectory $directory = null
	) {}

	/**
	 * Substitutes tokens into a template.
	 *
	 * A token with no value resolves to an empty string; a pipe list tries each name
	 * in turn and takes the first that has one. Unknown token names resolve empty
	 * rather than being left as literal braces — a card that renders "{{titel}}" to
	 * 40,000 timelines is worse than one that renders nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $template Token-bearing text.
	 * @param array<string, string> $tokens   Token map.
	 *
	 * @return string Resolved text, whitespace-collapsed.
	 */
	public function apply( string $template, array $tokens ): string {
		if ( '' === $template ) {
			return '';
		}

		$resolved = preg_replace_callback(
			self::PATTERN,
			static function ( array $matches ) use ( $tokens ): string {
				foreach ( explode( '|', $matches[1] ) as $name ) {
					$value = (string) ( $tokens[ trim( $name ) ] ?? '' );

					if ( '' !== $value ) {
						return $value;
					}
				}

				return '';
			},
			$template
		);

		return Str::collapse_whitespace( null === $resolved ? '' : $resolved );
	}

	/**
	 * Reports whether a template resolves to nothing.
	 *
	 * Layers whose content collapses are dropped before layout, per SPEC §8 and
	 * §6.3 step 5.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $template Token-bearing text.
	 * @param array<string, string> $tokens   Token map.
	 *
	 * @return bool True when the layer should be collapsed.
	 */
	public function collapses( string $template, array $tokens ): bool {
		return '' === $this->apply( $template, $tokens );
	}

	/**
	 * Builds the token map for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post to resolve against. 0 resolves the site-level tokens only.
	 *
	 * @return array<string, string> Token map.
	 */
	public function for_post( int $post_id ): array {
		$post   = $post_id > 0 ? get_post( $post_id ) : null;
		$tokens = $this->site_tokens();

		if ( $post instanceof WP_Post ) {
			$tokens = array_merge(
				$tokens,
				$this->post_tokens( $post ),
				$this->editorial_tokens( $post ),
				$this->author_tokens( $post )
			);
		}

		/**
		 * Filters the resolved token map.
		 *
		 * The documented escape hatch of SPEC §8 for ACF and arbitrary meta until
		 * native mapping arrives in Phase 4.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $tokens  Resolved tokens.
		 * @param int                   $post_id Post being resolved, 0 for site level.
		 */
		$tokens = (array) apply_filters( 'scstudio_resolve_token', $tokens, $post_id );

		return array_map( static fn ( $value ): string => (string) $value, $tokens );
	}

	/**
	 * Site-level tokens, available with or without a post.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Token map.
	 */
	private function site_tokens(): array {
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'site_name'    => $this->clean( (string) get_bloginfo( 'name' ) ),
			'site_tagline' => $this->clean( (string) get_bloginfo( 'description' ) ),
			'site_logo'    => $this->site_logo(),
			'domain'       => preg_replace( '/^www\./', '', $home ) ?? $home,
			'brand_accent' => (string) $this->settings->get( 'brand.accent', '' ),
		);
	}

	/**
	 * Core post tokens.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being resolved.
	 *
	 * @return array<string, string> Token map.
	 */
	private function post_tokens( WP_Post $post ): array {
		$overrides = get_post_meta( $post->ID, CardRepository::META_OVERRIDES, true );
		$overrides = is_array( $overrides ) ? $overrides : array();

		$title    = $this->clean( (string) get_the_title( $post ) );
		$headline = $this->clean( (string) ( $overrides['headline'] ?? '' ) );
		$subhead  = $this->clean( (string) ( $overrides['subhead'] ?? '' ) );

		$thumbnail = (int) get_post_thumbnail_id( $post );

		$resolved_headline = '' !== $headline ? $headline : $title;

		return array(
			'title'          => $title,
			// Falls through to the title, per SPEC §8.
			'share_headline' => $resolved_headline,

			/*
			 * SPEC §8 names this token `share_headline`, but the default
			 * alt_text_pattern in §5.1 and §7.3 is written as `{{headline}}`. Without
			 * this alias every card's alt text ships as " — Site Name". Both spellings
			 * resolve to the same value rather than making authors learn which
			 * section of the spec a given field came from.
			 */
			'headline'       => $resolved_headline,
			'subhead'        => $subhead,
			'featured_image' => $thumbnail > 0 ? (string) $thumbnail : '',
			'permalink'      => (string) get_permalink( $post ),
		);
	}

	/**
	 * Editorial tokens.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being resolved.
	 *
	 * @return array<string, string> Token map.
	 */
	private function editorial_tokens( WP_Post $post ): array {
		$type    = get_post_type_object( $post->post_type );
		$words   = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		$minutes = max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );

		return array(
			'excerpt'         => Str::truncate_words(
				$this->clean( (string) get_the_excerpt( $post ) ),
				self::EXCERPT_CHARS
			),
			'primary_term'    => $this->primary_term( $post ),
			'date'            => (string) get_the_date( '', $post ),
			'reading_time'    => sprintf(
				/* translators: %s: number of minutes, already localised. */
				_n( '%s min read', '%s min read', $minutes, 'social-card-studio' ),
				number_format_i18n( $minutes )
			),
			'post_type_label' => $type instanceof \WP_Post_Type ? (string) $type->labels->singular_name : '',
		);
	}

	/**
	 * Author tokens.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being resolved.
	 *
	 * @return array<string, string> Token map.
	 */
	private function author_tokens( WP_Post $post ): array {
		$author_id = (int) $post->post_author;

		if ( $author_id <= 0 ) {
			return array(
				'author_name'   => '',
				'author_avatar' => '',
				'author_role'   => '',
			);
		}

		$byline = (string) get_user_meta( $author_id, 'scstudio_byline', true );

		if ( '' === $byline ) {
			$description = (string) get_the_author_meta( 'description', $author_id );
			$lines       = preg_split( '/\r\n|\r|\n/', $description );
			$byline      = $this->clean( is_array( $lines ) ? (string) ( $lines[0] ?? '' ) : '' );
		}

		return array(
			'author_name'   => $this->clean( (string) get_the_author_meta( 'display_name', $author_id ) ),
			'author_avatar' => $this->author_avatar( $author_id ),
			'author_role'   => $byline,
		);
	}

	/**
	 * Resolves the primary term.
	 *
	 * Yoast and Rank Math each record a primary term in their own meta key; either is
	 * preferred over "whatever term happens to sort first", because an editor chose it.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being resolved.
	 *
	 * @return string Term name, or empty.
	 */
	private function primary_term( WP_Post $post ): string {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public || ! $taxonomy->hierarchical ) {
				continue;
			}

			foreach ( array( '_yoast_wpseo_primary_' . $taxonomy->name, 'rank_math_primary_' . $taxonomy->name ) as $key ) {
				$primary = (int) get_post_meta( $post->ID, $key, true );

				if ( $primary > 0 ) {
					$term = get_term( $primary, $taxonomy->name );

					if ( $term instanceof \WP_Term ) {
						return $this->clean( $term->name );
					}
				}
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				return $this->clean( $terms[0]->name );
			}
		}

		return '';
	}

	/**
	 * Resolves the site logo to an attachment ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string Attachment ID as a string, or empty.
	 */
	private function site_logo(): string {
		$logo = (int) get_theme_mod( 'custom_logo' );

		if ( $logo <= 0 ) {
			$logo = (int) get_option( 'site_icon' );
		}

		return $logo > 0 ? (string) $logo : '';
	}

	/**
	 * Resolves the author avatar URL.
	 *
	 * Gravatar is a remote host. Per SPEC §8 this is skipped entirely when avatars are
	 * switched off or external requests are blocked, and the answer is cached for 30
	 * days so that a bulk backfill does not make one request per post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $author_id Author user ID.
	 *
	 * @return string Avatar URL, or empty.
	 */
	private function author_avatar( int $author_id ): string {
		if ( ! get_option( 'show_avatars' ) ) {
			return '';
		}

		$key    = 'scstudio_avatar_' . $author_id;
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			// A cached path whose file has since been pruned must be refetched.
			if ( '' === $cached || is_readable( $cached ) ) {
				return $cached;
			}

			delete_transient( $key );
		}

		$url = (string) get_avatar_url( $author_id, array( 'size' => 96 ) );

		if ( '' === $url ) {
			set_transient( $key, '', self::AVATAR_TTL );

			return '';
		}

		/*
		 * The renderers can only open local files — an image layer whose source is a
		 * URL is refused outright by the Card Document rules in SPEC §18.3. So a
		 * remote avatar is downloaded here, once, and the token resolves to the cached
		 * path rather than to the URL. Returning the URL would mean every avatar layer
		 * silently collapsed.
		 */
		$path = $this->is_remote( $url ) ? $this->cache_remote_avatar( $url ) : $this->local_avatar_path( $url );

		set_transient( $key, $path, self::AVATAR_TTL );

		return $path;
	}

	/**
	 * Downloads a remote avatar into the plugin's cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Remote avatar URL.
	 *
	 * @return string Absolute path, or empty when it could not be fetched.
	 */
	private function cache_remote_avatar( string $url ): string {
		if ( null === $this->directory || ! $this->external_requests_allowed( $url ) ) {
			return '';
		}

		$path = $this->directory->path( 'cache/avatar-' . Hash::of( $url, 16 ) . '.jpg' );

		if ( is_readable( $path ) ) {
			return $path;
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Never trust the response: confirm it decodes as an image before writing it.
		if ( '' === $body || strlen( $body ) > 2 * MB_IN_BYTES ) {
			return '';
		}

		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-image returns false, which is the answer.

		if ( false === $info || ! in_array( (int) $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP ), true ) ) {
			return '';
		}

		wp_mkdir_p( dirname( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem needs a credentials round-trip a render cannot perform.
		return false === file_put_contents( $path, $body ) ? '' : $path;
	}

	/**
	 * Maps a same-site avatar URL to its file on disk.
	 *
	 * A local avatar plugin's URL is used when present, per SPEC §8, but the renderer
	 * still needs a path rather than a URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Local avatar URL.
	 *
	 * @return string Absolute path, or empty.
	 */
	private function local_avatar_path( string $url ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = (string) ( $uploads['baseurl'] ?? '' );

		if ( '' !== $base && str_starts_with( $url, $base ) ) {
			$path = (string) ( $uploads['basedir'] ?? '' ) . substr( $url, strlen( $base ) );

			return is_readable( $path ) ? $path : '';
		}

		return '';
	}

	/**
	 * Reports whether a URL points off-site.
	 *
	 * A local avatar plugin's URL is used as-is; only a genuinely remote one is
	 * subject to the external-request check.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Candidate URL.
	 *
	 * @return bool True when the host differs from this site's.
	 */
	private function is_remote( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return '' !== $host && $host !== $home;
	}

	/**
	 * Reports whether WordPress would allow a request to this host.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Candidate URL.
	 *
	 * @return bool True when the request is permitted.
	 */
	private function external_requests_allowed( string $url ): bool {
		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
			return true;
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host || ! defined( 'WP_ACCESSIBLE_HOSTS' ) ) {
			return false;
		}

		foreach ( array_map( 'trim', explode( ',', (string) WP_ACCESSIBLE_HOSTS ) ) as $accessible ) {
			if ( $accessible === $host || fnmatch( $accessible, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalises a value for use on a card.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return string Normalised value.
	 */
	private function clean( string $value ): string {
		return Str::normalize( $value, 'keep_if_supported' === $this->settings->get( 'emoji', 'strip' ) );
	}
}
