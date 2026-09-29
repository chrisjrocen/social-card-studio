<?php
/**
 * Card file storage.
 *
 * Implements SPEC.md §5.3 (canonical store, grace retention) and §7.2 (filenames).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Storage;

use ChrxDigital\SocialCardStudio\Card\CardRecord;
use ChrxDigital\SocialCardStudio\Render\RenderResult;

defined( 'ABSPATH' ) || exit;

/**
 * Writes, retires and purges card files.
 *
 * Paths are stored relative and resolved through wp_upload_dir() at read time, so a
 * domain change or a Local-to-production migration needs no search-replace
 * (SPEC §18.2).
 *
 * @since 0.1.0
 */
final class CardStore {

	/**
	 * Option holding the retire index.
	 *
	 * Not autoloaded: it is only read by the daily purge.
	 */
	public const RETIRED_OPTION = 'scstudio_retired';

	/**
	 * Default grace window in days, per SPEC §5.3.
	 */
	public const GRACE_DAYS = 30;

	/**
	 * Ceiling on retire-index entries.
	 *
	 * A site regenerating tens of thousands of cards must not grow an unbounded
	 * option; the oldest entries are purged first when this is exceeded.
	 */
	private const MAX_RETIRED = 20000;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDirectory $directory Card directory resolver.
	 */
	public function __construct( private readonly CardDirectory $directory ) {}

	/**
	 * Writes a rendered card and returns its record.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $post_id    Post ID, 0 for the homepage card.
	 * @param RenderResult         $result     Rendered bytes.
	 * @param string               $input_hash Full input hash from InputHasher.
	 * @param array<string, mixed> $meta       Extra record fields: template_id,
	 *                                         template_ver, source, alt, size_slug.
	 *
	 * @return CardRecord|null Stored record, or null when the write failed.
	 */
	public function write( int $post_id, RenderResult $result, string $input_hash, array $meta = array() ): ?CardRecord {
		$size_slug = (string) ( $meta['size_slug'] ?? 'og' );
		$relative  = $this->relative_path( $post_id, $input_hash, $size_slug );
		$absolute  = $this->directory->path( $relative );

		if ( ! wp_mkdir_p( dirname( $absolute ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem needs a credentials round-trip a scheduled render cannot perform.
		if ( false === file_put_contents( $absolute, $result->bytes ) ) {
			return null;
		}

		$record = CardRecord::from_array(
			array(
				'input_hash'   => $input_hash,
				'template_id'  => (string) ( $meta['template_id'] ?? '' ),
				'template_ver' => (int) ( $meta['template_ver'] ?? 0 ),
				'source'       => (string) ( $meta['source'] ?? CardRecord::SOURCE_TEMPLATE ),
				'engine'       => $result->engine,
				'file'         => $relative,
				'w'            => $result->width,
				'h'            => $result->height,
				'bytes'        => $result->size(),
				'mime'         => $result->mime,
				'alt'          => (string) ( $meta['alt'] ?? '' ),
				'generated_at' => time(),
				'generated_by' => get_current_user_id(),
			)
		);

		/**
		 * Fires after a card file has been written.
		 *
		 * Documented in SPEC §5.3 as the hook for pushing a card to a CDN or object
		 * store without using media-library mode.
		 *
		 * @since 0.1.0
		 *
		 * @param string     $absolute Absolute path of the written file.
		 * @param int        $post_id  Post the card belongs to.
		 * @param CardRecord $record   The stored record.
		 */
		do_action( 'scstudio_card_written', $absolute, $post_id, $record );

		return $record;
	}

	/**
	 * Marks a file for deletion after the grace window.
	 *
	 * The file deliberately stays where it is. Platform caches and already-published
	 * posts still point at that exact URL, so moving or deleting it immediately is
	 * what breaks a share that was working ten minutes ago (SPEC §5.3).
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the card directory.
	 * @param int    $post_id  Post the file belonged to.
	 *
	 * @return void
	 */
	public function retire( string $relative, int $post_id ): void {
		if ( '' === $relative ) {
			return;
		}

		$index = $this->retired();

		foreach ( $index as $entry ) {
			if ( ( $entry['file'] ?? '' ) === $relative ) {
				return;
			}
		}

		$index[] = array(
			'file'       => $relative,
			'post'       => $post_id,
			'retired_at' => time(),
		);

		if ( count( $index ) > self::MAX_RETIRED ) {
			$index = array_slice( $index, count( $index ) - self::MAX_RETIRED );
		}

		update_option( self::RETIRED_OPTION, array_values( $index ), false );
	}

	/**
	 * Deletes retired files whose grace window has passed.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of files deleted.
	 */
	public function purge_retired(): int {
		/**
		 * Filters the grace window before a retired card file is deleted.
		 *
		 * @since 0.1.0
		 *
		 * @param int $days Days to keep a superseded card file.
		 */
		$days = (int) apply_filters( 'scstudio_grace_days', self::GRACE_DAYS );

		$cutoff  = time() - ( max( 0, $days ) * DAY_IN_SECONDS );
		$index   = $this->retired();
		$keep    = array();
		$deleted = 0;

		foreach ( $index as $entry ) {
			$retired_at = (int) ( $entry['retired_at'] ?? 0 );
			$relative   = (string) ( $entry['file'] ?? '' );

			if ( '' === $relative ) {
				continue;
			}

			if ( $retired_at > $cutoff ) {
				$keep[] = $entry;

				continue;
			}

			$absolute = $this->directory->path( $relative );

			if ( file_exists( $absolute ) ) {
				wp_delete_file( $absolute );
				++$deleted;
			}
		}

		update_option( self::RETIRED_OPTION, array_values( $keep ), false );

		return $deleted;
	}

	/**
	 * Deletes every card file belonging to a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Number of files deleted.
	 */
	public function delete_for_post( int $post_id ): int {
		$deleted = 0;
		$prefix  = $this->basename_prefix( $post_id );

		foreach ( $this->all_files() as $absolute ) {
			if ( ! str_starts_with( basename( $absolute ), $prefix ) ) {
				continue;
			}

			wp_delete_file( $absolute );
			++$deleted;
		}

		// Drop the post's retire entries too, so the index does not keep dead paths.
		$index = array_values(
			array_filter(
				$this->retired(),
				static fn ( array $entry ): bool => (int) ( $entry['post'] ?? 0 ) !== $post_id
			)
		);

		update_option( self::RETIRED_OPTION, $index, false );

		return $deleted;
	}

	/**
	 * The retire index.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>> Entries.
	 */
	public function retired(): array {
		$index = get_option( self::RETIRED_OPTION, array() );

		return is_array( $index ) ? array_values( array_filter( $index, 'is_array' ) ) : array();
	}

	/**
	 * Builds the relative path for a card.
	 *
	 * The eight-character hash in the filename is what actually busts platform caches
	 * (SPEC §7.2): a regenerated card gets a new URL, so Facebook and LinkedIn refetch
	 * rather than serving the copy they cached weeks ago.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id    Post ID, 0 for the homepage card.
	 * @param string $input_hash Full input hash.
	 * @param string $size_slug  Output size slug.
	 *
	 * @return string Path relative to the card directory.
	 */
	public function relative_path( int $post_id, string $input_hash, string $size_slug = 'og' ): string {
		$name = $this->basename_prefix( $post_id );

		if ( 'og' !== $size_slug ) {
			$name .= sanitize_key( $size_slug ) . '-';
		}

		return sprintf(
			'%s/%s.jpg',
			gmdate( 'Y/m' ),
			$name . substr( $input_hash, 0, 8 )
		);
	}

	/**
	 * Filename prefix identifying a post's cards.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID, 0 for the homepage card.
	 *
	 * @return string Prefix including its trailing hyphen.
	 */
	private function basename_prefix( int $post_id ): string {
		return $post_id > 0 ? 'card-' . $post_id . '-' : 'card-home-';
	}

	/**
	 * Every card file in the store.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Absolute paths.
	 */
	private function all_files(): array {
		$base = $this->directory->path();

		if ( ! is_dir( $base ) ) {
			return array();
		}

		$found = glob( $base . '/*/*/card-*.jpg' );

		return is_array( $found ) ? $found : array();
	}
}
