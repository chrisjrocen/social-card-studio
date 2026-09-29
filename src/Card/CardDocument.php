<?php
/**
 * Card Document value object.
 *
 * Implements SPEC.md §2.1 — the single contract between templates and renderers.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable, validated description of how to draw a card.
 *
 * There is no public constructor taking raw input: the only ways in are through
 * CardDocumentValidator, so an instance of this class is a guarantee that the
 * document passed the allowlist in SPEC §18.3.
 *
 * @since 0.1.0
 */
final class CardDocument {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $schema  Document schema version.
	 * @param string  $id      Template identifier.
	 * @param int     $version Template version.
	 * @param Canvas  $canvas  Canvas and safe area.
	 * @param Layer[] $layers  Ordered layers, back to front.
	 */
	private function __construct(
		private readonly int $schema,
		private readonly string $id,
		private readonly int $version,
		private readonly Canvas $canvas,
		private readonly array $layers
	) {}

	/**
	 * Builds from data that has already passed CardDocumentValidator.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $value Validated document data.
	 *
	 * @return self New document.
	 */
	public static function from_validated( array $value ): self {
		$layers = array();

		foreach ( (array) ( $value['layers'] ?? array() ) as $layer ) {
			$layers[] = Layer::from_array( (array) $layer );
		}

		return new self(
			(int) $value['schema'],
			(string) $value['id'],
			(int) $value['version'],
			Canvas::from_array(
				(array) ( $value['canvas'] ?? array() ),
				(array) ( $value['safe_area'] ?? array() )
			),
			$layers
		);
	}

	/**
	 * Validates and builds in one step.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed    $raw           Decoded document.
	 * @param string[] $allowed_roots Absolute directories an image path may live in.
	 *
	 * @return array{ok: bool, document: self|null, errors: string[]} Result.
	 */
	public static function try_from( mixed $raw, array $allowed_roots = array() ): array {
		$result = ( new CardDocumentValidator( $allowed_roots ) )->validate( $raw );

		if ( ! $result['ok'] ) {
			return array(
				'ok'       => false,
				'document' => null,
				'errors'   => $result['errors'],
			);
		}

		return array(
			'ok'       => true,
			'document' => self::from_validated( $result['value'] ),
			'errors'   => array(),
		);
	}

	/**
	 * Document schema version.
	 *
	 * @since 0.1.0
	 *
	 * @return int Schema version.
	 */
	public function schema(): int {
		return $this->schema;
	}

	/**
	 * Template identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string Template ID.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Template version.
	 *
	 * @since 0.1.0
	 *
	 * @return int Version number.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Canvas and safe area.
	 *
	 * @since 0.1.0
	 *
	 * @return Canvas The drawing surface.
	 */
	public function canvas(): Canvas {
		return $this->canvas;
	}

	/**
	 * Ordered layers, back to front.
	 *
	 * @since 0.1.0
	 *
	 * @return Layer[] Layers in draw order.
	 */
	public function layers(): array {
		return $this->layers;
	}

	/**
	 * Finds a layer by identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Layer identifier.
	 *
	 * @return Layer|null The layer, or null when absent.
	 */
	public function layer( string $id ): ?Layer {
		foreach ( $this->layers as $layer ) {
			if ( $layer->id === $id ) {
				return $layer;
			}
		}

		return null;
	}

	/**
	 * Returns a copy with a different layer list.
	 *
	 * @since 0.1.0
	 *
	 * @param Layer[] $layers Replacement layers.
	 *
	 * @return self New document.
	 */
	public function with_layers( array $layers ): self {
		return new self( $this->schema, $this->id, $this->version, $this->canvas, $layers );
	}

	/**
	 * Serialises back to an array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Document data.
	 */
	public function to_array(): array {
		return array(
			'schema'    => $this->schema,
			'id'        => $this->id,
			'version'   => $this->version,
			'canvas'    => array(
				'w'  => $this->canvas->w,
				'h'  => $this->canvas->h,
				'bg' => $this->canvas->bg,
			),
			'safe_area' => $this->canvas->safe_area,
			'layers'    => array_map(
				static fn ( Layer $layer ): array => $layer->to_array(),
				$this->layers
			),
		);
	}
}
