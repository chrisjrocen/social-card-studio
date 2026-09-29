<?php
/**
 * Raised when a card cannot be drawn.
 *
 * Supports the fallback chain of SPEC.md §12.1 and the never-fatal rule of §12.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * A render failure that callers are expected to catch and fall back from.
 *
 * Typed so that "no usable engine" and "this particular document failed" are
 * distinguishable from a genuine bug, and so no render path can ever surface as a
 * fatal on a public page.
 *
 * @since 0.1.0
 */
final class RenderException extends \RuntimeException {

	public const REASON_NO_ENGINE = 'no_engine';
	public const REASON_SOURCE    = 'source';
	public const REASON_DRAW      = 'draw';
	public const REASON_ENCODE    = 'encode';
	public const REASON_OVERSIZE  = 'oversize';
	public const REASON_MEMORY    = 'memory';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $reason   One of the REASON_* constants.
	 * @param string          $message  Human-readable detail.
	 * @param \Throwable|null $previous Underlying failure, if any.
	 */
	public function __construct(
		public readonly string $reason,
		string $message,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}
}
