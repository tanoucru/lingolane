<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lingolane Language Flags: SVG vector flags and country code mapping.
 */
class Lingolane_Flags {
    /**
     * Map language code / locale to ISO 3166-1 alpha-2 country code.
     *
     * @param string $code   Language code (e.g. 'vi', 'en', 'ja').
     * @param string $locale Optional locale (e.g. 'en_US', 'pt_BR').
     * @return string 2-letter lowercase country code (e.g. 'vn', 'gb', 'us').
     */
    public static function country_code( $code, $locale = '' ) {
        $code   = strtolower( trim( (string) $code ) );
        $locale = strtolower( trim( (string) $locale ) );

        // Extract country from locale if available (e.g. en_us -> us, pt_br -> br, zh_tw -> tw).
        if ( $locale ) {
            if ( preg_match( '/[_-]([a-z]{2})$/i', $locale, $m ) ) {
                $sub = strtolower( $m[1] );
                if ( 'en' === $code && 'us' === $sub ) { return 'us'; }
                if ( 'en' === $code && 'gb' === $sub ) { return 'gb'; }
                if ( 'zh' === $code && 'tw' === $sub ) { return 'tw'; }
                if ( 'pt' === $code && 'br' === $sub ) { return 'br'; }
                if ( 'es' === $code && 'mx' === $sub ) { return 'mx'; }
            }
        }

        // Direct mapping for common language codes.
        $map = array(
            'vi'    => 'vn',
            'en'    => 'gb', // Default English to GB (or US via en-us).
            'en-us' => 'us',
            'en-gb' => 'gb',
            'ja'    => 'jp',
            'ko'    => 'kr',
            'zh'    => 'cn',
            'zh-cn' => 'cn',
            'zh-tw' => 'tw',
            'fr'    => 'fr',
            'de'    => 'de',
            'es'    => 'es',
            'it'    => 'it',
            'ru'    => 'ru',
            'th'    => 'th',
            'id'    => 'id',
            'ms'    => 'my',
            'my'    => 'my',
            'sg'    => 'sg',
            'hi'    => 'in',
            'ar'    => 'sa',
            'nl'    => 'nl',
            'pl'    => 'pl',
            'sv'    => 'se',
            'se'    => 'se',
            'da'    => 'dk',
            'fi'    => 'fi',
            'no'    => 'no',
            'nb'    => 'no',
            'nn'    => 'no',
            'pt'    => 'pt',
            'pt-br' => 'br',
            'tr'    => 'tr',
            'el'    => 'gr',
            'cs'    => 'cz',
            'uk'    => 'ua',
            'ro'    => 'ro',
            'hu'    => 'hu',
            'bg'    => 'bg',
            'sk'    => 'sk',
            'hr'    => 'hr',
            'sr'    => 'rs',
            'sl'    => 'si',
            'et'    => 'ee',
            'lv'    => 'lv',
            'lt'    => 'lt',
            'he'    => 'il',
            'fa'    => 'ir',
            'tl'    => 'ph',
            'fil'   => 'ph',
            'km'    => 'kh',
            'lo'    => 'la',
        );

        if ( isset( $map[ $code ] ) ) {
            return $map[ $code ];
        }

        // If code is in xx-yy format, use yy.
        if ( preg_match( '/^[a-z]{2,3}[-_]([a-z]{2})$/i', $code, $m ) ) {
            return strtolower( $m[1] );
        }

        return strlen( $code ) === 2 ? $code : substr( $code, 0, 2 );
    }

    /**
     * Return SVG vector markup for a country or language flag.
     *
     * @param string $code_or_country Language code or 2-letter country code.
     * @param int    $width           Width in px (default 20).
     * @param int    $height          Height in px (default 15).
     * @param string $class           Extra CSS class.
     * @return string Sanitized SVG markup.
     */
    public static function get_flag_svg( $code_or_country, $width = 20, $height = 15, $class = '' ) {
        $country = self::country_code( $code_or_country );
        $width   = max( 12, min( 120, (int) $width ) );
        $height  = max( 9, min( 90, (int) $height ) );
        $classes = 'lingolane-flag lingolane-flag--' . esc_attr( $country ) . ( $class ? ' ' . esc_attr( $class ) : '' );

        $flags = self::vectors();

        if ( isset( $flags[ $country ] ) ) {
            $inner = $flags[ $country ];
            $svg   = sprintf(
                '<svg class="%1$s" width="%2$d" height="%3$d" viewBox="0 0 640 480" aria-hidden="true" focusable="false">%4$s</svg>',
                $classes,
                $width,
                $height,
                $inner
            );
        } else {
            // Elegant badge fallback with 2-letter uppercase text.
            $label = strtoupper( substr( $code_or_country, 0, 2 ) );
            $svg   = sprintf(
                '<svg class="%1$s lingolane-flag--fallback" width="%2$d" height="%3$d" viewBox="0 0 640 480" aria-hidden="true" focusable="false"><rect width="640" height="480" rx="40" fill="#2d3748"/><text x="50%%" y="55%%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-weight="700" font-size="220" letter-spacing="10">%4$s</text></svg>',
                $classes,
                $width,
                $height,
                esc_html( $label )
            );
        }

        return apply_filters( 'lingolane_flag_svg', $svg, $country, $width, $height );
    }

    /**
     * Bundled clean vector paths for common national flags (4:3 ratio, 640x480).
     */
    private static function vectors() {
        static $vectors = null;
        if ( null !== $vectors ) {
            return $vectors;
        }

        $vectors = array(
            // Vietnam (Red background, golden 5-point star).
            'vn' => '<rect width="640" height="480" fill="#da251d"/><polygon points="320,84 357,198 477,198 380,268 417,382 320,312 223,382 260,268 163,198 283,198" fill="#ff0"/>',

            // United Kingdom (Union Jack).
            'gb' => '<rect width="640" height="480" fill="#012169"/><path d="M0,0 L640,480 M640,0 L0,480" stroke="#fff" stroke-width="60"/><path d="M0,0 L320,240 M640,480 L320,240" stroke="#c8102e" stroke-width="20" stroke-linecap="square"/><path d="M640,0 L320,240 M0,480 L320,240" stroke="#c8102e" stroke-width="20" stroke-linecap="square"/><path d="M320,0 V480 M0,240 H640" stroke="#fff" stroke-width="100"/><path d="M320,0 V480 M0,240 H640" stroke="#c8102e" stroke-width="60"/>',

            // United States.
            'us' => '<rect width="640" height="480" fill="#fff"/><g fill="#b22234"><rect y="0" width="640" height="37"/><rect y="74" width="640" height="37"/><rect y="148" width="640" height="37"/><rect y="222" width="640" height="37"/><rect y="296" width="640" height="37"/><rect y="370" width="640" height="37"/><rect y="444" width="640" height="36"/></g><rect width="256" height="259" fill="#3c3b6e"/><g fill="#fff"><circle cx="42" cy="37" r="14"/><circle cx="128" cy="37" r="14"/><circle cx="214" cy="37" r="14"/><circle cx="85" cy="74" r="14"/><circle cx="171" cy="74" r="14"/><circle cx="42" cy="111" r="14"/><circle cx="128" cy="111" r="14"/><circle cx="214" cy="111" r="14"/><circle cx="85" cy="148" r="14"/><circle cx="171" cy="148" r="14"/><circle cx="42" cy="185" r="14"/><circle cx="128" cy="185" r="14"/><circle cx="214" cy="185" r="14"/><circle cx="85" cy="222" r="14"/><circle cx="171" cy="222" r="14"/></g>',

            // Japan (White, centered red sun).
            'jp' => '<rect width="640" height="480" fill="#fff"/><circle cx="320" cy="240" r="144" fill="#bc002d"/>',

            // South Korea (White, Taegeuk center, trigrams).
            'kr' => '<rect width="640" height="480" fill="#fff"/><g transform="translate(320,240)"><path d="M-120,0 A120,120 0 0,1 120,0 A60,60 0 0,1 0,0 A60,60 0 0,0 -120,0 Z" fill="#cd2e3a"/><path d="M120,0 A120,120 0 0,1 -120,0 A60,60 0 0,1 0,0 A60,60 0 0,0 120,0 Z" fill="#0047a0"/><g fill="#000"><rect x="-210" y="-140" width="16" height="70" rx="3"/><rect x="-185" y="-140" width="16" height="70" rx="3"/><rect x="-160" y="-140" width="16" height="70" rx="3"/><rect x="160" y="70" width="16" height="70" rx="3"/><rect x="185" y="70" width="16" height="70" rx="3"/><rect x="210" y="70" width="16" height="70" rx="3"/></g></g>',

            // France (Blue, White, Red).
            'fr' => '<rect width="213.3" height="480" fill="#002654"/><rect x="213.3" width="213.4" height="480" fill="#fff"/><rect x="426.7" width="213.3" height="480" fill="#ed2939"/>',

            // Germany (Black, Red, Gold).
            'de' => '<rect width="640" height="160" fill="#000"/><rect y="160" width="640" height="160" fill="#d00"/><rect y="320" width="640" height="160" fill="#ffce00"/>',

            // Spain (Red, Yellow, Red).
            'es' => '<rect width="640" height="120" fill="#c60b1e"/><rect y="120" width="640" height="240" fill="#ffc400"/><rect y="360" width="640" height="120" fill="#c60b1e"/>',

            // Italy (Green, White, Red).
            'it' => '<rect width="213.3" height="480" fill="#009246"/><rect x="213.3" width="213.4" height="480" fill="#fff"/><rect x="426.7" width="213.3" height="480" fill="#ce2b37"/>',

            // China (Red, large star and 4 small stars).
            'cn' => '<rect width="640" height="480" fill="#de2910"/><g fill="#ffde00"><polygon points="128,48 143,94 191,94 152,122 167,168 128,140 89,168 104,122 65,94 113,94"/><polygon points="213,24 220,38 235,38 223,47 227,61 213,52 201,61 205,47 193,38 208,38"/><polygon points="256,64 263,78 278,78 266,87 270,101 256,92 244,101 248,87 236,78 251,78"/><polygon points="256,128 263,142 278,142 266,151 270,165 256,156 244,165 248,151 236,142 251,142"/><polygon points="213,168 220,182 235,182 223,191 227,205 213,196 201,205 205,191 193,182 208,182"/></g>',

            // Taiwan (Red, Blue canton, White sun).
            'tw' => '<rect width="640" height="480" fill="#fe0000"/><rect width="320" height="240" fill="#000095"/><circle cx="160" cy="120" r="45" fill="#fff"/><circle cx="160" cy="120" r="34" fill="#000095"/><circle cx="160" cy="120" r="28" fill="#fff"/>',

            // Russia (White, Blue, Red).
            'ru' => '<rect width="640" height="160" fill="#fff"/><rect y="160" width="640" height="160" fill="#0039a6"/><rect y="320" width="640" height="160" fill="#d52b1e"/>',

            // Thailand (Red, White, Blue, White, Red).
            'th' => '<rect width="640" height="80" fill="#a51931"/><rect y="80" width="640" height="80" fill="#f4f5f8"/><rect y="160" width="640" height="160" fill="#2d2a4a"/><rect y="320" width="640" height="80" fill="#f4f5f8"/><rect y="400" width="640" height="80" fill="#a51931"/>',

            // Indonesia (Red, White).
            'id' => '<rect width="640" height="240" fill="#e70011"/><rect y="240" width="640" height="240" fill="#fff"/>',

            // Malaysia.
            'my' => '<rect width="640" height="480" fill="#fff"/><g fill="#cc0000"><rect y="0" width="640" height="34"/><rect y="68" width="640" height="34"/><rect y="136" width="640" height="34"/><rect y="204" width="640" height="34"/><rect y="272" width="640" height="34"/><rect y="340" width="640" height="34"/><rect y="408" width="640" height="34"/></g><rect width="320" height="272" fill="#000066"/><circle cx="150" cy="136" r="68" fill="#ffcc00"/><circle cx="168" cy="136" r="58" fill="#000066"/><polygon points="215,136 195,150 200,126 182,112 205,110 215,88 225,110 248,112 230,126 235,150" fill="#ffcc00"/>',

            // Singapore.
            'sg' => '<rect width="640" height="240" fill="#ed2939"/><rect y="240" width="640" height="240" fill="#fff"/><circle cx="96" cy="120" r="64" fill="#fff"/><circle cx="114" cy="120" r="54" fill="#ed2939"/><g fill="#fff"><circle cx="128" cy="85" r="8"/><circle cx="150" cy="102" r="8"/><circle cx="143" cy="128" r="8"/><circle cx="118" cy="128" r="8"/><circle cx="111" cy="102" r="8"/></g>',

            // India (Saffron, White, Green + Ashoka Chakra).
            'in' => '<rect width="640" height="160" fill="#ff9933"/><rect y="160" width="640" height="160" fill="#fff"/><rect y="320" width="640" height="160" fill="#138808"/><circle cx="320" cy="240" r="50" fill="none" stroke="#000088" stroke-width="6"/><circle cx="320" cy="240" r="12" fill="#000088"/>',

            // Saudi Arabia (Green, Arabic script / Sword motif).
            'sa' => '<rect width="640" height="480" fill="#006c35"/><path d="M190,320 H450 L430,310 H210 Z" fill="#fff"/><circle cx="320" cy="230" r="45" fill="none" stroke="#fff" stroke-width="12"/><path d="M230,220 Q320,180 410,220" stroke="#fff" stroke-width="14" fill="none"/>',

            // Netherlands (Red, White, Blue).
            'nl' => '<rect width="640" height="160" fill="#ae1c28"/><rect y="160" width="640" height="160" fill="#fff"/><rect y="320" width="640" height="160" fill="#21468b"/>',

            // Poland (White, Red).
            'pl' => '<rect width="640" height="240" fill="#fff"/><rect y="240" width="640" height="240" fill="#dc143c"/>',

            // Sweden (Blue, Yellow cross).
            'se' => '<rect width="640" height="480" fill="#006aa7"/><rect x="180" width="80" height="480" fill="#fecc00"/><rect y="200" width="640" height="80" fill="#fecc00"/>',

            // Norway (Red, White/Blue cross).
            'no' => '<rect width="640" height="480" fill="#ba0c2f"/><path d="M190,0 V480 M0,240 H640" stroke="#fff" stroke-width="100"/><path d="M190,0 V480 M0,240 H640" stroke="#00205b" stroke-width="50"/>',

            // Denmark (Dannebrog: Red, White cross).
            'dk' => '<rect width="640" height="480" fill="#c60c30"/><rect x="190" width="60" height="480" fill="#fff"/><rect y="210" width="640" height="60" fill="#fff"/>',

            // Finland (White, Blue cross).
            'fi' => '<rect width="640" height="480" fill="#fff"/><rect x="180" width="90" height="480" fill="#002f6c"/><rect y="195" width="640" height="90" fill="#002f6c"/>',

            // Portugal (Green, Red).
            'pt' => '<rect width="256" height="480" fill="#046a38"/><rect x="256" width="384" height="480" fill="#da291c"/><circle cx="256" cy="240" r="70" fill="#ffc72c"/><circle cx="256" cy="240" r="45" fill="#fff"/>',

            // Brazil (Green, Yellow rhombus, Blue circle).
            'br' => '<rect width="640" height="480" fill="#009739"/><polygon points="320,40 590,240 320,440 50,240" fill="#fed100"/><circle cx="320" cy="240" r="110" fill="#002776"/><path d="M215,225 Q320,290 425,225" stroke="#fff" stroke-width="14" fill="none"/>',

            // Turkey (Red, Crescent & Star).
            'tr' => '<rect width="640" height="480" fill="#e30a17"/><circle cx="270" cy="240" r="120" fill="#fff"/><circle cx="300" cy="240" r="96" fill="#e30a17"/><polygon points="370,240 395,248 377,262 384,286 362,272 344,288 348,264 328,252 352,250 360,228" fill="#fff"/>',

            // Greece (Blue/White stripes + cross).
            'gr' => '<rect width="640" height="480" fill="#0d5eaf"/><g fill="#fff"><rect y="53.3" width="640" height="53.3"/><rect y="160" width="640" height="53.3"/><rect y="266.6" width="640" height="53.3"/><rect y="373.3" width="640" height="53.3"/></g><rect width="240" height="240" fill="#0d5eaf"/><rect x="95" width="50" height="240" fill="#fff"/><rect y="95" width="240" height="50" fill="#fff"/>',

            // Philippines (Blue, Red, White triangle, Sun & 3 stars).
            'ph' => '<rect width="640" height="240" fill="#0038a8"/><rect y="240" width="640" height="240" fill="#ce1126"/><polygon points="0,0 340,240 0,480" fill="#fff"/><circle cx="110" cy="240" r="40" fill="#fcd116"/><circle cx="45" cy="65" r="12" fill="#fcd116"/><circle cx="45" cy="415" r="12" fill="#fcd116"/><circle cx="280" cy="240" r="12" fill="#fcd116"/>',

            // Canada (Red, White with Maple Leaf silhouette).
            'ca' => '<rect width="160" height="480" fill="#ff0000"/><rect x="160" width="320" height="480" fill="#fff"/><rect x="480" width="160" height="480" fill="#ff0000"/><path d="M320,110 L335,180 L380,165 L365,215 L415,225 L385,260 L395,280 L345,275 L330,345 L310,345 L295,275 L245,280 L255,260 L225,225 L275,215 L260,165 L305,180 Z" fill="#ff0000"/>',

            // Australia (Blue with Union Jack canton and Southern Cross).
            'au' => '<rect width="640" height="480" fill="#00008b"/><g transform="scale(0.5)"><rect width="640" height="480" fill="#012169"/><path d="M0,0 L640,480 M640,0 L0,480" stroke="#fff" stroke-width="60"/><path d="M320,0 V480 M0,240 H640" stroke="#fff" stroke-width="100"/><path d="M320,0 V480 M0,240 H640" stroke="#c8102e" stroke-width="60"/></g><g fill="#fff"><circle cx="480" cy="120" r="14"/><circle cx="560" cy="180" r="14"/><circle cx="530" cy="300" r="14"/><circle cx="430" cy="260" r="14"/><circle cx="490" cy="210" r="10"/><circle cx="160" cy="360" r="28"/></g>',

            // Cambodia (Blue, Red with Angkor Wat silhouette, Blue).
            'kh' => '<rect width="640" height="120" fill="#032ea1"/><rect y="120" width="640" height="240" fill="#e00025"/><rect y="360" width="640" height="120" fill="#032ea1"/><path d="M250,280 H390 V250 H360 V220 H340 V200 H300 V220 H280 V250 H250 Z" fill="#fff"/>',

            // Laos (Red, Blue with White Moon, Red).
            'la' => '<rect width="640" height="120" fill="#ce1126"/><rect y="120" width="640" height="240" fill="#002868"/><rect y="360" width="640" height="120" fill="#ce1126"/><circle cx="320" cy="240" r="76" fill="#fff"/>',
        );

        return $vectors;
    }
}
