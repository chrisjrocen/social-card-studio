<?php
/**
 * The render inputs that are not post content.
 *
 * Implements the context half of the input hash in SPEC.md §9.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\RenderException;
use ChrxDigital\SocialCardStudio\Render\RendererFactory;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "which template, engine and fonts apply to this post".
 *
 * It exists because the answer has to be identical in two places that are otherwise
 * unrelated: the generator, when it renders a card, and the repository, when it later
 * asks whether that card is stale. Computing it separately in each is how a
 * just-rendered card reports itself out of date the moment it is written — the
 * generator hashes with a template version and an engine id, the staleness check
 * hashes without them, and the two never agree again.
 *
 * @since 0.1.0
 */
final class RenderProfile {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings         $settings  Plugin settings.
	 * @param TemplateRegistry $templates Preset templates.
	 * @param FontResolver     $fonts     Font resolution.
	 * @param RendererFactory  $factory   Renderer selection.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly TemplateRegistry $templates,
		private readonly FontResolver $fonts,
		private readonly RendererFactory $factory
	) {}

	/**
	 * The template a post should use.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID, 0 for the homepage card.
	 *
	 * @return string Template identifier.
	 */
	public function template_for( int $post_id ): string {
		if ( 0 === $post_id ) {
			return $this->site_template();
		}

		$own = $this->post_template( $post_id );

		if ( '' !== $own ) {
			return $own;
		}

		return $this->sitewide_template_for( (string) get_post_type( $post_id ) );
	}

	/**
	 * The template a post chose for itself in the editor, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string Template identifier, empty when the post follows the sitewide design.
	 */
	public function post_template( int $post_id ): string {
		$overrides = get_post_meta( $post_id, CardRepository::META_OVERRIDES, true );
		$overrides = is_array( $overrides ) ? $overrides : array();

		return (string) ( $overrides['template'] ?? '' );
	}

	/**
	 * The template a post type uses when a post has not chosen its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return string Template identifier.
	 */
	public function sitewide_template_for( string $post_type ): string {
		$per_type = (array) $this->settings->get( 'per_type_template', array() );

		if ( '' !== (string) ( $per_type[ $post_type ] ?? '' ) ) {
			return (string) $per_type[ $post_type ];
		}

		return (string) $this->settings->get( 'default_template', 'editorial-left' );
	}

	/**
	 * The template for the site card: the homepage and the last-resort fallback.
	 *
	 * @since 0.2.0
	 *
	 * @return string Template identifier.
	 */
	public function site_template(): string {
		return (string) $this->settings->get( 'homepage_card.template', TemplateRegistry::HOMEPAGE );
	}

	/**
	 * The context to hash and render with.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID, 0 for the homepage card.
	 *
	 * @return array<string, mixed> Context.
	 */
	public function context( int $post_id ): array {
		$template = $this->template_for( $post_id );
		$document = $this->templates->get( $template );

		return array(
			'template_id'      => $template,
			'template_version' => null === $document ? 0 : $document->version(),
			'engine_id'        => $this->engine_id(),
			'font_fingerprint' => $this->fonts->fingerprint(),
		);
	}

	/**
	 * Identifier of the engine that would render.
	 *
	 * @since 0.1.0
	 *
	 * @return string Engine identifier, empty when none is available.
	 */
	public function engine_id(): string {
		try {
			return $this->factory->create()->id();
		} catch ( RenderException $e ) {
			return '';
		}
	}
}
