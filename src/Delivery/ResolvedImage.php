<?php
/**
 * A verified image ready to be emitted.
 *
 * Implements the output of the fallback chain in SPEC.md §12.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * An image the plugin is willing to put in a meta tag.
 *
 * Constructing one is a claim that the image was verified to exist, because SPEC
 * §12.1 ends with "never emit a URL we have not verified" — an og:image pointing at
 * a 404 is worse than no og:image, since the platform caches the failure.
 *
 * @since 0.1.0
 */
final class ResolvedImage {

	public const STEP_FRESH      = 'fresh_card';
	public const STEP_STALE      = 'stale_card';
	public const STEP_SEO_MANUAL = 'seo_manual';
	public const STEP_FEATURED   = 'featured_image';
	public const STEP_SITE_CARD  = 'site_default_card';
	public const STEP_LOGO       = 'site_logo';
	public const STEP_ENDPOINT   = 'lazy_endpoint';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url    Absolute URL.
	 * @param int    $width  Pixel width, 0 when unknown.
	 * @param int    $height Pixel height, 0 when unknown.
	 * @param string $mime   MIME type.
	 * @param string $alt    Alt text.
	 * @param string $step   Which step of the chain produced this.
	 */
	public function __construct(
		public readonly string $url,
		public readonly int $width = 0,
		public readonly int $height = 0,
		public readonly string $mime = 'image/jpeg',
		public readonly string $alt = '',
		public readonly string $step = ''
	) {}

	/**
	 * Whether the dimensions are known.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when both are non-zero.
	 */
	public function has_dimensions(): bool {
		return $this->width > 0 && $this->height > 0;
	}

	/**
	 * Whether this came from a card the plugin generated.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a fresh or stale card.
	 */
	public function is_card(): bool {
		return in_array( $this->step, array( self::STEP_FRESH, self::STEP_STALE, self::STEP_SITE_CARD ), true );
	}
}
