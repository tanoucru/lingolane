<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lingolane Language Switcher: shortcode, PHP helper, widget, nav menu support,
 * customizable flag/text layouts, and graceful untranslated notice modal.
 */
class Lingolane_Switcher {
    /** True once the switcher assets have been enqueued/printed. */
    private static $assets_printed = false;

    /** True once the dialog markup has been queued/printed. */
    private static $dialog_printed = false;

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_shortcode( 'lingolane_switcher', array( __CLASS__, 'shortcode' ) );
        add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'menu_items' ), 10, 2 );
        add_filter( 'nav_menu_link_attributes', array( __CLASS__, 'menu_attributes' ), 10, 2 );
        add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'menu_locations' ) );
        add_action( 'wp_footer', array( __CLASS__, 'print_dialog' ) );
    }

    public static function register_assets() {
        wp_register_style( 'lingolane-switcher', LINGOLANE_URL . 'assets/switcher.css', array(), LINGOLANE_VERSION );
        wp_register_script( 'lingolane-switcher', LINGOLANE_URL . 'assets/switcher.js', array(), LINGOLANE_VERSION, true );
    }

    /**
     * Print CSS and JS once, only on pages that render the switcher.
     */
    public static function assets() {
        if ( wp_style_is( 'lingolane-switcher', 'registered' ) ) {
            wp_enqueue_style( 'lingolane-switcher' );
        }
        if ( wp_script_is( 'lingolane-switcher', 'registered' ) ) {
            wp_enqueue_script( 'lingolane-switcher' );
        }
        if ( self::$assets_printed ) { return ''; }
        self::$assets_printed = true;

        $css = file_get_contents( LINGOLANE_DIR . 'assets/switcher.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local bundled asset.
        $js  = file_get_contents( LINGOLANE_DIR . 'assets/switcher.js' );  // phpcs:ignore WordPress.WP.AlternativeFunctions -- local bundled asset.

        $out = '';
        if ( $css ) {
            $out .= '<style id="lingolane-switcher-css">' . wp_strip_all_tags( $css ) . '</style>';
        }
        if ( $js ) {
            $out .= '<script id="lingolane-switcher-js">' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- local bundled JS.
        }
        return $out;
    }

    public static function register_widget() {
        register_widget( 'Lingolane_Switcher_Widget' );
    }

    /**
     * Backward-compatible language query.
     */
    public static function languages() {
        return array_filter( Lingolane_Languages::all(), function ( $language ) {
            return false !== Lingolane_Urls::published_url( $language['code'] );
        } );
    }

    private static function attributes( $code ) {
        return ' hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . ( $code === Lingolane_Languages::current() ? ' aria-current="true"' : '' );
    }

    /**
     * Main switcher rendering engine.
     *
     * @param array $args Options:
     *                    - style: 'list'|'dropdown'|'pills'|'select'
     *                    - show: 'flag_name'|'flag_code'|'flag_only'|'name'|'code'
     *                    - flags: bool
     *                    - shape: 'rect'|'round'|'square'
     *                    - class: extra CSS class
     *                    - echo: bool (default true when called via lingolane_switcher)
     * @return string Switcher HTML.
     */
    public static function render( $args = array() ) {
        $settings = get_option( 'lingolane_settings', array() );

        // Defaults from database or sensible presets.
        $default_style  = isset( $settings['switcher_style'] ) ? $settings['switcher_style'] : 'list';
        $default_show   = isset( $settings['switcher_show'] ) ? $settings['switcher_show'] : 'flag_name';
        $default_shape  = isset( $settings['switcher_flag_shape'] ) ? $settings['switcher_flag_shape'] : 'rect';
        $untrans_action = isset( $settings['untranslated_action'] ) ? $settings['untranslated_action'] : 'notice';
        $untrans_notice = ! empty( $settings['untranslated_notice'] ) ? $settings['untranslated_notice'] : __( 'Ngôn ngữ bạn chọn hiện chưa có bản dịch', 'lingolane' );
        $untrans_url    = ! empty( $settings['untranslated_redirect_url'] ) ? $settings['untranslated_redirect_url'] : '';

        $style = isset( $args['style'] ) ? sanitize_key( $args['style'] ) : $default_style;
        if ( ! in_array( $style, array( 'list', 'dropdown', 'pills', 'select' ), true ) ) {
            $style = 'list';
        }

        $show = isset( $args['show'] ) ? sanitize_key( $args['show'] ) : $default_show;
        if ( ! in_array( $show, array( 'flag_name', 'flag_code', 'flag_only', 'name', 'code' ), true ) ) {
            $show = 'flag_name';
        }

        // Support explicit flags=false / flags=0 parameter.
        if ( isset( $args['flags'] ) && ! $args['flags'] && false !== strpos( $show, 'flag' ) ) {
            $show = ( 'flag_code' === $show ) ? 'code' : 'name';
        }

        $shape = isset( $args['shape'] ) ? sanitize_key( $args['shape'] ) : $default_shape;
        if ( ! in_array( $shape, array( 'rect', 'round', 'square' ), true ) ) {
            $shape = 'rect';
        }

        $custom_class = isset( $args['class'] ) ? sanitize_html_class( $args['class'] ) : '';

        $all_languages = Lingolane_Languages::all();
        $current_code  = Lingolane_Languages::current();
        $current_lang  = null;

        $items = array();
        foreach ( $all_languages as $lang ) {
            $code      = $lang['code'];
            $is_cur    = ( $code === $current_code );
            $has_trans = Lingolane_Urls::has_translation( $code );
            $trans_url = lingolane_get_translation_url( $code );

            if ( $is_cur ) {
                $current_lang = $lang;
            }

            // Fallback action attributes if untranslated.
            $data_attrs = '';
            if ( ! $has_trans && ! $is_cur ) {
                $data_attrs .= ' data-lingolane-missing="1"';
                $data_attrs .= ' data-action="' . esc_attr( $untrans_action ) . '"';
                $data_attrs .= ' data-lang-name="' . esc_attr( $lang['name'] ) . '"';
                $data_attrs .= ' data-message="' . esc_attr( $untrans_notice ) . '"';
                if ( 'redirect' === $untrans_action && $untrans_url ) {
                    $data_attrs .= ' data-redirect="' . esc_url( $untrans_url ) . '"';
                }
            }

            // Build item inner HTML based on $show.
            $flag_svg   = class_exists( 'Lingolane_Flags' ) ? Lingolane_Flags::get_flag_svg( $code, 20, 15, 'lingolane-flag--' . $shape ) : '';
            $inner_html = '';

            switch ( $show ) {
                case 'flag_only':
                    $inner_html = '<span class="lingolane-flag-wrap" title="' . esc_attr( $lang['name'] ) . '">' . $flag_svg . '</span>';
                    break;
                case 'flag_code':
                    $inner_html = '<span class="lingolane-flag-wrap">' . $flag_svg . '</span><span class="lingolane-code-label">' . esc_html( strtoupper( $code ) ) . '</span>';
                    break;
                case 'code':
                    $inner_html = '<span class="lingolane-code-label">' . esc_html( strtoupper( $code ) ) . '</span>';
                    break;
                case 'name':
                    $inner_html = '<span class="lingolane-name">' . esc_html( $lang['name'] ) . '</span>';
                    break;
                case 'flag_name':
                default:
                    $inner_html = '<span class="lingolane-flag-wrap">' . $flag_svg . '</span><span class="lingolane-name">' . esc_html( $lang['name'] ) . '</span>';
                    break;
            }

            $items[] = array(
                'code'       => $code,
                'name'       => $lang['name'],
                'url'        => $trans_url,
                'is_current' => $is_cur,
                'has_trans'  => $has_trans,
                'data_attrs' => $data_attrs,
                'inner_html' => $inner_html,
            );
        }

        if ( ! $current_lang && $items ) {
            $current_lang = $all_languages[0];
        }

        $wrapper_classes = 'lingolane-switcher lingolane-switcher--' . esc_attr( $style );
        if ( $custom_class ) {
            $wrapper_classes .= ' ' . $custom_class;
        }

        $html = self::assets();

        // 1. SELECT STYLE
        if ( 'select' === $style ) {
            $html .= '<div class="' . esc_attr( $wrapper_classes ) . '">';
            $html .= '<select class="lingolane-switcher__select" onchange="if(this.value){window.location.href=this.value;}" aria-label="' . esc_attr__( 'Select language', 'lingolane' ) . '">';
            foreach ( $items as $it ) {
                $html .= '<option value="' . esc_url( $it['url'] ) . '"' . ( $it['is_current'] ? ' selected' : '' ) . '>';
                $html .= esc_html( 'code' === $show ? strtoupper( $it['code'] ) : $it['name'] );
                $html .= '</option>';
            }
            $html .= '</select></div>';
            return $html;
        }

        // 2. DROPDOWN STYLE
        if ( 'dropdown' === $style ) {
            $current_flag = class_exists( 'Lingolane_Flags' ) ? Lingolane_Flags::get_flag_svg( $current_code, 20, 15, 'lingolane-flag--' . $shape ) : '';
            $current_label = ( 'code' === $show || 'flag_code' === $show ) ? strtoupper( $current_code ) : ( $current_lang ? $current_lang['name'] : '' );

            $toggle_content = '';
            if ( false !== strpos( $show, 'flag' ) ) {
                $toggle_content .= '<span class="lingolane-flag-wrap">' . $current_flag . '</span>';
            }
            if ( 'flag_only' !== $show ) {
                $toggle_content .= '<span class="lingolane-switcher__label">' . esc_html( $current_label ) . '</span>';
            }

            $html .= '<div class="' . esc_attr( $wrapper_classes ) . '" data-style="dropdown">';
            $html .= '<button type="button" class="lingolane-switcher__toggle" aria-expanded="false" aria-haspopup="true" aria-label="' . esc_attr__( 'Select language', 'lingolane' ) . '">';
            $html .= $toggle_content;
            $html .= '<svg class="lingolane-switcher__chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>';
            $html .= '</button>';

            $html .= '<ul class="lingolane-switcher__menu" role="menu">';
            foreach ( $items as $it ) {
                $link_classes = 'lingolane-switcher__link' . ( $it['is_current'] ? ' is-current' : '' ) . ( ! $it['has_trans'] ? ' lingolane-switcher__link--missing' : '' );
                $html .= '<li role="none">';
                $html .= '<a href="' . esc_url( $it['url'] ) . '" class="' . esc_attr( $link_classes ) . '"' . self::attributes( $it['code'] ) . $it['data_attrs'] . ' role="menuitem">';
                $html .= $it['inner_html'];
                $html .= '</a></li>';
            }
            $html .= '</ul></div>';
            return $html;
        }

        // 3. LIST & PILLS STYLES
        $html .= '<nav class="' . esc_attr( $wrapper_classes ) . '" aria-label="' . esc_attr__( 'Languages', 'lingolane' ) . '">';
        $html .= '<ul class="lingolane-switcher__items">';
        foreach ( $items as $it ) {
            $link_classes = 'lingolane-switcher__link' . ( $it['is_current'] ? ' is-current' : '' ) . ( ! $it['has_trans'] ? ' lingolane-switcher__link--missing' : '' );
            $html .= '<li class="lingolane-switcher__item">';
            $html .= '<a href="' . esc_url( $it['url'] ) . '" class="' . esc_attr( $link_classes ) . '"' . self::attributes( $it['code'] ) . $it['data_attrs'] . '>';
            $html .= $it['inner_html'];
            $html .= '</a></li>';
        }
        $html .= '</ul></nav>';

        return $html;
    }

    /**
     * Shortcode handler: [lingolane_switcher style="dropdown" show="flag_name"]
     */
    public static function shortcode( $atts = array() ) {
        $atts = is_array( $atts ) ? $atts : array();
        return self::render( $atts );
    }

    /**
     * Render untranslated notice modal in footer.
     */
    public static function print_dialog() {
        if ( self::$dialog_printed ) { return; }
        self::$dialog_printed = true;

        $settings = get_option( 'lingolane_settings', array() );
        $notice   = ! empty( $settings['untranslated_notice'] ) ? $settings['untranslated_notice'] : __( 'Ngôn ngữ bạn chọn hiện chưa có bản dịch', 'lingolane' );
        ?>
        <div id="lingolane-notice-dialog" class="lingolane-dialog" role="dialog" aria-modal="true" aria-labelledby="lingolane-dialog-title" hidden>
            <div class="lingolane-dialog__panel">
                <button type="button" class="lingolane-dialog__close" aria-label="<?php esc_attr_e( 'Close', 'lingolane' ); ?>">&times;</button>
                <div class="lingolane-dialog__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                </div>
                <h3 id="lingolane-dialog-title" class="lingolane-dialog__title"><?php esc_html_e( 'Thông báo', 'lingolane' ); ?></h3>
                <p id="lingolane-dialog-desc" class="lingolane-dialog__desc"><?php echo esc_html( $notice ); ?></p>
                <button type="button" class="lingolane-dialog__btn"><?php esc_html_e( 'Đã hiểu', 'lingolane' ); ?></button>
            </div>
        </div>
        <?php
    }

    public static function menu_items( $items, $args ) {
        $result = array();
        $next_id = -1;
        foreach ( $items as $existing ) { $next_id = min( $next_id, (int) $existing->ID - 1 ); }
        foreach ( $items as $item ) {
            if ( ! in_array( $item->url, array( '#lingolane-switcher', '#tml-switcher' ), true ) || ! in_array( $item->title, array( '{{lingolane}}', '{{tml}}' ), true ) ) {
                $result[] = $item;
                continue;
            }
            foreach ( Lingolane_Languages::all() as $language ) {
                $copy = clone $item;
                $copy->ID = $next_id--;
                $copy->db_id = $copy->ID;
                $copy->object_id = $copy->ID;
                $copy->title = $language['name'];
                $copy->url = lingolane_get_translation_url( $language['code'] );
                $copy->lingolane_language = $language['code'];
                $copy->current = $language['code'] === Lingolane_Languages::current();
                $copy->classes = array( 'menu-item', 'lingolane-language' );
                if ( $copy->current ) { $copy->classes[] = 'current-menu-item'; }
                $result[] = $copy;
            }
        }
        return $result;
    }

    public static function menu_attributes( $atts, $item ) {
        if ( isset( $item->lingolane_language ) ) {
            $atts['hreflang'] = $item->lingolane_language;
            $atts['lang'] = $item->lingolane_language;
            unset( $atts['aria-current'] );
            if ( $item->current ) { $atts['aria-current'] = 'true'; }
        }
        return $atts;
    }

    public static function menu_locations( $locations ) {
        if ( is_admin() || ! is_array( $locations ) ) { return $locations; }
        foreach ( get_registered_nav_menus() as $location => $label ) {
            $menu = wp_get_nav_menu_object( $location . ' ' . Lingolane_Languages::current() );
            if ( $menu && ! is_wp_error( $menu ) ) { $locations[ $location ] = (int) $menu->term_id; }
        }
        return $locations;
    }
}

/**
 * Global helper function to output or return the language switcher anywhere in templates/code:
 * <?php if ( function_exists( 'lingolane_switcher' ) ) { lingolane_switcher(); } ?>
 *
 * @param array $args Options array.
 * @return string|void
 */
function lingolane_switcher( $args = array() ) {
    $echo = ! isset( $args['echo'] ) || (bool) $args['echo'];
    $html = Lingolane_Switcher::render( $args );
    if ( $echo ) {
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        return $html;
    }
}

function lingolane_the_switcher( $args = array() ) {
    $args['echo'] = true;
    lingolane_switcher( $args );
}

function lingolane_get_switcher( $args = array() ) {
    $args['echo'] = false;
    return lingolane_switcher( $args );
}

class Lingolane_Switcher_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'lingolane_switcher',
            __( 'Select language', 'lingolane' ),
            array( 'description' => __( 'Display language switcher with flags and customizable styles.', 'lingolane' ) )
        );
    }

    public function widget( $args, $instance ) {
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( ! empty( $instance['title'] ) ) {
            echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        $switcher_args = array(
            'style' => ! empty( $instance['style'] ) ? $instance['style'] : 'list',
            'show'  => ! empty( $instance['show'] ) ? $instance['show'] : 'flag_name',
        );
        echo Lingolane_Switcher::render( $switcher_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : '';
        $style = isset( $instance['style'] ) ? $instance['style'] : 'list';
        $show  = isset( $instance['show'] ) ? $instance['show'] : 'flag_name';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'lingolane' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Style:', 'lingolane' ); ?></label>
            <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
                <option value="list" <?php selected( $style, 'list' ); ?>><?php esc_html_e( 'List', 'lingolane' ); ?></option>
                <option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'lingolane' ); ?></option>
                <option value="pills" <?php selected( $style, 'pills' ); ?>><?php esc_html_e( 'Pills', 'lingolane' ); ?></option>
                <option value="select" <?php selected( $style, 'select' ); ?>><?php esc_html_e( 'Select', 'lingolane' ); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>"><?php esc_html_e( 'Show:', 'lingolane' ); ?></label>
            <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show' ) ); ?>">
                <option value="flag_name" <?php selected( $show, 'flag_name' ); ?>><?php esc_html_e( 'Flag & Name', 'lingolane' ); ?></option>
                <option value="flag_code" <?php selected( $show, 'flag_code' ); ?>><?php esc_html_e( 'Flag & Code', 'lingolane' ); ?></option>
                <option value="flag_only" <?php selected( $show, 'flag_only' ); ?>><?php esc_html_e( 'Flag only', 'lingolane' ); ?></option>
                <option value="name" <?php selected( $show, 'name' ); ?>><?php esc_html_e( 'Name only', 'lingolane' ); ?></option>
                <option value="code" <?php selected( $show, 'code' ); ?>><?php esc_html_e( 'Code only', 'lingolane' ); ?></option>
            </select>
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance = array();
        $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
        $instance['style'] = ( ! empty( $new_instance['style'] ) ) ? sanitize_key( $new_instance['style'] ) : 'list';
        $instance['show']  = ( ! empty( $new_instance['show'] ) ) ? sanitize_key( $new_instance['show'] ) : 'flag_name';
        return $instance;
    }
}
