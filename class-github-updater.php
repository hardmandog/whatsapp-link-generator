<?php
/**
 * GitHub Updater — comprobador de actualizaciones para plugins hospedados en GitHub.
 *
 * Funciona con repositorios públicos y GitHub Releases con versionado semántico (v1.2.3).
 * No requiere dependencias externas.
 *
 * Uso:
 *   require_once __DIR__ . '/class-github-updater.php';
 *   new GitHub_Updater( __FILE__, 'hardmandog', 'nombre-del-repo' );
 *
 * Para publicar una actualización:
 *   1. Sube el Version: en la cabecera del plugin (ej. 3.8.0)
 *   2. Haz commit y push a GitHub
 *   3. Crea un Release en GitHub con el tag v3.8.0
 *   4. WordPress detectará la actualización en el próximo ciclo (máx. 12 h)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GitHub_Updater {

	private string $plugin_file;
	private string $github_user;
	private string $github_repo;
	private string $plugin_slug;
	private string $version;
	private string $cache_key;

	public function __construct( string $plugin_file, string $github_user, string $github_repo ) {
		$this->plugin_file = $plugin_file;
		$this->github_user = $github_user;
		$this->github_repo = $github_repo;
		$this->plugin_slug = plugin_basename( $plugin_file );
		$this->cache_key   = 'ghu_' . md5( $this->plugin_slug );

		$data          = get_file_data( $plugin_file, [ 'Version' => 'Version' ] );
		$this->version = $data['Version'] ?? '0.0.0';

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_folder'  ], 10, 4 );
	}

	// ─── Obtiene el último release de GitHub (cacheado 12 h) ───────────────────

	private function get_release(): ?array {
		$cached = get_transient( $this->cache_key );
		if ( $cached !== false ) return $cached ?: null;

		$url      = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
		$response = wp_remote_get( $url, [
			'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $this->cache_key, [], HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			set_transient( $this->cache_key, [], HOUR_IN_SECONDS );
			return null;
		}

		$release = [
			'version'     => ltrim( $data['tag_name'], 'v' ),
			'zip_url'     => $data['zipball_url'] ?? '',
			'changelog'   => $data['body'] ?? '',
			'released_at' => $data['published_at'] ?? '',
		];

		set_transient( $this->cache_key, $release, 12 * HOUR_IN_SECONDS );
		return $release;
	}

	// ─── Inyecta la actualización en el transient de WP ────────────────────────

	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) return $transient;

		$release = $this->get_release();
		if ( ! $release ) return $transient;

		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			$transient->response[ $this->plugin_slug ] = (object) [
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $release['version'],
				'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
				'package'     => $release['zip_url'],
			];
		}

		return $transient;
	}

	// ─── Info del plugin en el modal "Ver detalles" ─────────────────────────────

	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) return $result;
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) return $result;

		$release = $this->get_release();
		if ( ! $release ) return $result;

		return (object) [
			'name'          => $this->github_repo,
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $release['version'],
			'author'        => $this->github_user,
			'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'sections'      => [ 'changelog' => nl2br( esc_html( $release['changelog'] ) ) ],
			'download_link' => $release['zip_url'],
			'last_updated'  => $release['released_at'],
		];
	}

	// ─── Renombra la carpeta extraída al slug correcto del plugin ───────────────
	// GitHub nombra el zip como {repo}-{hash}/, WP necesita {slug}/

	public function fix_folder( $source, $remote_source, $upgrader, $extra = [] ) {
		global $wp_filesystem;

		if ( ! isset( $extra['plugin'] ) || $extra['plugin'] !== $this->plugin_slug ) return $source;

		$correct = trailingslashit( $remote_source ) . dirname( $this->plugin_slug ) . '/';
		if ( $source !== $correct && $wp_filesystem->exists( $source ) ) {
			$wp_filesystem->move( $source, $correct );
			return $correct;
		}

		return $source;
	}
}
