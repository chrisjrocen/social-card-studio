<?php
/**
 * Environment capability detection.
 *
 * Implements the capability half of SPEC.md §4.3 and §12.4 (diagnostics).
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reports what this server can actually do.
 *
 * Probing GD's feature set is not free, so results are cached in a transient. The
 * cache is versioned by SCSTUDIO_VERSION and PHP_VERSION so a plugin update or a PHP
 * upgrade re-probes rather than serving a stale answer — the failure mode being a
 * site that upgraded PHP, gained FreeType, and is still told it has none.
 *
 * @since 0.1.0
 */
final class Env {

	public const TRANSIENT = 'scstudio_env';

	/**
	 * Cache lifetime in seconds.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * In-request memo.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $probe = null;

	/**
	 * Returns the full capability report.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Capability data, shape documented in probe().
	 */
	public function all(): array {
		if ( null !== $this->probe ) {
			return $this->probe;
		}

		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && ( $cached['cache_key'] ?? '' ) === $this->cache_key() ) {
			$this->probe = $cached;

			return $this->probe;
		}

		$this->probe = $this->probe();
		set_transient( self::TRANSIENT, $this->probe, self::TTL );

		return $this->probe;
	}

	/**
	 * Discards the cached probe.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->probe = null;
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Identifier of the renderer that would be selected.
	 *
	 * @since 0.1.0
	 *
	 * @return string Either 'gd' or 'none'.
	 */
	public function engine(): string {
		$env = $this->all();

		if ( $env['gd']['usable'] ) {
			return 'gd';
		}

		return 'none';
	}

	/**
	 * Reports whether any renderer can draw text.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a usable engine exists.
	 */
	public function can_render(): bool {
		return 'none' !== $this->engine();
	}

	/**
	 * Memory limit in bytes, or 0 when unlimited.
	 *
	 * @since 0.1.0
	 *
	 * @return int Byte value of WP_MEMORY_LIMIT.
	 */
	public function memory_limit_bytes(): int {
		$limit = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : (string) ini_get( 'memory_limit' );

		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $limit );
	}

	/**
	 * Runs the capability probe.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Fresh capability data.
	 */
	private function probe(): array {
		return array(
			'cache_key'        => $this->cache_key(),
			'probed_at'        => time(),
			'php'              => PHP_VERSION,
			'wp'               => get_bloginfo( 'version' ),
			'gd'               => $this->probe_gd(),
			'memory_limit'     => (string) ini_get( 'memory_limit' ),
			'wp_memory_limit'  => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'max_execution'    => (int) ini_get( 'max_execution_time' ),
			'upload_max'       => (string) ini_get( 'upload_max_filesize' ),
			'external_http'    => ! ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ),
			'is_multisite'     => is_multisite(),
			'permalink_pretty' => '' !== (string) get_option( 'permalink_structure' ),
		);
	}

	/**
	 * Probes GD.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> GD capability detail.
	 */
	private function probe_gd(): array {
		$result = array(
			'loaded'   => extension_loaded( 'gd' ),
			'usable'   => false,
			'version'  => '',
			'freetype' => false,
			'reason'   => '',
		);

		if ( ! $result['loaded'] || ! function_exists( 'gd_info' ) ) {
			$result['reason'] = 'extension not loaded';

			return $result;
		}

		$info = gd_info();

		$result['version']  = (string) ( $info['GD Version'] ?? '' );
		$result['freetype'] = ! empty( $info['FreeType Support'] ) && function_exists( 'imagettftext' );
		$result['usable']   = $result['freetype'];

		if ( ! $result['freetype'] ) {
			$result['reason'] = 'no FreeType support (imagettftext unavailable)';
		}

		return $result;
	}

	/**
	 * Cache key that invalidates on a plugin or PHP change.
	 *
	 * @since 0.1.0
	 *
	 * @return string Version-derived cache key.
	 */
	private function cache_key(): string {
		return SCSTUDIO_VERSION . '|' . PHP_VERSION;
	}
}
