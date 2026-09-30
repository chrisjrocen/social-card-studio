<?php
/**
 * Render inputs.
 *
 * Implements the Context half of the Renderer contract in SPEC.md §2.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a renderer needs besides the Card Document.
 *
 * @since 0.1.0
 */
final class RenderContext {

	/**
	 * Reasons this render was degraded, per SPEC §4.4.
	 *
	 * @var string[]
	 */
	private array $degradations = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $tokens     Resolved tokens for this post.
	 * @param FontResolver          $fonts      Font resolution chain.
	 * @param MemoryGuard           $memory     Memory headroom guard.
	 * @param int                   $post_id    Post being rendered, 0 for the homepage card.
	 * @param int                   $quality    Starting JPEG quality.
	 */
	public function __construct(
		public readonly array $tokens,
		public readonly FontResolver $fonts,
		public readonly MemoryGuard $memory,
		public readonly int $post_id = 0,
		public readonly int $quality = 82
	) {}

	/**
	 * Records that an effect was skipped to save memory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Short machine-readable reason.
	 *
	 * @return void
	 */
	public function degrade( string $reason ): void {
		if ( ! in_array( $reason, $this->degradations, true ) ) {
			$this->degradations[] = $reason;
		}
	}

	/**
	 * Whether anything was skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the render was degraded.
	 */
	public function is_degraded(): bool {
		return array() !== $this->degradations;
	}

	/**
	 * The recorded degradations.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Reasons.
	 */
	public function degradations(): array {
		return $this->degradations;
	}
}
