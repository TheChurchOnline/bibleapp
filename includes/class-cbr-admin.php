<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CBR_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menus' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'admin_post_cbr_import_sql', [ $this, 'handle_import' ] );
        add_action( 'admin_post_cbr_reimport_bundled', [ $this, 'handle_reimport' ] );
        add_action( 'admin_post_cbr_reset_data', [ $this, 'handle_reset' ] );
        add_action( 'admin_post_cbr_repair_data', [ $this, 'handle_repair' ] );
    }

    public function add_menus() {
        add_menu_page(
            'The Church Online Bible Reader', 'Bible Reader', 'manage_options',
            'church-bible-reader', [ $this, 'render_dashboard' ],
            'dashicons-book-alt', 30
        );

        add_submenu_page(
            'church-bible-reader', 'Settings', 'Settings',
            'manage_options', 'cbr-settings', [ $this, 'render_settings' ]
        );

        add_submenu_page(
            'church-bible-reader', 'Manage Data', 'Manage Data',
            'manage_options', 'cbr-import', [ $this, 'render_import' ]
        );
    }

    public function enqueue_admin( $hook ) {
        if ( strpos( $hook, 'church-bible-reader' ) === false && strpos( $hook, 'cbr-' ) === false ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style( 'cbr-admin', CBR_PLUGIN_URL . 'assets/css/admin.css', [], CBR_VERSION );
    }

    public function register_settings() {
        $fields = [
            'cbr_default_version','cbr_default_book','cbr_primary_color','cbr_secondary_color',
            'cbr_accent_color','cbr_bg_color','cbr_text_color','cbr_heading_font','cbr_body_font',
            'cbr_heading_font_custom','cbr_body_font_custom',
            'cbr_verse_font_size','cbr_reader_height','cbr_length_mode','cbr_preview_verses','cbr_verses_per_page','cbr_show_verse_nums','cbr_enable_search',
            'cbr_enable_dark_mode','cbr_layout_style',
        ];
        foreach ( $fields as $f ) {
            register_setting( 'cbr_settings_group', $f );
        }
    }

    /* ======================================================================
       Dashboard
       ====================================================================== */
    public function render_dashboard() {
        $verse_count   = CBR_Database::get_verse_count();
        $version_count = CBR_Database::get_version_count();
        ?>
        <div class="wrap cbr-admin-wrap cbr-settings">
            <div class="cbr-page-head">
                <h1>Bible Reader <span>Overview</span></h1>
                <p class="cbr-page-sub">The Church Online Bible Reader v<?php echo CBR_VERSION; ?> — 6 translations, 66 books, 185,000+ verses.</p>
            </div>

            <div class="cbr-admin-cards">
                <div class="cbr-card">
                    <h3>📖 Total Verses</h3>
                    <p class="cbr-stat"><?php echo number_format( $verse_count ); ?></p>
                </div>
                <div class="cbr-card">
                    <h3>📚 Translations</h3>
                    <p class="cbr-stat"><?php echo $version_count; ?></p>
                </div>
                <div class="cbr-card">
                    <h3>📋 Shortcode</h3>
                    <p><code>[church_bible]</code></p>
                    <p class="description">Place this on any page or post.</p>
                </div>
            </div>

            <?php if ( $verse_count === 0 ) : ?>
            <div class="notice notice-warning">
                <p><strong>No Bible data found!</strong> Go to <a href="<?php echo admin_url( 'admin.php?page=cbr-import' ); ?>">Manage Data</a> and click <em>Re-import Bundled Data</em>.</p>
            </div>
            <?php endif; ?>

            <div class="cbr-card" style="max-width:700px;">
                <h3>Quick Start</h3>
                <ol>
                    <li>All 185,000+ verses were imported automatically when the plugin was activated.</li>
                    <li>Customize colors and fonts in <a href="<?php echo admin_url( 'admin.php?page=cbr-settings' ); ?>">Settings</a>.</li>
                    <li>Add <code>[church_bible]</code> to any page or post.</li>
                </ol>
                <h4>Shortcode Options</h4>
                <p><code>[church_bible version="3" book="43" chapter="3"]</code> — Opens to John 3 in KJV</p>
                <p><code>[church_bible layout="modern"]</code> — Card-based layout</p>
                <p><code>[church_bible layout="minimal" dark="1"]</code> — Minimal layout, dark mode default</p>
            
                <h4>Per-Shortcode Theme Overrides</h4>
                <p>Anything not specified falls back to the site-wide settings.</p>
                <p><code>[church_bible theme="navy"]</code> — use a preset. Presets: <?php echo implode( ', ', array_map( function( $k ){ return '<code>' . $k . '</code>'; }, array_keys( self::presets() ) ) ); ?></p>
                <p><code>[church_bible primary="#1a2535" accent="#c49840" bg="#f5f3ee" text_color="#1f2733"]</code></p>
                <p><code>[church_bible heading_font="EB Garamond" body_font="Crimson Text" font_size="20" height="450"]</code></p>
                <table class="widefat" style="margin-top:12px;max-width:640px;">
                    <thead><tr><th>Attribute</th><th>Controls</th></tr></thead>
                    <tbody>
                        <tr><td><code>theme</code></td><td>Preset palette slug</td></tr>
                        <tr><td><code>primary</code></td><td>Toolbar, chapter title, buttons</td></tr>
                        <tr><td><code>secondary</code></td><td>Headings &amp; buttons in dark mode</td></tr>
                        <tr><td><code>accent</code></td><td>Verse numbers, highlights, card borders</td></tr>
                        <tr><td><code>bg</code></td><td>Reader background</td></tr>
                        <tr><td><code>text_color</code></td><td>Verse text</td></tr>
                        <tr><td><code>heading_font</code> / <code>body_font</code></td><td>Any Google Fonts family</td></tr>
                        <tr><td><code>font_size</code></td><td>Base verse size in px</td></tr>
                        <tr><td><code>mode</code></td><td><code>paginate</code>, <code>scroll</code>, <code>expand</code> (Continue reading), or <code>full</code></td></tr>
                        <tr><td><code>per_page</code></td><td>Verses per page (paginate mode, default 7)</td></tr>
                        <tr><td><code>height</code></td><td>Scroll box height in px (scroll mode)</td></tr>
                        <tr><td><code>preview</code></td><td>Verses shown before "Continue reading" (expand mode)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /* ======================================================================
       Settings
       ====================================================================== */
    public function render_settings() {
        $versions = CBR_Database::get_versions();
        $books    = CBR_Database::get_books();
        ?>
        <div class="wrap cbr-admin-wrap cbr-settings">
            <div class="cbr-page-head">
                <h1>Bible Reader <span>Settings</span></h1>
                <p class="cbr-page-sub">Site-wide defaults for every <code>[church_bible]</code> reader on this site. Any of these can be overridden per page with shortcode attributes.</p>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php" id="cbr-settings-form">
                <?php settings_fields( 'cbr_settings_group' ); ?>

                <nav class="cbr-tabs" aria-label="Settings sections">
                    <a href="#cbr-tab-general" class="cbr-tab is-active" data-tab="general"><span class="dashicons dashicons-admin-generic"></span> General</a>
                    <a href="#cbr-tab-colors" class="cbr-tab" data-tab="colors"><span class="dashicons dashicons-art"></span> Colors</a>
                    <a href="#cbr-tab-fonts" class="cbr-tab" data-tab="fonts"><span class="dashicons dashicons-editor-textcolor"></span> Fonts</a>
                </nav>

                <div class="cbr-settings-grid">
                    <div class="cbr-card cbr-tab-panel is-active" id="cbr-tab-general" data-panel="general">
                        <div class="cbr-card-head"><h3>General</h3><p>What the reader opens to and how it behaves.</p></div>
                        <table class="form-table">
                            <tr>
                                <th>Default Translation</th>
                                <td>
                                    <select name="cbr_default_version">
                                        <?php foreach ( $versions as $v ) : ?>
                                        <option value="<?php echo $v->version_id; ?>" <?php selected( get_option('cbr_default_version'), $v->version_id ); ?>>
                                            <?php echo esc_html( $v->version_name ); ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php if ( empty( $versions ) ) : ?>
                                        <option value="3">KJV (import data first)</option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Default Book</th>
                                <td>
                                    <select name="cbr_default_book">
                                        <?php foreach ( $books as $b ) : ?>
                                        <option value="<?php echo $b->book_id; ?>" <?php selected( get_option('cbr_default_book'), $b->book_id ); ?>>
                                            <?php echo esc_html( $b->book_name ); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Layout Style</th>
                                <td>
                                    <select name="cbr_layout_style">
                                        <option value="classic" <?php selected( get_option('cbr_layout_style'), 'classic' ); ?>>Classic (traditional)</option>
                                        <option value="modern" <?php selected( get_option('cbr_layout_style'), 'modern' ); ?>>Modern (card-based)</option>
                                        <option value="minimal" <?php selected( get_option('cbr_layout_style'), 'minimal' ); ?>>Minimal (clean)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Font Size (px)</th>
                                <td><input type="number" name="cbr_verse_font_size" value="<?php echo esc_attr( get_option('cbr_verse_font_size', 18) ); ?>" min="12" max="32" /></td>
                            </tr>
                            <tr>
                                <th>Long Chapters</th>
                                <td>
                                    <?php $mode = get_option( 'cbr_length_mode', 'paginate' ); ?>
                                    <select name="cbr_length_mode" id="cbr_length_mode">
                                        <option value="paginate" <?php selected( $mode, 'paginate' ); ?>>Paginate — a few verses per page with Prev / Next</option>
                                        <option value="scroll" <?php selected( $mode, 'scroll' ); ?>>Scroll box — fixed height, scrolls inside the reader</option>
                                        <option value="expand" <?php selected( $mode, 'expand' ); ?>>Continue reading — short preview, one click expands the chapter</option>
                                        <option value="full"   <?php selected( $mode, 'full' ); ?>>Full — show the whole chapter, page grows</option>
                                    </select>
                                    <p class="description">Override per page with <code>[church_bible mode="scroll"]</code>, <code>mode="expand"</code>, or <code>mode="full"</code>.</p>
                                </td>
                            </tr>
                            <tr class="cbr-mode-row cbr-mode-paginate">
                                <th>Verses per Page</th>
                                <td>
                                    <input type="number" name="cbr_verses_per_page" value="<?php echo esc_attr( get_option('cbr_verses_per_page', 7) ); ?>" min="1" max="200" />
                                    <p class="description">Override with <code>per_page="10"</code>.</p>
                                </td>
                            </tr>
                            <tr class="cbr-mode-row cbr-mode-scroll">
                                <th>Reader Height (px)</th>
                                <td>
                                    <input type="number" name="cbr_reader_height" value="<?php echo esc_attr( get_option('cbr_reader_height', 600) ); ?>" min="100" max="2000" step="50" />
                                    <p class="description">Scroll box height. Override with <code>height="400"</code>.</p>
                                </td>
                            </tr>
                            <tr class="cbr-mode-row cbr-mode-expand">
                                <th>Preview Verses</th>
                                <td>
                                    <input type="number" name="cbr_preview_verses" value="<?php echo esc_attr( get_option('cbr_preview_verses', 15) ); ?>" min="3" max="100" />
                                    <p class="description">Verses shown before the "Continue reading" button. Override with <code>preview="10"</code>.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Show Verse Numbers</th>
                                <td><input type="checkbox" name="cbr_show_verse_nums" value="1" <?php checked( get_option('cbr_show_verse_nums'), '1' ); ?> /></td>
                            </tr>
                            <tr>
                                <th>Enable Search</th>
                                <td><input type="checkbox" name="cbr_enable_search" value="1" <?php checked( get_option('cbr_enable_search'), '1' ); ?> /></td>
                            </tr>
                            <tr>
                                <th>Enable Dark Mode Toggle</th>
                                <td><input type="checkbox" name="cbr_enable_dark_mode" value="1" <?php checked( get_option('cbr_enable_dark_mode'), '1' ); ?> /></td>
                            </tr>
                        </table>
                    </div>

                    <div class="cbr-card cbr-tab-panel" id="cbr-tab-colors" data-panel="colors">
                        <div class="cbr-card-head"><h3>Colors</h3><p>Match the reader to this website. Pull from the theme's palette, start from a preset, or set each color by hand.</p></div>
                        <div class="cbr-two-col">
                        <div class="cbr-col-main">

                        <?php $palette = $this->get_theme_palette(); ?>
                        <?php if ( ! empty( $palette ) ) : ?>
                        <div class="cbr-theme-palette">
                            <div class="cbr-palette-head">
                                <strong>This site's theme palette</strong> <span class="description">(<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>)</span>
                                <button type="button" class="button button-small" id="cbr-automatch">Auto-match reader to theme</button>
                            </div>
                            <div class="cbr-swatches" id="cbr-theme-swatches">
                                <?php foreach ( $palette as $c ) : ?>
                                <button type="button" class="cbr-swatch" data-color="<?php echo esc_attr( $c['color'] ); ?>" data-slug="<?php echo esc_attr( $c['slug'] ); ?>" title="<?php echo esc_attr( $c['name'] . ' ' . $c['color'] ); ?>" style="background:<?php echo esc_attr( $c['color'] ); ?>"></button>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">Click a swatch, then click the color field you want to apply it to.</p>
                        </div>
                        <?php else : ?>
                        <p class="description"><em>The active theme doesn't publish a color palette. Use a preset or set colors manually below.</em></p>
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th>Start from a preset</th>
                                <td>
                                    <select id="cbr-preset">
                                        <option value="">— Choose a preset —</option>
                                        <?php foreach ( self::presets() as $slug => $pr ) : ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>" data-colors='<?php echo esc_attr( wp_json_encode( $pr['colors'] ) ); ?>'><?php echo esc_html( $pr['name'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Fills the fields below; you can still tweak any of them afterwards.</p>
                                </td>
                            </tr>
                            <?php
                            $colors = [
                                'cbr_primary_color'   => [ 'Primary',    'Toolbar, chapter title, buttons',        '--cbr-primary'   ],
                                'cbr_secondary_color' => [ 'Secondary',  'Headings & buttons in dark mode',        '--cbr-secondary' ],
                                'cbr_accent_color'    => [ 'Accent',     'Verse numbers, highlights, card borders', '--cbr-accent'    ],
                                'cbr_bg_color'        => [ 'Background', 'Reader background',                      '--cbr-bg'        ],
                                'cbr_text_color'      => [ 'Text',       'Verse text',                             '--cbr-text'      ],
                            ];
                            foreach ( $colors as $key => $meta ) :
                            ?>
                            <tr>
                                <th><?php echo $meta[0]; ?><br><span class="description" style="font-weight:normal;"><?php echo $meta[1]; ?></span></th>
                                <td><input type="text" name="<?php echo $key; ?>" id="<?php echo $key; ?>" value="<?php echo esc_attr( get_option( $key ) ); ?>" class="cbr-color-picker" data-var="<?php echo $meta[2]; ?>" /></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>

                        </div><!-- /.cbr-col-main -->
                        <aside class="cbr-col-side">
                        <h4>Live preview</h4>
                        <div id="cbr-preview" class="cbr-preview" style="--cbr-primary:<?php echo esc_attr( get_option('cbr_primary_color','#2c5f2d') ); ?>;--cbr-secondary:<?php echo esc_attr( get_option('cbr_secondary_color','#97bc62') ); ?>;--cbr-accent:<?php echo esc_attr( get_option('cbr_accent_color','#d4a574') ); ?>;--cbr-bg:<?php echo esc_attr( get_option('cbr_bg_color','#faf8f5') ); ?>;--cbr-text:<?php echo esc_attr( get_option('cbr_text_color','#2d2926') ); ?>;">
                            <div class="cbr-preview-toolbar"><span>KJV</span><span>Genesis</span><span>Chapter 1</span></div>
                            <div class="cbr-preview-body">
                                <div class="cbr-preview-title">Genesis 1</div>
                                <p><sup>1</sup>In the beginning God created the heaven and the earth. <sup>2</sup>And the earth was without form, and void; and darkness was upon the face of the deep.</p>
                                <span class="cbr-preview-btn">Next →</span>
                            </div>
                        </div>
                        <p class="description">Updates as you change colors or fonts. Save to apply to the site.</p>
                        </aside>
                        </div><!-- /.cbr-two-col -->
                    </div>

                    <div class="cbr-card cbr-tab-panel" id="cbr-tab-fonts" data-panel="fonts">
                        <div class="cbr-card-head"><h3>Fonts</h3><p>Match the website's own fonts, inherit whatever the page uses, pick from the list, or enter any <a href="https://fonts.google.com" target="_blank" rel="noopener">Google Fonts</a> family.</p></div>

                        <?php $theme_fonts = self::get_theme_fonts(); ?>
                        <div class="cbr-theme-palette">
                            <div class="cbr-palette-head">
                                <strong>This site's theme fonts</strong> <span class="description">(<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>)</span>
                                <button type="button" class="button button-small" id="cbr-automatch-fonts">Auto-match fonts to theme</button>
                            </div>
                            <?php if ( ! empty( $theme_fonts ) ) : ?>
                            <div class="cbr-font-samples">
                                <?php foreach ( $theme_fonts as $tf ) : ?>
                                <div class="cbr-font-sample" style="font-family: var(--wp--preset--font-family--<?php echo esc_attr( $tf['slug'] ); ?>, <?php echo esc_attr( $tf['family'] ); ?>);">
                                    <span class="cbr-font-sample-text">In the beginning God created the heaven and the earth.</span>
                                    <span class="cbr-font-sample-name"><?php echo esc_html( $tf['name'] ); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">These appear under <em>Match this website</em> in both dropdowns. Auto-match uses the first for headings and the second (or the same one) for verse text.</p>
                            <?php else : ?>
                            <p class="description" style="margin:0;"><em>This theme doesn't publish font families.</em> Auto-match will set both fonts to <strong>Inherit page font</strong>, which uses whatever the page already uses.</p>
                            <?php endif; ?>
                        </div>
                        <table class="form-table">
                            <?php
                            // Shared curated list. 30+ fonts across serif, sans-serif, and display.
                            $font_choices = [
                                'Serif' => [
                                    'Playfair Display','Merriweather','Lora','Crimson Text','Crimson Pro',
                                    'EB Garamond','Cormorant Garamond','Libre Baskerville','Libre Caslon Text',
                                    'Vollkorn','Alegreya','Source Serif 4','PT Serif','Noto Serif','Cardo',
                                    'Spectral','Bitter','Gentium Book Plus','Literata',
                                ],
                                'Sans-serif' => [
                                    'Inter','Lato','Open Sans','Roboto','Montserrat','Nunito','Poppins',
                                    'Work Sans','Source Sans 3','PT Sans','Noto Sans','Figtree','Outfit','Rubik',
                                ],
                                'Display / Modern' => [
                                    'DM Serif Display','DM Serif Text','Fraunces','Bodoni Moda','Cinzel',
                                    'Prata','Marcellus','Cormorant','Yeseva One',
                                ],
                                'System' => [ 'Georgia','Charter' ],
                            ];
                            $current_heading = get_option( 'cbr_heading_font', 'Playfair Display' );
                            $current_body    = get_option( 'cbr_body_font', 'Source Serif 4' );

                            // Detect whether the saved value is in the curated list. If not, treat it
                            // as a custom entry so older custom values survive an upgrade.
                            $all_fonts = [];
                            foreach ( $font_choices as $group ) { $all_fonts = array_merge( $all_fonts, $group ); }
                            $is_special = function( $v ) { return $v === '__inherit__' || strpos( (string) $v, '__theme__:' ) === 0; };
                            $heading_is_custom = ! in_array( $current_heading, $all_fonts, true ) && ! $is_special( $current_heading );
                            $body_is_custom    = ! in_array( $current_body, $all_fonts, true ) && ! $is_special( $current_body );

                            // If the saved value is actually a custom name, seed the custom field with it
                            // on first render so the input isn't empty.
                            $heading_custom_val = get_option( 'cbr_heading_font_custom', '' );
                            if ( $heading_is_custom && $heading_custom_val === '' ) $heading_custom_val = $current_heading;
                            $body_custom_val    = get_option( 'cbr_body_font_custom', '' );
                            if ( $body_is_custom && $body_custom_val === '' ) $body_custom_val = $current_body;

                            $render_font_select = function( $name, $current, $is_custom ) use ( $font_choices, $theme_fonts ) {
                                ?>
                                <select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" class="cbr-font-select" data-target="<?php echo esc_attr( $name ); ?>_custom_wrap">
                                    <optgroup label="Match this website">
                                        <option value="__inherit__" <?php selected( $current === '__inherit__' ); ?>>Inherit page font</option>
                                        <?php foreach ( $theme_fonts as $tf ) : ?>
                                        <option value="__theme__:<?php echo esc_attr( $tf['slug'] ); ?>" <?php selected( $current === '__theme__:' . $tf['slug'] ); ?>>Theme: <?php echo esc_html( $tf['name'] ); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php foreach ( $font_choices as $group_label => $fonts ) : ?>
                                        <optgroup label="<?php echo esc_attr( $group_label ); ?>">
                                        <?php foreach ( $fonts as $f ) : ?>
                                            <option value="<?php echo esc_attr( $f ); ?>" <?php selected( ! $is_custom && $current === $f ); ?>>
                                                <?php echo esc_html( $f ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                    <option value="__custom__" <?php selected( $is_custom ); ?>>Custom…</option>
                                </select>
                                <?php
                            };
                            ?>
                            <tr>
                                <th>Heading Font</th>
                                <td>
                                    <?php $render_font_select( 'cbr_heading_font', $current_heading, $heading_is_custom ); ?>
                                    <div class="cbr-font-custom-wrap" id="cbr_heading_font_custom_wrap" style="margin-top:8px; <?php echo $heading_is_custom ? '' : 'display:none;'; ?>">
                                        <input type="text" name="cbr_heading_font_custom" value="<?php echo esc_attr( $heading_custom_val ); ?>"
                                               class="regular-text" placeholder="e.g. DM Serif Display" />
                                        <p class="description">Exact Google Fonts family name. Used only when <strong>Custom…</strong> is selected above.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>Body / Verse Font</th>
                                <td>
                                    <?php $render_font_select( 'cbr_body_font', $current_body, $body_is_custom ); ?>
                                    <div class="cbr-font-custom-wrap" id="cbr_body_font_custom_wrap" style="margin-top:8px; <?php echo $body_is_custom ? '' : 'display:none;'; ?>">
                                        <input type="text" name="cbr_body_font_custom" value="<?php echo esc_attr( $body_custom_val ); ?>"
                                               class="regular-text" placeholder="e.g. Inter" />
                                        <p class="description">Exact Google Fonts family name. Used only when <strong>Custom…</strong> is selected above.</p>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="cbr-save-bar">
                    <?php submit_button( 'Save Settings', 'primary large', 'submit', false ); ?>
                    <span class="description">Changes apply to every reader on this site.</span>
                </div>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            function syncModeRows(){ var m = $('#cbr_length_mode').val(); $('.cbr-mode-row').hide(); $('.cbr-mode-' + m).show(); }
            $('#cbr_length_mode').on('change', syncModeRows); syncModeRows();

            // Tabs (remembered across saves via hash)
            function showTab(t){
                $('.cbr-tab').removeClass('is-active').filter('[data-tab="'+t+'"]').addClass('is-active');
                $('.cbr-tab-panel').removeClass('is-active').filter('[data-panel="'+t+'"]').addClass('is-active');
                try { localStorage.setItem('cbr_settings_tab', t); } catch(e){}
            }
            $('.cbr-tab').on('click', function(e){ e.preventDefault(); showTab($(this).data('tab')); });
            var startTab = (location.hash || '').replace('#cbr-tab-','');
            if (!startTab) { try { startTab = localStorage.getItem('cbr_settings_tab') || 'general'; } catch(e){ startTab = 'general'; } }
            if ($('.cbr-tab[data-tab="'+startTab+'"]').length) showTab(startTab);

            var $preview = $('#cbr-preview');
            var selectedSwatch = null;

            function setColor($input, hex){
                $input.wpColorPicker('color', hex);
                $preview.css($input.data('var'), hex);
            }

            $('.cbr-color-picker').each(function(){
                var $inp = $(this);
                $inp.wpColorPicker({
                    change: function(e, ui){ $preview.css($inp.data('var'), ui.color.toString()); },
                    clear:  function(){ $preview.css($inp.data('var'), ''); }
                });
                // After picking a theme swatch, clicking a color field applies it
                $inp.closest('td').on('mousedown', '.wp-color-result, .wp-color-picker', function(e){
                    if (selectedSwatch) {
                        e.preventDefault();
                        setColor($inp, selectedSwatch);
                        selectedSwatch = null;
                        $('.cbr-swatch').removeClass('is-selected');
                        $('body').removeClass('cbr-swatch-armed');
                    }
                });
            });

            $('#cbr-theme-swatches').on('click', '.cbr-swatch', function(){
                var already = $(this).hasClass('is-selected');
                $('.cbr-swatch').removeClass('is-selected');
                if (already) { selectedSwatch = null; $('body').removeClass('cbr-swatch-armed'); return; }
                $(this).addClass('is-selected');
                selectedSwatch = $(this).data('color');
                $('body').addClass('cbr-swatch-armed');
            });

            // Fonts: auto-match + live preview
            function fontCss(val){
                if (val === '__inherit__') return 'inherit';
                if (val.indexOf('__theme__:') === 0) return 'var(--wp--preset--font-family--' + val.substring(10) + ')';
                if (val === '__custom__') return null;
                if (!document.getElementById('cbr-gf-' + val)) {
                    var l = document.createElement('link'); l.id = 'cbr-gf-' + val; l.rel = 'stylesheet';
                    l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(val) + ':wght@400;700&display=swap';
                    document.head.appendChild(l);
                }
                return "'" + val + "', serif";
            }
            function previewFonts(){
                var h = fontCss($('#cbr_heading_font').val()), b = fontCss($('#cbr_body_font').val());
                if (h) $preview.find('.cbr-preview-title').css('font-family', h);
                if (b) $preview.find('.cbr-preview-body p').css('font-family', b);
            }
            $('#cbr_heading_font, #cbr_body_font').on('change', previewFonts); previewFonts();

            $('#cbr-automatch-fonts').on('click', function(){
                var opts = $('#cbr_heading_font option[value^="__theme__:"]').map(function(){ return this.value; }).get();
                var h = opts[0] || '__inherit__', b = opts[1] || opts[0] || '__inherit__';
                $('#cbr_heading_font').val(h).trigger('change');
                $('#cbr_body_font').val(b).trigger('change');
            });

            // Preset → fill all five
            $('#cbr-preset').on('change', function(){
                var colors = $(this).find(':selected').data('colors');
                if (!colors) return;
                ['cbr_primary_color','cbr_secondary_color','cbr_accent_color','cbr_bg_color','cbr_text_color']
                    .forEach(function(id, i){ setColor($('#'+id), colors[i]); });
            });

            // Auto-match: theme palette slugs → reader slots, luminance fallbacks when slugs are generic
            $('#cbr-automatch').on('click', function(){
                var sw = $('#cbr-theme-swatches .cbr-swatch').map(function(){
                    return { slug: String($(this).data('slug')).toLowerCase(), color: String($(this).data('color')) };
                }).get();
                if (!sw.length) return;

                function lum(hex){
                    var m = /^#?([0-9a-f]{6})$/i.exec(hex); if (!m) return 0.5;
                    var n = parseInt(m[1],16), r = n>>16&255, g = n>>8&255, b = n&255;
                    return (0.299*r + 0.587*g + 0.114*b) / 255;
                }
                function bySlug(words){
                    for (var i=0;i<sw.length;i++) for (var j=0;j<words.length;j++)
                        if (sw[i].slug.indexOf(words[j]) > -1) return sw[i].color;
                    return null;
                }
                var byLight = sw.slice().sort(function(a,b){ return lum(b.color)-lum(a.color); });
                var byDark  = sw.slice().sort(function(a,b){ return lum(a.color)-lum(b.color); });
                var mids    = sw.filter(function(c){ var l = lum(c.color); return l > 0.12 && l < 0.72; });

                // Contrast helper: pick the palette color with the greatest luminance difference from `against`
                function farthest(against, exclude){
                    var best = null, bd = -1;
                    sw.forEach(function(c){ if (exclude.indexOf(c.color) > -1) return; var d = Math.abs(lum(c.color) - lum(against)); if (d > bd) { bd = d; best = c.color; } });
                    return best;
                }
                function contrast(a, b){ return Math.abs(lum(a) - lum(b)); }

                // Reader background should be the lightest palette color unless the theme is clearly dark.
                var themeBg   = bySlug(['background','base']) || byLight[0].color;
                var themeDark = lum(themeBg) < 0.4;
                var bg        = themeDark ? themeBg : byLight[0].color;
                var text      = farthest(bg, []) ;                                   // max contrast for body text
                var primary   = bySlug(['primary','brand','main']) || (mids[0] ? mids[0].color : null);
                if (!primary || contrast(primary, bg) < 0.3) primary = farthest(bg, [text]) || text;   // toolbar must stand out from bg
                var accent    = bySlug(['accent','highlight','tertiary']) || (mids[1] ? mids[1].color : null);
                if (!accent || contrast(accent, bg) < 0.2) accent = mids.filter(function(c){ return c.color !== primary; })[0] ? mids.filter(function(c){ return c.color !== primary; })[0].color : primary;
                var secondary = bySlug(['secondary']) || accent;

                setColor($('#cbr_primary_color'),   primary);
                setColor($('#cbr_secondary_color'), secondary);
                setColor($('#cbr_accent_color'),    accent);
                setColor($('#cbr_bg_color'),        bg);
                setColor($('#cbr_text_color'),      text);
            });

            // Show/hide the "Custom…" text input for font selects
            $('.cbr-font-select').on('change', function(){
                var $wrap = $('#' + $(this).data('target'));
                if ( $(this).val() === '__custom__' ) {
                    $wrap.show();
                } else {
                    $wrap.hide();
                }
            });
        });
        </script>
        <?php
    }

    /* ======================================================================
       Theme palette & presets
       ====================================================================== */

    /**
     * Colors the active theme publishes: theme.json palette (block themes) or
     * add_theme_support('editor-color-palette') (classic themes). [] if none.
     */
    private function get_theme_palette() {
        $out = [];

        if ( function_exists( 'wp_get_global_settings' ) ) {
            $settings = wp_get_global_settings();
            $groups   = $settings['color']['palette'] ?? [];
            foreach ( [ 'theme', 'custom' ] as $origin ) {
                foreach ( (array) ( $groups[ $origin ] ?? [] ) as $c ) {
                    if ( ! empty( $c['color'] ) ) {
                        $out[] = [ 'name' => $c['name'] ?? ( $c['slug'] ?? '' ), 'slug' => $c['slug'] ?? '', 'color' => $c['color'] ];
                    }
                }
            }
        }

        if ( empty( $out ) ) {
            $support = get_theme_support( 'editor-color-palette' );
            if ( is_array( $support ) && ! empty( $support[0] ) ) {
                foreach ( $support[0] as $c ) {
                    if ( ! empty( $c['color'] ) ) {
                        $out[] = [ 'name' => $c['name'] ?? '', 'slug' => $c['slug'] ?? '', 'color' => $c['color'] ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Font families the active theme publishes via theme.json (block themes).
     * Each: [ 'slug', 'name', 'family' (CSS font-family value) ]. [] if none.
     */
    public static function get_theme_fonts() {
        $out = [];
        if ( function_exists( 'wp_get_global_settings' ) ) {
            $settings = wp_get_global_settings();
            $groups   = $settings['typography']['fontFamilies'] ?? [];
            foreach ( [ 'theme', 'custom' ] as $origin ) {
                foreach ( (array) ( $groups[ $origin ] ?? [] ) as $f ) {
                    if ( empty( $f['slug'] ) || empty( $f['fontFamily'] ) ) continue;
                    $out[] = [
                        'slug'   => sanitize_key( $f['slug'] ),
                        'name'   => $f['name'] ?? $f['slug'],
                        'family' => preg_replace( '/[^A-Za-z0-9 ,\'"\-]/', '', $f['fontFamily'] ),
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Ready-made palettes. Order: primary, secondary, accent, bg, text.
     */
    public static function presets() {
        return [
            'forest'   => [ 'name' => 'Forest (default)', 'colors' => [ '#2c5f2d', '#97bc62', '#d4a574', '#faf8f5', '#2d2926' ] ],
            'slate'    => [ 'name' => 'Slate Neutral',    'colors' => [ '#3f4a56', '#8a97a5', '#b08d57', '#f7f7f5', '#2b2f33' ] ],
            'navy'     => [ 'name' => 'Navy & Gold',      'colors' => [ '#1a2535', '#5b7ba6', '#c49840', '#f5f3ee', '#1f2733' ] ],
            'burgundy' => [ 'name' => 'Burgundy',         'colors' => [ '#6b1f2a', '#b56b74', '#c9a86a', '#faf6f3', '#2f2224' ] ],
            'royal'    => [ 'name' => 'Royal Purple',     'colors' => [ '#4a2c6b', '#9b7fc1', '#d4b46a', '#f8f6fb', '#2a2333' ] ],
            'ocean'    => [ 'name' => 'Ocean',            'colors' => [ '#1f5f7a', '#6fb0c8', '#e0a458', '#f4f8fa', '#1f2a30' ] ],
            'charcoal' => [ 'name' => 'Charcoal Modern',  'colors' => [ '#222222', '#888888', '#e06c3c', '#ffffff', '#1a1a1a' ] ],
            'warm'     => [ 'name' => 'Warm Sand',        'colors' => [ '#8a5a2b', '#c9a27a', '#7a9e7e', '#fbf7f0', '#3a2f25' ] ],
        ];
    }

    /* ======================================================================
       Manage Data
       ====================================================================== */
    public function render_import() {
        $verse_count   = CBR_Database::get_verse_count();
        $version_count = CBR_Database::get_version_count();
        $data_exists   = file_exists( CBR_PLUGIN_DIR . 'data/verses.tsv.gz' );
        ?>
        <div class="wrap cbr-admin-wrap cbr-settings">
            <div class="cbr-page-head">
                <h1>Bible Reader <span>Manage Data</span></h1>
                <p class="cbr-page-sub">Verse data status, re-import, and repair tools.</p>
            </div>

            <?php if ( isset( $_GET['imported'] ) ) : ?>
            <div class="notice notice-success"><p>Successfully imported <strong><?php echo number_format( intval( $_GET['imported'] ) ); ?></strong> verses!</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['repaired'] ) ) : ?>
            <div class="notice notice-success"><p>Verse data repaired. Chapters now show the correct scripture.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['reset'] ) ) : ?>
            <div class="notice notice-warning"><p>All Bible data has been cleared.</p></div>
            <?php endif; ?>

            <?php $prog = CBR_Importer::progress(); if ( $prog ) : ?>
            <div class="cbr-card" style="max-width:700px; border-color:#2271b1;">
                <h3>Importing Bible data&hellip;</h3>
                <div class="cbr-progress"><div class="cbr-progress-bar" id="cbr-progress-bar" style="width:<?php echo (int) $prog['percent']; ?>%"></div></div>
                <p id="cbr-progress-text"><?php echo number_format( $prog['inserted'] ); ?> of <?php echo number_format( $prog['total'] ); ?> verses (<?php echo (int) $prog['percent']; ?>%)</p>
                <p class="description">Keep this page open and it will finish in a minute or two. If you leave, it continues in the background.</p>
            </div>
            <script>
            jQuery(function($){
                function step(){
                    $.post(ajaxurl, { action: 'cbr_import_progress', nonce: '<?php echo wp_create_nonce( 'cbr_nonce' ); ?>' }, function(res){
                        if (!res.success) return;
                        var p = res.data.progress;
                        if (!p) { window.location = '<?php echo admin_url( 'admin.php?page=cbr-import&imported=' ); ?>' + res.data.verses; return; }
                        $('#cbr-progress-bar').css('width', p.percent + '%');
                        $('#cbr-progress-text').text(p.inserted.toLocaleString() + ' of ' + p.total.toLocaleString() + ' verses (' + p.percent + '%)');
                        step();
                    });
                }
                step();
            });
            </script>
            <?php endif; ?>

            <div class="cbr-card" style="max-width:700px;">
                <h3>Current Data</h3>
                <table class="widefat" style="max-width:400px;">
                    <tr><td><strong>Verses</strong></td><td><?php echo number_format( $verse_count ); ?></td></tr>
                    <tr><td><strong>Translations</strong></td><td><?php echo $version_count; ?></td></tr>
                    <tr><td><strong>Bundled data file</strong></td><td><?php echo $data_exists ? '✅ Present (verses.tsv.gz)' : '❌ Missing'; ?></td></tr>
                    <tr><td><strong>Data check</strong></td><td><?php echo CBR_Database::verses_are_swapped() ? '❌ Book/verse columns reversed — click Repair below' : '✅ Verses map to the correct books'; ?></td></tr>
                </table>
                <?php if ( CBR_Database::verses_are_swapped() ) : ?>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="margin-top:12px;">
                    <?php wp_nonce_field( 'cbr_repair_data', 'cbr_repair_nonce' ); ?>
                    <input type="hidden" name="action" value="cbr_repair_data" />
                    <?php submit_button( 'Repair Verse Data Now', 'primary', 'submit', false ); ?>
                </form>
                <?php endif; ?>
            </div>

            <!-- Re-import bundled -->
            <?php if ( $data_exists ) : ?>
            <div class="cbr-card" style="max-width:700px; margin-top:20px;">
                <h3>Re-import Bundled Data</h3>
                <p>Clear the database and re-import the 185,000+ verses that ship with the plugin.</p>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <?php wp_nonce_field( 'cbr_reimport_bundled', 'cbr_reimport_nonce' ); ?>
                    <input type="hidden" name="action" value="cbr_reimport_bundled" />
                    <?php submit_button( 'Re-import Bundled Data', 'primary', 'submit', false ); ?>
                </form>
            </div>
            <?php endif; ?>

            <!-- Manual SQL upload -->
            <div class="cbr-card" style="max-width:700px; margin-top:20px;">
                <h3>Upload Custom SQL File</h3>
                <p>Upload a <code>.sql</code> or <code>.sql.gz</code> file to add or replace data.</p>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'cbr_import_sql', 'cbr_import_nonce' ); ?>
                    <input type="hidden" name="action" value="cbr_import_sql" />
                    <p><input type="file" name="cbr_sql_file" accept=".sql,.gz" required /></p>
                    <p><label><input type="checkbox" name="cbr_clear_first" value="1" /> Clear existing data before importing</label></p>
                    <?php submit_button( 'Import SQL File', 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <!-- Reset -->
            <div class="cbr-card" style="max-width:700px; margin-top:20px;">
                <h3>Reset All Data</h3>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <?php wp_nonce_field( 'cbr_reset_data', 'cbr_reset_nonce' ); ?>
                    <input type="hidden" name="action" value="cbr_reset_data" />
                    <button type="submit" class="button button-link-delete" onclick="return confirm('Are you sure? This will delete ALL imported Bible data. You can re-import afterwards.');">
                        Delete All Verse Data
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /* ======================================================================
       Handlers
       ====================================================================== */

    public function handle_reimport() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'cbr_reimport_bundled', 'cbr_reimport_nonce' );

        update_option( 'cbr_data_version', 2 );
        $r = CBR_Importer::start_bundled_import();
        if ( is_wp_error( $r ) ) {
            wp_redirect( admin_url( 'admin.php?page=cbr-import&error=' . urlencode( $r->get_error_message() ) ) );
            exit;
        }
        CBR_Importer::run_until( 20 );
        // Manage Data page shows a progress bar and drives the rest via AJAX
        wp_redirect( admin_url( 'admin.php?page=cbr-import' . ( CBR_Importer::is_pending() ? '&importing=1' : '&imported=' . intval( get_option( 'cbr_activation_imported', CBR_Importer::TOTAL_LINES ) ) ) ) );
        exit;
    }

    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'cbr_import_sql', 'cbr_import_nonce' );

        if ( empty( $_FILES['cbr_sql_file']['tmp_name'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=cbr-import&error=' . urlencode( 'No file uploaded.' ) ) );
            exit;
        }

        if ( ! empty( $_POST['cbr_clear_first'] ) ) {
            CBR_Database::truncate_verses();
        }

        $result = CBR_Importer::import_sql_file( $_FILES['cbr_sql_file']['tmp_name'] );

        if ( is_wp_error( $result ) ) {
            wp_redirect( admin_url( 'admin.php?page=cbr-import&error=' . urlencode( $result->get_error_message() ) ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=cbr-import&imported=' . intval( $result ) ) );
        }
        exit;
    }

    public function handle_repair() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'cbr_repair_data', 'cbr_repair_nonce' );
        $ok = CBR_Database::repair_swapped_verses();
        update_option( 'cbr_data_version', 2 );
        wp_redirect( admin_url( 'admin.php?page=cbr-import&' . ( $ok ? 'repaired=1' : 'error=' . urlencode( 'Repair did not complete. Use Re-import Bundled Data.' ) ) ) );
        exit;
    }

    public function handle_reset() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'cbr_reset_data', 'cbr_reset_nonce' );

        CBR_Database::truncate_verses();

        wp_redirect( admin_url( 'admin.php?page=cbr-import&reset=1' ) );
        exit;
    }
}
