<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every language a site can add. The full list comes from the WordPress.org translation API (the same
 * list as Settings > General, cached by core), so the plugin ships only a small offline fallback.
 */
class Lingolane_Catalog {
    /** Shown first in the picker, in this order. Also decides which locale gets the bare URL code. */
    const POPULAR = array(
        'en_US', 'zh_CN', 'es_ES', 'ar', 'hi_IN', 'pt_PT', 'pt_BR', 'fr_FR', 'ru_RU', 'ja', 'de_DE', 'ko_KR',
        'vi', 'it_IT', 'tr_TR', 'id_ID', 'th', 'pl_PL', 'nl_NL', 'uk', 'fa_IR', 'ms_MY', 'zh_TW', 'bn_BD',
    );

    /** URL codes that differ from the generated ones, kept stable for sites created before the catalog. */
    const CODES = array( 'en_US' => 'en', 'zh_CN' => 'zh', 'zh_TW' => 'zh-tw', 'pt_PT' => 'pt', 'pt_BR' => 'pt-br' );

    /** Offline fallback when WordPress.org cannot be reached: locale => [native name, English name]. */
    const FALLBACK = array(
        'ar' => array( 'العربية', 'Arabic' ),
        'bg_BG' => array( 'Български', 'Bulgarian' ),
        'bn_BD' => array( 'বাংলা', 'Bengali (Bangladesh)' ),
        'cs_CZ' => array( 'Čeština', 'Czech' ),
        'da_DK' => array( 'Dansk', 'Danish' ),
        'de_DE' => array( 'Deutsch', 'German' ),
        'el' => array( 'Ελληνικά', 'Greek' ),
        'es_ES' => array( 'Español', 'Spanish (Spain)' ),
        'es_MX' => array( 'Español de México', 'Spanish (Mexico)' ),
        'fa_IR' => array( 'فارسی', 'Persian' ),
        'fi' => array( 'Suomi', 'Finnish' ),
        'fr_FR' => array( 'Français', 'French (France)' ),
        'he_IL' => array( 'עִבְרִית', 'Hebrew' ),
        'hi_IN' => array( 'हिन्दी', 'Hindi' ),
        'hr' => array( 'Hrvatski', 'Croatian' ),
        'hu_HU' => array( 'Magyar', 'Hungarian' ),
        'id_ID' => array( 'Bahasa Indonesia', 'Indonesian' ),
        'it_IT' => array( 'Italiano', 'Italian' ),
        'ja' => array( '日本語', 'Japanese' ),
        'km' => array( 'ភាសាខ្មែរ', 'Khmer' ),
        'ko_KR' => array( '한국어', 'Korean' ),
        'ms_MY' => array( 'Bahasa Melayu', 'Malay' ),
        'my_MM' => array( 'ဗမာစာ', 'Myanmar (Burmese)' ),
        'nb_NO' => array( 'Norsk bokmål', 'Norwegian (Bokmål)' ),
        'nl_NL' => array( 'Nederlands', 'Dutch' ),
        'pl_PL' => array( 'Polski', 'Polish' ),
        'pt_BR' => array( 'Português do Brasil', 'Portuguese (Brazil)' ),
        'pt_PT' => array( 'Português', 'Portuguese (Portugal)' ),
        'ro_RO' => array( 'Română', 'Romanian' ),
        'ru_RU' => array( 'Русский', 'Russian' ),
        'sk_SK' => array( 'Slovenčina', 'Slovak' ),
        'sr_RS' => array( 'Српски језик', 'Serbian' ),
        'sv_SE' => array( 'Svenska', 'Swedish' ),
        'sw' => array( 'Kiswahili', 'Swahili' ),
        'ta_IN' => array( 'தமிழ்', 'Tamil' ),
        'th' => array( 'ไทย', 'Thai' ),
        'tl' => array( 'Tagalog', 'Tagalog' ),
        'tr_TR' => array( 'Türkçe', 'Turkish' ),
        'uk' => array( 'Українська', 'Ukrainian' ),
        'ur' => array( 'اردو', 'Urdu' ),
        'vi' => array( 'Tiếng Việt', 'Vietnamese' ),
        'zh_CN' => array( '简体中文', 'Chinese (China)' ),
        'zh_HK' => array( '香港中文', 'Chinese (Hong Kong)' ),
        'zh_TW' => array( '繁體中文', 'Chinese (Taiwan)' ),
    );

    /** @var array|null */
    private static $cache = null;

    public static function reset() { self::$cache = null; }

    /** Raw locale list: locale => [native name, English name]. */
    private static function source() {
        $list = array();
        if ( ! function_exists( 'wp_get_available_translations' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }
        foreach ( (array) wp_get_available_translations() as $locale => $item ) {
            if ( ! is_array( $item ) || empty( $item['native_name'] ) ) { continue; }
            $list[ $locale ] = array( (string) $item['native_name'], isset( $item['english_name'] ) ? (string) $item['english_name'] : (string) $item['native_name'] );
        }
        $list = $list + self::FALLBACK;
        $list['en_US'] = array( 'English', 'English (United States)' );
        return (array) apply_filters( 'lingolane_language_catalog_source', $list );
    }

    /**
     * Every addable language, popular ones first: code => [code, locale, name, english, popular].
     * The code is the language subtag (fr, de, ja); other locales of the same language add their region
     * (pt-br, en-gb). Formal/informal and spelling variants (de_DE_formal) are left out: a site needs one
     * URL code per language.
     */
    public static function all() {
        if ( null !== self::$cache ) { return self::$cache; }
        $source = self::source();
        $groups = array();
        foreach ( $source as $locale => $names ) {
            if ( ! is_string( $locale ) || ! is_array( $names ) || ! preg_match( '/^([a-z]{2,3})(?:_([A-Z]{2}))?$/D', $locale, $parts ) ) { continue; }
            $groups[ $parts[1] ][ $locale ] = isset( $parts[2] ) ? strtolower( $parts[2] ) : '';
        }
        $popular = array_flip( self::POPULAR );
        $items = array();
        foreach ( $groups as $lang => $locales ) {
            $primary = self::primary( $lang, $locales, $popular );
            foreach ( $locales as $locale => $region ) {
                $code = isset( self::CODES[ $locale ] ) ? self::CODES[ $locale ] : ( $locale === $primary || '' === $region ? $lang : $lang . '-' . $region );
                if ( isset( $items[ $code ] ) ) { continue; }
                $items[ $code ] = array(
                    'code'    => $code,
                    'locale'  => $locale,
                    'name'    => sanitize_text_field( $source[ $locale ][0] ),
                    'english' => sanitize_text_field( isset( $source[ $locale ][1] ) ? $source[ $locale ][1] : $source[ $locale ][0] ),
                    'popular' => isset( $popular[ $locale ] ),
                );
            }
        }
        uasort( $items, function ( $a, $b ) use ( $popular ) {
            if ( $a['popular'] !== $b['popular'] ) { return $a['popular'] ? -1 : 1; }
            if ( $a['popular'] ) { return $popular[ $a['locale'] ] - $popular[ $b['locale'] ]; }
            return strcasecmp( $a['english'], $b['english'] );
        } );
        return self::$cache = $items;
    }

    /** Locale of a language that gets the bare code: the most popular one, else the one whose region matches (fr_FR), else a lone locale. */
    private static function primary( $lang, $locales, $popular ) {
        $ranked = array_intersect_key( $popular, $locales );
        if ( $ranked ) {
            asort( $ranked );
            return key( $ranked );
        }
        foreach ( $locales as $locale => $region ) {
            if ( $region === $lang ) { return $locale; }
        }
        return 1 === count( $locales ) ? key( $locales ) : '';
    }

    public static function get( $code ) {
        $all = self::all();
        return isset( $all[ $code ] ) ? $all[ $code ] : null;
    }

    public static function by_locale( $locale ) {
        foreach ( self::all() as $item ) {
            if ( $item['locale'] === $locale ) { return $item; }
        }
        return null;
    }
}
