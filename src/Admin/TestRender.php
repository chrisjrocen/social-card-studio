<?php
/**
 * Diagnostics test render.
 *
 * Implements the "Run test render" button of SPEC.md §12.4.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Admin;

use ChrxDigital\SocialCardStudio\Card\CardDocument;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\MemoryGuard;
use ChrxDigital\SocialCardStudio\Render\RenderContext;
use ChrxDigital\SocialCardStudio\Render\RenderException;
use ChrxDigital\SocialCardStudio\Render\RendererFactory;
use ChrxDigital\SocialCardStudio\Render\RenderResult;
use ChrxDigital\SocialCardStudio\Render\UnsupportedScriptException;
use ChrxDigital\SocialCardStudio\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a sample card with the site's real settings and fonts.
 *
 * The point is to answer "will this work here" with an actual image rather than a
 * checklist. It uses the resolved fonts, the configured brand colour and whichever
 * engine the factory picks, so a failure here is the failure a real post would hit.
 *
 * @since 0.1.0
 */
final class TestRender {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param RendererFactory $factory  Renderer selection.
	 * @param FontResolver    $fonts    Font resolution chain.
	 * @param MemoryGuard     $memory   Memory guard.
	 * @param Settings        $settings Plugin settings.
	 */
	public function __construct(
		private readonly RendererFactory $factory,
		private readonly FontResolver $fonts,
		private readonly MemoryGuard $memory,
		private readonly Settings $settings
	) {}

	/**
	 * Runs a sample render.
	 *
	 * Never throws: this is the diagnostic that has to work when everything else is
	 * broken, so a failure is a reported result rather than an exception.
	 *
	 * @since 0.1.0
	 *
	 * @return array{ok: bool, error: string, result: RenderResult|null, data_uri: string} Outcome.
	 */
	public function run(): array {
		try {
			$built = CardDocument::try_from( $this->document() );

			if ( ! $built['ok'] ) {
				return $this->failure( implode( '; ', $built['errors'] ) );
			}

			$renderer = $this->factory->create();

			$result = $renderer->render(
				$built['document'],
				new RenderContext(
					$this->sample_tokens(),
					$this->fonts,
					$this->memory,
					'keep_if_supported' === $this->settings->get( 'emoji', 'strip' ),
					0,
					(int) $this->settings->get( 'output.quality', 82 )
				)
			);

			return array(
				'ok'       => true,
				'error'    => '',
				'result'   => $result,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a data: URI so the sample renders inline without being written to disk.
				'data_uri' => 'data:' . $result->mime . ';base64,' . base64_encode( $result->bytes ),
			);
		} catch ( UnsupportedScriptException $e ) {
			return $this->failure( $e->getMessage() );
		} catch ( RenderException $e ) {
			return $this->failure( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->failure( $e->getMessage() );
		}
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string $error Failure detail.
	 *
	 * @return array{ok: bool, error: string, result: RenderResult|null, data_uri: string} Outcome.
	 */
	private function failure( string $error ): array {
		return array(
			'ok'       => false,
			'error'    => $error,
			'result'   => null,
			'data_uri' => '',
		);
	}

	/**
	 * Sample token values.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Tokens.
	 */
	private function sample_tokens(): array {
		return array(
			'share_headline' => __( 'Your backups are lying to you', 'social-card-studio' ),
			'headline'       => __( 'Your backups are lying to you', 'social-card-studio' ),
			'title'          => __( 'Your backups are lying to you', 'social-card-studio' ),
			'primary_term'   => __( 'Sample', 'social-card-studio' ),
			'site_name'      => (string) get_bloginfo( 'name' ),
			'author_name'    => __( 'Test render', 'social-card-studio' ),
			'reading_time'   => __( '6 min read', 'social-card-studio' ),
		);
	}

	/**
	 * A document exercising every layer type the renderers support.
	 *
	 * Deliberately includes a gradient, a shape, tracked uppercase text and a
	 * shadowed headline: a test render that only drew a solid colour would pass on a
	 * server where real cards fail.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Card document.
	 */
	private function document(): array {
		$accent = (string) $this->settings->get( 'brand.accent', '#2563EB' );

		return array(
			'schema'    => 1,
			'id'        => 'diagnostics-sample',
			'version'   => 1,
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
			'layers'    => array(
				array(
					'type'  => 'gradient',
					'id'    => 'wash',
					'box'   => array(
						'x' => 0,
						'y' => 0,
						'w' => 1200,
						'h' => 630,
					),
					'stops' => array(
						array(
							'at'    => 0.0,
							'color' => '#1E293BFF',
						),
						array(
							'at'    => 1.0,
							'color' => '#0F172AFF',
						),
					),
					'angle' => 90,
				),
				array(
					'type'  => 'shape',
					'id'    => 'accent-bar',
					'shape' => 'rect',
					'fill'  => $accent,
					'box'   => array(
						'x' => 56,
						'y' => 262,
						'w' => 84,
						'h' => 6,
					),
				),
				array(
					'type'      => 'text',
					'id'        => 'eyebrow',
					'content'   => '{{primary_term}}',
					'box'       => array(
						'x' => 56,
						'y' => 210,
						'w' => 600,
						'h' => 34,
					),
					'size'      => array(
						'min' => 16,
						'max' => 22,
					),
					'font'      => array(
						'role'   => 'body',
						'weight' => 600,
					),
					'color'     => '#93C5FD',
					'transform' => 'uppercase',
					'tracking'  => 0.08,
					'max_lines' => 1,
				),
				array(
					'type'           => 'text',
					'id'             => 'headline',
					'content'        => '{{share_headline}}',
					'box'            => array(
						'x' => 56,
						'y' => 300,
						'w' => 760,
						'h' => 220,
					),
					'size'           => array(
						'min' => 34,
						'max' => 64,
					),
					'font'           => array(
						'role'   => 'heading',
						'weight' => 700,
					),
					'line_height'    => 1.12,
					'tracking'       => -0.01,
					'vertical_align' => 'bottom',
					'max_lines'      => 3,
					'color'          => '#FFFFFF',
					'shadow'         => array(
						'x'     => 0,
						'y'     => 2,
						'blur'  => 8,
						'color' => '#00000059',
					),
				),
				array(
					'type'      => 'text',
					'id'        => 'meta',
					'content'   => '{{author_name}} · {{reading_time}} · {{site_name}}',
					'box'       => array(
						'x' => 56,
						'y' => 545,
						'w' => 900,
						'h' => 30,
					),
					'size'      => array(
						'min' => 16,
						'max' => 20,
					),
					'color'     => '#CBD5E1',
					'max_lines' => 1,
				),
			),
		);
	}
}
