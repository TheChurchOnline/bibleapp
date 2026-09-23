<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CBR_Frontend {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'church_bible', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register_assets() {
        wp_register_style( 'cbr-frontend', CBR_PLUGIN_URL . 'assets/css/frontend.css', [], CBR_VERSION );
        wp_register_script( 'cbr-frontend', CBR_PLUGIN_URL . 'assets/js/frontend.js', [ 'jquery' ], CBR_VERSION, true );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts([
            'version' => get_option( 'cbr_default_version', 3 ),
            'book'    => get_option( 'cbr_default_book', 1 ),
            'chapter' => 1,
            'layout'  => get_option( 'cbr_layout_style', 'classic' ),
            'dark'    => '0',
            'height'  => get_option( 'cbr_reader_height', '600' ), // px; 0 or "auto" = grow with content
            'mode'     => get_option( 'cbr_length_mode', 'paginate' ), // paginate | scroll | expand | full
            'preview'  => get_option( 'cbr_preview_verses', '15' ),    // verses shown before "Continue reading" (expand mode)
            'per_page' => get_option( 'cbr_verses_per_page', '7' ),    // verses per page (paginate mode)
            // Per-shortcode theme overrides (blank = use site-wide settings)
            'theme'        => '',   // preset slug, e.g. theme="navy"
            'primary'      => '',
            'secondary'    => '',
            'accent'       => '',
            'bg'           => '',
            'text_color'   => '',
            'heading_font' => '',
            'body_font'    => '',
            'font_size'    => '',
        ], $atts, 'church_bible' );

        // Reader height: numeric px value, or 0/auto for no cap
        $mode = strtolower( trim( (string) $atts['mode'] ) );
        if ( ! in_array( $mode, [ 'paginate', 'scroll', 'expand', 'full' ], true ) ) $mode = 'paginate';

        $height_px = ( strtolower( trim( (string) $atts['height'] ) ) === 'auto' ) ? 0 : absint( $atts['height'] );
        if ( $mode !== 'scroll' ) $height_px = 0; // height cap only applies in scroll mode

        $per_page = absint( $atts['per_page'] );
        if ( $per_page < 1 || $per_page > 200 ) $per_page = 7;

        $preview_verses = absint( $atts['preview'] );
        if ( $preview_verses < 3 ) $preview_verses = 15;

        wp_enqueue_style( 'cbr-frontend' );
        wp_enqueue_script( 'cbr-frontend' );

        // Resolve effective fonts: if the saved value is "__custom__", use the custom
        // text field; otherwise use the dropdown selection. Fall back to defaults.
        // Each font resolves to [ 'css' => font-family value, 'google' => family to load or null ]
        $heading = $this->resolve_font_spec( $atts['heading_font'] !== '' ? $atts['heading_font'] : null, 'heading', 'Playfair Display', 'serif' );
        $body    = $this->resolve_font_spec( $atts['body_font']    !== '' ? $atts['body_font']    : null, 'body',    'Source Serif 4',   'Georgia, serif' );

        // Google Fonts only for families that need it (theme/inherit fonts are already on the page)
        $gf = [];
        if ( $heading['google'] ) $gf[] = urlencode( $heading['google'] ) . ':wght@400;600;700';
        if ( $body['google'] && $body['google'] !== $heading['google'] ) $gf[] = urlencode( $body['google'] ) . ':ital,wght@0,400;0,600;1,400';
        if ( $gf ) {
            wp_enqueue_style( 'cbr-google-fonts', 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $gf ) . '&display=swap', [], null );
        }
        $heading_font = $heading['css'];
        $body_font    = $body['css'];

        wp_localize_script( 'cbr-frontend', 'cbrData', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'cbr_nonce' ),
            'defaultVersion'=> absint( $atts['version'] ),
            'defaultBook'   => absint( $atts['book'] ),
            'defaultChapter'=> absint( $atts['chapter'] ),
            'showVerseNums' => get_option( 'cbr_show_verse_nums', '1' ),
            'enableSearch'  => get_option( 'cbr_enable_search', '1' ),
            'enableDark'    => get_option( 'cbr_enable_dark_mode', '1' ),
            'layout'        => sanitize_text_field( $atts['layout'] ),
            'lengthMode'    => $mode,
            'previewVerses' => $preview_verses,
            'perPage'       => $per_page,
        ]);

        // CSS custom properties from admin settings
        $css_vars = $this->build_css_vars( $atts, $heading_font, $body_font );

        $versions = CBR_Database::get_versions();
        $books    = CBR_Database::get_books();

        $layout_class = 'cbr-layout-' . esc_attr( $atts['layout'] );
        $dark_class   = $atts['dark'] === '1' ? ' cbr-dark' : '';

        ob_start();
        ?>
        <style>.cbr-reader { <?php echo $css_vars; ?> }</style>

        <div id="cbr-bible-reader" class="cbr-reader <?php echo $layout_class . $dark_class; ?>" data-version="<?php echo esc_attr( $atts['version'] ); ?>" data-book="<?php echo esc_attr( $atts['book'] ); ?>" data-chapter="<?php echo esc_attr( $atts['chapter'] ); ?>">

            <!-- Top Bar -->
            <div class="cbr-toolbar">
                <div class="cbr-toolbar-left">
                    <select id="cbr-version-select" class="cbr-select" aria-label="Translation">
                        <?php foreach ( $versions as $v ) : ?>
                        <option value="<?php echo $v->version_id; ?>" <?php selected( $v->version_id, $atts['version'] ); ?>>
                            <?php echo esc_html( $v->version_abbr ?: $v->version_name ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="cbr-book-select" class="cbr-select" aria-label="Book">
                        <optgroup label="Old Testament">
                        <?php foreach ( $books as $b ) :
                            if ( $b->book_id == 40 ) echo '</optgroup><optgroup label="New Testament">';
                        ?>
                        <option value="<?php echo $b->book_id; ?>" data-chapters="<?php echo $b->total_chapters; ?>" <?php selected( $b->book_id, $atts['book'] ); ?>>
                            <?php echo esc_html( $b->book_name ); ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>

                    <select id="cbr-chapter-select" class="cbr-select" aria-label="Chapter">
                        <!-- Populated via JS -->
                    </select>

                    <select id="cbr-verse-select" class="cbr-select cbr-select-verse" aria-label="Verse">
                        <option value="0">All verses</option>
                    </select>

                    <button id="cbr-reset" class="cbr-btn cbr-btn-icon cbr-btn-reset" aria-label="Clear and return to default" title="Clear all &amp; return to default">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                    </button>
                </div>

                <div class="cbr-toolbar-right">
                    <?php if ( get_option( 'cbr_enable_search', '1' ) === '1' ) : ?>
                    <div class="cbr-search-wrap">
                        <input type="text" id="cbr-search-input" class="cbr-search" placeholder="Search, or go to e.g. John 3:16 or Gen 8:15-20" aria-label="Search" />
                        <button id="cbr-search-btn" class="cbr-btn cbr-btn-search" aria-label="Search">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php if ( get_option( 'cbr_enable_dark_mode', '1' ) === '1' ) : ?>
                    <button id="cbr-dark-toggle" class="cbr-btn cbr-btn-icon" aria-label="Toggle dark mode" title="Toggle dark mode">
                        <svg class="cbr-icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                        <svg class="cbr-icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                    <?php endif; ?>

                    <button id="cbr-font-up" class="cbr-btn cbr-btn-icon" aria-label="Increase font size" title="Increase font">A+</button>
                    <button id="cbr-font-down" class="cbr-btn cbr-btn-icon" aria-label="Decrease font size" title="Decrease font">A-</button>
                </div>
            </div>

            <!-- Book Browser (slide-out) -->
            <div id="cbr-book-browser" class="cbr-book-browser" style="display:none;">
                <div class="cbr-book-browser-inner">
                    <div class="cbr-testament">
                        <h4>Old Testament</h4>
                        <div class="cbr-book-grid" id="cbr-ot-books"></div>
                    </div>
                    <div class="cbr-testament">
                        <h4>New Testament</h4>
                        <div class="cbr-book-grid" id="cbr-nt-books"></div>
                    </div>
                </div>
            </div>

            <!-- Chapter Heading -->
            <div class="cbr-chapter-header">
                <button id="cbr-prev-chapter" class="cbr-nav-btn" aria-label="Previous chapter">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <h2 id="cbr-chapter-title" class="cbr-chapter-title">Loading…</h2>
                <button id="cbr-next-chapter" class="cbr-nav-btn" aria-label="Next chapter">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>

            <!-- Verse Content -->
            <div id="cbr-content" class="cbr-content<?php echo $height_px ? ' cbr-content-scroll' : ''; ?>" role="main"<?php if ( $height_px ) echo ' style="max-height:' . $height_px . 'px;"'; ?>>
                <div class="cbr-loading">
                    <div class="cbr-spinner"></div>
                </div>
            </div>

            <!-- Search Results -->
            <div id="cbr-search-results" class="cbr-search-results" style="display:none;">
                <div class="cbr-search-header">
                    <h3 id="cbr-search-title"></h3>
                    <button id="cbr-search-close" class="cbr-btn cbr-btn-icon" aria-label="Close search">✕</button>
                </div>
                <div id="cbr-search-list" class="cbr-search-list"></div>
            </div>

            <!-- Bottom Nav -->
            <div class="cbr-bottom-nav">
                <button id="cbr-prev-chapter-bottom" class="cbr-btn cbr-btn-nav">← Previous</button>
                <span id="cbr-page-info" class="cbr-page-info"></span>
                <button id="cbr-next-chapter-bottom" class="cbr-btn cbr-btn-nav">Next →</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * CSS custom properties for this reader instance.
     * Precedence: explicit shortcode attr > shortcode theme="preset" > site-wide settings.
     */
    private function build_css_vars( $atts, $heading_font, $body_font ) {
        $site = [
            'primary'   => get_option( 'cbr_primary_color',   '#2c5f2d' ),
            'secondary' => get_option( 'cbr_secondary_color', '#97bc62' ),
            'accent'    => get_option( 'cbr_accent_color',    '#d4a574' ),
            'bg'        => get_option( 'cbr_bg_color',        '#faf8f5' ),
            'text'      => get_option( 'cbr_text_color',      '#2d2926' ),
        ];

        // theme="slug" preset
        $preset_slug = sanitize_key( $atts['theme'] );
        if ( $preset_slug && class_exists( 'CBR_Admin' ) ) {
            $presets = CBR_Admin::presets();
            if ( isset( $presets[ $preset_slug ] ) ) {
                list( $site['primary'], $site['secondary'], $site['accent'], $site['bg'], $site['text'] ) = $presets[ $preset_slug ]['colors'];
            }
        }

        // individual attrs
        $map = [ 'primary' => 'primary', 'secondary' => 'secondary', 'accent' => 'accent', 'bg' => 'bg', 'text_color' => 'text' ];
        foreach ( $map as $attr => $slot ) {
            $c = $this->sanitize_color( $atts[ $attr ] );
            if ( $c ) $site[ $slot ] = $c;
        }

        $size = $atts['font_size'] !== '' ? absint( $atts['font_size'] ) : absint( get_option( 'cbr_verse_font_size', '18' ) );
        if ( $size < 10 || $size > 48 ) $size = 18;

        $vars = [
            '--cbr-primary'      => $this->sanitize_color( $site['primary'] )   ?: '#2c5f2d',
            '--cbr-secondary'    => $this->sanitize_color( $site['secondary'] ) ?: '#97bc62',
            '--cbr-accent'       => $this->sanitize_color( $site['accent'] )    ?: '#d4a574',
            '--cbr-bg'           => $this->sanitize_color( $site['bg'] )        ?: '#faf8f5',
            '--cbr-text'         => $this->sanitize_color( $site['text'] )      ?: '#2d2926',
            '--cbr-heading-font' => $heading_font,
            '--cbr-body-font'    => $body_font,
            '--cbr-verse-size'   => $size . 'px',
        ];

        $out = '';
        foreach ( $vars as $k => $v ) {
            $out .= "$k: $v; ";
        }
        return $out;
    }

    /**
     * Accept #hex (3/4/6/8), rgb()/rgba()/hsl()/hsla(), or a CSS named color. Anything else → ''.
     */
    private function sanitize_color( $value ) {
        $v = trim( (string) $value );
        if ( $v === '' ) return '';
        if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) ) return $v;
        if ( preg_match( '/^(rgb|hsl)a?\(\s*[\d.%]+\s*,?\s*[\d.%]+\s*,?\s*[\d.%]+\s*(?:[,\/]\s*[\d.%]+\s*)?\)$/i', $v ) ) return $v;
        if ( preg_match( '/^[a-z]{3,20}$/i', $v ) ) return strtolower( $v ); // named color
        return '';
    }

    /**
     * Resolve a font slot to a CSS font-family value and (if needed) a Google Fonts family to load.
     *
     * Accepted values (from settings or shortcode attr):
     *   "__inherit__" / "inherit"      → inherit the page's font
     *   "__theme__:<slug>" / "theme:<slug>" → theme.json font family (CSS variable WP outputs)
     *   "__custom__"                   → use the custom text field for this slot
     *   any other name                 → Google Fonts family
     */
    private function resolve_font_spec( $override, $slot, $fallback, $generic ) {
        $value = $override !== null ? trim( (string) $override ) : (string) get_option( "cbr_{$slot}_font", $fallback );

        if ( $value === '__custom__' ) {
            $custom = trim( (string) get_option( "cbr_{$slot}_font_custom", '' ) );
            $value  = $custom !== '' ? $custom : $fallback;
        }

        $lower = strtolower( $value );
        if ( $lower === '__inherit__' || $lower === 'inherit' ) {
            return [ 'css' => 'inherit', 'google' => null ];
        }

        if ( preg_match( '/^(?:__theme__:|theme:)([a-z0-9\-]+)$/i', $value, $m ) ) {
            $slug = sanitize_key( $m[1] );
            $family = '';
            if ( class_exists( 'CBR_Admin' ) ) {
                foreach ( CBR_Admin::get_theme_fonts() as $tf ) {
                    if ( $tf['slug'] === $slug ) { $family = $tf['family']; break; }
                }
            }
            $fb = $family !== '' ? $family : $generic;
            return [ 'css' => "var(--wp--preset--font-family--{$slug}, {$fb})", 'google' => null ];
        }

        $name = $this->sanitize_font_name( $value, $fallback );
        return [ 'css' => "'" . $name . "', " . $generic, 'google' => $name ];
    }

    /**
     * Strip anything that could break the CSS variable block or the Google Fonts URL.
     * Allowed: letters, digits, spaces, and hyphens. Anything else (quotes, commas,
     * semicolons, newlines, etc.) is removed.
     */
    private function sanitize_font_name( $name, $fallback ) {
        $clean = preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) $name );
        $clean = trim( preg_replace( '/\s+/', ' ', $clean ) );
        return $clean !== '' ? $clean : $fallback;
    }
}
