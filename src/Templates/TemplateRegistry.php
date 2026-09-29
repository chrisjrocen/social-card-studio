<?php
/**
 * Preset template registry.
 *
 * Implements the template half of SPEC.md §5.3 and the preset list in §1.3.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Templates;

use ChrxDigital\SocialCardStudio\Card\CardDocument;
use ChrxDigital\SocialCardStudio\Card\CardDocumentValidator;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * Loads, validates and serves the preset Card Documents.
 *
 * Presets are ordinary Card Documents and go through the same validator as anything
 * a user could supply. A preset that only worked because it skipped validation would
 * be a preset that stopped working the moment someone edited it.
 *
 * @since 0.1.0
 */
final class TemplateRegistry {

	/**
	 * The presets shipped in 1.0, in the order the settings UI lists them.
	 */
	public const PRESETS = array(
		'editorial-left',
		'bold-statement',
		'split-frame',
		'minimal-serif',
		'author-feature',
		'brand-statement',
	);

	/**
	 * The preset used for the homepage and as the site default card.
	 */
	public const HOMEPAGE = 'brand-statement';

	/**
	 * Validated documents, keyed by identifier.
	 *
	 * @var array<string, CardDocument>|null
	 */
	private ?array $documents = null;

	/**
	 * Validation errors encountered while loading, keyed by identifier.
	 *
	 * @var array<string, string[]>
	 */
	private array $errors = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CardDirectory $directory Card directory, for allowed image roots.
	 */
	public function __construct( private readonly CardDirectory $directory ) {}

	/**
	 * Absolute path to the bundled preset directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string Directory path without a trailing slash.
	 */
	public static function directory(): string {
		return rtrim( SCSTUDIO_DIR, '/' ) . '/src/Templates/presets';
	}

	/**
	 * Every registered template.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, CardDocument> Documents keyed by identifier.
	 */
	public function all(): array {
		if ( null !== $this->documents ) {
			return $this->documents;
		}

		$raw = array();

		foreach ( self::PRESETS as $id ) {
			$path = self::directory() . '/' . $id . '.json';

			if ( ! is_readable( $path ) ) {
				$this->errors[ $id ] = array( 'the preset file is missing' );

				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset.
			$decoded = json_decode( (string) file_get_contents( $path ), true );

			if ( ! is_array( $decoded ) ) {
				$this->errors[ $id ] = array( 'the preset file is not valid JSON' );

				continue;
			}

			$raw[ $id ] = $decoded;
		}

		/**
		 * Filters the raw template documents before validation.
		 *
		 * Documented in SPEC §21 as the way a theme or plugin registers its own
		 * templates. Everything registered here is validated exactly as the bundled
		 * presets are.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array<string, mixed>> $raw Documents keyed by identifier.
		 */
		$raw = (array) apply_filters( 'scstudio_template_registry', $raw );

		$roots     = array( $this->directory->path(), rtrim( SCSTUDIO_DIR, '/' ) . '/assets' );
		$validator = new CardDocumentValidator( $roots );

		$this->documents = array();

		foreach ( $raw as $id => $document ) {
			$result = $validator->validate( $document );

			if ( ! $result['ok'] ) {
				$this->errors[ (string) $id ] = $result['errors'];

				continue;
			}

			$this->documents[ (string) $id ] = CardDocument::from_validated( $result['value'] );
		}

		return $this->documents;
	}

	/**
	 * Returns one template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Template identifier.
	 *
	 * @return CardDocument|null The document, or null when unknown or invalid.
	 */
	public function get( string $id ): ?CardDocument {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Returns a template, falling back to a usable one.
	 *
	 * A missing or broken template must never stop a card being drawn; the settings
	 * screen reports the problem separately.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id       Preferred identifier.
	 * @param string $fallback Identifier to use when the first is unavailable.
	 *
	 * @return CardDocument|null A document, or null when nothing loaded at all.
	 */
	public function get_or_fallback( string $id, string $fallback = 'editorial-left' ): ?CardDocument {
		$document = $this->get( $id );

		if ( null !== $document ) {
			return $document;
		}

		$document = $this->get( $fallback );

		if ( null !== $document ) {
			return $document;
		}

		$all = $this->all();

		return array() === $all ? null : reset( $all );
	}

	/**
	 * Identifiers of every usable template.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Identifiers.
	 */
	public function ids(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Whether a template is available.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Template identifier.
	 *
	 * @return bool True when usable.
	 */
	public function exists( string $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * Validation errors from the last load.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string[]> Errors keyed by identifier.
	 */
	public function errors(): array {
		$this->all();

		return $this->errors;
	}

	/**
	 * Discards the cached documents.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->documents = null;
		$this->errors    = array();
	}
}
