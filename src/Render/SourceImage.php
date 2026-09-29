<?php
/**
 * Source image resolution and load planning.
 *
 * Implements SPEC.md §4.4 (downscale before compositing) and the source restrictions
 * of §18.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Render;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Card\ImageSource as SourceRule;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what to load, and at what size, before any pixels are read.
 *
 * Named for what it plans rather than what it holds: nothing here opens an image.
 * The whole point is to settle the target dimensions first, so that neither renderer
 * ever calls its "load this file" function on a 6000px original. Deciding afterwards
 * is deciding too late — the memory has already been taken.
 *
 * @since 0.1.0
 */
final class SourceImage {

	/**
	 * Largest multiple of the destination box a source is loaded at, per SPEC §4.4.
	 */
	public const MAX_SCALE = 2;

	/**
	 * Smallest inbound background accepted, per SPEC §7.1.
	 */
	public const MIN_EDGE_W = 600;
	public const MIN_EDGE_H = 315;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path        Absolute path to the source file.
	 * @param int    $width       Intrinsic width.
	 * @param int    $height      Intrinsic height.
	 * @param int    $load_width  Width to decode at.
	 * @param int    $load_height Height to decode at.
	 * @param int    $orientation EXIF orientation, 1 when absent.
	 */
	private function __construct(
		public readonly string $path,
		public readonly int $width,
		public readonly int $height,
		public readonly int $load_width,
		public readonly int $load_height,
		public readonly int $orientation = 1
	) {}

	/**
	 * Resolves a layer source into a load plan.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $source        Layer source: a token value, attachment ID or path.
	 * @param Box         $destination   Box the image will be drawn into.
	 * @param MemoryGuard $memory        Memory guard.
	 * @param string[]    $allowed_roots Directories a path source may live in.
	 *
	 * @return self|null Plan, or null when the source cannot be used.
	 */
	public static function plan( string $source, Box $destination, MemoryGuard $memory, array $allowed_roots = array() ): ?self {
		$path = self::path_for( $source, $allowed_roots );

		if ( null === $path ) {
			return null;
		}

		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-image returns false, which is the answer.

		if ( false === $size || $size[0] < 1 || $size[1] < 1 ) {
			return null;
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];

		list( $load_width, $load_height ) = self::load_size( $width, $height, $destination, $memory );

		return new self( $path, $width, $height, $load_width, $load_height, self::orientation( $path ) );
	}

	/**
	 * Whether the source is large enough to be worth using as a background.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it meets the minimum from SPEC §7.1.
	 */
	public function meets_minimum(): bool {
		return $this->width >= self::MIN_EDGE_W && $this->height >= self::MIN_EDGE_H;
	}

	/**
	 * Whether the plan downscales the source.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the load size is smaller than the original.
	 */
	public function is_downscaled(): bool {
		return $this->load_width < $this->width || $this->load_height < $this->height;
	}

	/**
	 * Source aspect ratio.
	 *
	 * @since 0.1.0
	 *
	 * @return float Width divided by height.
	 */
	public function ratio(): float {
		return $this->height > 0 ? $this->width / $this->height : 1.0;
	}

	/**
	 * Computes the crop and destination rectangles for a fit mode.
	 *
	 * @since 0.1.0
	 *
	 * @param Box    $destination Box to fill.
	 * @param string $fit         cover | contain | fill.
	 *
	 * @return array{sx: int, sy: int, sw: int, sh: int, dx: int, dy: int, dw: int, dh: int} Rectangles.
	 */
	public function rects( Box $destination, string $fit ): array {
		$source_w = $this->load_width;
		$source_h = $this->load_height;

		if ( 'fill' === $fit ) {
			return array(
				'sx' => 0,
				'sy' => 0,
				'sw' => $source_w,
				'sh' => $source_h,
				'dx' => $destination->x,
				'dy' => $destination->y,
				'dw' => $destination->w,
				'dh' => $destination->h,
			);
		}

		$scale_x = $destination->w / max( 1, $source_w );
		$scale_y = $destination->h / max( 1, $source_h );

		if ( 'contain' === $fit ) {
			// Fit entirely inside the box, centred, leaving the rest of the box alone.
			$scale  = min( $scale_x, $scale_y );
			$width  = (int) round( $source_w * $scale );
			$height = (int) round( $source_h * $scale );

			return array(
				'sx' => 0,
				'sy' => 0,
				'sw' => $source_w,
				'sh' => $source_h,
				'dx' => $destination->x + (int) round( ( $destination->w - $width ) / 2 ),
				'dy' => $destination->y + (int) round( ( $destination->h - $height ) / 2 ),
				'dw' => $width,
				'dh' => $height,
			);
		}

		// cover: fill the box, cropping the overflowing axis from the centre.
		$scale  = max( $scale_x, $scale_y );
		$crop_w = (int) round( $destination->w / $scale );
		$crop_h = (int) round( $destination->h / $scale );
		$crop_w = min( $crop_w, $source_w );
		$crop_h = min( $crop_h, $source_h );

		return array(
			'sx' => (int) round( ( $source_w - $crop_w ) / 2 ),
			'sy' => (int) round( ( $source_h - $crop_h ) / 2 ),
			'sw' => $crop_w,
			'sh' => $crop_h,
			'dx' => $destination->x,
			'dy' => $destination->y,
			'dw' => $destination->w,
			'dh' => $destination->h,
		);
	}

	/**
	 * Resolves a source string to a readable path.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $source        Attachment ID, path or resolved token value.
	 * @param string[] $allowed_roots Directories a path source may live in.
	 *
	 * @return string|null Absolute path, or null.
	 */
	private static function path_for( string $source, array $allowed_roots ): ?string {
		$source = trim( $source );

		if ( '' === $source ) {
			return null;
		}

		if ( preg_match( '/^\d+$/', $source ) ) {
			$path = get_attached_file( (int) $source );

			return is_string( $path ) && is_readable( $path ) ? $path : null;
		}

		// Anything else must satisfy the same rule the Card Document validator applies.
		$classified = SourceRule::classify( $source, $allowed_roots );

		if ( SourceRule::KIND_PATH !== $classified['kind'] ) {
			return null;
		}

		return is_readable( $classified['value'] ) ? $classified['value'] : null;
	}

	/**
	 * Chooses the decode size.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $width       Intrinsic width.
	 * @param int         $height      Intrinsic height.
	 * @param Box         $destination Destination box.
	 * @param MemoryGuard $memory      Memory guard.
	 *
	 * @return array{0: int, 1: int} Load width and height.
	 */
	private static function load_size( int $width, int $height, Box $destination, MemoryGuard $memory ): array {
		$ratio = $height > 0 ? $width / $height : 1.0;

		// Never more than twice the destination box, per SPEC §4.4.
		$cap = max( 1, max( $destination->w, $destination->h ) * self::MAX_SCALE );

		$longest = max( $width, $height );
		$target  = min( $longest, $cap );

		// Then shrink further if even that will not fit in the remaining headroom.
		$target = min( $target, $memory->affordable_edge( (int) $target, $ratio ) );

		if ( $target >= $longest ) {
			return array( $width, $height );
		}

		$scale = $target / max( 1, $longest );

		return array(
			max( 1, (int) round( $width * $scale ) ),
			max( 1, (int) round( $height * $scale ) ),
		);
	}

	/**
	 * Reads EXIF orientation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path.
	 *
	 * @return int Orientation, 1 when unknown.
	 */
	private static function orientation( string $path ): int {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return 1;
		}

		$type = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $type, array( 'jpg', 'jpeg', 'tif', 'tiff' ), true ) ) {
			return 1;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed EXIF warns; the default is the answer.
		$exif = @exif_read_data( $path );

		if ( ! is_array( $exif ) || ! isset( $exif['Orientation'] ) ) {
			return 1;
		}

		$orientation = (int) $exif['Orientation'];

		return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
	}
}
