<?php
/**
 * PHP-versus-JavaScript layout parity.
 *
 * Implements the parity rule of SPEC.md §2.3 and the M3 acceptance criterion: the two
 * engines must choose identical font sizes and produce identical line counts for the
 * whole fixture set.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Card\Box;
use ChrxDigital\SocialCardStudio\Render\LayoutSpec;
use ChrxDigital\SocialCardStudio\Render\ResolvedFont;
use ChrxDigital\SocialCardStudio\Render\TableMetrics;
use ChrxDigital\SocialCardStudio\Render\TextLayout;
use ChrxDigital\SocialCardStudio\Render\UnsupportedScriptException;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared fixtures through the PHP engine, and through node when available.
 */
final class LayoutParityTest extends TestCase {

	/**
	 * Decoded fixture file.
	 *
	 * @var array<string, mixed>
	 */
	private static array $fixtures = array();

	/**
	 * Loads the fixtures once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$path = dirname( __DIR__ ) . '/fixtures/layout-fixtures.json';

		self::$fixtures = (array) json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * Runs every fixture through the PHP engine.
	 *
	 * @return array<string, array<string, mixed>> Results keyed by case ID.
	 */
	private function run_php(): array {
		$metrics = TableMetrics::from_array( (array) self::$fixtures['metrics'] );
		$layout  = new TextLayout( $metrics );
		$font    = new ResolvedFont( ResolvedFont::ROLE_HEADING, 'Inter', '', 400 );
		$results = array();

		foreach ( (array) self::$fixtures['cases'] as $case ) {
			$case = (array) $case;
			$spec = (array) $case['spec'];
			$box  = (array) $spec['box'];

			$layout_spec = new LayoutSpec(
				new Box( (int) $box['x'], (int) $box['y'], (int) $box['w'], (int) $box['h'] ),
				(float) $spec['sizeMin'],
				(float) $spec['sizeMax'],
				(bool) ( $spec['autofit'] ?? true ),
				(float) $spec['lineHeight'],
				(float) ( $spec['tracking'] ?? 0 ),
				(string) ( $spec['align'] ?? 'left' ),
				(string) ( $spec['verticalAlign'] ?? 'top' ),
				(int) $spec['maxLines'],
				(string) ( $spec['overflow'] ?? 'ellipsis' ),
				(string) ( $spec['transform'] ?? 'none' )
			);

			try {
				$result = $layout->layout( (string) $case['text'], $layout_spec, $font, false );

				$results[ (string) $case['id'] ] = array(
					'size'      => $result->size,
					'lineCount' => $result->line_count(),
					'lines'     => $result->texts(),
					'collapsed' => $result->collapsed,
					'truncated' => $result->truncated,
				);
			} catch ( UnsupportedScriptException $e ) {
				$results[ (string) $case['id'] ] = array( 'error' => 'UnsupportedScriptError' );
			}
		}

		return $results;
	}

	/**
	 * The fixture file is well formed and covers the spec's title shapes.
	 *
	 * @return void
	 */
	public function test_fixtures_are_loaded(): void {
		$this->assertNotEmpty( self::$fixtures['cases'] ?? array() );
		$this->assertNotEmpty( self::$fixtures['metrics']['advances'] ?? array() );
		$this->assertGreaterThan( 100, count( (array) self::$fixtures['cases'] ) );
	}

	/**
	 * The PHP engine lays out every fixture without throwing.
	 *
	 * @return void
	 */
	public function test_php_engine_handles_every_fixture(): void {
		$results = $this->run_php();

		$this->assertCount( count( (array) self::$fixtures['cases'] ), $results );

		foreach ( $results as $id => $result ) {
			$this->assertArrayNotHasKey( 'error', $result, $id . ' should not raise' );
		}
	}

	/**
	 * Every laid-out line fits its box, and the line ceiling is respected.
	 *
	 * This is the substance behind "lays out sensibly at every preset box size": a
	 * chosen size that overflows the box would still be a parity match while being
	 * visibly wrong.
	 *
	 * @return void
	 */
	public function test_every_result_respects_its_box(): void {
		$metrics = TableMetrics::from_array( (array) self::$fixtures['metrics'] );
		$font    = new ResolvedFont( ResolvedFont::ROLE_HEADING, 'Inter', '', 400 );
		$results = $this->run_php();

		foreach ( (array) self::$fixtures['cases'] as $case ) {
			$case   = (array) $case;
			$id     = (string) $case['id'];
			$spec   = (array) $case['spec'];
			$box    = (array) $spec['box'];
			$result = $results[ $id ];

			if ( ! empty( $result['collapsed'] ) ) {
				continue;
			}

			$this->assertLessThanOrEqual(
				(int) $spec['maxLines'],
				$result['lineCount'],
				$id . ' exceeded max_lines'
			);

			$this->assertGreaterThanOrEqual( (float) $spec['sizeMin'], $result['size'], $id . ' went below size.min' );
			$this->assertLessThanOrEqual( (float) $spec['sizeMax'], $result['size'], $id . ' went above size.max' );

			// Chosen sizes must land on the 0.5px grid from SPEC §6.3 step 2.
			$this->assertSame(
				0.0,
				fmod( $result['size'] * 2, 1.0 ),
				$id . ' chose a size off the 0.5px grid'
			);

			foreach ( $result['lines'] as $line ) {
				$width = $metrics->width( $line, $font, (float) $result['size'], (float) ( $spec['tracking'] ?? 0 ) );

				// A single grapheme wider than the box cannot be broken further.
				$characters = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );

				if ( is_array( $characters ) && 1 === count( $characters ) ) {
					continue;
				}

				$this->assertLessThanOrEqual(
					(float) $box['w'] + 0.01,
					$width,
					$id . ' produced a line wider than its box: "' . $line . '"'
				);
			}
		}
	}

	/**
	 * The PHP and JavaScript engines agree on sizes, line counts and line contents.
	 *
	 * Skipped rather than failed when node is absent, so a contributor without it can
	 * still run the suite; CI always has node, so parity is always enforced there.
	 *
	 * @return void
	 */
	public function test_php_and_javascript_engines_agree(): void {
		$root   = dirname( __DIR__, 2 );
		$script = $root . '/editor/test/parity.mjs';
		$json   = $root . '/tests/fixtures/layout-fixtures.json';

		$node = $this->node_binary();

		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not available; parity is enforced in CI.' );
		}

		// escapeshellarg, not escapeshellcmd: a node installed under a path containing
		// spaces (nvm under "Application Support", say) needs quoting, not escaping.
		$command = sprintf( '%s %s %s 2>/dev/null', escapeshellarg( $node ), escapeshellarg( $script ), escapeshellarg( $json ) );
		$output  = shell_exec( $command );

		$this->assertIsString( $output, 'the JavaScript parity runner produced no output' );

		$js = json_decode( (string) $output, true );

		$this->assertIsArray( $js, 'the JavaScript parity runner produced invalid JSON' );

		$php = $this->run_php();

		$this->assertSame( array_keys( $php ), array_keys( $js ), 'the engines disagree on which cases exist' );

		$size_mismatches = array();
		$line_mismatches = array();
		$text_mismatches = array();

		foreach ( $php as $id => $expected ) {
			$actual = $js[ $id ];

			/*
			 * Compared numerically, not with !==: JSON gives back 64 where PHP holds
			 * 64.0, and both are exact multiples of the 0.5px grid, so any real
			 * divergence is far larger than this epsilon.
			 */
			if ( abs( (float) ( $expected['size'] ?? -1 ) - (float) ( $actual['size'] ?? -2 ) ) > 1e-9 ) {
				$size_mismatches[] = sprintf( '%s: php=%s js=%s', $id, var_export( $expected['size'] ?? null, true ), var_export( $actual['size'] ?? null, true ) );
			}

			if ( ( $expected['lineCount'] ?? null ) !== ( $actual['lineCount'] ?? null ) ) {
				$line_mismatches[] = sprintf( '%s: php=%s js=%s', $id, var_export( $expected['lineCount'] ?? null, true ), var_export( $actual['lineCount'] ?? null, true ) );
			}

			if ( ( $expected['lines'] ?? null ) !== ( $actual['lines'] ?? null ) ) {
				$text_mismatches[] = sprintf(
					'%s: php=%s js=%s',
					$id,
					wp_json_encode( $expected['lines'] ?? null ),
					wp_json_encode( $actual['lines'] ?? null )
				);
			}
		}

		$this->assertSame( array(), $size_mismatches, "Chosen font sizes diverged:\n" . implode( "\n", array_slice( $size_mismatches, 0, 10 ) ) );
		$this->assertSame( array(), $line_mismatches, "Line counts diverged:\n" . implode( "\n", array_slice( $line_mismatches, 0, 10 ) ) );
		$this->assertSame( array(), $text_mismatches, "Line contents diverged:\n" . implode( "\n", array_slice( $text_mismatches, 0, 10 ) ) );
	}

	/**
	 * Locates a node binary.
	 *
	 * @return string Path, or empty when unavailable.
	 */
	private function node_binary(): string {
		$found = shell_exec( 'command -v node 2>/dev/null' );

		return is_string( $found ) ? trim( $found ) : '';
	}
}
