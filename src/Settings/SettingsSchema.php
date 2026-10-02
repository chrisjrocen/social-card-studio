<?php
/**
 * Settings defaults and validation schema.
 *
 * Implements the scstudio_settings structure of SPEC.md §5.1.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the settings shape is declared.
 *
 * @since 0.1.0
 */
final class SettingsSchema {

	/**
	 * Default settings, per SPEC §5.1.
	 *
	 * DB version 2 removed brand.logo_id, brand.logo_position, homepage_card.enabled
	 * and output.format, target_bytes and max_bytes: nothing read them.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Defaults.
	 */
	public static function defaults(): array {
		return array(
			'enabled_post_types'       => array( 'post' ),
			'default_template'         => 'editorial-left',
			'per_type_template'        => array(),
			'triggers'                 => array(
				'on_publish' => true,
				'lazy'       => true,
			),
			'brand'                    => array(
				'accent' => '#2563EB',
			),
			'typography'               => array(
				'source'  => 'theme',
				'heading' => '',
				'body'    => '',
			),
			'homepage_card'            => array(
				'headline' => '',
				'template' => 'brand-statement',
			),
			'output'                   => array(
				'quality' => 82,
			),
			'media_library_mode'       => false,
			'alt_text_pattern'         => '{{headline}} — {{site_name}}',
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Validation schema consumed by Support\Schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schema node for the whole settings array.
	 */
	public static function schema(): array {
		return array(
			'type'  => 'map',
			'shape' => array(
				'enabled_post_types'       => array(
					'type'     => 'array',
					'maxitems' => 100,
					'items'    => array(
						'type'      => 'string',
						'maxlength' => 20,
						'pattern'   => '/^[a-z0-9_\-]+$/',
					),
				),
				'default_template'         => array(
					'type'      => 'string',
					'maxlength' => 64,
					'pattern'   => '/^[a-z0-9\-]+$/',
				),
				'per_type_template'        => array( 'type' => 'array' ),
				'triggers'                 => array(
					'type'  => 'map',
					'shape' => array(
						'on_publish' => array( 'type' => 'bool' ),
						'lazy'       => array( 'type' => 'bool' ),
					),
				),
				'brand'                    => array(
					'type'  => 'map',
					'shape' => array(
						'accent' => array(
							'type'   => 'string',
							'format' => 'color',
						),
					),
				),
				'typography'               => array(
					'type'  => 'map',
					'shape' => array(
						'source'  => array(
							'type'   => 'enum',
							'values' => array( 'theme', 'bundled' ),
						),
						'heading' => array(
							'type'      => 'string',
							'maxlength' => 128,
						),
						'body'    => array(
							'type'      => 'string',
							'maxlength' => 128,
						),
					),
				),
				'homepage_card'            => array(
					'type'  => 'map',
					'shape' => array(
						'headline' => array(
							'type'      => 'string',
							'maxlength' => 200,
						),
						'template' => array(
							'type'      => 'string',
							'maxlength' => 64,
							'pattern'   => '/^[a-z0-9\-]+$/',
						),
					),
				),
				'output'                   => array(
					'type'  => 'map',
					'shape' => array(
						'quality' => array(
							'type'  => 'int',
							'min'   => 40,
							'max'   => 100,
							'clamp' => true,
						),
					),
				),
				'media_library_mode'       => array( 'type' => 'bool' ),
				'alt_text_pattern'         => array(
					'type'      => 'string',
					'maxlength' => 200,
				),
				'delete_data_on_uninstall' => array( 'type' => 'bool' ),
			),
		);
	}
}
