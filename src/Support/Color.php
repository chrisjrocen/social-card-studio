<?php
/**
 * Colour parsing and contrast maths.
 *
 * Implements the colour validation of SPEC.md §18.3 and the contrast target used by
 * the auto-contrast scrim (§2.1) and the AI legibility guard (§13.1).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable RGBA colour.
 *
 * @since 0.1.0
 */
final class Color {

	/**
	 * Accepted literal forms, per SPEC §18.3.
	 */
	private const PATTERN = '/^(#[0-9a-f]{3}|#[0-9a-f]{6}|#[0-9a-f]{8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\))$/i';

	/**
	 * Constructs a colour from clamped channels.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $r Red, 0-255.
	 * @param int   $g Green, 0-255.
	 * @param int   $b Blue, 0-255.
	 * @param float $a Alpha, 0.0-1.0.
	 */
	private function __construct(
		public readonly int $r,
		public readonly int $g,
		public readonly int $b,
		public readonly float $a
	) {}

	/**
	 * Parses a colour literal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Colour string.
	 *
	 * @return self|null Parsed colour, or null when the literal is not accepted.
	 */
	public static function parse( string $value ): ?self {
		$value = trim( $value );

		if ( ! preg_match( self::PATTERN, $value ) ) {
			return null;
		}

		if ( str_starts_with( $value, '#' ) ) {
			return self::from_hex( $value );
		}

		return self::from_rgb_function( $value );
	}

	/**
	 * Reports whether a literal is acceptable without constructing it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Colour string.
	 *
	 * @return bool True when parseable.
	 */
	public static function is_valid( string $value ): bool {
		return null !== self::parse( $value );
	}

	/**
	 * Renders as #RRGGBB.
	 *
	 * @since 0.1.0
	 *
	 * @return string Hex string without alpha.
	 */
	public function to_hex(): string {
		return sprintf( '#%02X%02X%02X', $this->r, $this->g, $this->b );
	}

	/**
	 * Renders as #RRGGBBAA.
	 *
	 * @since 0.1.0
	 *
	 * @return string Hex string with alpha.
	 */
	public function to_hexa(): string {
		return sprintf( '#%02X%02X%02X%02X', $this->r, $this->g, $this->b, (int) round( $this->a * 255 ) );
	}

	/**
	 * Returns a copy with a different alpha.
	 *
	 * @since 0.1.0
	 *
	 * @param float $alpha New alpha, clamped to 0.0-1.0.
	 *
	 * @return self New colour.
	 */
	public function with_alpha( float $alpha ): self {
		return new self( $this->r, $this->g, $this->b, max( 0.0, min( 1.0, $alpha ) ) );
	}

	/**
	 * Linearly interpolates between two colours, alpha included.
	 *
	 * Used for gradient stops. Interpolation is in sRGB rather than a perceptual
	 * space: it is what every browser and design tool does for a CSS gradient, so a
	 * scrim authored against the DOM preview matches the shipped card.
	 *
	 * @since 0.1.0
	 *
	 * @param self  $from Start colour.
	 * @param self  $to   End colour.
	 * @param float $t    Position, 0.0-1.0.
	 *
	 * @return self Interpolated colour.
	 */
	public static function mix( self $from, self $to, float $t ): self {
		$t = max( 0.0, min( 1.0, $t ) );

		$lerp = static fn ( int $a, int $b ): int => (int) round( $a + ( ( $b - $a ) * $t ) );

		return new self(
			$lerp( $from->r, $to->r ),
			$lerp( $from->g, $to->g ),
			$lerp( $from->b, $to->b ),
			$from->a + ( ( $to->a - $from->a ) * $t )
		);
	}

	/**
	 * Relative luminance per WCAG 2.1.
	 *
	 * @since 0.1.0
	 *
	 * @return float Luminance, 0.0-1.0.
	 */
	public function luminance(): float {
		$channels = array();

		foreach ( array( $this->r, $this->g, $this->b ) as $channel ) {
			$srgb       = $channel / 255;
			$channels[] = $srgb <= 0.04045 ? $srgb / 12.92 : ( ( $srgb + 0.055 ) / 1.055 ) ** 2.4;
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * Contrast ratio against another colour, per WCAG 2.1.
	 *
	 * Alpha is ignored: callers composite first, then measure. Measuring a
	 * translucent colour against a background it has not been blended onto gives a
	 * number that looks authoritative and is wrong.
	 *
	 * @since 0.1.0
	 *
	 * @param self $other Colour to compare against.
	 *
	 * @return float Ratio between 1.0 and 21.0.
	 */
	public function contrast( self $other ): float {
		$a = $this->luminance();
		$b = $other->luminance();

		$light = max( $a, $b );
		$dark  = min( $a, $b );

		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * Composites this colour over an opaque backdrop.
	 *
	 * @since 0.1.0
	 *
	 * @param self $backdrop Opaque colour underneath.
	 *
	 * @return self Blended, fully opaque colour.
	 */
	public function over( self $backdrop ): self {
		$alpha = $this->a;
		$blend = static fn ( int $top, int $bottom ): int => (int) round( ( $top * $alpha ) + ( $bottom * ( 1 - $alpha ) ) );

		return new self(
			$blend( $this->r, $backdrop->r ),
			$blend( $this->g, $backdrop->g ),
			$blend( $this->b, $backdrop->b ),
			1.0
		);
	}

	/**
	 * Parses #RGB, #RRGGBB and #RRGGBBAA.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Hex literal including the leading hash.
	 *
	 * @return self Parsed colour.
	 */
	private static function from_hex( string $value ): self {
		$hex = ltrim( $value, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$alpha = 8 === strlen( $hex ) ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0;

		return new self(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
			(float) $alpha
		);
	}

	/**
	 * Parses rgb() and rgba().
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Functional literal.
	 *
	 * @return self Parsed colour.
	 */
	private static function from_rgb_function( string $value ): self {
		preg_match_all( '/[\d.]+/', $value, $matches );

		$parts = $matches[0];
		$clamp = static fn ( string $n ): int => max( 0, min( 255, (int) $n ) );

		return new self(
			$clamp( $parts[0] ?? '0' ),
			$clamp( $parts[1] ?? '0' ),
			$clamp( $parts[2] ?? '0' ),
			isset( $parts[3] ) ? max( 0.0, min( 1.0, (float) $parts[3] ) ) : 1.0
		);
	}
}
