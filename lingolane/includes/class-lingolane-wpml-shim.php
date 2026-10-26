<?php
/**
 * WPML Compatibility API Shim (M3 C2)
 *
 * Provides compatibility filters and functions commonly used by themes
 * (Flatsome, Astra, OceanWP, Divi, WoodMart, etc.) and third-party plugins
 * expecting WPML / Polylang hooks.
 *
 * @package Lingolane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingolane_WPML_Shim {

	public static function init() {
		// Only enable if WPML or Polylang are not active
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) || defined( 'POLYLANG_VERSION' ) || class_exists( 'Polylang' ) ) {
			return;
		}

		add_filter( 'wpml_current_language', array( __CLASS__, 'current_language' ) );
		add_filter( 'wpml_default_language', array( __CLASS__, 'default_language' ) );
		add_filter( 'wpml_active_languages', array( __CLASS__, 'active_languages' ), 10, 2 );
		add_filter( 'wpml_object_id', array( __CLASS__, 'object_id' ), 10, 4 );
		add_filter( 'wpml_element_language_details', array( __CLASS__, 'element_language_details' ), 10, 2 );

		self::register_global_functions();
	}

	public static function current_language() {
		return Lingolane_Languages::current();
	}

	public static function default_language() {
		return Lingolane_Languages::default_code();
	}

	public static function active_languages( $languages = null, $args = '' ) {
		$all = Lingolane_Languages::all();
		$result = array();
		$current = Lingolane_Languages::current();
		$default = Lingolane_Languages::default_code();

		foreach ( $all as $lang ) {
			$code = $lang['code'];
			$result[ $code ] = array(
				'id'              => $code,
				'active'          => $code === $current ? 1 : 0,
				'native_name'     => $lang['name'],
				'translated_name' => $lang['name'],
				'language_code'   => $code,
				'url'             => Lingolane_Urls::home( $code ),
				'default_locale'  => $lang['locale'],
			);
		}
		return $result;
	}

	public static function object_id( $element_id, $type = 'post', $return_original_if_missing = false, $ulanguage_code = null ) {
		$element_id = absint( $element_id );
		if ( ! $element_id ) {
			return null;
		}

		$target_lang = $ulanguage_code ? sanitize_key( $ulanguage_code ) : Lingolane_Languages::current();
		if ( ! Lingolane_Languages::is_valid( $target_lang ) ) {
			return $return_original_if_missing ? $element_id : null;
		}

		// Normalize element type: 'post', 'page', 'category', 'post_tag', or CPT
		$internal_type = 'post';
		if ( post_type_exists( $type ) ) {
			$internal_type = 'post_' . $type;
		} elseif ( taxonomy_exists( $type ) ) {
			$internal_type = 'tax_' . $type;
		} elseif ( 'any' === $type ) {
			$post = get_post( $element_id );
			if ( $post ) {
				$internal_type = 'post_' . $post->post_type;
			}
		} else {
			$internal_type = 'post_' . $type;
		}

		$translated_id = Lingolane_Elements::get_translation( $internal_type, $element_id, $target_lang );
		if ( $translated_id ) {
			return (int) $translated_id;
		}

		return $return_original_if_missing ? $element_id : null;
	}

	public static function element_language_details( $details, $args ) {
		$element_id = isset( $args['element_id'] ) ? absint( $args['element_id'] ) : 0;
		$element_type = isset( $args['element_type'] ) ? sanitize_key( $args['element_type'] ) : 'post';

		if ( ! $element_id ) {
			return $details;
		}

		$internal_type = post_type_exists( $element_type ) ? 'post_' . $element_type : ( taxonomy_exists( $element_type ) ? 'tax_' . $element_type : 'post_' . $element_type );
		$lang = Lingolane_Elements::get_language( $internal_type, $element_id );
		$trid = Lingolane_Elements::get_trid( $internal_type, $element_id );

		return (object) array(
			'language_code'        => $lang,
			'source_language_code' => Lingolane_Languages::source_code(),
			'trid'                 => $trid,
		);
	}

	private static function register_global_functions() {
		if ( ! function_exists( 'icl_object_id' ) ) {
			function icl_object_id( $element_id, $type = 'post', $return_original_if_missing = false, $ulanguage_code = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				return Lingolane_WPML_Shim::object_id( $element_id, $type, $return_original_if_missing, $ulanguage_code );
			}
		}

		if ( ! function_exists( 'icl_get_languages' ) ) {
			function icl_get_languages( $args = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				return Lingolane_WPML_Shim::active_languages( null, $args );
			}
		}
	}
}
