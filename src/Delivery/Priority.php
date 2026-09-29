<?php
/**
 * Priority modes.
 *
 * Implements the `priority` setting of SPEC.md §10.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether our card should replace what an SEO plugin already has.
 *
 * The three modes differ only in what they treat as untouchable:
 *   - gap_fill      nothing may be replaced; we fill a genuine hole
 *   - override_auto a human's explicit choice may not be replaced; a derived one may
 *   - always        nothing is untouchable except a per-post opt-out
 *
 * @since 0.1.0
 */
final class Priority {

	public const GAP_FILL      = 'gap_fill';
	public const OVERRIDE_AUTO = 'override_auto';
	public const ALWAYS        = 'always';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings       $settings   Plugin settings.
	 * @param CardRepository $repository Card meta storage.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly CardRepository $repository
	) {}

	/**
	 * The active mode.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of the mode constants.
	 */
	public function mode(): string {
		$mode = (string) $this->settings->get( 'priority', self::GAP_FILL );

		return in_array( $mode, array( self::GAP_FILL, self::OVERRIDE_AUTO, self::ALWAYS ), true )
			? $mode
			: self::GAP_FILL;
	}

	/**
	 * Whether our card should be supplied for this post.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id          Post being rendered.
	 * @param bool $seo_has_image    Whether the SEO plugin already has any image.
	 * @param bool $seo_has_manual   Whether that image was explicitly chosen by a human.
	 *
	 * @return bool True when our card should win.
	 */
	public function should_supply( int $post_id, bool $seo_has_image, bool $seo_has_manual ): bool {
		// The per-post opt-out is honoured in all three modes, per SPEC §10.2.
		if ( $post_id > 0 && $this->repository->is_disabled( $post_id ) ) {
			return false;
		}

		$mode = $this->mode();

		$supply = match ( $mode ) {
			self::ALWAYS        => true,
			self::OVERRIDE_AUTO => ! $seo_has_manual,
			default             => ! $seo_has_image,
		};

		/**
		 * Filters the priority decision for one post.
		 *
		 * Documented in SPEC §21 as the per-post priority override.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $supply         Whether our card should be supplied.
		 * @param int    $post_id        Post being rendered.
		 * @param string $mode           Active priority mode.
		 * @param bool   $seo_has_manual Whether a human set an image in the SEO plugin.
		 */
		return (bool) apply_filters( 'scstudio_priority_for_post', $supply, $post_id, $mode, $seo_has_manual );
	}

	/**
	 * The modes and how they are described in the settings UI.
	 *
	 * The gap_fill description is deliberately blunt. SPEC §10.2 requires the UI to
	 * say plainly that it rarely fires — most SEO plugins fall back to the featured
	 * image, so "only when there is no image at all" almost never happens, and a site
	 * owner who picks it wondering why nothing changed is the predictable outcome.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{label: string, description: string}> Mode descriptions.
	 */
	public static function choices(): array {
		return array(
			self::GAP_FILL      => array(
				'label'       => __( 'Fill gaps only', 'social-card-studio' ),
				'description' => __( 'Use your card only when the SEO plugin would otherwise have no image at all. Be aware that this rarely happens: most SEO plugins fall back to the featured image, so they almost always have something. If your cards never seem to appear, this setting is why — choose "Replace automatic images" instead.', 'social-card-studio' ),
			),
			self::OVERRIDE_AUTO => array(
				'label'       => __( 'Replace automatic images (recommended)', 'social-card-studio' ),
				'description' => __( 'Use your card in place of images the SEO plugin picked by itself — the featured image, the first image in the content, the site default — but never in place of one you chose by hand in the post\'s social tab. This is what most sites want.', 'social-card-studio' ),
			),
			self::ALWAYS        => array(
				'label'       => __( 'Always use my card', 'social-card-studio' ),
				'description' => __( 'Use your card everywhere, including on posts where someone chose a social image by hand. Individual posts can still opt out.', 'social-card-studio' ),
			),
		);
	}
}
