<?php
/**
 * Classic editor metabox.
 *
 * Implements the reduced-feature metabox of SPEC.md §14.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Generation\Scheduler;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The same fields, without the live preview.
 *
 * The classic editor has no React runtime to host the DOM preview, so this offers the
 * fields and queues the render rather than pretending to show it live. Everything an
 * author can set in the block editor they can set here.
 *
 * @since 0.1.0
 */
final class ClassicMetabox {

	public const NONCE = 'scstudio_metabox';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardRepository   $repository Card meta storage.
	 * @param TemplateRegistry $templates  Preset templates.
	 * @param CardDirectory    $directory  Card directory resolver.
	 * @param Scheduler        $scheduler  Background queue.
	 * @param Settings         $settings   Plugin settings.
	 */
	public function __construct(
		private readonly CardRepository $repository,
		private readonly TemplateRegistry $templates,
		private readonly CardDirectory $directory,
		private readonly Scheduler $scheduler,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the metabox.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Adds the box to every enabled post type.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add(): void {
		foreach ( (array) $this->settings->get( 'enabled_post_types', array() ) as $type ) {
			add_meta_box(
				'scstudio-card',
				__( 'Social card', 'social-card-studio' ),
				array( $this, 'render' ),
				(string) $type,
				'side',
				'default',
				// The block editor has its own panel; this box is for the classic one.
				array( '__back_compat_meta_box' => true )
			);
		}
	}

	/**
	 * Renders the box.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post being edited.
	 *
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$overrides = $this->repository->overrides( $post->ID );
		$record    = $this->repository->get( $post->ID );
		$status    = $this->repository->status( $post );

		if ( null !== $record && $record->exists( $this->directory ) ) {
			printf(
				'<img src="%s" alt="%s" style="width:100%%;height:auto;border:1px solid #dcdcde;border-radius:3px;margin-bottom:8px" />',
				esc_url( $record->url( $this->directory ) ),
				esc_attr( $record->alt() )
			);
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $this->status_label( $status ) ) );

		printf(
			'<p><label for="scstudio_headline">%s</label><input type="text" id="scstudio_headline" name="scstudio_headline" value="%s" placeholder="%s" class="widefat" /></p>',
			esc_html__( 'Headline', 'social-card-studio' ),
			esc_attr( (string) ( $overrides['headline'] ?? '' ) ),
			esc_attr( (string) get_the_title( $post ) )
		);

		printf(
			'<p><label for="scstudio_subhead">%s</label><textarea id="scstudio_subhead" name="scstudio_subhead" rows="2" class="widefat">%s</textarea></p>',
			esc_html__( 'Subhead', 'social-card-studio' ),
			esc_textarea( (string) ( $overrides['subhead'] ?? '' ) )
		);

		echo '<p><label for="scstudio_template">' . esc_html__( 'Template', 'social-card-studio' ) . '</label>';
		echo '<select id="scstudio_template" name="scstudio_template" class="widefat">';
		printf( '<option value="">%s</option>', esc_html__( 'Site default', 'social-card-studio' ) );

		foreach ( $this->templates->ids() as $id ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( (string) ( $overrides['template'] ?? '' ), $id, false ),
				esc_html( $id )
			);
		}

		echo '</select></p>';

		printf(
			'<p><label for="scstudio_alt">%s</label><textarea id="scstudio_alt" name="scstudio_alt" rows="2" class="widefat">%s</textarea></p>',
			esc_html__( 'Alt text', 'social-card-studio' ),
			esc_textarea( (string) ( $overrides['alt'] ?? ( null !== $record ? $record->alt() : '' ) ) )
		);

		printf(
			'<p><label><input type="checkbox" name="scstudio_disabled" value="1"%s /> %s</label></p>',
			checked( $this->repository->is_disabled( $post->ID ), true, false ),
			esc_html__( 'No social card for this post', 'social-card-studio' )
		);

		printf(
			'<p><label><input type="checkbox" name="scstudio_regenerate" value="1" /> %s</label></p>',
			esc_html__( 'Redraw the card when I update this post', 'social-card-studio' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Cards are drawn in the background, so updating the post stays fast.', 'social-card-studio' )
		);
	}

	/**
	 * Saves the fields.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    The post.
	 *
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		$overrides = array();

		foreach ( CardRepository::EDITABLE_FIELDS as $field ) {
			$key = 'scstudio_' . $field;

			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) );

			if ( '' !== $value ) {
				$overrides[ $field ] = $value;
			}
		}

		update_post_meta( $post_id, CardRepository::META_OVERRIDES, $this->repository->sanitize_overrides( $overrides ) );

		if ( isset( $_POST['scstudio_disabled'] ) ) {
			update_post_meta( $post_id, CardRepository::META_DISABLED, true );
		} else {
			delete_post_meta( $post_id, CardRepository::META_DISABLED );
		}

		if ( isset( $_POST['scstudio_regenerate'] ) ) {
			// Queued, never rendered here: a metabox save is still a post save, and
			// SPEC §4.4 forbids making that slower.
			$this->scheduler->enqueue_generate( $post_id, true );
		}
	}

	/**
	 * A human-readable status.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status One of the CardRepository::STATUS_* constants.
	 *
	 * @return string Label.
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			CardRepository::STATUS_CURRENT  => __( 'Up to date', 'social-card-studio' ),
			CardRepository::STATUS_STALE    => __( 'Needs regenerating', 'social-card-studio' ),
			CardRepository::STATUS_DISABLED => __( 'Switched off for this post', 'social-card-studio' ),
			default                         => __( 'No card yet', 'social-card-studio' ),
		};
	}
}
