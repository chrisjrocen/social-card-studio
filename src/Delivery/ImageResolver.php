<?php
/**
 * The fallback chain.
 *
 * Implements SPEC.md §12.1 in full: seven steps, each verified before use.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

use ChrxDigital\SocialCardStudio\Card\CardRecord;
use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Generation\CardGenerator;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which image a post should advertise.
 *
 * The chain is walked top-down and every step is verified before it is accepted. A
 * card record whose file has been deleted, an attachment whose file is missing, a
 * logo too small for a platform to use — each is skipped rather than emitted, because
 * a broken og:image gets cached by the platform and outlives the mistake.
 *
 * @since 0.1.0
 */
final class ImageResolver {

	/**
	 * Smallest image a platform will reliably use, per SPEC §7.1 and §12.1.
	 */
	public const MIN_WIDTH  = 600;
	public const MIN_HEIGHT = 315;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardRepository $repository Card meta storage.
	 * @param CardDirectory  $directory  Card directory resolver.
	 * @param CardGenerator  $generator  Generator, for the homepage card record.
	 * @param \Closure       $seo        Returns the SeoDetector, resolved at call time.
	 */
	public function __construct(
		private readonly CardRepository $repository,
		private readonly CardDirectory $directory,
		private readonly CardGenerator $generator,
		private readonly \Closure $seo
	) {}

	/**
	 * The SEO detector.
	 *
	 * Resolved through a closure rather than injected directly, because the two
	 * genuinely depend on each other: the chain asks the detector for the SEO
	 * plugin's manually chosen image, and each integration asks the chain for a card.
	 * Constructing both eagerly is an infinite loop, so one side has to defer.
	 *
	 * @since 0.1.0
	 *
	 * @return SeoDetector The detector.
	 */
	private function seo(): SeoDetector {
		return ( $this->seo )();
	}

	/**
	 * Resolves the image for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id       Post ID, 0 for the front page.
	 * @param bool $allow_seo_own Whether step 3 may return the SEO plugin's own manual image.
	 *
	 * @return ResolvedImage|null A verified image, or null to emit nothing.
	 */
	public function resolve( int $post_id, bool $allow_seo_own = true ): ?ResolvedImage {
		/**
		 * Filters the fallback chain.
		 *
		 * Documented in SPEC §21. Each entry is a callable returning a ResolvedImage
		 * or null; the first non-null wins.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, callable(int): ?ResolvedImage> $chain   The chain.
		 * @param int                                       $post_id Post being resolved.
		 */
		$chain = (array) apply_filters(
			'scstudio_fallback_chain',
			array(
				fn ( int $id ): ?ResolvedImage => $this->from_card( $id, false ),
				fn ( int $id ): ?ResolvedImage => $this->from_card( $id, true ),
				fn ( int $id ): ?ResolvedImage => $allow_seo_own ? $this->from_seo_manual( $id ) : null,
				fn ( int $id ): ?ResolvedImage => $this->from_featured( $id ),
				// These two are site-wide; the parameter is kept so every entry in the
				// chain — including one added through the filter — has one signature.
				fn ( int $id ): ?ResolvedImage => $this->from_site_card(), // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				fn ( int $id ): ?ResolvedImage => $this->from_logo(), // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			),
			$post_id
		);

		foreach ( $chain as $step ) {
			if ( ! is_callable( $step ) ) {
				continue;
			}

			$resolved = $step( $post_id );

			if ( $resolved instanceof ResolvedImage && '' !== $resolved->url ) {
				return $resolved;
			}
		}

		// Step 7: emit nothing rather than something unverified.
		return null;
	}

	/**
	 * Steps 1 and 2: the post's own card.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id     Post ID.
	 * @param bool $allow_stale Whether to accept a card whose hash no longer matches.
	 *
	 * @return ResolvedImage|null Resolved image, or null.
	 */
	private function from_card( int $post_id, bool $allow_stale ): ?ResolvedImage {
		if ( $post_id <= 0 ) {
			return null;
		}

		$record = $this->repository->get( $post_id );

		if ( null === $record || ! $record->exists( $this->directory ) ) {
			return null;
		}

		$post  = get_post( $post_id );
		$stale = $post instanceof WP_Post && $this->repository->is_stale( $post );

		if ( $stale && ! $allow_stale ) {
			return null;
		}

		if ( $stale && ! $this->serve_stale() ) {
			return null;
		}

		return $this->from_record( $record, $stale ? ResolvedImage::STEP_STALE : ResolvedImage::STEP_FRESH );
	}

	/**
	 * Step 3: an image a human set in the SEO plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return ResolvedImage|null Resolved image, or null.
	 */
	private function from_seo_manual( int $post_id ): ?ResolvedImage {
		$integration = $this->seo()->active();

		if ( null === $integration || $post_id <= 0 || ! $integration->has_manual_image( $post_id ) ) {
			return null;
		}

		$attachment = $integration->manual_image_id( $post_id );

		if ( $attachment > 0 ) {
			return $this->from_attachment( $attachment, ResolvedImage::STEP_SEO_MANUAL );
		}

		$url = $integration->manual_image_url( $post_id );

		// A URL we cannot measure is still the site owner's explicit choice, so it is
		// emitted without dimensions rather than discarded.
		return '' === $url ? null : new ResolvedImage( $url, 0, 0, 'image/jpeg', '', ResolvedImage::STEP_SEO_MANUAL );
	}

	/**
	 * Step 4: the featured image.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return ResolvedImage|null Resolved image, or null.
	 */
	private function from_featured( int $post_id ): ?ResolvedImage {
		if ( $post_id <= 0 ) {
			return null;
		}

		$attachment = (int) get_post_thumbnail_id( $post_id );

		return $attachment > 0 ? $this->from_attachment( $attachment, ResolvedImage::STEP_FEATURED ) : null;
	}

	/**
	 * Step 5: the site default card.
	 *
	 * @since 0.1.0
	 *
	 * @return ResolvedImage|null Resolved image, or null.
	 */
	private function from_site_card(): ?ResolvedImage {
		$record = $this->generator->home_record();

		if ( null === $record || ! $record->exists( $this->directory ) ) {
			return null;
		}

		return $this->from_record( $record, ResolvedImage::STEP_SITE_CARD );
	}

	/**
	 * Step 6: the custom logo or site icon.
	 *
	 * @since 0.1.0
	 *
	 * @return ResolvedImage|null Resolved image, or null.
	 */
	private function from_logo(): ?ResolvedImage {
		foreach ( array( (int) get_theme_mod( 'custom_logo' ), (int) get_option( 'site_icon' ) ) as $attachment ) {
			if ( $attachment <= 0 ) {
				continue;
			}

			$resolved = $this->from_attachment( $attachment, ResolvedImage::STEP_LOGO );

			if ( null !== $resolved ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Builds a resolved image from a card record.
	 *
	 * @since 0.1.0
	 *
	 * @param CardRecord $record Card record.
	 * @param string     $step   Step identifier.
	 *
	 * @return ResolvedImage Resolved image.
	 */
	private function from_record( CardRecord $record, string $step ): ResolvedImage {
		return new ResolvedImage(
			$record->url( $this->directory ),
			(int) $record->get( 'w', 0 ),
			(int) $record->get( 'h', 0 ),
			(string) $record->get( 'mime', 'image/jpeg' ),
			$record->alt(),
			$step
		);
	}

	/**
	 * Builds a resolved image from an attachment, verifying it first.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $step          Step identifier.
	 *
	 * @return ResolvedImage|null Resolved image, or null when unusable.
	 */
	private function from_attachment( int $attachment_id, string $step ): ?ResolvedImage {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$source = wp_get_attachment_image_src( $attachment_id, 'full' );

		if ( ! is_array( $source ) || '' === (string) ( $source[0] ?? '' ) ) {
			return null;
		}

		$width  = (int) ( $source[1] ?? 0 );
		$height = (int) ( $source[2] ?? 0 );

		// Too small to unfurl: platforms either ignore it or render a tiny thumbnail.
		if ( $width < self::MIN_WIDTH || $height < self::MIN_HEIGHT ) {
			return null;
		}

		return new ResolvedImage(
			(string) $source[0],
			$width,
			$height,
			(string) get_post_mime_type( $attachment_id ),
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			$step
		);
	}

	/**
	 * Whether a stale card may still be served.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when stale cards are acceptable.
	 */
	private function serve_stale(): bool {
		/**
		 * Filters whether a stale card may be served.
		 *
		 * True by default: SPEC §12.1 step 2 reasons that a slightly outdated card
		 * beats no card.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $serve Whether to serve stale cards.
		 */
		return (bool) apply_filters( 'scstudio_serve_stale', true );
	}
}
