<?php
/**
 * Stampede protection.
 *
 * Implements the lock required by SPEC.md §10.3 step 3 and §11 (concurrency).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

defined( 'ABSPATH' ) || exit;

/**
 * A short-lived lock keyed on post and size.
 *
 * When a post is shared, several scrapers hit the card endpoint within the same
 * second. Without this each would start its own render of the same card: three times
 * the memory, three times the CPU, and three writes racing for one filename.
 *
 * Uses add_option() rather than a transient. add_option() is atomic — it fails when
 * the row already exists — while get/set transient is a read followed by a write,
 * which is exactly the gap two simultaneous scrapers slip through.
 *
 * @since 0.1.0
 */
final class Lock {

	/**
	 * Lifetime of a lock, per SPEC §10.3.
	 *
	 * Long enough to cover the 5 s render budget several times over, short enough
	 * that a killed process does not block regeneration for long.
	 */
	public const TTL = 30;

	/**
	 * Option name prefix.
	 */
	private const PREFIX = 'scstudio_lock_';

	/**
	 * Acquires a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id   Post being rendered, 0 for the homepage card.
	 * @param string $size_slug Output size.
	 *
	 * @return bool True when this caller holds the lock.
	 */
	public function acquire( int $post_id, string $size_slug = 'og' ): bool {
		$key     = $this->key( $post_id, $size_slug );
		$expires = time() + self::TTL;

		// add_option() is a single INSERT that fails if the row exists.
		if ( add_option( $key, $expires, '', false ) ) {
			return true;
		}

		// A lock left behind by a process that died is taken over rather than waited on.
		$held = (int) get_option( $key, 0 );

		if ( $held > 0 && $held < time() ) {
			delete_option( $key );

			return add_option( $key, $expires, '', false );
		}

		return false;
	}

	/**
	 * Releases a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id   Post being rendered.
	 * @param string $size_slug Output size.
	 *
	 * @return void
	 */
	public function release( int $post_id, string $size_slug = 'og' ): void {
		delete_option( $this->key( $post_id, $size_slug ) );
	}

	/**
	 * Whether a lock is currently held by someone.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id   Post being rendered.
	 * @param string $size_slug Output size.
	 *
	 * @return bool True when locked.
	 */
	public function is_locked( int $post_id, string $size_slug = 'og' ): bool {
		$held = (int) get_option( $this->key( $post_id, $size_slug ), 0 );

		return $held > time();
	}

	/**
	 * Builds the option name.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id   Post being rendered.
	 * @param string $size_slug Output size.
	 *
	 * @return string Option name.
	 */
	private function key( int $post_id, string $size_slug ): string {
		return self::PREFIX . $post_id . '_' . sanitize_key( $size_slug );
	}
}
