<?php
/**
 * Card Document schema declarations.
 *
 * Implements the strict allowlist required by SPEC.md §18.3, describing the structure
 * of SPEC.md §2.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Card;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a Card Document's shape is declared.
 *
 * Every numeric bound here is a real limit, not decoration. A card is 1200x630; a
 * layer at x=900000 with a 40000px font is not a design choice, it is a way to make
 * the renderer allocate until the process dies.
 *
 * @since 0.1.0
 */
final class CardDocumentSchema {

	/**
	 * Current document schema version.
	 */
	public const SCHEMA = 1;

	/**
	 * Ceiling on layers per document.
	 *
	 * Six presets use fewer than a dozen. Anything approaching this is either a bug
	 * or an attempt to exhaust memory.
	 */
	public const MAX_LAYERS = 60;

	/**
	 * Largest canvas edge accepted, in pixels.
	 */
	public const MAX_EDGE = 4000;

	/**
	 * Top-level document schema.
	 *
	 * `layers` is validated separately, per type, by CardDocumentValidator: the
	 * generic validator has no notion of a discriminated union.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	public static function document(): array {
		return array(
			'type'  => 'map',
			'shape' => array(
				'schema'    => array(
					'type'     => 'int',
					'required' => true,
					'min'      => 1,
					'max'      => self::SCHEMA,
				),
				'id'        => array(
					'type'      => 'string',
					'required'  => true,
					'maxlength' => 64,
					'pattern'   => '/^[a-z0-9][a-z0-9\-]*$/',
				),
				'version'   => array(
					'type'     => 'int',
					'required' => true,
					'min'      => 1,
					'max'      => 100000,
				),
				'canvas'    => array(
					'type'     => 'map',
					'required' => true,
					'shape'    => array(
						'w'  => array(
							'type'     => 'int',
							'required' => true,
							'min'      => 1,
							'max'      => self::MAX_EDGE,
						),
						'h'  => array(
							'type'     => 'int',
							'required' => true,
							'min'      => 1,
							'max'      => self::MAX_EDGE,
						),
						'bg' => array(
							'type'    => 'string',
							'format'  => 'color',
							'default' => '#000000',
						),
					),
				),
				'safe_area' => array(
					'type'  => 'map',
					'shape' => array(
						'top'    => self::inset(),
						'right'  => self::inset(),
						'bottom' => self::inset(),
						'left'   => self::inset(),
					),
				),
				'layers'    => array(
					'type'     => 'array',
					'required' => true,
					'maxitems' => self::MAX_LAYERS,
				),
			),
		);
	}

	/**
	 * Returns the schema for one layer type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type One of Layer::TYPES.
	 *
	 * @return array<string, mixed> Schema node, empty when the type is unknown.
	 */
	public static function layer( string $type ): array {
		return match ( $type ) {
			Layer::TYPE_IMAGE    => self::image_layer(),
			Layer::TYPE_TEXT     => self::text_layer(),
			Layer::TYPE_GRADIENT => self::gradient_layer(),
			Layer::TYPE_SHAPE    => self::shape_layer(),
			default              => array(),
		};
	}

	/**
	 * Schema for an image layer.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function image_layer(): array {
		return array(
			'type'  => 'map',
			'shape' => array_merge(
				self::common_shape( Layer::TYPE_IMAGE ),
				array(
					'source'   => array(
						'type'      => 'string',
						'required'  => true,
						'maxlength' => 512,
					),
					'fallback' => array(
						'type'      => 'string',
						'maxlength' => 512,
						'default'   => '',
					),
					'fit'      => array(
						'type'    => 'enum',
						'values'  => array( 'cover', 'contain', 'fill' ),
						'default' => 'cover',
					),
					'shape'    => array(
						'type'    => 'enum',
						'values'  => array( 'rect', 'rounded', 'circle' ),
						'default' => 'rect',
					),
					'radius'   => array(
						'type'    => 'int',
						'min'     => 0,
						'max'     => self::MAX_EDGE,
						'clamp'   => true,
						'default' => 0,
					),
					'opacity'  => self::opacity(),
					'effects'  => array(
						'type'  => 'map',
						'shape' => array(
							'blur'      => array(
								'type'    => 'int',
								'min'     => 0,
								'max'     => 100,
								'clamp'   => true,
								'default' => 0,
							),
							'grayscale' => array(
								'type'    => 'bool',
								'default' => false,
							),
							'tint'      => array(
								'type'    => 'string',
								'format'  => 'color',
								'default' => '',
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Schema for a text layer.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function text_layer(): array {
		return array(
			'type'  => 'map',
			'shape' => array_merge(
				self::common_shape( Layer::TYPE_TEXT ),
				array(
					'content'        => array(
						'type'      => 'string',
						'required'  => true,
						'maxlength' => 2000,
					),
					'font'           => array(
						'type'  => 'map',
						'shape' => array(
							'role'   => array(
								'type'    => 'enum',
								'values'  => array( 'heading', 'body', 'mono' ),
								'default' => 'body',
							),
							'weight' => array(
								'type'    => 'int',
								'min'     => 100,
								'max'     => 900,
								'clamp'   => true,
								'default' => 400,
							),
							// A preset's preferred bundled family. Consulted only at the
							// bundled step of SPEC §6.1, so an admin's explicit font
							// choice and the site's theme still take precedence.
							'family' => array(
								'type'      => 'string',
								'maxlength' => 64,
								'pattern'   => '/^[a-z0-9\-]*$/',
								'default'   => '',
							),
						),
					),
					'size'           => array(
						'type'  => 'map',
						'shape' => array(
							'min'     => array(
								'type'    => 'int',
								'min'     => 6,
								'max'     => 400,
								'clamp'   => true,
								'default' => 16,
							),
							'max'     => array(
								'type'    => 'int',
								'min'     => 6,
								'max'     => 400,
								'clamp'   => true,
								'default' => 64,
							),
							'autofit' => array(
								'type'    => 'bool',
								'default' => true,
							),
						),
					),
					'line_height'    => array(
						'type'    => 'float',
						'min'     => 0.5,
						'max'     => 4.0,
						'clamp'   => true,
						'default' => 1.2,
					),
					'tracking'       => array(
						'type'    => 'float',
						'min'     => -0.5,
						'max'     => 1.0,
						'clamp'   => true,
						'default' => 0.0,
					),
					'align'          => array(
						'type'    => 'enum',
						'values'  => array( 'left', 'center', 'right' ),
						'default' => 'left',
					),
					'vertical_align' => array(
						'type'    => 'enum',
						'values'  => array( 'top', 'middle', 'bottom' ),
						'default' => 'top',
					),
					'max_lines'      => array(
						'type'    => 'int',
						'min'     => 1,
						'max'     => 20,
						'clamp'   => true,
						'default' => 3,
					),
					'overflow'       => array(
						'type'    => 'enum',
						'values'  => array( 'ellipsis', 'shrink_box', 'clip' ),
						'default' => 'ellipsis',
					),
					'color'          => array(
						'type'    => 'string',
						'format'  => 'color',
						'default' => '#FFFFFF',
					),
					'transform'      => array(
						'type'    => 'enum',
						'values'  => array( 'none', 'uppercase', 'lowercase' ),
						'default' => 'none',
					),
					'shadow'         => array(
						'type'  => 'map',
						'shape' => array(
							'x'     => self::offset(),
							'y'     => self::offset(),
							'blur'  => array(
								'type'    => 'int',
								'min'     => 0,
								'max'     => 100,
								'clamp'   => true,
								'default' => 0,
							),
							'color' => array(
								'type'    => 'string',
								'format'  => 'color',
								'default' => '#00000059',
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Schema for a gradient layer.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function gradient_layer(): array {
		return array(
			'type'  => 'map',
			'shape' => array_merge(
				self::common_shape( Layer::TYPE_GRADIENT ),
				array(
					'stops'         => array(
						'type'     => 'array',
						'required' => true,
						'maxitems' => 8,
						'items'    => array(
							'type'  => 'map',
							'shape' => array(
								'at'    => array(
									'type'     => 'float',
									'required' => true,
									'min'      => 0.0,
									'max'      => 1.0,
									'clamp'    => true,
								),
								'color' => array(
									'type'     => 'string',
									'required' => true,
									'format'   => 'color',
								),
							),
						),
					),
					'angle'         => array(
						'type'    => 'int',
						'min'     => 0,
						'max'     => 360,
						'clamp'   => true,
						'default' => 90,
					),
					'auto_contrast' => array(
						'type'  => 'map',
						'shape' => array(
							'target'  => array(
								'type'    => 'float',
								'min'     => 1.0,
								'max'     => 21.0,
								'clamp'   => true,
								'default' => 4.5,
							),
							'against' => array(
								'type'      => 'string',
								'maxlength' => 64,
								'pattern'   => '/^[a-z0-9][a-z0-9\-_]*$/',
								'default'   => '',
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Schema for a shape layer.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function shape_layer(): array {
		return array(
			'type'  => 'map',
			'shape' => array_merge(
				self::common_shape( Layer::TYPE_SHAPE ),
				array(
					'shape'        => array(
						'type'     => 'enum',
						'required' => true,
						'values'   => array( 'rect', 'rounded-rect', 'circle', 'line' ),
					),
					'fill'         => array(
						'type'    => 'string',
						'format'  => 'color',
						'default' => '',
					),
					'stroke'       => array(
						'type'    => 'string',
						'format'  => 'color',
						'default' => '',
					),
					'stroke_width' => array(
						'type'    => 'int',
						'min'     => 0,
						'max'     => 200,
						'clamp'   => true,
						'default' => 0,
					),
					'radius'       => array(
						'type'    => 'int',
						'min'     => 0,
						'max'     => self::MAX_EDGE,
						'clamp'   => true,
						'default' => 0,
					),
					'opacity'      => self::opacity(),
				)
			),
		);
	}

	/**
	 * Keys every layer type shares.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type The layer type this shape belongs to.
	 *
	 * @return array<string, mixed> Partial shape.
	 */
	private static function common_shape( string $type ): array {
		return array(
			'type'     => array(
				'type'     => 'enum',
				'required' => true,
				'values'   => array( $type ),
			),
			'id'       => array(
				'type'      => 'string',
				'required'  => true,
				'maxlength' => 64,
				'pattern'   => '/^[a-z0-9][a-z0-9\-_]*$/',
			),
			'box'      => array(
				'type'     => 'map',
				'required' => true,
				'shape'    => array(
					'x' => self::coordinate(),
					'y' => self::coordinate(),
					'w' => array(
						'type'     => 'int',
						'required' => true,
						'min'      => 0,
						'max'      => self::MAX_EDGE,
					),
					'h' => array(
						'type'     => 'int',
						'required' => true,
						'min'      => 0,
						'max'      => self::MAX_EDGE,
					),
				),
			),
			'flexible' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'hidden'   => array(
				'type'    => 'bool',
				'default' => false,
			),
		);
	}

	/**
	 * A coordinate node.
	 *
	 * Negative values are legitimate — a background image is often bled off-canvas —
	 * but only to one canvas-width outside, not to arbitrary depths.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function coordinate(): array {
		return array(
			'type'     => 'int',
			'required' => true,
			'min'      => -self::MAX_EDGE,
			'max'      => self::MAX_EDGE,
		);
	}

	/**
	 * A safe-area inset node.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function inset(): array {
		return array(
			'type'    => 'int',
			'min'     => 0,
			'max'     => 1000,
			'clamp'   => true,
			'default' => 0,
		);
	}

	/**
	 * A shadow offset node.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function offset(): array {
		return array(
			'type'    => 'int',
			'min'     => -100,
			'max'     => 100,
			'clamp'   => true,
			'default' => 0,
		);
	}

	/**
	 * An opacity node.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node.
	 */
	private static function opacity(): array {
		return array(
			'type'    => 'float',
			'min'     => 0.0,
			'max'     => 1.0,
			'clamp'   => true,
			'default' => 1.0,
		);
	}
}
