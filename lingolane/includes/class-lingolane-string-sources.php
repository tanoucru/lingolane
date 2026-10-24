<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_String_Sources {

    public static function init() {
        add_filter( 'option_blogname', array( __CLASS__, 'filter_blogname' ), 20 );
        add_filter( 'option_blogdescription', array( __CLASS__, 'filter_blogdescription' ), 20 );
        add_filter( 'get_post_metadata', array( __CLASS__, 'filter_post_metadata' ), 20, 4 );
        add_filter( 'wp_get_attachment_caption', array( __CLASS__, 'filter_attachment_caption' ), 20, 2 );
        add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_nav_menu_items' ), 20, 3 );
    }

    public static function filter_blogname( $value ) {
        if ( is_admin() ) { return $value; }
        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) { return $value; }
        $trans = Lingolane_Strings::get( 'lingolane-site', $value, 'blogname', $current );
        return ( '' !== $trans ) ? $trans : $value;
    }

    public static function filter_blogdescription( $value ) {
        if ( is_admin() ) { return $value; }
        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) { return $value; }
        $trans = Lingolane_Strings::get( 'lingolane-site', $value, 'blogdescription', $current );
        return ( '' !== $trans ) ? $trans : $value;
    }

    public static function filter_post_metadata( $value, $object_id, $meta_key, $single ) {
        if ( '_wp_attachment_image_alt' !== $meta_key || is_admin() ) {
            return $value;
        }
        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) {
            return $value;
        }

        remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_post_metadata' ), 20 );
        $raw = get_post_meta( $object_id, '_wp_attachment_image_alt', true );
        add_filter( 'get_post_metadata', array( __CLASS__, 'filter_post_metadata' ), 20, 4 );

        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return $value;
        }

        $trans = Lingolane_Strings::get( 'lingolane-media', $raw, 'alt', $current );
        if ( '' !== $trans ) {
            return $single ? $trans : array( $trans );
        }
        return $value;
    }

    public static function filter_attachment_caption( $caption, $post_id ) {
        if ( is_admin() || ! is_string( $caption ) || '' === trim( $caption ) ) {
            return $caption;
        }
        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) {
            return $caption;
        }
        $trans = Lingolane_Strings::get( 'lingolane-media', $caption, 'caption', $current );
        return ( '' !== $trans ) ? $trans : $caption;
    }

    public static function filter_nav_menu_items( $items, $menu, $args ) {
        if ( is_admin() || empty( $items ) || ! is_array( $items ) ) {
            return $items;
        }
        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) {
            return $items;
        }

        foreach ( $items as $item ) {
            if ( isset( $item->title ) && is_string( $item->title ) ) {
                $trans_title = Lingolane_Strings::get( 'lingolane-menu', $item->title, 'label', $current );
                if ( '' !== $trans_title ) {
                    $item->title = $trans_title;
                }
            }

            if ( isset( $item->type, $item->url ) && 'custom' === $item->type && is_string( $item->url ) ) {
                $url = trim( $item->url );
                if ( '' !== $url && '#' !== $url[0] && ! preg_match( '/^(?:mailto|tel|javascript):/i', $url ) ) {
                    $root = rtrim( get_option( 'home' ), '/' );
                    if ( preg_match( '~^(?:https?:)?//~i', $url ) ) {
                        if ( strpos( $url, $root . '/' ) === 0 || $url === $root ) {
                            $item->url = Lingolane_Urls::for_language( $url, $current );
                        }
                    } elseif ( '/' === $url[0] ) {
                        $stripped = Lingolane_Urls::strip_slug( $url );
                        $item->url = home_url( '/' . $current . ( '/' === substr( $stripped, 0, 1 ) ? $stripped : '/' . $stripped ) );
                    }
                }
            }
        }

        return $items;
    }

    public static function menu_is_language_specific( $menu_id, $language ) {
        $term = get_term( $menu_id, 'nav_menu' );
        if ( ! $term || is_wp_error( $term ) ) {
            return false;
        }
        $lang = strtolower( (string) $language );
        if ( preg_match( '/[-_]' . preg_quote( $lang, '/' ) . '$/i', $term->slug ) ) {
            return true;
        }
        if ( preg_match( '/(?:^|[\s\(])' . preg_quote( $lang, '/' ) . '\)?$/i', $term->name ) ) {
            return true;
        }
        return false;
    }

    public static function resolve( $provider, $object_id, $field ) {
        if ( ! in_array( $provider, array( 'site', 'menu', 'media' ), true ) ) {
            return new WP_Error( 'invalid_provider', __( 'Unknown string source provider.', 'lingolane' ) );
        }

        if ( 'site' === $provider ) {
            if ( ! in_array( $field, array( 'blogname', 'blogdescription' ), true ) ) {
                return new WP_Error( 'invalid_field', __( 'Field is not supported for site options.', 'lingolane' ) );
            }
            global $wpdb;
            $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $field ) );
            return array(
                'domain'  => 'lingolane-site',
                'source'  => (string) $raw,
                'context' => $field,
            );
        }

        if ( 'menu' === $provider ) {
            $post = get_post( $object_id );
            if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
                return new WP_Error( 'invalid_object', __( 'Menu item not found.', 'lingolane' ) );
            }
            return array(
                'domain'  => 'lingolane-menu',
                'source'  => (string) $post->post_title,
                'context' => 'label',
            );
        }

        if ( 'media' === $provider ) {
            $post = get_post( $object_id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                return new WP_Error( 'invalid_object', __( 'Attachment not found.', 'lingolane' ) );
            }
            if ( 'alt' === $field ) {
                $raw = get_post_meta( $object_id, '_wp_attachment_image_alt', true );
                return array(
                    'domain'  => 'lingolane-media',
                    'source'  => (string) $raw,
                    'context' => 'alt',
                );
            }
            if ( 'caption' === $field ) {
                return array(
                    'domain'  => 'lingolane-media',
                    'source'  => (string) $post->post_excerpt,
                    'context' => 'caption',
                );
            }
            return new WP_Error( 'invalid_field', __( 'Field is not supported for media.', 'lingolane' ) );
        }

        return new WP_Error( 'invalid_provider', __( 'Provider not supported.', 'lingolane' ) );
    }

    public static function enumerate( $provider, $offset = 0, $limit = 50 ) {
        if ( ! in_array( $provider, array( 'site', 'menu', 'media' ), true ) ) {
            return new WP_Error( 'invalid_provider', __( 'Unknown string source provider.', 'lingolane' ) );
        }

        $items = array();

        if ( 'site' === $provider ) {
            global $wpdb;
            $options = array( 'blogname', 'blogdescription' );
            foreach ( $options as $opt ) {
                $val = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $opt ) );
                if ( '' !== trim( $val ) ) {
                    $items[] = array(
                        'identity'   => array(
                            'domain'  => 'lingolane-site',
                            'source'  => $val,
                            'context' => $opt,
                        ),
                        'occurrence' => array(
                            'group_key'   => 'site',
                            'subgroup'    => 'header',
                            'provider'    => 'site',
                            'object_id'   => 0,
                            'field'       => $opt,
                            'page_path'   => '/',
                            'locator'     => "option:{$opt}",
                            'supported'   => 1,
                        ),
                    );
                }
            }
            return array( 'items' => $items, 'next' => null );
        }

        if ( 'menu' === $provider ) {
            $menus = wp_get_nav_menus();
            $all_items = array();
            foreach ( (array) $menus as $menu ) {
                $menu_items = wp_get_nav_menu_items( $menu->term_id );
                foreach ( (array) $menu_items as $mi ) {
                    if ( empty( $mi->title ) ) {
                        continue;
                    }
                    $all_items[] = array(
                        'identity'   => array(
                            'domain'  => 'lingolane-menu',
                            'source'  => (string) $mi->title,
                            'context' => 'label',
                        ),
                        'occurrence' => array(
                            'group_key'   => 'menu',
                            'subgroup'    => (string) $menu->slug,
                            'provider'    => 'menu',
                            'object_id'   => (int) $mi->ID,
                            'field'       => 'title',
                            'page_path'   => '',
                            'locator'     => "menu:{$menu->slug}:item:{$mi->ID}",
                            'supported'   => 1,
                        ),
                    );
                }
            }
            $slice = array_slice( $all_items, $offset, $limit );
            $next = ( $offset + count( $slice ) < count( $all_items ) ) ? $offset + count( $slice ) : null;
            return array( 'items' => $slice, 'next' => $next );
        }

        if ( 'media' === $provider ) {
            $query = new WP_Query( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'fields'         => 'ids',
            ) );
            $custom_logo = (int) get_theme_mod( 'custom_logo' );
            foreach ( $query->posts as $post_id ) {
                $alt = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
                $caption = get_post_field( 'post_excerpt', $post_id );
                $is_logo = ( $post_id === $custom_logo );

                if ( is_string( $alt ) && '' !== trim( $alt ) ) {
                    $items[] = array(
                        'identity'   => array(
                            'domain'  => 'lingolane-media',
                            'source'  => $alt,
                            'context' => 'alt',
                        ),
                        'occurrence' => array(
                            'group_key'   => $is_logo ? 'site' : 'media',
                            'subgroup'    => $is_logo ? 'header' : '',
                            'provider'    => 'media',
                            'object_id'   => $post_id,
                            'field'       => 'alt',
                            'page_path'   => '',
                            'locator'     => $is_logo ? 'site-logo' : "attachment:{$post_id}:alt",
                            'supported'   => 1,
                        ),
                    );
                }

                if ( is_string( $caption ) && '' !== trim( $caption ) ) {
                    $items[] = array(
                        'identity'   => array(
                            'domain'  => 'lingolane-media',
                            'source'  => $caption,
                            'context' => 'caption',
                        ),
                        'occurrence' => array(
                            'group_key'   => 'media',
                            'subgroup'    => '',
                            'provider'    => 'media',
                            'object_id'   => $post_id,
                            'field'       => 'caption',
                            'page_path'   => '',
                            'locator'     => "attachment:{$post_id}:caption",
                            'supported'   => 1,
                        ),
                    );
                }
            }
            $next = ( count( $query->posts ) === $limit ) ? $offset + $limit : null;
            return array( 'items' => $items, 'next' => $next );
        }

        return new WP_Error( 'invalid_provider', __( 'Provider not supported.', 'lingolane' ) );
    }

    public static function translate( $identity, $fallback ) {
        if ( is_admin() || ! isset( $identity['domain'], $identity['source'] ) ) {
            return $fallback;
        }
        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) {
            return $fallback;
        }
        $context = isset( $identity['context'] ) ? (string) $identity['context'] : '';
        $trans = Lingolane_Strings::get( $identity['domain'], $identity['source'], $context, $current );
        return ( '' !== $trans ) ? $trans : $fallback;
    }
}
