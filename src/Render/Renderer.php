<?php
/**
 * Renderer contract.
 *
 * Implements the interface declared in SPEC.md §2.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Card\CardDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Draws a Card Document.
 *
 * Every implementation consumes the identical document and the identical TextLayout
 * results; only the drawing primitives differ (SPEC §4.2). GD is the shipped engine;
 * another can be added through the `scstudio_renderers` filter.
 *
 * @since 0.1.0
 */
interface Renderer {

	/**
	 * Whether this renderer can run on this server.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when usable.
	 */
	public function supports(): bool;

	/**
	 * Selection priority; higher wins.
	 *
	 * @since 0.1.0
	 *
	 * @return int Priority.
	 */
	public function priority(): int;

	/**
	 * Engine identifier, including version.
	 *
	 * Stored on the card record and mixed into the input hash, so a server upgrade
	 * that changes rasterisation invalidates cards rather than leaving a mismatched
	 * archive behind.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier, e.g. "gd-2.3.3".
	 */
	public function id(): string;

	/**
	 * Draws the document.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDocument  $document Validated card document.
	 * @param RenderContext $context  Render inputs.
	 *
	 * @throws RenderException When the card cannot be drawn.
	 * @throws UnsupportedScriptException When text needs shaping this server cannot do.
	 *
	 * @return RenderResult Encoded image and its cost.
	 */
	public function render( CardDocument $document, RenderContext $context ): RenderResult;
}
