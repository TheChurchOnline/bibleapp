<?php
/**
 * Plugin Name: The Church Online Bible Reader
 * Plugin URI: https://thechurchonline.com
 * Description: A modern, customizable Bible reader with 6 translations (NIV, KJV, NKJV, NLT, YLT, GNT), full-text search, AJAX navigation, and fully themeable fonts. Use shortcode [church_bible] to embed anywhere. All 185,000+ verses are bundled and auto-imported on activation.
 * Version: 1.9.3
 * Author: The Church Online
 * License: GPL v2 or later
 * Text Domain: the-church-online-bible-reader
 * Update URI: https://github.com/TheChurchOnline/bibleapp
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CBR_VERSION', '1.9.3' );

// GitHub repo used for update checks ("owner/repo"). Must match the Update URI header above.
if ( ! defined( 'CBR_GITHUB_REPO' ) ) define( 'CBR_GITHUB_REPO', 'TheChurchOnline/bibleapp' );
define( 'CBR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CBR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CBR_PLUGIN_DIR . 'includes/class-cbr-database.php';
require_once CBR_PLUGIN_DIR . 'includes/class-cbr-admin.php';
require_once CBR_PLUGIN_DIR . 'includes/class-cbr-frontend.php';
require_once CBR_PLUGIN_DIR . 'includes/class-cbr-ajax.php';
require_once CBR_PLUGIN_DIR . 'includes/class-cbr-importer.php';
require_once CBR_PLUGIN_DIR . 'includes/class-cbr-updater.php';

class Church_Bible_Reader {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );
        add_action( 'admin_notices', [ $this, 'activation_notice' ] );

        // If activation timed out mid-import, finish via an async action
        add_action( 'admin_init', [ $this, 'maybe_resume_import' ] );

        // One-time data repair for installs that imported with <= 1.4.1.
        // Runs on any request (front or admin) so it can't be skipped.
        add_action( 'init', [ $this, 'maybe_repair_data' ], 5 );

        // Resumable import: cron keeps it moving; init makes sure cron is queued.
        add_action( CBR_Importer::CRON_HOOK, [ $this, 'cron_import_step' ] );
        add_action( 'init', [ 'CBR_Importer', 'schedule_continue' ], 6 );

        CBR_Admin::instance();
        CBR_Frontend::instance();
        CBR_Ajax::instance();
        CBR_Updater::instance( __FILE__ );
    }

    public function init() {
        load_plugin_textdomain( 'the-church-online-bible-reader', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Activation: create tables → seed books/versions → auto-import all bundled verses
     */
    public static function activate() {
        // Generous time limit for the ~186K row import
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        CBR_Database::create_tables();
        self::set_default_options();

        // Fresh install (or empty table): import from bundled data. Runs ~25s now,
        // then WP-Cron / the admin progress page finish the rest.
        if ( CBR_Database::get_verse_count() === 0 && ! CBR_Importer::is_pending() ) {
            update_option( 'cbr_data_version', 2 ); // corrected data
            $r = CBR_Importer::start_bundled_import();
            if ( ! is_wp_error( $r ) ) CBR_Importer::run_until( 25 );
        }

        flush_rewrite_rules();
    }

    /**
     * Admin page loads nudge a pending import along (cron may be slow on low-traffic sites).
     */
    public function maybe_resume_import() {
        if ( ! CBR_Importer::is_pending() ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        CBR_Importer::run_chunk( 10 );
    }

    /** WP-Cron step: import a chunk, reschedule if more remains. */
    public function cron_import_step() {
        CBR_Importer::run_chunk( 25 );
        CBR_Importer::schedule_continue();
    }

    /**
     * Versions <= 1.4.1 imported verses with book_id and verse_id swapped.
     * Repair once, then record the data version so this never runs again.
     */
    public function maybe_repair_data() {
        // Once the data has been verified correct, skip the check (reset by re-import / reset actions).
        if ( get_option( 'cbr_data_verified' ) ) return;

        $swapped = CBR_Database::verses_are_swapped();
        if ( ! $swapped ) {
            update_option( 'cbr_data_version', 2 );
            if ( CBR_Database::get_verse_count() > 0 && ! CBR_Importer::is_pending() ) update_option( 'cbr_data_verified', 1 );
            return;
        }

        // Lock so concurrent requests don't both run the UPDATE
        if ( get_transient( 'cbr_repair_lock' ) ) return;
        set_transient( 'cbr_repair_lock', 1, 5 * MINUTE_IN_SECONDS );

        if ( CBR_Importer::is_pending() ) { delete_transient( 'cbr_repair_lock' ); return; } // re-import already underway

        $ok = CBR_Database::repair_swapped_verses();
        if ( ! $ok ) {
            // Fallback: resumable re-import from the corrected bundled data
            $r = CBR_Importer::start_bundled_import();
            if ( ! is_wp_error( $r ) ) { CBR_Importer::run_until( 10 ); CBR_Importer::schedule_continue(); }
        }
        update_option( 'cbr_data_repaired_notice', 1 );
        update_option( 'cbr_data_version', 2 );
        delete_transient( 'cbr_repair_lock' );
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * One-time success banner after activation import
     */
    public function activation_notice() {
        if ( get_option( 'cbr_data_repaired_notice' ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>The Church Online Bible Reader:</strong> Verse data was repaired (book/verse columns were reversed in earlier versions). Chapters now display the correct scripture.</p></div>';
            delete_option( 'cbr_data_repaired_notice' );
        }
        $imported = get_option( 'cbr_activation_imported' );
        if ( $imported ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>✝ The Church Online Bible Reader:</strong> Successfully imported <strong>' . number_format( $imported ) . '</strong> verses across 6 translations. ';
            echo 'Add <code>[church_bible]</code> to any page to embed the reader!</p>';
            echo '</div>';
            delete_option( 'cbr_activation_imported' );
        }
    }

    public static function set_default_options() {
        $defaults = [
            'cbr_default_version'   => '3',
            'cbr_default_book'      => '1',
            'cbr_primary_color'     => '#2c5f2d',
            'cbr_secondary_color'   => '#97bc62',
            'cbr_accent_color'      => '#d4a574',
            'cbr_bg_color'          => '#faf8f5',
            'cbr_text_color'        => '#2d2926',
            'cbr_heading_font'      => 'Playfair Display',
            'cbr_heading_font_custom' => '',
            'cbr_body_font'         => 'Source Serif 4',
            'cbr_body_font_custom'  => '',
            'cbr_verse_font_size'   => '18',
            'cbr_reader_height'     => '600',
            'cbr_length_mode'       => 'paginate',
            'cbr_preview_verses'    => '15',
            'cbr_verses_per_page'   => '7',
            'cbr_show_verse_nums'   => '1',
            'cbr_enable_search'     => '1',
            'cbr_enable_dark_mode'  => '1',
            'cbr_layout_style'      => 'classic',
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}

Church_Bible_Reader::instance();
