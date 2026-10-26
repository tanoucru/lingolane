<?php
/**
 * WPML XML Configuration Parser (M3 C1)
 *
 * Reads and parses wpml-config.xml from active theme, child theme,
 * and active plugins to automatically identify translatable custom post types,
 * taxonomies, and custom fields to copy or ignore.
 *
 * @package Lingolane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingolane_WPML_Config {

	private static $config = null;

	public static function init() {
		add_filter( 'lingolane_translatable_post_types', array( __CLASS__, 'filter_translatable_post_types' ) );
		add_filter( 'lingolane_copy_post_metas', array( __CLASS__, 'filter_copy_metas' ), 10, 4 );
	}

	/**
	 * Parse XML string into structured config array.
	 *
	 * @param string $xml_content XML string.
	 * @return array Parsed configuration.
	 */
	public static function parse_xml( $xml_content ) {
		$result = array(
			'copy_fields'   => array(),
			'ignore_fields' => array(),
			'custom_types'  => array(),
			'taxonomies'    => array(),
		);

		if ( ! is_string( $xml_content ) || '' === trim( $xml_content ) ) {
			return $result;
		}

		// Disable external entity loading for security
		$prev_entity_loader = true;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
			$prev_entity_loader = libxml_disable_entity_loader( true );
		}

		$xml = @simplexml_load_string( $xml_content, 'SimpleXMLElement', LIBXML_NONET );

		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
			libxml_disable_entity_loader( $prev_entity_loader );
		}

		if ( ! $xml ) {
			return $result;
		}

		// Parse custom-fields
		if ( isset( $xml->{'custom-fields'}->{'custom-field'} ) ) {
			foreach ( $xml->{'custom-fields'}->{'custom-field'} as $field ) {
				$name   = trim( (string) $field );
				$action = isset( $field['action'] ) ? (string) $field['action'] : '';
				if ( 'copy' === $action && '' !== $name ) {
					$result['copy_fields'][] = $name;
				} elseif ( 'ignore' === $action && '' !== $name ) {
					$result['ignore_fields'][] = $name;
				}
			}
		}

		// Parse custom-types
		if ( isset( $xml->{'custom-types'}->{'custom-type'} ) ) {
			foreach ( $xml->{'custom-types'}->{'custom-type'} as $type ) {
				$name      = trim( (string) $type );
				$translate = isset( $type['translate'] ) ? (string) $type['translate'] : '0';
				if ( '1' === $translate && '' !== $name ) {
					$result['custom_types'][] = $name;
				}
			}
		}

		// Parse taxonomies
		if ( isset( $xml->{'taxonomies'}->{'taxonomy'} ) ) {
			foreach ( $xml->{'taxonomies'}->{'taxonomy'} as $tax ) {
				$name      = trim( (string) $tax );
				$translate = isset( $tax['translate'] ) ? (string) $tax['translate'] : '0';
				if ( '1' === $translate && '' !== $name ) {
					$result['taxonomies'][] = $name;
				}
			}
		}

		return $result;
	}

	/**
	 * Get combined config from all active theme and plugins.
	 *
	 * @return array
	 */
	public static function get_config() {
		if ( null !== self::$config ) {
			return self::$config;
		}

		self::$config = array(
			'copy_fields'   => array(),
			'ignore_fields' => array(),
			'custom_types'  => array(),
			'taxonomies'    => array(),
		);

		$files = array();

		// Stylesheet (child theme)
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$file = get_stylesheet_directory() . '/wpml-config.xml';
			if ( file_exists( $file ) ) {
				$files[] = $file;
			}
		}

		// Template (parent theme)
		if ( function_exists( 'get_template_directory' ) ) {
			$file = get_template_directory() . '/wpml-config.xml';
			if ( file_exists( $file ) && ! in_array( $file, $files, true ) ) {
				$files[] = $file;
			}
		}

		foreach ( $files as $file ) {
			$content = @file_get_contents( $file );
			if ( is_string( $content ) ) {
				$parsed = self::parse_xml( $content );
				self::$config['copy_fields']   = array_merge( self::$config['copy_fields'], $parsed['copy_fields'] );
				self::$config['ignore_fields'] = array_merge( self::$config['ignore_fields'], $parsed['ignore_fields'] );
				self::$config['custom_types']  = array_merge( self::$config['custom_types'], $parsed['custom_types'] );
				self::$config['taxonomies']    = array_merge( self::$config['taxonomies'], $parsed['taxonomies'] );
			}
		}

		return self::$config;
	}

	public static function filter_translatable_post_types( $types ) {
		$config = self::get_config();
		if ( ! empty( $config['custom_types'] ) && is_array( $types ) ) {
			$types = array_unique( array_merge( $types, $config['custom_types'] ) );
		}
		return $types;
	}

	public static function filter_copy_metas( $keys, $source_id, $new_id, $target_lang ) {
		$config = self::get_config();
		if ( ! empty( $config['ignore_fields'] ) ) {
			$keys = array_diff( $keys, $config['ignore_fields'] );
		}
		return array_values( $keys );
	}
}
