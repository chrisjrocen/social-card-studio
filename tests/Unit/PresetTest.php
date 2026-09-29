<?php
/**
 * Preset template tests.
 *
 * Covers the six presets required by SPEC.md §1.3, validated exactly as a
 * user-supplied document would be.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\CardDocument;
use ChrxDigital\SocialCardStudio\Card\CardDocumentValidator;
use ChrxDigital\SocialCardStudio\Card\ImageSource;
use ChrxDigital\SocialCardStudio\Card\Layer;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the bundled presets.
 */
final class PresetTest extends TestCase {

	/**
	 * Loads and validates one preset.
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return CardDocument Validated document.
	 */
	private function preset( string $id ): CardDocument {
		$path = dirname( __DIR__, 2 ) . '/src/Templates/presets/' . $id . '.json';

		$this->assertFileExists( $path, $id . ' is missing' );

		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $decoded, $id . ' is not valid JSON' );

		$result = ( new CardDocumentValidator() )->validate( $decoded );

		$this->assertTrue( $result['ok'], $id . ' failed validation: ' . implode( '; ', $result['errors'] ) );

		return CardDocument::from_validated( $result['value'] );
	}

	/**
	 * Every preset named in the registry ships and validates.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_preset_validates( string $id ): void {
		$document = $this->preset( $id );

		$this->assertSame( $id, $document->id(), 'the document id must match its filename' );
		$this->assertGreaterThanOrEqual( 1, $document->version() );
		$this->assertNotEmpty( $document->layers() );
	}

	/**
	 * The six presets from SPEC §1.3.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_presets(): array {
		return array_map(
			static fn ( string $id ): array => array( $id ),
			TemplateRegistry::PRESETS
		);
	}

	/**
	 * Exactly the six named presets exist, no more and no fewer.
	 *
	 * @return void
	 */
	public function test_the_shipped_set_is_the_declared_set(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Templates/presets/*.json' );

		$found = array_map(
			static fn ( string $path ): string => basename( $path, '.json' ),
			is_array( $files ) ? $files : array()
		);

		sort( $found );
		$expected = TemplateRegistry::PRESETS;
		sort( $expected );

		$this->assertSame( $expected, $found );
	}

	/**
	 * Every preset draws at the OG size.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_canvas_is_the_og_size( string $id ): void {
		$canvas = $this->preset( $id )->canvas();

		$this->assertSame( 1200, $canvas->w );
		$this->assertSame( 630, $canvas->h );
	}

	/**
	 * Every layer stays inside the canvas.
	 *
	 * A box hanging off the edge is not a design choice at this size; it is a preset
	 * that will look truncated in every feed.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_layers_stay_within_the_canvas( string $id ): void {
		$document = $this->preset( $id );
		$canvas   = $document->canvas();

		foreach ( $document->layers() as $layer ) {
			$box = $layer->box();

			$this->assertGreaterThanOrEqual( 0, $box->x, "{$id}/{$layer->id} starts left of the canvas" );
			$this->assertGreaterThanOrEqual( 0, $box->y, "{$id}/{$layer->id} starts above the canvas" );
			$this->assertLessThanOrEqual( $canvas->w, $box->right(), "{$id}/{$layer->id} runs off the right" );
			$this->assertLessThanOrEqual( $canvas->h, $box->bottom(), "{$id}/{$layer->id} runs off the bottom" );
		}
	}

	/**
	 * Image sources are tokens, never baked-in paths.
	 *
	 * A preset that referenced a file on the machine that built it would work once,
	 * in development, and nowhere else.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_image_sources_are_tokens( string $id ): void {
		foreach ( $this->preset( $id )->layers() as $layer ) {
			if ( Layer::TYPE_IMAGE !== $layer->type ) {
				continue;
			}

			foreach ( array( $layer->source(), (string) $layer->get( 'fallback', '' ) ) as $source ) {
				if ( '' === $source ) {
					continue;
				}

				$this->assertSame(
					ImageSource::KIND_TOKEN,
					ImageSource::classify( $source )['kind'],
					"{$id}/{$layer->id} uses a non-token image source: {$source}"
				);
			}
		}
	}

	/**
	 * Every text layer can shrink, wrap and truncate.
	 *
	 * This is what makes the acceptance criterion "survives a 1-word and a 40-word
	 * title" structurally true rather than true for the titles someone happened to try.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_text_layers_can_adapt( string $id ): void {
		$found = 0;

		foreach ( $this->preset( $id )->layers() as $layer ) {
			if ( Layer::TYPE_TEXT !== $layer->type ) {
				continue;
			}

			++$found;

			$size  = (array) $layer->get( 'size', array() );
			$min   = (int) ( $size['min'] ?? 0 );
			$max   = (int) ( $size['max'] ?? 0 );
			$where = "{$id}/{$layer->id}";

			$this->assertTrue( (bool) ( $size['autofit'] ?? false ), "{$where} has autofit off" );
			$this->assertLessThan( $max, $min, "{$where} has no room to shrink" );
			$this->assertGreaterThanOrEqual( 12, $min, "{$where} can shrink below legibility" );
			$this->assertNotSame( 'clip', $layer->get( 'overflow' ), "{$where} clips instead of ellipsising" );

			// The box must fit at least max_lines at the minimum size.
			$lines  = (int) $layer->get( 'max_lines', 1 );
			$needed = $lines * $min * (float) $layer->get( 'line_height', 1.2 );

			$this->assertGreaterThanOrEqual(
				$needed - 1.0,
				(float) $layer->box()->h,
				"{$where} cannot fit {$lines} lines at its minimum size"
			);
		}

		$this->assertGreaterThan( 0, $found, $id . ' has no text at all' );
	}

	/**
	 * Every preset has a headline layer bound to the share headline.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_preset_renders_the_headline( string $id ): void {
		$content = '';

		foreach ( $this->preset( $id )->layers() as $layer ) {
			if ( Layer::TYPE_TEXT === $layer->type ) {
				$content .= $layer->content();
			}
		}

		$this->assertStringContainsString(
			'share_headline',
			$content,
			$id . ' never renders {{share_headline}}'
		);
	}

	/**
	 * Layer identifiers are unique within a preset.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_layer_ids_are_unique( string $id ): void {
		$ids = array();

		foreach ( $this->preset( $id )->layers() as $layer ) {
			$ids[] = $layer->id;
		}

		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), $id . ' repeats a layer id' );
	}

	/**
	 * An auto-contrast scrim points at a text layer that exists.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $id Preset identifier.
	 *
	 * @return void
	 */
	public function test_auto_contrast_targets_resolve( string $id ): void {
		$document = $this->preset( $id );

		foreach ( $document->layers() as $layer ) {
			if ( Layer::TYPE_GRADIENT !== $layer->type ) {
				continue;
			}

			$against = (string) ( ( (array) $layer->get( 'auto_contrast', array() ) )['against'] ?? '' );

			if ( '' === $against ) {
				continue;
			}

			$target = $document->layer( $against );

			$this->assertNotNull( $target, "{$id}/{$layer->id} guards a layer that does not exist: {$against}" );
			$this->assertSame( Layer::TYPE_TEXT, $target->type, "{$id}/{$layer->id} guards a non-text layer" );
		}
	}
}
