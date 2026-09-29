<?php
/**
 * SEO plugin detection.
 *
 * Implements the detection half of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

use ChrxDigital\SocialCardStudio\Delivery\Integrations\AIOSEO;
use ChrxDigital\SocialCardStudio\Delivery\Integrations\RankMath;
use ChrxDigital\SocialCardStudio\Delivery\Integrations\SEOFramework;
use ChrxDigital\SocialCardStudio\Delivery\Integrations\SEOPress;
use ChrxDigital\SocialCardStudio\Delivery\Integrations\SlimSEO;
use ChrxDigital\SocialCardStudio\Delivery\Integrations\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * Picks exactly one SEO integration.
 *
 * Exactly one, deliberately. Two plugins both emitting og:image is already a broken
 * site, and hooking both would make our card the tie-breaker in a fight we did not
 * start. The order is the one in SPEC §10.2.
 *
 * @since 0.1.0
 */
final class SeoDetector {

	/**
	 * Every integration, in detection order.
	 *
	 * @var SeoIntegration[]|null
	 */
	private ?array $integrations = null;

	/**
	 * The active integration, resolved once per request.
	 *
	 * @var SeoIntegration|null
	 */
	private ?SeoIntegration $active = null;

	/**
	 * Whether detection has run.
	 *
	 * @var bool
	 */
	private bool $detected = false;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param ImageResolver $resolver Fallback chain.
	 * @param Priority      $priority Priority modes.
	 */
	public function __construct(
		private readonly ImageResolver $resolver,
		private readonly Priority $priority
	) {}

	/**
	 * All known integrations, in priority order.
	 *
	 * @since 0.1.0
	 *
	 * @return SeoIntegration[] Integrations.
	 */
	public function all(): array {
		if ( null !== $this->integrations ) {
			return $this->integrations;
		}

		$this->integrations = array(
			new Yoast( $this->resolver, $this->priority ),
			new RankMath( $this->resolver, $this->priority ),
			new SEOPress( $this->resolver, $this->priority ),
			new AIOSEO( $this->resolver, $this->priority ),
			new SEOFramework( $this->resolver, $this->priority ),
			new SlimSEO( $this->resolver, $this->priority ),
		);

		return $this->integrations;
	}

	/**
	 * The active integration, if any.
	 *
	 * @since 0.1.0
	 *
	 * @return SeoIntegration|null Active integration, or null when none is installed.
	 */
	public function active(): ?SeoIntegration {
		if ( $this->detected ) {
			return $this->active;
		}

		$this->detected = true;

		foreach ( $this->all() as $integration ) {
			if ( $integration->is_active() ) {
				$this->active = $integration;

				break;
			}
		}

		return $this->active;
	}

	/**
	 * Whether any SEO plugin is handling the tags.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when one is active.
	 */
	public function has_active(): bool {
		return null !== $this->active();
	}

	/**
	 * Registers the active integration's filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hook(): void {
		$active = $this->active();

		if ( null !== $active ) {
			$active->hook();
		}
	}

	/**
	 * Resets detection.
	 *
	 * Used by tests, which activate plugins after the container was built.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->detected     = false;
		$this->active       = null;
		$this->integrations = null;
	}
}
