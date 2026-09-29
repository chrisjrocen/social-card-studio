<?php
/**
 * Card Document validation tests.
 *
 * Covers SPEC.md §2.1 and the hostile-input requirements of §18.3. Every test in the
 * rejection group is an input that must never reach a renderer.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\CardDocument;
use ChrxDigital\SocialCardStudio\Card\CardDocumentSchema;
use ChrxDigital\SocialCardStudio\Card\CardDocumentValidator;
use ChrxDigital\SocialCardStudio\Card\Layer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Card Document layer.
 */
final class CardDocumentTest extends TestCase {

	/**
	 * A minimal valid document.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> Document.
	 */
	private function document( array $overrides = array() ): array {
		return array_merge(
			array(
				'schema'    => 1,
				'id'        => 'editorial-left',
				'version'   => 3,
				'canvas'    => array(
					'w'  => 1200,
					'h'  => 630,
					'bg' => '#0F172A',
				),
				'safe_area' => array(
					'top'    => 48,
					'right'  => 56,
					'bottom' => 48,
					'left'   => 56,
				),
				'layers'    => array( $this->text_layer() ),
			),
			$overrides
		);
	}

	/**
	 * A minimal valid text layer.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> Layer.
	 */
	private function text_layer( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'    => 'text',
				'id'      => 'headline',
				'content' => '{{share_headline|title}}',
				'box'     => array(
					'x' => 56,
					'y' => 300,
					'w' => 760,
					'h' => 220,
				),
			),
			$overrides
		);
	}

	/**
	 * Validates a document and returns the raw result.
	 *
	 * @param array<string, mixed> $document      Document to validate.
	 * @param string[]             $allowed_roots Allowed image roots.
	 *
	 * @return array{ok: bool, value: array<string, mixed>, errors: string[]} Result.
	 */
	private function validate( array $document, array $allowed_roots = array() ): array {
		return ( new CardDocumentValidator( $allowed_roots ) )->validate( $document );
	}

	// ---------------------------------------------------------------- accepted

	/**
	 * A well-formed document validates and builds.
	 *
	 * @return void
	 */
	public function test_valid_document_builds(): void {
		$result = CardDocument::try_from( $this->document() );

		$this->assertTrue( $result['ok'], implode( '; ', $result['errors'] ) );
		$this->assertInstanceOf( CardDocument::class, $result['document'] );
		$this->assertSame( 'editorial-left', $result['document']->id() );
		$this->assertSame( 3, $result['document']->version() );
		$this->assertSame( 1200, $result['document']->canvas()->w );
		$this->assertCount( 1, $result['document']->layers() );
	}

	/**
	 * Typed accessors expose canvas, safe area and ordered layers.
	 *
	 * @return void
	 */
	public function test_typed_accessors(): void {
		$document = CardDocument::try_from(
			$this->document(
				array(
					'layers' => array(
						$this->text_layer( array( 'id' => 'first' ) ),
						$this->text_layer( array( 'id' => 'second' ) ),
					),
				)
			)
		)['document'];

		$this->assertNotNull( $document );

		$layers = $document->layers();

		$this->assertSame( 'first', $layers[0]->id );
		$this->assertSame( 'second', $layers[1]->id );
		$this->assertSame( Layer::TYPE_TEXT, $layers[0]->type );
		$this->assertNotNull( $document->layer( 'second' ) );
		$this->assertNull( $document->layer( 'absent' ) );

		$safe = $document->canvas()->safe_box();

		$this->assertSame( 56, $safe->x );
		$this->assertSame( 1200 - 56 - 56, $safe->w );
		$this->assertSame( 56, $layers[0]->box()->x );
	}

	/**
	 * Defaults are filled in for omitted optional keys.
	 *
	 * @return void
	 */
	public function test_defaults_are_applied(): void {
		$document = CardDocument::try_from( $this->document() )['document'];

		$this->assertNotNull( $document );

		$layer = $document->layers()[0];

		$this->assertSame( 'left', $layer->get( 'align' ) );
		$this->assertSame( 3, $layer->get( 'max_lines' ) );
		$this->assertSame( 'ellipsis', $layer->get( 'overflow' ) );
	}

	/**
	 * A round trip through to_array() preserves the document.
	 *
	 * @return void
	 */
	public function test_round_trip(): void {
		$first = CardDocument::try_from( $this->document() )['document'];
		$this->assertNotNull( $first );

		$second = CardDocument::try_from( $first->to_array() );

		$this->assertTrue( $second['ok'], implode( '; ', $second['errors'] ) );
		$this->assertSame( $first->to_array(), $second['document']->to_array() );
	}

	/**
	 * All four layer types are accepted.
	 *
	 * @return void
	 */
	public function test_all_layer_types_accepted(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						array(
							'type'   => 'image',
							'id'     => 'bg',
							'source' => '{{featured_image}}',
							'box'    => array(
								'x' => 0,
								'y' => 0,
								'w' => 1200,
								'h' => 630,
							),
						),
						array(
							'type'  => 'gradient',
							'id'    => 'scrim',
							'box'   => array(
								'x' => 0,
								'y' => 0,
								'w' => 1200,
								'h' => 630,
							),
							'stops' => array(
								array(
									'at'    => 0.0,
									'color' => '#0F172AE6',
								),
								array(
									'at'    => 1.0,
									'color' => '#0F172A33',
								),
							),
						),
						$this->text_layer(),
						array(
							'type'  => 'shape',
							'id'    => 'accent-bar',
							'shape' => 'rect',
							'fill'  => '#2563EB',
							'box'   => array(
								'x' => 56,
								'y' => 260,
								'w' => 80,
								'h' => 6,
							),
						),
					),
				)
			)
		);

		$this->assertTrue( $result['ok'], implode( '; ', $result['errors'] ) );
		$this->assertCount( 4, $result['value']['layers'] );
	}

	// --------------------------------------------------------------- rejected

	/**
	 * A path traversal in an image source is rejected.
	 *
	 * @return void
	 */
	public function test_path_traversal_source_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						array(
							'type'   => 'image',
							'id'     => 'bg',
							'source' => '../../../wp-config.php',
							'box'    => array(
								'x' => 0,
								'y' => 0,
								'w' => 10,
								'h' => 10,
							),
						),
					),
				)
			),
			array( '/tmp' )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'traversal', implode( ' ', $result['errors'] ) );
	}

	/**
	 * Remote and exotic URL schemes are all rejected.
	 *
	 * @dataProvider provide_remote_sources
	 *
	 * @param string $source Hostile source value.
	 *
	 * @return void
	 */
	public function test_remote_sources_are_rejected( string $source ): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						array(
							'type'   => 'image',
							'id'     => 'bg',
							'source' => $source,
							'box'    => array(
								'x' => 0,
								'y' => 0,
								'w' => 10,
								'h' => 10,
							),
						),
					),
				)
			)
		);

		$this->assertFalse( $result['ok'], $source . ' should be rejected' );
	}

	/**
	 * Hostile source values.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_remote_sources(): array {
		return array(
			array( 'https://evil.example/x.png' ),
			array( 'http://127.0.0.1:8080/admin' ),
			array( '//evil.example/x.png' ),
			array( 'file:///etc/passwd' ),
			array( 'data:image/png;base64,iVBORw0KGgo=' ),
			array( 'phar://payload.phar/x.png' ),
			array( 'php://filter/read=convert.base64-encode/resource=wp-config.php' ),
		);
	}

	/**
	 * A document with more layers than the ceiling is rejected.
	 *
	 * @return void
	 */
	public function test_ten_thousand_layers_are_rejected(): void {
		$result = $this->validate(
			$this->document( array( 'layers' => array_fill( 0, 10000, $this->text_layer() ) ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString(
			sprintf( 'more than %d items', CardDocumentSchema::MAX_LAYERS ),
			implode( ' ', $result['errors'] )
		);
	}

	/**
	 * A negative box width is rejected.
	 *
	 * @return void
	 */
	public function test_negative_box_width_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						$this->text_layer(
							array(
								'box' => array(
									'x' => 0,
									'y' => 0,
									'w' => -500,
									'h' => 100,
								),
							)
						),
					),
				)
			)
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A colour carrying script is rejected.
	 *
	 * @dataProvider provide_hostile_colors
	 *
	 * @param string $color Hostile colour literal.
	 *
	 * @return void
	 */
	public function test_hostile_colors_are_rejected( string $color ): void {
		$result = $this->validate(
			$this->document(
				array( 'layers' => array( $this->text_layer( array( 'color' => $color ) ) ) )
			)
		);

		$this->assertFalse( $result['ok'], $color . ' should be rejected' );
	}

	/**
	 * Hostile colour literals.
	 *
	 * @return array<int, array{0: string}> Test cases.
	 */
	public static function provide_hostile_colors(): array {
		return array(
			array( 'javascript:alert(1)' ),
			array( '#fff" onload="alert(1)' ),
			array( 'expression(alert(1))' ),
			array( 'url(https://evil.example/x)' ),
			array( '<script>alert(1)</script>' ),
		);
	}

	/**
	 * A deeply nested structure is rejected without exhausting the stack.
	 *
	 * @return void
	 */
	public function test_deeply_nested_document_is_rejected(): void {
		$nested = array( 'deep' => 'value' );

		for ( $i = 0; $i < 400; $i++ ) {
			$nested = array( 'deep' => $nested );
		}

		$result = $this->validate( $this->document( array( 'safe_area' => $nested ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'nests deeper', implode( ' ', $result['errors'] ) );
	}

	/**
	 * An unknown layer type is rejected rather than ignored.
	 *
	 * @return void
	 */
	public function test_unknown_layer_type_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						array(
							'type' => 'html',
							'id'   => 'x',
							'box'  => array(
								'x' => 0,
								'y' => 0,
								'w' => 1,
								'h' => 1,
							),
						),
					),
				)
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'type must be one of', implode( ' ', $result['errors'] ) );
	}

	/**
	 * An unknown key inside a layer is rejected.
	 *
	 * @return void
	 */
	public function test_unknown_layer_key_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array( 'layers' => array( $this->text_layer( array( 'onclick' => 'alert(1)' ) ) ) )
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'onclick', implode( ' ', $result['errors'] ) );
	}

	/**
	 * Duplicate layer IDs are rejected, since layers are addressed by ID.
	 *
	 * @return void
	 */
	public function test_duplicate_layer_ids_are_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array( $this->text_layer(), $this->text_layer() ),
				)
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'already used', implode( ' ', $result['errors'] ) );
	}

	/**
	 * A canvas larger than the ceiling is rejected.
	 *
	 * @return void
	 */
	public function test_oversized_canvas_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'canvas' => array(
						'w'  => 100000,
						'h'  => 100000,
						'bg' => '#000000',
					),
				)
			)
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A future schema version is rejected rather than guessed at.
	 *
	 * @return void
	 */
	public function test_future_schema_version_is_rejected(): void {
		$this->assertFalse( $this->validate( $this->document( array( 'schema' => 99 ) ) )['ok'] );
	}

	/**
	 * A text layer whose minimum size exceeds its maximum is rejected.
	 *
	 * @return void
	 */
	public function test_inverted_font_size_range_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						$this->text_layer(
							array(
								'size' => array(
									'min' => 90,
									'max' => 30,
								),
							)
						),
					),
				)
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'above size.max', implode( ' ', $result['errors'] ) );
	}

	/**
	 * A gradient with one stop is rejected.
	 *
	 * @return void
	 */
	public function test_single_stop_gradient_is_rejected(): void {
		$result = $this->validate(
			$this->document(
				array(
					'layers' => array(
						array(
							'type'  => 'gradient',
							'id'    => 'scrim',
							'box'   => array(
								'x' => 0,
								'y' => 0,
								'w' => 10,
								'h' => 10,
							),
							'stops' => array(
								array(
									'at'    => 0.0,
									'color' => '#000000',
								),
							),
						),
					),
				)
			)
		);

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Scalars and nulls in place of a document fail cleanly.
	 *
	 * @dataProvider provide_non_documents
	 *
	 * @param mixed $input Non-document input.
	 *
	 * @return void
	 */
	public function test_non_documents_fail_cleanly( mixed $input ): void {
		$result = ( new CardDocumentValidator() )->validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Inputs that are not documents at all.
	 *
	 * @return array<int, array{0: mixed}> Test cases.
	 */
	public static function provide_non_documents(): array {
		return array(
			array( null ),
			array( 'a string' ),
			array( 42 ),
			array( true ),
		);
	}

	/**
	 * Malformed JSON is reported as malformed JSON.
	 *
	 * @return void
	 */
	public function test_invalid_json_is_reported(): void {
		$result = ( new CardDocumentValidator() )->validate_json( '{"schema": 1,,}' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not valid JSON', implode( ' ', $result['errors'] ) );
	}

	/**
	 * Valid JSON validates through the JSON entry point.
	 *
	 * @return void
	 */
	public function test_valid_json_is_accepted(): void {
		$json   = (string) json_encode( $this->document() );
		$result = ( new CardDocumentValidator() )->validate_json( $json );

		$this->assertTrue( $result['ok'], implode( '; ', $result['errors'] ) );
	}
}
