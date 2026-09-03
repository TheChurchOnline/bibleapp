<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CBR_Ajax {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $actions = [ 'cbr_get_chapter', 'cbr_search', 'cbr_get_books', 'cbr_get_versions', 'cbr_import_progress' ];
        foreach ( $actions as $action ) {
            add_action( "wp_ajax_$action",        [ $this, $action ] );
            add_action( "wp_ajax_nopriv_$action",  [ $this, $action ] );
        }
    }

    /**
     * Get chapter verses (full chapter, or a verse range if verse_start is given)
     */
    public function cbr_get_chapter() {
        check_ajax_referer( 'cbr_nonce', 'nonce' );

        $version_id  = absint( $_POST['version_id'] ?? get_option( 'cbr_default_version', 3 ) );
        $book_id     = absint( $_POST['book_id'] ?? 1 );
        $chapter_id  = absint( $_POST['chapter_id'] ?? 1 );
        $verse_start = absint( $_POST['verse_start'] ?? 0 );
        $verse_end   = absint( $_POST['verse_end'] ?? 0 );

        wp_send_json_success( self::build_passage_payload( $version_id, $book_id, $chapter_id, $verse_start, $verse_end ) );
    }

    /**
     * Search: passage references return the exact verse(s); anything else is a keyword search.
     */
    public function cbr_search() {
        check_ajax_referer( 'cbr_nonce', 'nonce' );

        $version_id = absint( $_POST['version_id'] ?? get_option( 'cbr_default_version', 3 ) );
        $query      = wp_unslash( $_POST['query'] ?? '' );
        $query      = trim( wp_strip_all_tags( $query ) );
        $book_id    = absint( $_POST['book_id'] ?? 0 );

        if ( strlen( $query ) < 2 ) {
            wp_send_json_error( 'Search query too short.' );
        }

        // ── Passage reference? ("Genesis 8", "Genesis 8:15", "Genesis 8:15-20") ──
        $ref = self::parse_passage_ref( $query );
        if ( $ref ) {
            $payload = self::build_passage_payload(
                $version_id, $ref['book_id'], $ref['chapter'], $ref['verse_start'], $ref['verse_end']
            );

            // Only treat as a passage hit if we actually found verses
            if ( ! empty( $payload['verses'] ) ) {
                $payload['type']  = 'passage';
                $payload['query'] = $query;
                wp_send_json_success( $payload );
            }
        }

        // ── Keyword search ──
        $results = CBR_Database::search_verses( $version_id, $query, $book_id, 50 );

        wp_send_json_success([
            'type'    => 'search',
            'query'   => $query,
            'results' => $results,
            'count'   => count( $results ),
        ]);
    }

    /** Public progress poll (front end shows "loading data…"); admins also advance the import. */
    public function cbr_import_progress() {
        check_ajax_referer( 'cbr_nonce', 'nonce' );
        if ( current_user_can( 'manage_options' ) && CBR_Importer::is_pending() ) {
            CBR_Importer::run_chunk( 20 );
        }
        wp_send_json_success( [ 'progress' => CBR_Importer::progress(), 'verses' => CBR_Database::get_verse_count() ] );
    }

    public function cbr_get_books() {
        check_ajax_referer( 'cbr_nonce', 'nonce' );
        wp_send_json_success( CBR_Database::get_books() );
    }

    public function cbr_get_versions() {
        check_ajax_referer( 'cbr_nonce', 'nonce' );
        wp_send_json_success( CBR_Database::get_versions() );
    }

    /**
     * Build the response for a chapter or a verse range.
     * verse_start = 0 → full chapter. Otherwise only verses [start..end] are returned.
     */
    private static function build_passage_payload( $version_id, $book_id, $chapter_id, $verse_start = 0, $verse_end = 0 ) {
        if ( $verse_start > 0 && $verse_end < $verse_start ) {
            $verse_end = $verse_start;
        }

        if ( $verse_start > 0 ) {
            $verses = CBR_Database::get_verse_range( $version_id, $book_id, $chapter_id, $verse_start, $verse_end );
        } else {
            $verses = CBR_Database::get_chapter( $version_id, $book_id, $chapter_id );
        }

        $book_name = ! empty( $verses ) ? $verses[0]->book_name : CBR_Database::get_book_name( $book_id );

        return [
            'verses'         => $verses,
            'book_name'      => $book_name,
            'book_id'        => $book_id,
            'chapter_id'     => $chapter_id,
            'total_chapters' => CBR_Database::get_total_chapters( $book_id ),
            'verse_count'    => CBR_Database::get_chapter_verse_count( $version_id, $book_id, $chapter_id ),
            'version_id'     => $version_id,
            'verse_start'    => $verse_start,
            'verse_end'      => $verse_start > 0 ? $verse_end : 0,
            'is_partial'     => $verse_start > 0,
            'importing'      => empty( $verses ) ? CBR_Importer::progress() : null,
        ];
    }

    /**
     * Parse a passage reference into book/chapter/verse range.
     *
     *   "Genesis 8"          → whole chapter
     *   "Genesis 8:15"       → verse 15 only
     *   "Genesis 8:15-20"    → verses 15–20 (hyphen, en-dash, or em-dash; spaces allowed)
     *   "1 Corinthians 13:4" → numbered books
     *   "gen 1:1", "rev 22"  → prefix matching on book names
     *
     * Returns ['book_id','chapter','verse_start','verse_end'] or null.
     */
    public static function parse_passage_ref( $ref ) {
        // Normalize: unicode dashes → "-", collapse whitespace, tighten around ":" and "-"
        $ref = str_replace( [ "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x92", "\xE2\x88\x92" ], '-', $ref ); // – — ‒ −
        $ref = preg_replace( '/\s+/', ' ', trim( $ref ) );
        $ref = preg_replace( '/\s*:\s*/', ':', $ref );
        $ref = preg_replace( '/\s*-\s*/', '-', $ref );

        if ( ! preg_match( '/^(\d?\s*[a-z]+(?:\s+[a-z]+)*)\s+(\d+)(?::(\d+)(?:-(\d+))?)?$/i', $ref, $m ) ) {
            return null;
        }

        $book_input  = strtolower( trim( $m[1] ) );
        $chapter     = (int) $m[2];
        $verse_start = ( isset( $m[3] ) && $m[3] !== '' ) ? (int) $m[3] : 0;
        $verse_end   = ( isset( $m[4] ) && $m[4] !== '' ) ? (int) $m[4] : $verse_start;

        $book_id = self::match_book( $book_input );
        if ( ! $book_id || $chapter < 1 ) {
            return null;
        }

        return [
            'book_id'     => $book_id,
            'chapter'     => $chapter,
            'verse_start' => $verse_start,
            'verse_end'   => max( $verse_end, $verse_start ),
        ];
    }

    /**
     * Match user input to a book_id. Exact → compact (no spaces) → prefix.
     */
    private static function match_book( $input ) {
        $input_compact = preg_replace( '/\s+/', '', $input );

        $exact = []; $compact = [];
        foreach ( CBR_Database::get_books() as $b ) {
            $name = strtolower( $b->book_name );
            $exact[ $name ] = (int) $b->book_id;
            $compact[ preg_replace( '/\s+/', '', $name ) ] = (int) $b->book_id;
        }

        if ( isset( $exact[ $input ] ) )           return $exact[ $input ];
        if ( isset( $compact[ $input_compact ] ) ) return $compact[ $input_compact ];

        // Prefix match, in canonical book order (so "j" → Joshua not John, "jo" → Joshua, "joh" → John)
        foreach ( $compact as $name => $id ) {
            if ( strpos( $name, $input_compact ) === 0 ) return $id;
        }
        return 0;
    }
}
