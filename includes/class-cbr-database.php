<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CBR_Database {

    /**
     * Create the plugin's custom tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = [];

        // Versions table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cbr_versions (
            version_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            version_name VARCHAR(100) NOT NULL,
            version_abbr VARCHAR(10) NOT NULL DEFAULT '',
            table_name VARCHAR(100) NOT NULL DEFAULT '',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (version_id)
        ) $charset;";

        // Books table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cbr_books (
            book_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_name VARCHAR(100) NOT NULL,
            total_chapters SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            testament ENUM('OT','NT') NOT NULL DEFAULT 'OT',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (book_id)
        ) $charset;";

        // Verses table (unified — all versions in one table)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cbr_verses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            version_id SMALLINT UNSIGNED NOT NULL,
            book_id SMALLINT UNSIGNED NOT NULL,
            chapter_id SMALLINT UNSIGNED NOT NULL,
            verse_id SMALLINT UNSIGNED NOT NULL,
            verse_text TEXT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_lookup (version_id, book_id, chapter_id),
            KEY idx_book_chapter (book_id, chapter_id),
            KEY idx_version (version_id),
            FULLTEXT KEY ft_verse_text (verse_text)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }

        // Seed books if empty
        self::maybe_seed_books();
    }

    /**
     * Seed the 66 books of the Bible if table is empty
     */
    private static function maybe_seed_books() {
        global $wpdb;
        $table = $wpdb->prefix . 'cbr_books';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        if ( $count > 0 ) return;

        $books = [
            [1,'Genesis',50,'OT'], [2,'Exodus',40,'OT'], [3,'Leviticus',27,'OT'],
            [4,'Numbers',36,'OT'], [5,'Deuteronomy',34,'OT'], [6,'Joshua',24,'OT'],
            [7,'Judges',21,'OT'], [8,'Ruth',4,'OT'], [9,'1 Samuel',31,'OT'],
            [10,'2 Samuel',24,'OT'], [11,'1 Kings',22,'OT'], [12,'2 Kings',25,'OT'],
            [13,'1 Chronicles',29,'OT'], [14,'2 Chronicles',36,'OT'], [15,'Ezra',10,'OT'],
            [16,'Nehemiah',13,'OT'], [17,'Esther',10,'OT'], [18,'Job',42,'OT'],
            [19,'Psalms',150,'OT'], [20,'Proverbs',31,'OT'], [21,'Ecclesiastes',12,'OT'],
            [22,'Song of Solomon',8,'OT'], [23,'Isaiah',66,'OT'], [24,'Jeremiah',52,'OT'],
            [25,'Lamentations',5,'OT'], [26,'Ezekiel',48,'OT'], [27,'Daniel',12,'OT'],
            [28,'Hosea',14,'OT'], [29,'Joel',3,'OT'], [30,'Amos',9,'OT'],
            [31,'Obadiah',1,'OT'], [32,'Jonah',4,'OT'], [33,'Micah',7,'OT'],
            [34,'Nahum',3,'OT'], [35,'Habakkuk',3,'OT'], [36,'Zephaniah',3,'OT'],
            [37,'Haggai',2,'OT'], [38,'Zechariah',14,'OT'], [39,'Malachi',4,'OT'],
            [40,'Matthew',28,'NT'], [41,'Mark',16,'NT'], [42,'Luke',24,'NT'],
            [43,'John',21,'NT'], [44,'Acts',28,'NT'], [45,'Romans',16,'NT'],
            [46,'1 Corinthians',16,'NT'], [47,'2 Corinthians',13,'NT'], [48,'Galatians',6,'NT'],
            [49,'Ephesians',6,'NT'], [50,'Philippians',4,'NT'], [51,'Colossians',4,'NT'],
            [52,'1 Thessalonians',5,'NT'], [53,'2 Thessalonians',3,'NT'], [54,'1 Timothy',6,'NT'],
            [55,'2 Timothy',4,'NT'], [56,'Titus',3,'NT'], [57,'Philemon',1,'NT'],
            [58,'Hebrews',13,'NT'], [59,'James',5,'NT'], [60,'1 Peter',5,'NT'],
            [61,'2 Peter',3,'NT'], [62,'1 John',5,'NT'], [63,'2 John',1,'NT'],
            [64,'3 John',1,'NT'], [65,'Jude',1,'NT'], [66,'Revelation',22,'NT'],
        ];

        foreach ( $books as $i => $b ) {
            $wpdb->insert( $table, [
                'book_id'        => $b[0],
                'book_name'      => $b[1],
                'total_chapters' => $b[2],
                'testament'      => $b[3],
                'sort_order'     => $i + 1,
            ]);
        }
    }

    /**
     * Get all active versions
     */
    public static function get_versions() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}cbr_versions WHERE is_active = 1 ORDER BY sort_order, version_id"
        );
    }

    /**
     * Get all books
     */
    public static function get_books() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}cbr_books ORDER BY sort_order, book_id"
        );
    }

    /**
     * Get verses for a chapter
     */
    public static function get_chapter( $version_id, $book_id, $chapter_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.verse_id, v.verse_text, b.book_name
             FROM {$wpdb->prefix}cbr_verses v
             JOIN {$wpdb->prefix}cbr_books b ON b.book_id = v.book_id
             WHERE v.version_id = %d AND v.book_id = %d AND v.chapter_id = %d
             ORDER BY v.verse_id",
            $version_id, $book_id, $chapter_id
        ));
    }

    /**
     * Get a contiguous range of verses within one chapter
     */
    public static function get_verse_range( $version_id, $book_id, $chapter_id, $verse_start, $verse_end ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.verse_id, v.verse_text, b.book_name
             FROM {$wpdb->prefix}cbr_verses v
             JOIN {$wpdb->prefix}cbr_books b ON b.book_id = v.book_id
             WHERE v.version_id = %d AND v.book_id = %d AND v.chapter_id = %d
               AND v.verse_id BETWEEN %d AND %d
             ORDER BY v.verse_id",
            $version_id, $book_id, $chapter_id, $verse_start, $verse_end
        ));
    }

    /**
     * Number of verses in a chapter for a given version
     */
    public static function get_chapter_verse_count( $version_id, $book_id, $chapter_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(verse_id) FROM {$wpdb->prefix}cbr_verses WHERE version_id = %d AND book_id = %d AND chapter_id = %d",
            $version_id, $book_id, $chapter_id
        ));
    }

    /**
     * Get a book's display name by ID
     */
    public static function get_book_name( $book_id ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT book_name FROM {$wpdb->prefix}cbr_books WHERE book_id = %d", $book_id
        ));
    }

    /**
     * Get a single verse
     */
    public static function get_verse( $version_id, $book_id, $chapter_id, $verse_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT v.*, b.book_name
             FROM {$wpdb->prefix}cbr_verses v
             JOIN {$wpdb->prefix}cbr_books b ON b.book_id = v.book_id
             WHERE v.version_id = %d AND v.book_id = %d AND v.chapter_id = %d AND v.verse_id = %d",
            $version_id, $book_id, $chapter_id, $verse_id
        ));
    }

    /**
     * Search verses by keyword
     */
    public static function search_verses( $version_id, $query, $book_id = 0, $limit = 50 ) {
        global $wpdb;

        $where = $wpdb->prepare( "v.version_id = %d", $version_id );
        if ( $book_id > 0 ) {
            $where .= $wpdb->prepare( " AND v.book_id = %d", $book_id );
        }

        $search_term = '%' . $wpdb->esc_like( $query ) . '%';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.verse_id, v.chapter_id, v.book_id, v.verse_text, b.book_name
             FROM {$wpdb->prefix}cbr_verses v
             JOIN {$wpdb->prefix}cbr_books b ON b.book_id = v.book_id
             WHERE $where AND v.verse_text LIKE %s
             ORDER BY v.book_id, v.chapter_id, v.verse_id
             LIMIT %d",
            $search_term, $limit
        ));
    }

    /**
     * Get total chapters for a book
     */
    public static function get_total_chapters( $book_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT total_chapters FROM {$wpdb->prefix}cbr_books WHERE book_id = %d",
            $book_id
        ));
    }

    /**
     * Get verse count
     */
    public static function get_verse_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbr_verses" );
    }

    /**
     * Get version count
     */
    public static function get_version_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbr_versions" );
    }

    /**
     * Detect data imported with book_id/verse_id swapped (bug in plugin versions <= 1.4.1).
     * Genuine data has verses in book 1 chapter 1 beyond verse 31; swapped data has book_id values
     * up to 176 (Psalm 119's verse count) where nothing is stored in books > 66.
     */
    public static function verses_are_swapped() {
        global $wpdb;
        $t = $wpdb->prefix . 'cbr_verses';
        $max_book = (int) $wpdb->get_var( "SELECT MAX(book_id) FROM $t" ); // indexed; NULL/0 when empty
        return $max_book > 66;
    }

    /**
     * Swap book_id and verse_id in place. Single UPDATE; a few seconds on ~186K rows.
     */
    public static function repair_swapped_verses() {
        global $wpdb;
        $t = $wpdb->prefix . 'cbr_verses';
        $wpdb->query( "UPDATE $t SET book_id = (@cbr_tmp := book_id), book_id = verse_id, verse_id = @cbr_tmp" );
        return ! self::verses_are_swapped();
    }

    /**
     * Truncate verses table
     */
    public static function truncate_verses() {
        global $wpdb;
        delete_option( 'cbr_data_verified' );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cbr_verses" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cbr_versions" );
    }
}
