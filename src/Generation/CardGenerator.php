<?php
/**
 * Card generation.
 *
 * The single entry point required by SPEC.md §11: every trigger goes through here, and
 * it is idempotent on the input hash.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Generation;

use ChrxDigital\SocialCardStudio\Card\AltText;
use ChrxDigital\SocialCardStudio\Card\CardRecord;
use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Card\InputHasher;
use ChrxDigital\SocialCardStudio\Card\RenderProfile;
use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Render\MemoryGuard;
use ChrxDigital\SocialCardStudio\Render\RenderContext;
use ChrxDigital\SocialCardStudio\Render\RenderException;
use ChrxDigital\SocialCardStudio\Render\RendererFactory;
use ChrxDigital\SocialCardStudio\Render\UnsupportedScriptException;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Storage\CardStore;
use ChrxDigital\SocialCardStudio\Storage\MediaLibraryBridge;
use ChrxDigital\SocialCardStudio\Support\Log;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders one card and stores it.
 *
 * Wrapped end to end. SPEC §12.2 requires that no render path can produce a fatal on
 * a public page, and the lazy endpoint calls straight into this during a request, so
 * every failure here has to come back as a value.
 *
 * @since 0.1.0
 */
final class CardGenerator {

	/**
	 * Option holding the homepage card record.
	 */
	public const HOME_OPTION = 'scstudio_home_card';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param RendererFactory    $factory    Renderer selection.
	 * @param TemplateRegistry   $templates  Preset templates.
	 * @param TokenResolver      $tokens     Token substitution.
	 * @param FontResolver       $fonts      Font resolution.
	 * @param MemoryGuard        $memory     Memory guard.
	 * @param InputHasher        $hasher     Input hashing.
	 * @param CardStore          $store      File storage.
	 * @param CardRepository     $repository Meta storage.
	 * @param AltText            $alt        Alt text generation.
	 * @param MediaLibraryBridge $bridge     Attachment bridge.
	 * @param Settings           $settings   Plugin settings.
	 * @param Log                $log        Event log.
	 * @param Lock               $lock       Stampede protection.
	 * @param RenderProfile      $profile    Template, engine and font context.
	 */
	public function __construct(
		private readonly RendererFactory $factory,
		private readonly TemplateRegistry $templates,
		private readonly TokenResolver $tokens,
		private readonly FontResolver $fonts,
		private readonly MemoryGuard $memory,
		private readonly InputHasher $hasher,
		private readonly CardStore $store,
		private readonly CardRepository $repository,
		private readonly AltText $alt,
		private readonly MediaLibraryBridge $bridge,
		private readonly Settings $settings,
		private readonly Log $log,
		private readonly Lock $lock,
		private readonly RenderProfile $profile
	) {}

	/**
	 * Ensures a post has a current card, rendering only when needed.
	 *
	 * Idempotent on the input hash: calling it repeatedly for an unchanged post does
	 * no work beyond a hash comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id Post to generate for.
	 * @param bool $force   Render even when the stored hash still matches.
	 *
	 * @return array{ok: bool, record: CardRecord|null, error: string, rendered: bool} Outcome.
	 */
	public function ensure( int $post_id, bool $force = false ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return $this->failure( 'That post does not exist.' );
		}

		if ( $this->repository->is_disabled( $post_id ) ) {
			return $this->failure( 'Card generation is switched off for this post.' );
		}

		/**
		 * Filters whether a card should be generated for a post.
		 *
		 * Documented in SPEC §21 as the per-post veto.
		 *
		 * @since 0.1.0
		 *
		 * @param bool    $should Whether to generate.
		 * @param WP_Post $post   The post.
		 */
		if ( ! (bool) apply_filters( 'scstudio_should_generate', true, $post ) ) {
			return $this->failure( 'Generation was vetoed by a filter.' );
		}

		$hash     = $this->hasher->for_post( $post );
		$existing = $this->repository->get( $post_id );

		if ( ! $force
			&& null !== $existing
			&& $existing->input_hash() === $hash
			&& $existing->exists( $this->store_directory() )
		) {
			return array(
				'ok'       => true,
				'record'   => $existing,
				'error'    => '',
				'rendered' => false,
			);
		}

		return $this->render_and_store( $post_id, $hash, $existing );
	}

	/**
	 * Renders and stores the homepage card.
	 *
	 * This is also fallback step 5 in SPEC §12.1, so it is the one card that must
	 * exist even when nothing else does.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $force Render even when the stored hash still matches.
	 *
	 * @return array{ok: bool, record: CardRecord|null, error: string, rendered: bool} Outcome.
	 */
	public function ensure_home( bool $force = false ): array {
		$tokens = $this->tokens->for_post( 0 );

		$headline = (string) $this->settings->get( 'homepage_card.headline', '' );

		if ( '' !== $headline ) {
			$tokens['share_headline'] = $headline;
			$tokens['headline']       = $headline;
		}

		$hash = $this->hasher->hash(
			array_merge(
				$this->profile->context( 0 ),
				array(
					'home'    => 1,
					'name'    => $tokens['site_name'] ?? '',
					'tagline' => $tokens['site_tagline'] ?? '',
					'logo'    => $tokens['site_logo'] ?? '',
					'head'    => $headline,
				)
			)
		);

		$stored = $this->home_record();

		if ( ! $force && null !== $stored && $stored->input_hash() === $hash && $stored->exists( $this->store_directory() ) ) {
			return array(
				'ok'       => true,
				'record'   => $stored,
				'error'    => '',
				'rendered' => false,
			);
		}

		$template = (string) $this->settings->get( 'homepage_card.template', TemplateRegistry::HOMEPAGE );
		$document = $this->templates->get_or_fallback( $template, TemplateRegistry::HOMEPAGE );

		if ( null === $document ) {
			return $this->failure( 'No usable template for the homepage card.' );
		}

		try {
			$result = $this->factory->create()->render(
				$document,
				new RenderContext(
					$tokens,
					$this->fonts,
					$this->memory,
					0,
					(int) $this->settings->get( 'output.quality', 82 )
				)
			);
		} catch ( \Throwable $e ) {
			$this->log->error( 'Homepage card render failed: ' . $e->getMessage() );

			return $this->failure( $e->getMessage() );
		}

		if ( null !== $stored ) {
			$this->store->retire( $stored->file(), 0 );
		}

		$record = $this->store->write(
			0,
			$result,
			$hash,
			array(
				'template_id'  => $document->id(),
				'template_ver' => $document->version(),
				'alt'          => $this->alt->generate( $tokens ),
			)
		);

		if ( null === $record ) {
			return $this->failure( 'The homepage card could not be written to disk.' );
		}

		update_option( self::HOME_OPTION, $record->to_array(), false );

		return array(
			'ok'       => true,
			'record'   => $record,
			'error'    => '',
			'rendered' => true,
		);
	}

	/**
	 * The stored homepage card record.
	 *
	 * @since 0.1.0
	 *
	 * @return CardRecord|null Record, or null when none exists.
	 */
	public function home_record(): ?CardRecord {
		$raw = get_option( self::HOME_OPTION, array() );

		return is_array( $raw ) && array() !== $raw ? CardRecord::from_array( $raw ) : null;
	}

	/**
	 * Renders under a lock and stores the result.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $post_id  Post to render.
	 * @param string          $hash     Input hash.
	 * @param CardRecord|null $existing Record being replaced, if any.
	 *
	 * @return array{ok: bool, record: CardRecord|null, error: string, rendered: bool} Outcome.
	 */
	private function render_and_store( int $post_id, string $hash, ?CardRecord $existing ): array {
		if ( ! $this->lock->acquire( $post_id ) ) {
			return $this->failure( 'Another process is already rendering this card.' );
		}

		$started = microtime( true );

		try {
			$document = $this->templates->get_or_fallback( $this->profile->template_for( $post_id ) );

			if ( null === $document ) {
				return $this->failure( 'No usable template is available.' );
			}

			$tokens = $this->tokens->for_post( $post_id );

			$result = $this->factory->create()->render(
				$document,
				new RenderContext(
					$tokens,
					$this->fonts,
					$this->memory,
					$post_id,
					(int) $this->settings->get( 'output.quality', 82 )
				)
			);

			// The superseded file stays on disk through the grace window, because
			// platform caches still point at its URL (SPEC §5.3).
			if ( null !== $existing && $existing->file() !== '' ) {
				$this->store->retire( $existing->file(), $post_id );
			}

			$overrides = $this->repository->overrides( $post_id );

			$record = $this->store->write(
				$post_id,
				$result,
				$hash,
				array(
					'template_id'  => $document->id(),
					'template_ver' => $document->version(),
					'alt'          => $this->alt->generate( $tokens, (string) ( $overrides['alt'] ?? '' ) ),
				)
			);

			if ( null === $record ) {
				return $this->failure( 'The card could not be written to disk.' );
			}

			if ( $this->bridge->is_enabled() ) {
				$attachment = $this->bridge->attach( $post_id, $record );

				if ( $attachment > 0 ) {
					$record = CardRecord::from_array(
						array_merge( $record->to_array(), array( 'attachment_id' => $attachment ) )
					);
				}
			}

			$this->repository->save( $post_id, $record );

			$this->log->record(
				Log::TYPE_RENDER_OK,
				Log::STATUS_OK,
				array(
					'post_id'     => $post_id,
					'mode'        => 'template',
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'message'     => $result->is_degraded() ? 'degraded: ' . implode( ',', $result->degradations ) : '',
				)
			);

			if ( $result->is_degraded() ) {
				$this->log->record(
					Log::TYPE_DEGRADED,
					Log::STATUS_OK,
					array(
						'post_id' => $post_id,
						'message' => implode( ',', $result->degradations ),
					)
				);
			}

			return array(
				'ok'       => true,
				'record'   => $record,
				'error'    => '',
				'rendered' => true,
			);
		} catch ( UnsupportedScriptException $e ) {
			$this->log->record(
				Log::TYPE_RENDER_ERROR,
				Log::STATUS_ERROR,
				array(
					'post_id' => $post_id,
					'mode'    => 'unsupported_script',
					'message' => $e->getMessage(),
				)
			);

			return $this->failure( $e->getMessage() );
		} catch ( RenderException $e ) {
			$this->log->record(
				Log::TYPE_RENDER_ERROR,
				Log::STATUS_ERROR,
				array(
					'post_id' => $post_id,
					'mode'    => $e->reason,
					'message' => $e->getMessage(),
				)
			);

			return $this->failure( $e->getMessage() );
		} catch ( \Throwable $e ) {
			$this->log->error( $e->getMessage(), array( 'post_id' => $post_id ) );

			return $this->failure( $e->getMessage() );
		} finally {
			$this->lock->release( $post_id );
		}
	}

	/**
	 * The card directory, for existence checks.
	 *
	 * @since 0.1.0
	 *
	 * @return \ChrxDigital\SocialCardStudio\Storage\CardDirectory Directory resolver.
	 */
	private function store_directory(): \ChrxDigital\SocialCardStudio\Storage\CardDirectory {
		return new \ChrxDigital\SocialCardStudio\Storage\CardDirectory();
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string $error Failure detail.
	 *
	 * @return array{ok: bool, record: CardRecord|null, error: string, rendered: bool} Outcome.
	 */
	private function failure( string $error ): array {
		return array(
			'ok'       => false,
			'record'   => null,
			'error'    => $error,
			'rendered' => false,
		);
	}
}
