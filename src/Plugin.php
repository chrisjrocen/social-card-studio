<?php
/**
 * Plugin container and hook registration.
 *
 * Implements SPEC.md §3 (architecture).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio;

use ChrxDigital\SocialCardStudio\Admin\BackfillPage;
use ChrxDigital\SocialCardStudio\Admin\ClassicMetabox;
use ChrxDigital\SocialCardStudio\Admin\EditorAssets;
use ChrxDigital\SocialCardStudio\Admin\DiagnosticsPage;
use ChrxDigital\SocialCardStudio\Admin\PostListColumn;
use ChrxDigital\SocialCardStudio\Card\AltText;
use ChrxDigital\SocialCardStudio\Card\CardRepository;
use ChrxDigital\SocialCardStudio\Card\InputHasher;
use ChrxDigital\SocialCardStudio\Card\RenderProfile;
use ChrxDigital\SocialCardStudio\Card\TokenResolver;
use ChrxDigital\SocialCardStudio\Delivery\Endpoint;
use ChrxDigital\SocialCardStudio\Delivery\ImageResolver;
use ChrxDigital\SocialCardStudio\Delivery\MetaTags;
use ChrxDigital\SocialCardStudio\Delivery\Priority;
use ChrxDigital\SocialCardStudio\Delivery\SeoDetector;
use ChrxDigital\SocialCardStudio\Generation\CardGenerator;
use ChrxDigital\SocialCardStudio\Generation\Backfill;
use ChrxDigital\SocialCardStudio\Generation\JobRunner;
use ChrxDigital\SocialCardStudio\Generation\Lock;
use ChrxDigital\SocialCardStudio\Generation\OnPublish;
use ChrxDigital\SocialCardStudio\Generation\Scheduler;
use ChrxDigital\SocialCardStudio\Render\FontResolver;
use ChrxDigital\SocialCardStudio\Rest\EditorController;
use ChrxDigital\SocialCardStudio\Render\Legibility;
use ChrxDigital\SocialCardStudio\Render\MemoryGuard;
use ChrxDigital\SocialCardStudio\Render\Optimizer;
use ChrxDigital\SocialCardStudio\Render\RendererFactory;
use ChrxDigital\SocialCardStudio\Settings\Settings;
use ChrxDigital\SocialCardStudio\Setup\Installer;
use ChrxDigital\SocialCardStudio\Storage\CardDirectory;
use ChrxDigital\SocialCardStudio\Storage\CardStore;
use ChrxDigital\SocialCardStudio\Storage\Lifecycle;
use ChrxDigital\SocialCardStudio\Storage\MediaLibraryBridge;
use ChrxDigital\SocialCardStudio\Templates\TemplateRegistry;
use ChrxDigital\SocialCardStudio\Support\Env;
use ChrxDigital\SocialCardStudio\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services together and registers WordPress hooks.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Builds the container.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Returns the singleton.
	 *
	 * This is the one piece of static state in the plugin. It holds no blog-specific
	 * data itself — the container partitions those by blog ID.
	 *
	 * @since 0.1.0
	 *
	 * @return self The plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Exposes the container.
	 *
	 * @since 0.1.0
	 *
	 * @return Container The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Registers hooks. Safe to call more than once.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'wp_initialize_site', array( Installer::class, 'on_new_site' ), 20 );

		// Late on init, so a CPT registered at the default priority is already known
		// and its meta can be registered against it.
		add_action( 'init', array( $this, 'register_meta' ), 20 );

		$this->container->get( Lifecycle::class )->register();
		$this->container->get( MediaLibraryBridge::class )->register();
		$this->container->get( Endpoint::class )->register();
		$this->container->get( JobRunner::class )->register();
		$this->container->get( OnPublish::class )->register();
		$this->container->get( Backfill::class )->register();
		$this->container->get( EditorController::class )->register();

		// Detection happens at plugins_loaded, per SPEC §10.2, by which point every
		// SEO plugin has declared itself.
		add_action( 'plugins_loaded', array( $this, 'register_delivery' ), 20 );

		if ( is_admin() ) {
			$this->container->get( DiagnosticsPage::class )->register();
			$this->container->get( BackfillPage::class )->register();
			$this->container->get( PostListColumn::class )->register();
			$this->container->get( EditorAssets::class )->register();
			$this->container->get( ClassicMetabox::class )->register();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'social-card-studio',
			false,
			dirname( plugin_basename( SCSTUDIO_FILE ) ) . '/languages'
		);
	}

	/**
	 * Registers whichever delivery path applies.
	 *
	 * Exactly one of the two: an SEO plugin's filters, or our own tags. Both at once
	 * would emit og:image twice.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_delivery(): void {
		$detector = $this->container->get( SeoDetector::class );

		if ( $detector->has_active() ) {
			$detector->hook();

			return;
		}

		$this->container->get( MetaTags::class )->register();
	}

	/**
	 * Registers the plugin's post meta.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$repository = $this->container->get( CardRepository::class );

		if ( $repository instanceof CardRepository ) {
			$repository->register_meta();
		}
	}

	/**
	 * Registers service factories.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_services(): void {
		$this->container->set( Env::class, static fn (): Env => new Env() );
		$this->container->set( Log::class, static fn (): Log => new Log() );
		$this->container->set( Settings::class, static fn (): Settings => new Settings() );
		$this->container->set( CardDirectory::class, static fn (): CardDirectory => new CardDirectory() );

		$this->container->set(
			TokenResolver::class,
			static fn ( Container $c ): TokenResolver => new TokenResolver(
				$c->get( Settings::class ),
				$c->get( CardDirectory::class )
			)
		);

		$this->container->set(
			FontResolver::class,
			static fn ( Container $c ): FontResolver => new FontResolver( $c->get( Settings::class ) )
		);

		$this->container->set(
			CardStore::class,
			static fn ( Container $c ): CardStore => new CardStore( $c->get( CardDirectory::class ) )
		);

		$this->container->set(
			MediaLibraryBridge::class,
			static fn ( Container $c ): MediaLibraryBridge => new MediaLibraryBridge(
				$c->get( Settings::class ),
				$c->get( CardDirectory::class )
			)
		);

		$this->container->set(
			TemplateRegistry::class,
			static fn ( Container $c ): TemplateRegistry => new TemplateRegistry( $c->get( CardDirectory::class ) )
		);

		$this->container->set(
			AltText::class,
			static fn ( Container $c ): AltText => new AltText(
				$c->get( Settings::class ),
				$c->get( TokenResolver::class )
			)
		);

		$this->container->set(
			Lifecycle::class,
			static fn ( Container $c ): Lifecycle => new Lifecycle(
				$c->get( CardStore::class ),
				$c->get( CardRepository::class ),
				$c->get( MediaLibraryBridge::class ),
				$c->get( Log::class )
			)
		);

		$this->container->set( Lock::class, static fn (): Lock => new Lock() );
		$this->container->set( Scheduler::class, static fn (): Scheduler => new Scheduler() );
		$this->container->set( MemoryGuard::class, static fn (): MemoryGuard => new MemoryGuard() );
		$this->container->set( Optimizer::class, static fn (): Optimizer => new Optimizer() );
		$this->container->set( Legibility::class, static fn (): Legibility => new Legibility() );

		$this->container->set(
			RendererFactory::class,
			static fn ( Container $c ): RendererFactory => new RendererFactory(
				$c->get( TokenResolver::class ),
				$c->get( Optimizer::class ),
				$c->get( Legibility::class ),
				$c->get( CardDirectory::class )
			)
		);

		$this->container->set(
			RenderProfile::class,
			static fn ( Container $c ): RenderProfile => new RenderProfile(
				$c->get( Settings::class ),
				$c->get( TemplateRegistry::class ),
				$c->get( FontResolver::class ),
				$c->get( RendererFactory::class )
			)
		);

		$this->container->set(
			InputHasher::class,
			static fn ( Container $c ): InputHasher => new InputHasher(
				$c->get( Settings::class ),
				$c->get( FontResolver::class ),
				$c->get( RenderProfile::class )
			)
		);

		$this->container->set(
			CardRepository::class,
			static fn ( Container $c ): CardRepository => new CardRepository(
				$c->get( Settings::class ),
				$c->get( InputHasher::class ),
				$c->get( CardDirectory::class )
			)
		);

		$this->container->set(
			CardGenerator::class,
			static fn ( Container $c ): CardGenerator => new CardGenerator(
				$c->get( RendererFactory::class ),
				$c->get( TemplateRegistry::class ),
				$c->get( TokenResolver::class ),
				$c->get( FontResolver::class ),
				$c->get( MemoryGuard::class ),
				$c->get( InputHasher::class ),
				$c->get( CardStore::class ),
				$c->get( CardRepository::class ),
				$c->get( AltText::class ),
				$c->get( MediaLibraryBridge::class ),
				$c->get( Settings::class ),
				$c->get( Log::class ),
				$c->get( Lock::class ),
				$c->get( RenderProfile::class )
			)
		);

		$this->container->set(
			Priority::class,
			static fn ( Container $c ): Priority => new Priority( $c->get( CardRepository::class ) )
		);

		$this->container->set(
			SeoDetector::class,
			static fn ( Container $c ): SeoDetector => new SeoDetector(
				$c->get( ImageResolver::class ),
				$c->get( Priority::class )
			)
		);

		/*
		 * ImageResolver and SeoDetector genuinely depend on each other, so the
		 * detector is handed over as a closure and resolved at call time. Injecting it
		 * directly makes the two factories call each other until the process dies.
		 */
		$this->container->set(
			ImageResolver::class,
			static fn ( Container $c ): ImageResolver => new ImageResolver(
				$c->get( CardRepository::class ),
				$c->get( CardDirectory::class ),
				$c->get( CardGenerator::class ),
				static fn (): SeoDetector => $c->get( SeoDetector::class )
			)
		);

		$this->container->set(
			Endpoint::class,
			static fn ( Container $c ): Endpoint => new Endpoint(
				$c->get( CardRepository::class ),
				$c->get( CardGenerator::class ),
				$c->get( CardDirectory::class ),
				$c->get( ImageResolver::class ),
				$c->get( Lock::class )
			)
		);

		$this->container->set(
			MetaTags::class,
			static fn ( Container $c ): MetaTags => new MetaTags(
				$c->get( ImageResolver::class ),
				$c->get( SeoDetector::class ),
				$c->get( Priority::class ),
				$c->get( CardRepository::class ),
				$c->get( Settings::class ),
				$c->get( Endpoint::class )
			)
		);

		$this->container->set(
			JobRunner::class,
			static fn ( Container $c ): JobRunner => new JobRunner( $c->get( CardGenerator::class ) )
		);

		$this->container->set(
			OnPublish::class,
			static fn ( Container $c ): OnPublish => new OnPublish(
				$c->get( Scheduler::class ),
				$c->get( CardRepository::class ),
				$c->get( Settings::class )
			)
		);

		$this->container->set(
			Backfill::class,
			static fn ( Container $c ): Backfill => new Backfill(
				$c->get( Scheduler::class ),
				$c->get( CardGenerator::class ),
				$c->get( CardRepository::class ),
				$c->get( MemoryGuard::class ),
				$c->get( Settings::class ),
				$c->get( Log::class )
			)
		);

		$this->container->set(
			BackfillPage::class,
			static fn ( Container $c ): BackfillPage => new BackfillPage(
				$c->get( Backfill::class ),
				$c->get( Scheduler::class ),
				$c->get( Settings::class )
			)
		);

		$this->container->set(
			PostListColumn::class,
			static fn ( Container $c ): PostListColumn => new PostListColumn(
				$c->get( CardRepository::class ),
				$c->get( CardDirectory::class ),
				$c->get( Scheduler::class ),
				$c->get( Settings::class )
			)
		);

		$this->container->set(
			EditorController::class,
			static fn ( Container $c ): EditorController => new EditorController(
				$c->get( RendererFactory::class ),
				$c->get( TemplateRegistry::class ),
				$c->get( TokenResolver::class ),
				$c->get( FontResolver::class ),
				$c->get( MemoryGuard::class ),
				$c->get( CardRepository::class ),
				$c->get( CardGenerator::class ),
				$c->get( CardDirectory::class ),
				$c->get( AltText::class ),
				$c->get( Settings::class )
			)
		);

		$this->container->set(
			EditorAssets::class,
			static fn ( Container $c ): EditorAssets => new EditorAssets(
				$c->get( TemplateRegistry::class ),
				$c->get( TokenResolver::class ),
				$c->get( FontResolver::class ),
				$c->get( EditorController::class ),
				$c->get( Settings::class )
			)
		);

		$this->container->set(
			ClassicMetabox::class,
			static fn ( Container $c ): ClassicMetabox => new ClassicMetabox(
				$c->get( CardRepository::class ),
				$c->get( TemplateRegistry::class ),
				$c->get( CardDirectory::class ),
				$c->get( Scheduler::class ),
				$c->get( Settings::class )
			)
		);

		$this->container->set(
			DiagnosticsPage::class,
			static fn ( Container $c ): DiagnosticsPage => new DiagnosticsPage(
				$c->get( Env::class ),
				$c->get( Settings::class ),
				$c->get( CardDirectory::class ),
				$c->get( Log::class ),
				$c->get( FontResolver::class )
			)
		);
	}
}
