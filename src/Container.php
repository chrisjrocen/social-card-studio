<?php
/**
 * Minimal dependency-injection container.
 *
 * Implements the container half of SPEC.md §3 ("dependency injection through a tiny
 * container in Plugin.php; no service locators, no static state that survives a
 * switch_to_blog()").
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily constructs and caches services.
 *
 * Services are cached per blog. Anything that resolves an option, an upload path or a
 * table name is blog-specific, and a service cached across a switch_to_blog() would
 * quietly serve another site's data — the exact bug SPEC.md §16 warns about.
 *
 * @since 0.1.0
 */
final class Container {

	/**
	 * Service factories, keyed by identifier.
	 *
	 * @var array<string, callable(self): object>
	 */
	private array $factories = array();

	/**
	 * Resolved instances, keyed by blog ID then identifier.
	 *
	 * @var array<int, array<string, object>>
	 */
	private array $instances = array();

	/**
	 * Registers a service factory.
	 *
	 * @since 0.1.0
	 *
	 * @param string                 $id      Service identifier, normally a class name.
	 * @param callable(self): object $factory Factory receiving the container.
	 *
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;

		// Registration must not depend on WordPress being loaded, so this clears the
		// identifier for every blog rather than asking which blog is current.
		foreach ( array_keys( $this->instances ) as $blog ) {
			unset( $this->instances[ $blog ][ $id ] );
		}
	}

	/**
	 * Resolves a service.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service identifier.
	 *
	 * @throws \InvalidArgumentException When the identifier was never registered.
	 *
	 * @return object The resolved service.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Social Card Studio: no service registered for "%s".', esc_html( $id ) )
			);
		}

		$blog = $this->blog_id();

		if ( ! isset( $this->instances[ $blog ][ $id ] ) ) {
			$this->instances[ $blog ][ $id ] = ( $this->factories[ $id ] )( $this );
		}

		return $this->instances[ $blog ][ $id ];
	}

	/**
	 * Reports whether an identifier is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service identifier.
	 *
	 * @return bool True when a factory exists.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Current blog ID, or 1 outside multisite.
	 *
	 * @since 0.1.0
	 *
	 * @return int Blog identifier used to partition the instance cache.
	 */
	private function blog_id(): int {
		return is_multisite() ? get_current_blog_id() : 1;
	}
}
