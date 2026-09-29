<?php
/**
 * Raised when text cannot be drawn correctly.
 *
 * Implements the "correct or fallback, never mangled" rule of SPEC.md §6.2 and §17.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Signals that a string needs shaping this environment cannot perform.
 *
 * Callers catch this and fall back to the site default card (SPEC §12.1 step 5),
 * logging `unsupported_script`. It is deliberately a typed exception rather than a
 * boolean return: silently drawing disconnected Arabic is the failure this whole
 * class exists to prevent, so it has to be impossible to ignore by accident.
 *
 * @since 0.1.0
 */
final class UnsupportedScriptException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $script Detected script identifier.
	 * @param string $reason Why it cannot be drawn.
	 */
	public function __construct(
		public readonly string $script,
		string $reason = ''
	) {
		parent::__construct(
			sprintf(
				'Text in the %s script cannot be rendered on this server: %s',
				$script,
				'' !== $reason ? $reason : 'complex shaping is unavailable'
			)
		);
	}
}
