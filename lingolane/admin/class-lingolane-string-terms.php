<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_String_Terms {

    public static function init() {
        add_action( 'wp_ajax_lingolane_save_term_translation', array( __CLASS__, 'ajax_save' ) );
    }

    public static function is_supported_taxonomy( $taxonomy ) {
        if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
            return false;
        }
        $tax_obj = get_taxonomy( $taxonomy );
        if ( ! $tax_obj || empty( $tax_obj->public ) ) {
            return false;
        }
        $supported = Lingolane_Query::taxonomies();
        return in_array( $taxonomy, $supported, true );
    }

    public static function page( $taxonomy, $language, $keyword = '', $page = 1, $per_page = 20 ) {
        if ( ! self::is_supported_taxonomy( $taxonomy ) || ! Lingolane_Languages::is_valid( $language ) ) {
            return array( 'items' => array(), 'total' => 0 );
        }

        $default_lang = Lingolane_Languages::default_code();
        $type         = 'tax_' . $taxonomy;
        $args         = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        );

        if ( '' !== trim( (string) $keyword ) ) {
            $args['search'] = trim( (string) $keyword );
        }

        $all_terms = get_terms( $args );
        if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
            return array( 'items' => array(), 'total' => 0 );
        }

        $source_items = array();
        foreach ( (array) $all_terms as $term ) {
            $term_lang = Lingolane_Elements::get_language( $type, $term->term_id );
            // Only terms belonging to the default language (or unassigned, which defaults to default language) are source terms.
            if ( $term_lang !== $default_lang ) {
                continue;
            }

            $trans_id = Lingolane_Elements::get_translation( $type, $term->term_id, $language );
            $trans_term = null;
            if ( $trans_id && (int) $trans_id !== (int) $term->term_id ) {
                $candidate = get_term( $trans_id, $taxonomy );
                if ( $candidate && ! is_wp_error( $candidate ) ) {
                    $trans_term = $candidate;
                }
            }

            $has_trans = ( $trans_term && '' !== trim( (string) $trans_term->name ) );
            $source_items[] = array(
                'source_id'              => (int) $term->term_id,
                'name'                   => (string) $term->name,
                'description'            => (string) $term->description,
                'slug'                   => (string) $term->slug,
                'count'                  => (int) $term->count,
                'parent'                 => (int) $term->parent,
                'translated_id'          => $trans_term ? (int) $trans_term->term_id : 0,
                'translated_name'        => $trans_term ? (string) $trans_term->name : '',
                'translated_description' => $trans_term ? (string) $trans_term->description : '',
                'translated_slug'        => $trans_term ? (string) $trans_term->slug : '',
                'status'                 => $has_trans ? 'translated' : 'missing',
            );
        }

        $total   = count( $source_items );
        $page    = max( 1, (int) $page );
        $per_page = max( 1, (int) $per_page );
        $offset  = ( $page - 1 ) * $per_page;
        $items   = array_slice( $source_items, $offset, $per_page );

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    public static function save( $source_id, $taxonomy, $language, $name, $description = '' ) {
        $source_id = absint( $source_id );
        if ( ! $source_id ) {
            return new WP_Error( 'invalid_source_id', __( 'Invalid source term ID.', 'lingolane' ) );
        }
        if ( ! self::is_supported_taxonomy( $taxonomy ) ) {
            return new WP_Error( 'invalid_taxonomy', __( 'Taxonomy is not supported or not public.', 'lingolane' ) );
        }
        if ( ! Lingolane_Languages::is_valid( $language ) ) {
            return new WP_Error( 'invalid_language', __( 'Invalid target language.', 'lingolane' ) );
        }

        $default_lang = Lingolane_Languages::default_code();
        if ( $language === $default_lang ) {
            return new WP_Error( 'lingolane_immutable_source', __( 'Source term cannot be modified through the translation tool.', 'lingolane' ) );
        }

        $clean_name = sanitize_text_field( $name );
        if ( '' === trim( $clean_name ) ) {
            return new WP_Error( 'empty_name', __( 'Term name cannot be empty.', 'lingolane' ) );
        }
        $clean_desc = sanitize_textarea_field( $description );

        $source_term = get_term( $source_id, $taxonomy );
        if ( ! $source_term || is_wp_error( $source_term ) ) {
            return new WP_Error( 'source_not_found', __( 'Source term not found.', 'lingolane' ) );
        }

        $type = 'tax_' . $taxonomy;
        $source_lang = Lingolane_Elements::get_language( $type, $source_id );
        if ( $source_lang !== $default_lang ) {
            return new WP_Error( 'invalid_source_lang', __( 'Source term does not belong to the default language.', 'lingolane' ) );
        }

        global $wpdb;
        $lock_key = 'lingolane_t_' . md5( $source_id . '_' . $taxonomy . '_' . $language );
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, 5 ) );
        if ( '1' !== (string) $acquired ) {
            return new WP_Error( 'lock_error', __( 'Could not acquire lock for saving term translation.', 'lingolane' ) );
        }

        try {
            // Re-check existing translation under lock.
            $existing_id = Lingolane_Elements::get_translation( $type, $source_id, $language );
            if ( $existing_id && (int) $existing_id !== $source_id ) {
                $update_args = array(
                    'name'        => $clean_name,
                    'description' => $clean_desc,
                );
                // Sync parent if source has parent and parent translation exists.
                if ( (int) $source_term->parent > 0 ) {
                    $parent_trans = Lingolane_Elements::get_translation( $type, (int) $source_term->parent, $language );
                    if ( $parent_trans ) {
                        $update_args['parent'] = (int) $parent_trans;
                    }
                }
                $updated = wp_update_term( (int) $existing_id, $taxonomy, $update_args );
                if ( is_wp_error( $updated ) ) {
                    return $updated;
                }
                self::maybe_sync_children_parents( $source_id, (int) $existing_id, $taxonomy, $language );
                return (int) $existing_id;
            }

            // Determine parent for newly created term.
            $parent_id = 0;
            if ( (int) $source_term->parent > 0 ) {
                $parent_trans = Lingolane_Elements::get_translation( $type, (int) $source_term->parent, $language );
                if ( $parent_trans ) {
                    $parent_id = (int) $parent_trans;
                }
            }

            $inserted = wp_insert_term( $clean_name, $taxonomy, array(
                'description' => $clean_desc,
                'parent'      => $parent_id,
            ) );

            if ( is_wp_error( $inserted ) ) {
                return $inserted;
            }

            $new_term_id = (int) $inserted['term_id'];

            // Register in Lingolane_Elements translation group.
            $trid = Lingolane_Elements::get_trid( $type, $source_id );
            if ( ! $trid ) {
                $trid = Lingolane_Elements::set( $type, $source_id, $default_lang );
                if ( is_wp_error( $trid ) ) {
                    wp_delete_term( $new_term_id, $taxonomy );
                    return $trid;
                }
            }

            $set_result = Lingolane_Elements::set( $type, $new_term_id, $language, $trid );
            if ( is_wp_error( $set_result ) ) {
                wp_delete_term( $new_term_id, $taxonomy );
                return $set_result;
            }

            self::maybe_sync_children_parents( $source_id, $new_term_id, $taxonomy, $language );
            return $new_term_id;

        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
        }
    }

    private static function maybe_sync_children_parents( $source_parent_id, $trans_parent_id, $taxonomy, $language ) {
        $children = get_terms( array(
            'taxonomy'   => $taxonomy,
            'parent'     => (int) $source_parent_id,
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $children ) || empty( $children ) ) {
            return;
        }

        $type = 'tax_' . $taxonomy;
        foreach ( (array) $children as $child ) {
            $child_trans_id = Lingolane_Elements::get_translation( $type, $child->term_id, $language );
            if ( $child_trans_id ) {
                $child_trans_term = get_term( (int) $child_trans_id, $taxonomy );
                if ( $child_trans_term && ! is_wp_error( $child_trans_term ) && 0 === (int) $child_trans_term->parent ) {
                    wp_update_term( (int) $child_trans_id, $taxonomy, array(
                        'parent' => (int) $trans_parent_id,
                    ) );
                    clean_term_cache( (int) $child_trans_id, $taxonomy );
                }
            }
        }
    }

    public static function progress( $language ) {
        if ( ! Lingolane_Languages::is_valid( $language ) ) {
            return array( 'total' => 0, 'completed' => 0, 'percent' => 100 );
        }

        $default_lang = Lingolane_Languages::default_code();
        $taxonomies   = Lingolane_Query::taxonomies();
        $total        = 0;
        $completed    = 0;

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ) );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $type = 'tax_' . $taxonomy;
            foreach ( (array) $terms as $term ) {
                $term_lang = Lingolane_Elements::get_language( $type, $term->term_id );
                if ( $term_lang !== $default_lang ) {
                    continue;
                }

                $total++; // Name field is always non-empty.
                $has_desc = ( '' !== trim( (string) $term->description ) );
                if ( $has_desc ) {
                    $total++;
                }

                $trans_id = Lingolane_Elements::get_translation( $type, $term->term_id, $language );
                if ( $trans_id && (int) $trans_id !== (int) $term->term_id ) {
                    $trans_term = get_term( (int) $trans_id, $taxonomy );
                    if ( $trans_term && ! is_wp_error( $trans_term ) ) {
                        if ( '' !== trim( (string) $trans_term->name ) ) {
                            $completed++;
                        }
                        if ( $has_desc && '' !== trim( (string) $trans_term->description ) ) {
                            $completed++;
                        }
                    }
                }
            }
        }

        $percent = $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 100;
        return array(
            'total'     => $total,
            'completed' => $completed,
            'percent'   => $percent,
        );
    }

    public static function ajax_save() {
        if ( ! Lingolane_Admin::can_translate() ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to edit translations.', 'lingolane' ) ), 403 );
        }

        if ( ! check_ajax_referer( 'lingolane_terms_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'lingolane' ) ), 403 );
        }

        $source_id   = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
        $taxonomy    = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        $language    = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
        $name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        $result = self::save( $source_id, $taxonomy, $language, $name, $description );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( array(
            'term_id' => (int) $result,
            'message' => __( 'Term translation saved.', 'lingolane' ),
        ) );
    }
}
