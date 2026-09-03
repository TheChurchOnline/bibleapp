<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GitHub Releases updater.
 *
 * Every WordPress install running this plugin checks the GitHub repo for a newer
 * release (cached 6h), shows the standard "update available" row on the Plugins
 * page, and can update with one click.
 *
 * Publishing a release:
 *   1. Bump the version in the plugin header (and CBR_VERSION).
 *   2. Tag it:  git tag v1.8.1 && git push --tags
 *   3. Create a GitHub Release for that tag and attach the plugin zip
 *      (folder inside must be "the-church-online-bible-reader/").
 *      The included .github/workflows/release.yml does steps 3 automatically.
 *
 * Private repo? Add to wp-config.php:  define( 'CBR_GITHUB_TOKEN', 'ghp_…' );
 */
class CBR_Updater {

    const CACHE_KEY = 'cbr_github_release';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    private static $instance = null;
    private $file, $slug, $basename, $repo;

    public static function instance( $file ) {
        if ( null === self::$instance ) self::$instance = new self( $file );
        return self::$instance;
    }

    private function __construct( $file ) {
        $this->file     = $file;
        $this->basename = plugin_basename( $file );                 // the-church-online-bible-reader/the-church-online-bible-reader.php
        $this->slug     = dirname( $this->basename );               // the-church-online-bible-reader
        $this->repo     = defined( 'CBR_GITHUB_REPO' ) ? CBR_GITHUB_REPO : '';

        if ( ! $this->repo ) return;

        // WP 5.8+: the Update URI header routes update checks here instead of wordpress.org
        add_filter( 'update_plugins_github.com', [ $this, 'check_update' ], 10, 4 );
        add_filter( 'plugins_api',                [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection',  [ $this, 'fix_folder_name' ], 10, 4 );
        add_filter( 'plugin_row_meta',            [ $this, 'row_meta' ], 10, 2 );
        add_action( 'admin_init',                 [ $this, 'maybe_force_check' ] );
        add_action( 'upgrader_process_complete',  [ $this, 'clear_cache_after_update' ], 10, 2 );
    }

    /* ------------------------------------------------------------------ */
    /*  GitHub                                                              */
    /* ------------------------------------------------------------------ */

    /** Latest release as [ version, zip, url, body, published ] or null. */
    private function latest_release( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) return $cached;
        }

        $headers = [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url() ];
        if ( defined( 'CBR_GITHUB_TOKEN' ) && CBR_GITHUB_TOKEN ) $headers['Authorization'] = 'Bearer ' . CBR_GITHUB_TOKEN;

        $res = wp_remote_get( "https://api.github.com/repos/{$this->repo}/releases/latest", [ 'headers' => $headers, 'timeout' => 15 ] );
        if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
            set_site_transient( self::CACHE_KEY, [ 'error' => true ], HOUR_IN_SECONDS ); // back off for an hour
            return null;
        }

        $r = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $r['tag_name'] ) ) return null;

        $version = ltrim( $r['tag_name'], 'vV' );

        // Prefer a release asset zip (correct folder name inside); fall back to GitHub's zipball
        $zip = '';
        foreach ( (array) ( $r['assets'] ?? [] ) as $a ) {
            if ( ! empty( $a['browser_download_url'] ) && substr( $a['name'], -4 ) === '.zip' ) { $zip = $a['browser_download_url']; break; }
        }
        if ( ! $zip ) $zip = $r['zipball_url'] ?? '';

        $release = [
            'version'   => $version,
            'zip'       => $zip,
            'url'       => $r['html_url'] ?? "https://github.com/{$this->repo}/releases",
            'body'      => (string) ( $r['body'] ?? '' ),
            'published' => (string) ( $r['published_at'] ?? '' ),
        ];
        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /* ------------------------------------------------------------------ */
    /*  WordPress hooks                                                     */
    /* ------------------------------------------------------------------ */

    /** Tell WP whether a newer version exists. */
    public function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( $plugin_file !== $this->basename ) return $update;

        $rel = $this->latest_release();
        if ( ! $rel || empty( $rel['version'] ) || empty( $rel['zip'] ) ) return $update;

        $item = [
            'id'           => 'github.com/' . $this->repo,
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'version'      => $rel['version'],
            'url'          => $rel['url'],
            'package'      => $rel['zip'],
            'tested'       => get_bloginfo( 'version' ),
            'requires_php' => '7.4',
            'icons'        => [],
            'banners'      => [],
        ];

        // Returning a version <= current tells WP "no update"; WP compares itself.
        return $item;
    }

    /** "View details" modal on the Plugins page. */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) return $result;

        $rel = $this->latest_release();
        if ( ! $rel ) return $result;

        $info = new stdClass();
        $info->name          = 'The Church Online Bible Reader';
        $info->slug          = $this->slug;
        $info->version       = $rel['version'];
        $info->author        = '<a href="https://thechurchonline.com">The Church Online</a>';
        $info->homepage      = "https://github.com/{$this->repo}";
        $info->download_link = $rel['zip'];
        $info->requires      = '5.8';
        $info->requires_php  = '7.4';
        $info->tested        = get_bloginfo( 'version' );
        $info->last_updated  = $rel['published'];
        $info->sections      = [
            'description' => '<p>A modern, customizable Bible reader with 6 translations, full-text search, AJAX navigation, and theme-matching colors and fonts.</p>',
            'changelog'   => $rel['body'] ? wpautop( esc_html( $rel['body'] ) ) : '<p>See the release on GitHub.</p>',
        ];
        return $info;
    }

    /**
     * GitHub zipballs unpack as "owner-repo-abc123/". Rename to the plugin slug so
     * WP replaces the existing folder instead of installing a duplicate.
     */
    public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) return $source;

        $desired = trailingslashit( $remote_source ) . $this->slug . '/';
        if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) return $source;

        global $wp_filesystem;
        if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) return $desired;
        return $source;
    }

    /** "Check for updates" link on the plugin row. */
    public function row_meta( $links, $file ) {
        if ( $file !== $this->basename ) return $links;
        $url = wp_nonce_url( add_query_arg( [ 'cbr_check_update' => 1 ], self_admin_url( 'plugins.php' ) ), 'cbr_check_update' );
        $links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';
        $links[] = '<a href="https://github.com/' . esc_attr( $this->repo ) . '/releases" target="_blank" rel="noopener">Releases on GitHub</a>';
        return $links;
    }

    public function maybe_force_check() {
        if ( empty( $_GET['cbr_check_update'] ) || ! current_user_can( 'update_plugins' ) ) return;
        check_admin_referer( 'cbr_check_update' );
        delete_site_transient( self::CACHE_KEY );
        $this->latest_release( true );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
        wp_safe_redirect( self_admin_url( 'plugins.php?cbr_checked=1' ) );
        exit;
    }

    public function clear_cache_after_update( $upgrader, $hook_extra ) {
        if ( ( $hook_extra['type'] ?? '' ) === 'plugin' ) delete_site_transient( self::CACHE_KEY );
    }
}
