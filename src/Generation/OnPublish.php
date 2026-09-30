<?php
/**
 * On-publish trigger.
 *
 * Implements the "on publish/update" row of SPEC.md §11. Per §9.2, a card whose inputs
 * changed is always regenerated in the background.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Queues a card when a post is published or updated.
 *
 * Never renders inline. SPEC §4.4 calls that a release blocker, and it is the right
 * call: a render is hundreds of milliseconds and tens of megabytes, and an editor who
 * notices that saving got slower after installing a plugin uninstalls the plugin.
 * Everything this class does is decide whether to enqueue.
 *
 * @since 0.1.0
 */
final class OnPublish {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Scheduler      $scheduler  Background queue.
	 * @param CardRepository $repository Card meta storage.
	 * @param Settings       $settings   Plugin settings.
	 */
	public function __construct(
		private readonly Scheduler $scheduler,
		private readonly CardRepository $repository,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_updated' ), 10, 3 );
		add_action( 'scstudio_settings_changed', array( $this, 'on_settings_changed' ) );
	}

	/**
	 * Handles a status transition.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status Status being moved to.
	 * @param string  $old_status Status being moved from.
	 * @param WP_Post $post       The post.
	 *
	 * @return void
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || $new_status === $old_status ) {
			return;
		}

		$this->maybe_enqueue( $post );
	}

	/**
	 * Handles an update to an already-published post.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 *
	 * @return void
	 */
	public function on_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( 'publish' !== $after->post_status ) {
			return;
		}

		/*
		 * A REST request that only touched meta still fires post_updated with an
		 * unchanged post. Comparing the fields that actually feed the input hash is
		 * cheaper and more accurate than trying to detect the request's shape, and it
		 * covers bulk edit for free.
		 */
		if ( $this->is_unchanged( $before, $after ) ) {
			return;
		}

		$this->maybe_enqueue( $after );
	}

	/**
	 * Queues the homepage card after a settings change.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_settings_changed(): void {
		$this->scheduler->enqueue_home();
	}

	/**
	 * Decides whether to queue, then queues.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return bool True when a job was queued.
	 */
	public function maybe_enqueue( WP_Post $post ): bool {
		if ( ! $this->should_enqueue( $post ) ) {
			return false;
		}

		return $this->scheduler->enqueue_generate( $post->ID );
	}

	/**
	 * Whether this post should be queued at all.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return bool True when it should.
	 */
	public function should_enqueue( WP_Post $post ): bool {
		if ( ! (bool) $this->settings->get( 'triggers.on_publish', true ) ) {
			return false;
		}

		// Autosaves and revisions are not the post; acting on them would generate a
		// card for a draft snapshot and then again for the real save.
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, (array) $this->settings->get( 'enabled_post_types', array() ), true ) ) {
			return false;
		}

		return ! $this->repository->is_disabled( $post->ID );
	}

	/**
	 * Whether an update changed anything a card is drawn from.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $before Post before the update.
	 * @param WP_Post $after  Post after the update.
	 *
	 * @return bool True when nothing card-relevant changed.
	 */
	private function is_unchanged( WP_Post $before, WP_Post $after ): bool {
		foreach ( array( 'post_title', 'post_excerpt', 'post_author', 'post_status', 'post_name' ) as $field ) {
			if ( $before->{$field} !== $after->{$field} ) {
				return false;
			}
		}

		return true;
	}
}
