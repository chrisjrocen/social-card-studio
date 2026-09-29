<?php
/**
 * Queue job handlers.
 *
 * Connects the hooks queued by Scheduler to CardGenerator, per SPEC.md §11.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

defined( 'ABSPATH' ) || exit;

/**
 * Executes queued generation jobs.
 *
 * Kept apart from the generator so the queue's argument shapes — which differ between
 * Action Scheduler and WP-Cron — are unpacked in one place rather than leaking into
 * the generator's signature.
 *
 * @since 0.1.0
 */
final class JobRunner {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardGenerator $generator Card generation.
	 */
	public function __construct( private readonly CardGenerator $generator ) {}

	/**
	 * Registers the handlers.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::HOOK_GENERATE, array( $this, 'generate' ), 10, 1 );
		add_action( Scheduler::HOOK_HOME, array( $this, 'generate_home' ), 10, 0 );
	}

	/**
	 * Generates one card.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args Job arguments.
	 *
	 * @return void
	 */
	public function generate( $args = array() ): void {
		$args    = is_array( $args ) ? $args : array();
		$post_id = (int) ( $args['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			return;
		}

		$this->generator->ensure( $post_id, (bool) ( $args['force'] ?? false ) );
	}

	/**
	 * Generates the homepage card.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function generate_home(): void {
		$this->generator->ensure_home();
	}
}
