<?php
/**
 * Renderer selection.
 *
 * Implements SPEC.md §4.2 step 1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the best renderer this server can run.
 *
 * @since 0.1.0
 */
final class RendererFactory {

	/**
	 * Resolved renderer for this request.
	 *
	 * @var Renderer|null
	 */
	private ?Renderer $selected = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenResolver $tokens     Token substitution.
	 * @param Optimizer     $optimizer  Output size budget.
	 * @param Legibility    $legibility Contrast guard.
	 * @param CardDirectory $directory  Card directory, for allowed image roots.
	 */
	public function __construct(
		private readonly TokenResolver $tokens,
		private readonly Optimizer $optimizer,
		private readonly Legibility $legibility,
		private readonly CardDirectory $directory
	) {}

	/**
	 * Returns the renderer to use.
	 *
	 * @since 0.1.0
	 *
	 * @throws RenderException When no engine on this server can draw text.
	 *
	 * @return Renderer Selected renderer.
	 */
	public function create(): Renderer {
		if ( null !== $this->selected ) {
			return $this->selected;
		}

		$candidates = $this->candidates();

		usort( $candidates, static fn ( Renderer $a, Renderer $b ): int => $b->priority() <=> $a->priority() );

		foreach ( $candidates as $renderer ) {
			if ( $renderer->supports() ) {
				$this->selected = $renderer;

				return $renderer;
			}
		}

		throw new RenderException(
			RenderException::REASON_NO_ENGINE,
			'Neither Imagick nor GD can draw text on this server.'
		);
	}

	/**
	 * Whether any renderer is available.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a card can be drawn.
	 */
	public function available(): bool {
		foreach ( $this->candidates() as $renderer ) {
			if ( $renderer->supports() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a specific renderer by identifier.
	 *
	 * Used by the parity tests and the Diagnostics test render, which need to drive
	 * one engine deliberately rather than whichever the server prefers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Either "imagick" or "gd".
	 *
	 * @throws RenderException When that engine is unavailable here.
	 *
	 * @return Renderer The requested renderer.
	 */
	public function create_specific( string $id ): Renderer {
		foreach ( $this->candidates() as $renderer ) {
			if ( str_starts_with( $renderer->id(), $id ) ) {
				if ( ! $renderer->supports() ) {
					throw new RenderException(
						RenderException::REASON_NO_ENGINE,
						sprintf( 'The %s engine is not available on this server.', $id )
					);
				}

				return $renderer;
			}
		}

		throw new RenderException(
			RenderException::REASON_NO_ENGINE,
			sprintf( 'There is no renderer with the identifier "%s".', $id )
		);
	}

	/**
	 * Every renderer the plugin knows about.
	 *
	 * @since 0.1.0
	 *
	 * @return Renderer[] Candidates.
	 */
	private function candidates(): array {
		$roots = array(
			$this->directory->path(),
			rtrim( SCSTUDIO_DIR, '/' ) . '/assets',
		);

		$candidates = array(
			new ImagickRenderer( $this->tokens, $this->optimizer, $this->legibility, $roots ),
			new GdRenderer( $this->tokens, $this->optimizer, $this->legibility, $roots ),
		);

		/**
		 * Filters the renderers available for selection.
		 *
		 * Documented in SPEC §21 as the extension point for an additional engine, such
		 * as the Phase 4 headless HTML renderer.
		 *
		 * @since 0.1.0
		 *
		 * @param Renderer[] $candidates Renderers, unsorted.
		 */
		$candidates = (array) apply_filters( 'scstudio_renderers', $candidates );

		return array_values( array_filter( $candidates, static fn ( $r ): bool => $r instanceof Renderer ) );
	}
}
