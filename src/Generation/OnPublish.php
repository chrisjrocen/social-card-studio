<?php
/**
 * On-publish trigger.
 *
 * Implements the "on publish/update" row of SPEC.md §11 and the regeneration policy of
 * §9.2.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

use ChrxDigital\SocialCardStudio\Card\CardRecord;
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

	public const MODE_AUTO_TEMPLATE = 'auto_template_manual_ai';
	public const MODE_AUTO_ALL      = 'auto_all';
	public const MODE_MANUAL_ALL    = 'manual_all';

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

		if ( $this->repository->is_disabled( $post->ID ) ) {
			return false;
		}

		return $this->regeneration_allows( $post->ID );
	}

	/**
	 * Whether the regeneration policy permits replacing this post's card.
	 *
	 * SPEC §9.2: an AI-generated card is never replaced silently under the default
	 * mode. Someone paid for that image and a human approved it, so it is marked stale
	 * and left alone rather than being quietly swapped for a different one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when a regeneration may be queued.
	 */
	public function regeneration_allows( int $post_id ): bool {
		$record = $this->repository->get( $post_id );

		// No card yet: nothing to protect.
		if ( null === $record ) {
			return true;
		}

		$mode = (string) $this->settings->get( 'regeneration_mode', self::MODE_AUTO_TEMPLATE );

		if ( self::MODE_AUTO_ALL === $mode ) {
			return true;
		}

		if ( self::MODE_MANUAL_ALL === $mode ) {
			$this->repository->mark_stale( $post_id );

			return false;
		}

		if ( $record->is_ai() ) {
			$this->repository->mark_stale( $post_id );

			return false;
		}

		return true;
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

	/**
	 * The regeneration modes and how they read in the settings UI.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{label: string, description: string}> Mode descriptions.
	 */
	public static function modes(): array {
		return array(
			self::MODE_AUTO_TEMPLATE => array(
				'label'       => __( 'Regenerate template cards, leave AI cards alone (recommended)', 'social-card-studio' ),
				'description' => __( 'Cards drawn from a template are redrawn whenever the post changes. Cards made with AI are marked as needing attention instead, because they cost money and someone approved that particular image.', 'social-card-studio' ),
			),
			self::MODE_AUTO_ALL      => array(
				'label'       => __( 'Regenerate everything automatically', 'social-card-studio' ),
				'description' => __( 'Redraw every card when its post changes, including AI ones. This spends credits without asking, and replaces images a person approved.', 'social-card-studio' ),
			),
			self::MODE_MANUAL_ALL    => array(
				'label'       => __( 'Never regenerate on its own', 'social-card-studio' ),
				'description' => __( 'Cards are only ever created or replaced when you ask. Changed posts are flagged as needing attention.', 'social-card-studio' ),
			),
		);
	}

	/**
	 * The card sources considered AI-generated.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Source identifiers.
	 */
	public static function ai_sources(): array {
		return CardRecord::AI_SOURCES;
	}
}
