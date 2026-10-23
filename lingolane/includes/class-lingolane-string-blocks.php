<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_String_Blocks {

    private static $special_tags = array(
        'script', 'style', 'textarea', 'svg', 'code', 'pre', 'noscript', 'template',
    );

    public static function init() {
        add_filter( 'render_block', array( __CLASS__, 'render' ), 20, 2 );
        add_filter( 'widget_display_callback', array( __CLASS__, 'widget' ), 20, 2 );
    }

    public static function extract( $html ) {
        if ( ! is_string( $html ) || '' === $html ) {
            return array();
        }

        $nodes       = array();
        $len         = strlen( $html );
        $cursor      = 0;
        $in_special  = null;
        $in_nav      = false;
        $nav_tag     = '';
        $nav_depth   = 0;

        while ( $cursor < $len ) {
            $tag_open = strpos( $html, '<', $cursor );
            if ( false === $tag_open ) {
                // Trailing text.
                if ( null === $in_special && ! $in_nav ) {
                    $text = substr( $html, $cursor );
                    if ( '' !== trim( $text ) ) {
                        $nodes[] = array(
                            'start'  => $cursor,
                            'length' => $len - $cursor,
                            'text'   => $text,
                        );
                    }
                }
                break;
            }

            // Text between current cursor and tag_open.
            if ( $tag_open > $cursor && null === $in_special && ! $in_nav ) {
                $text = substr( $html, $cursor, $tag_open - $cursor );
                if ( '' !== trim( $text ) ) {
                    $nodes[] = array(
                        'start'  => $cursor,
                        'length' => $tag_open - $cursor,
                        'text'   => $text,
                    );
                }
            }

            // Handle comments <!-- ... -->
            if ( substr( $html, $tag_open, 4 ) === '<!--' ) {
                $comment_close = strpos( $html, '-->', $tag_open + 4 );
                $cursor = ( false !== $comment_close ) ? $comment_close + 3 : $len;
                continue;
            }

            // Handle CDATA <![CDATA[ ... ]]>
            if ( substr( $html, $tag_open, 9 ) === '<![CDATA[' ) {
                $cdata_close = strpos( $html, ']]>', $tag_open + 9 );
                $cursor = ( false !== $cdata_close ) ? $cdata_close + 3 : $len;
                continue;
            }

            // Normal tag search.
            $tag_close = strpos( $html, '>', $tag_open );
            if ( false === $tag_close ) {
                // Malformed tag without closing bracket.
                break;
            }

            $tag_content = substr( $html, $tag_open + 1, $tag_close - $tag_open - 1 );
            $tag_trimmed = trim( $tag_content );
            $is_closing  = ( isset( $tag_trimmed[0] ) && '/' === $tag_trimmed[0] );
            $clean_tag   = ltrim( $tag_trimmed, '/' );

            // Extract tag name.
            preg_match( '/^[a-zA-Z0-9:-]+/', $clean_tag, $m );
            $tag_name = isset( $m[0] ) ? strtolower( $m[0] ) : '';

            // Handle special tags (script, style, code, pre, svg, etc.)
            if ( in_array( $tag_name, self::$special_tags, true ) ) {
                if ( $is_closing ) {
                    if ( $in_special === $tag_name ) {
                        $in_special = null;
                    }
                } else {
                    // Check if self-closing.
                    if ( substr( rtrim( $tag_content ), -1 ) !== '/' ) {
                        $in_special = $tag_name;
                    }
                }
            }

            // Handle navigation block exclusion.
            if ( ! $in_nav ) {
                if ( 'nav' === $tag_name || false !== strpos( $tag_content, 'wp-block-navigation' ) ) {
                    if ( ! $is_closing && substr( rtrim( $tag_content ), -1 ) !== '/' ) {
                        $in_nav    = true;
                        $nav_tag   = $tag_name;
                        $nav_depth = 1;
                    }
                }
            } else {
                if ( $tag_name === $nav_tag ) {
                    if ( $is_closing ) {
                        $nav_depth--;
                        if ( $nav_depth <= 0 ) {
                            $in_nav  = false;
                            $nav_tag = '';
                        }
                    } elseif ( substr( rtrim( $tag_content ), -1 ) !== '/' ) {
                        $nav_depth++;
                    }
                }
            }

            $cursor = $tag_close + 1;
        }

        return $nodes;
    }

    public static function replace( $html, $translations, $context = 'header' ) {
        if ( ! is_string( $html ) || '' === $html || empty( $translations ) ) {
            return $html;
        }

        $nodes = self::extract( $html );
        if ( empty( $nodes ) ) {
            return $html;
        }

        // Sort descending by start position to replace safely from tail to head.
        usort( $nodes, function( $a, $b ) {
            return $b['start'] - $a['start'];
        } );

        foreach ( $nodes as $node ) {
            $raw_text = $node['text'];
            $trimmed  = trim( $raw_text );
            $decoded  = html_entity_decode( $trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $replacement = null;
            if ( isset( $translations[ $decoded ] ) ) {
                $replacement = $translations[ $decoded ];
            } elseif ( isset( $translations[ $trimmed ] ) ) {
                $replacement = $translations[ $trimmed ];
            }

            if ( null !== $replacement && is_string( $replacement ) && '' !== $replacement ) {
                // Preserve leading and trailing whitespace of the original node text.
                $leading_ws  = '';
                $trailing_ws = '';
                if ( preg_match( '/^(\s+)/u', $raw_text, $m_lead ) ) {
                    $leading_ws = $m_lead[1];
                }
                if ( preg_match( '/(\s+)$/u', $raw_text, $m_trail ) ) {
                    $trailing_ws = $m_trail[1];
                }

                $escaped = esc_html( $replacement );
                $final   = $leading_ws . $escaped . $trailing_ws;

                $html = substr_replace( $html, $final, $node['start'], $node['length'] );
            }
        }

        return $html;
    }

    public static function render( $content, $block ) {
        if ( is_admin() || ! is_string( $content ) || '' === $content ) {
            return $content;
        }

        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) {
            return $content;
        }

        if ( ! isset( $block['blockName'] ) ) {
            return $content;
        }

        $name = $block['blockName'];
        $domain = null;
        $context = 'header';

        // 1. Template part root.
        if ( 'core/template-part' === $name ) {
            $area = isset( $block['attrs']['area'] ) ? (string) $block['attrs']['area'] : '';
            if ( '' === $area && isset( $block['attrs']['slug'] ) ) {
                $area = (string) $block['attrs']['slug'];
            }
            if ( in_array( $area, array( 'header', 'footer', 'sidebar' ), true ) ) {
                $domain  = 'lingolane-block';
                $context = $area;
            }
        }

        // 2. Widget group root.
        if ( 'core/widget-group' === $name || 0 === strpos( $name, 'core/widget' ) ) {
            $domain  = 'lingolane-widget';
            $context = 'title';
        }

        if ( ! $domain ) {
            return $content;
        }

        $nodes = self::extract( $content );
        if ( empty( $nodes ) ) {
            return $content;
        }

        // Build translations map for extracted text nodes.
        $translations = array();
        foreach ( $nodes as $node ) {
            $trimmed = trim( $node['text'] );
            $decoded = html_entity_decode( $trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( '' !== $decoded && ! isset( $translations[ $decoded ] ) ) {
                $trans = Lingolane_Strings::get( $domain, $decoded, $context, $current );
                if ( '' !== $trans ) {
                    $translations[ $decoded ] = $trans;
                }
            }
        }

        if ( empty( $translations ) ) {
            return $content;
        }

        return self::replace( $content, $translations, $context );
    }

    public static function widget( $instance, $widget ) {
        if ( is_admin() || ! is_array( $instance ) ) {
            return $instance;
        }

        $current = Lingolane_Languages::current();
        if ( $current === Lingolane_Languages::default_code() ) {
            return $instance;
        }

        if ( isset( $instance['title'] ) && is_string( $instance['title'] ) && '' !== trim( $instance['title'] ) ) {
            $trans_title = Lingolane_Strings::get( 'lingolane-widget', $instance['title'], 'title', $current );
            if ( '' !== $trans_title ) {
                $instance['title'] = $trans_title;
            }
        }

        if ( isset( $instance['text'] ) && is_string( $instance['text'] ) && '' !== trim( $instance['text'] ) ) {
            $trans_text = Lingolane_Strings::get( 'lingolane-widget', $instance['text'], 'text', $current );
            if ( '' !== $trans_text ) {
                $instance['text'] = $trans_text;
            }
        }

        return $instance;
    }
}
