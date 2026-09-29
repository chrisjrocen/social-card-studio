<?php
/**
 * Layer value object.
 *
 * Implements the `layers` structure of SPEC.md §2.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

defined( 'ABSPATH' ) || exit;

/**
 * One drawable layer, already validated against its type's schema.
 *
 * Layer data stays as an array behind typed accessors rather than becoming four
 * subclasses. The renderers switch on type anyway, the shapes are declared once in
 * CardDocumentSchema, and nothing has been validated by the time a subclass would
 * need to be chosen.
 *
 * @since 0.1.0
 */
final class Layer {

	public const TYPE_IMAGE    = 'image';
	public const TYPE_TEXT     = 'text';
	public const TYPE_GRADIENT = 'gradient';
	public const TYPE_SHAPE    = 'shape';

	/**
	 * Every layer type the renderers accept, per SPEC §2.1.
	 */
	public const TYPES = array(
		self::TYPE_IMAGE,
		self::TYPE_TEXT,
		self::TYPE_GRADIENT,
		self::TYPE_SHAPE,
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $type Layer type, one of TYPES.
	 * @param string               $id   Layer identifier, unique within a document.
	 * @param array<string, mixed> $data Validated layer data.
	 */
	private function __construct(
		public readonly string $type,
		public readonly string $id,
		private readonly array $data
	) {}

	/**
	 * Builds from validated data.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $raw Validated layer data including type and id.
	 *
	 * @return self New layer.
	 */
	public static function from_array( array $raw ): self {
		return new self(
			(string) $raw['type'],
			(string) $raw['id'],
			$raw
		);
	}

	/**
	 * Returns one property.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key      Property name.
	 * @param mixed  $fallback Returned when the property is absent.
	 *
	 * @return mixed Property value.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	/**
	 * The layer's box.
	 *
	 * @since 0.1.0
	 *
	 * @return Box Layer rectangle, zero-sized when the layer declares none.
	 */
	public function box(): Box {
		$box = $this->data['box'] ?? array();

		return Box::from_array( is_array( $box ) ? $box : array() );
	}

	/**
	 * Whether siblings may reflow into this layer's space when it collapses.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the layer is marked flexible.
	 */
	public function is_flexible(): bool {
		return (bool) ( $this->data['flexible'] ?? false );
	}

	/**
	 * The raw content template of a text layer.
	 *
	 * @since 0.1.0
	 *
	 * @return string Token-bearing template, empty for non-text layers.
	 */
	public function content(): string {
		return (string) ( $this->data['content'] ?? '' );
	}

	/**
	 * The source template of an image layer.
	 *
	 * @since 0.1.0
	 *
	 * @return string Token or reference, empty for non-image layers.
	 */
	public function source(): string {
		return (string) ( $this->data['source'] ?? '' );
	}

	/**
	 * Serialises back to an array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Layer data.
	 */
	public function to_array(): array {
		return $this->data;
	}
}
