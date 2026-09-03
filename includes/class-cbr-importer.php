<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CBR_Importer {

    /**
     * Version definitions (shared by both import methods)
     */
    private static $versions = [
        1 => [ 'name' => 'New International Version',    'abbr' => 'NIV',  'tbl' => 'ajax_bible_niv'  ],
        2 => [ 'name' => "Young's Literal Translation",  'abbr' => 'YLT',  'tbl' => 'ajax_bible_ylt'  ],
        3 => [ 'name' => 'King James Version',            'abbr' => 'KJV',  'tbl' => 'ajax_bible_kjv'  ],
        4 => [ 'name' => 'Good News Translation',         'abbr' => 'GNT',  'tbl' => 'ajax_bible_gnt'  ],
        5 => [ 'name' => 'New King James Version',        'abbr' => 'NKJV', 'tbl' => 'ajax_bible_nkjv' ],
        6 => [ 'name' => 'New Living Translation',        'abbr' => 'NLT',  'tbl' => 'ajax_bible_nlt'  ],
    ];

    /**
     * Ensure all 6 versions exist in the versions table
     */
    private static function seed_versions() {
        global $wpdb;
        $table = $wpdb->prefix . 'cbr_versions';

        foreach ( self::$versions as $id => $info ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT version_id FROM $table WHERE version_id = %d", $id
            ) );
            if ( ! $exists ) {
                $wpdb->insert( $table, [
                    'version_id'   => $id,
                    'version_name' => $info['name'],
                    'version_abbr' => $info['abbr'],
                    'table_name'   => $info['tbl'],
                    'sort_order'   => $id,
                    'is_active'    => 1,
                ] );
            }
        }
    }

    // =========================================================================
    //  BUNDLED TSV IMPORT — chunked & resumable
    //  Format: version_id \t book_id \t chapter_id \t verse_id \t verse_text
    //
    //  State lives in the option 'cbr_import_state' => [ line, inserted, total, started ].
    //  Each call to run_chunk() imports for up to $budget seconds, committing every
    //  batch, then records where it stopped. Any request (activation, cron, admin
    //  progress loop) can pick it up. An interrupted import can never leave the
    //  table empty or roll back work already done.
    // =========================================================================

    const TOTAL_LINES = 185753;
    const STATE_KEY   = 'cbr_import_state';
    const CRON_HOOK   = 'cbr_import_continue';

    public static function data_file() {
        return CBR_PLUGIN_DIR . 'data/verses.tsv.gz';
    }

    /** Current progress, or null when no import is pending. */
    public static function progress() {
        $st = get_option( self::STATE_KEY );
        if ( ! is_array( $st ) ) return null;
        $pct = $st['total'] > 0 ? min( 99, (int) floor( $st['inserted'] * 100 / $st['total'] ) ) : 0;
        return [ 'inserted' => (int) $st['inserted'], 'total' => (int) $st['total'], 'percent' => $pct, 'started' => (int) $st['started'] ];
    }

    public static function is_pending() {
        return is_array( get_option( self::STATE_KEY ) );
    }

    /**
     * Begin a fresh import: clear verses, seed versions, reset state.
     * Does NOT import anything itself — call run_chunk() / run_until() next.
     */
    public static function start_bundled_import() {
        if ( ! file_exists( self::data_file() ) ) {
            return new WP_Error( 'file_missing', 'Bundled data file not found.' );
        }
        CBR_Database::truncate_verses();
        self::seed_versions();
        update_option( self::STATE_KEY, [ 'line' => 0, 'inserted' => 0, 'total' => self::TOTAL_LINES, 'started' => time() ], false );
        return true;
    }

    /**
     * Import for up to $budget seconds. Returns progress array, or null when finished.
     */
    public static function run_chunk( $budget = 20 ) {
        global $wpdb;

        $st = get_option( self::STATE_KEY );
        if ( ! is_array( $st ) ) return null;

        // Lock against concurrent runners (cron + admin loop + activation)
        if ( get_transient( 'cbr_import_lock' ) ) return self::progress();
        set_transient( 'cbr_import_lock', 1, max( 30, $budget + 10 ) );

        @set_time_limit( $budget + 30 );

        $handle = gzopen( self::data_file(), 'r' );
        if ( ! $handle ) { delete_transient( 'cbr_import_lock' ); return self::progress(); }

        // Skip already-imported lines (gz can't seek; ~0.5s per 100K lines)
        $line_no = 0;
        while ( $line_no < $st['line'] && ! gzeof( $handle ) ) { gzgets( $handle, 65536 ); $line_no++; }

        $table = $wpdb->prefix . 'cbr_verses';
        $t0 = microtime( true );
        $batch = []; $batch_size = 500; $done = false;

        while ( true ) {
            if ( gzeof( $handle ) ) { $done = true; break; }
            $line = gzgets( $handle, 65536 );
            if ( false === $line ) { $done = true; break; }
            $line_no++;

            $line = rtrim( $line, "\r\n" );
            if ( $line !== '' ) {
                $parts = explode( "\t", $line, 5 );
                if ( count( $parts ) === 5 ) {
                    $batch[] = $wpdb->prepare( '(%d,%d,%d,%d,%s)', (int) $parts[0], (int) $parts[1], (int) $parts[2], (int) $parts[3], $parts[4] );
                }
            }

            if ( count( $batch ) >= $batch_size ) {
                $wpdb->query( "INSERT INTO $table (version_id, book_id, chapter_id, verse_id, verse_text) VALUES " . implode( ',', $batch ) );
                $st['inserted'] += count( $batch );
                $st['line'] = $line_no;
                $batch = [];
                update_option( self::STATE_KEY, $st, false );   // checkpoint after every committed batch
                if ( microtime( true ) - $t0 > $budget ) break;
            }
        }

        if ( ! empty( $batch ) ) {
            $wpdb->query( "INSERT INTO $table (version_id, book_id, chapter_id, verse_id, verse_text) VALUES " . implode( ',', $batch ) );
            $st['inserted'] += count( $batch );
            $st['line'] = $line_no;
        }
        gzclose( $handle );

        if ( $done ) {
            delete_option( self::STATE_KEY );
            update_option( 'cbr_data_version', 2 );
            update_option( 'cbr_activation_imported', (int) $st['inserted'] );
            wp_clear_scheduled_hook( self::CRON_HOOK );
            delete_transient( 'cbr_import_lock' );
            return null;
        }

        update_option( self::STATE_KEY, $st, false );
        delete_transient( 'cbr_import_lock' );
        self::schedule_continue();
        return self::progress();
    }

    /** Run chunks back-to-back until done or $budget seconds elapse. */
    public static function run_until( $budget = 25 ) {
        $t0 = microtime( true );
        while ( self::is_pending() && ( microtime( true ) - $t0 ) < $budget ) {
            $remaining = $budget - ( microtime( true ) - $t0 );
            if ( $remaining < 3 ) break;
            self::run_chunk( (int) $remaining );
        }
        return self::is_pending() ? self::progress() : null;
    }

    /** Make sure WP-Cron will keep the import moving even with no admin around. */
    public static function schedule_continue() {
        if ( self::is_pending() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK );
        }
    }

    /**
     * Back-compat entry point: start + run as far as the budget allows.
     * Returns inserted count so far (import continues via cron if not finished).
     */
    public static function import_bundled_tsv( $file_path = null, $budget = 25 ) {
        $r = self::start_bundled_import();
        if ( is_wp_error( $r ) ) return $r;
        self::run_until( $budget );
        $p = self::progress();
        return $p ? $p['inserted'] : (int) get_option( 'cbr_activation_imported', self::TOTAL_LINES );
    }

    // =========================================================================
    //  MANUAL SQL IMPORT (admin upload — kept as fallback / for adding data)
    // =========================================================================

    public static function import_sql_file( $file_path ) {
        global $wpdb;

        @set_time_limit( 600 );

        self::seed_versions();

        $is_gz  = ( substr( $file_path, -3 ) === '.gz' );
        $handle = $is_gz ? gzopen( $file_path, 'r' ) : fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'file_error', 'Could not open the SQL file.' );
        }

        $tbl_to_version = [];
        foreach ( self::$versions as $id => $info ) {
            $tbl_to_version[ $info['tbl'] ] = $id;
        }

        $verses_table = $wpdb->prefix . 'cbr_verses';
        $batch     = [];
        $total     = 0;
        $batch_size = 500;

        $wpdb->query( 'SET autocommit = 0' );
        $wpdb->query( 'SET unique_checks = 0' );
        $wpdb->query( 'SET foreign_key_checks = 0' );

        while ( ! ( $is_gz ? gzeof( $handle ) : feof( $handle ) ) ) {
            $line = $is_gz ? gzgets( $handle, 1048576 ) : fgets( $handle, 1048576 );
            if ( false === $line ) break;

            $line = trim( $line );
            if ( strpos( $line, 'INSERT INTO `ajax_bible_' ) !== 0 ) continue;

            $target_version = null;
            foreach ( $tbl_to_version as $tbl => $vid ) {
                if ( strpos( $line, "INSERT INTO `$tbl`" ) === 0 ) {
                    $target_version = $vid;
                    break;
                }
            }
            if ( ! $target_version ) continue;

            preg_match_all(
                '/\((\d+),(\d+),(\d+),(\d+),(\d+),\'((?:[^\'\\\\]|\\\\.)*)\'\)/',
                $line, $matches, PREG_SET_ORDER
            );

            foreach ( $matches as $m ) {
                $text = stripslashes( $m[6] );
                $stripped = trim( $text );
                if ( $stripped === 'See Footnote' || $stripped === '' ) continue;

                $batch[] = $wpdb->prepare(
                    '(%d,%d,%d,%d,%s)',
                    $target_version,
                    (int) $m[3],  // book_id    (source column order: id, version_id, book_id, chapter_id, verse_id, text)
                    (int) $m[4],  // chapter_id
                    (int) $m[5],  // verse_id
                    $text
                );

                if ( count( $batch ) >= $batch_size ) {
                    $vals = implode( ',', $batch );
                    $wpdb->query( "INSERT INTO $verses_table (version_id, book_id, chapter_id, verse_id, verse_text) VALUES $vals" );
                    $total += count( $batch );
                    $batch = [];
                }
            }
        }

        if ( ! empty( $batch ) ) {
            $vals = implode( ',', $batch );
            $wpdb->query( "INSERT INTO $verses_table (version_id, book_id, chapter_id, verse_id, verse_text) VALUES $vals" );
            $total += count( $batch );
        }

        $wpdb->query( 'COMMIT' );
        $wpdb->query( 'SET autocommit = 1' );
        $wpdb->query( 'SET unique_checks = 1' );
        $wpdb->query( 'SET foreign_key_checks = 1' );

        $is_gz ? gzclose( $handle ) : fclose( $handle );

        return $total;
    }
}
