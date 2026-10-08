<?php
/**
 * Plugin Name: Knygos įgarsinimo registracija
 * Description: Bendruomenių narių registracija knygos skyrių įgarsinimui su rezervacijomis, administravimo lentele ir Excel eksportu.
 * Version: 2.2.2
 * Author: Lithuania Conference
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Update URI: https://github.com/kiritoshiro/registracija
 * Text Domain: knygos-igarsinimo-registracija
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class KIR_Plugin {
    const VERSION               = '2.2.2';
    const DB_VERSION            = '1.7.0';
    const OPTION_TEXTS          = 'kir_texts';
    const OPTION_CONGREGATIONS  = 'kir_congregations';
    const OPTION_DB_VER         = 'kir_db_version';
    const OPTION_GOOGLE_SHEETS  = 'kir_google_sheets';
    const OPTION_GOOGLE_SHEETS_STATUS = 'kir_google_sheets_status';
    const NONCE_ACTION          = 'kir_public_form';
    const SHORTCODE             = 'knygos_igarsinimo_registracija';
    const PLUGIN_SLUG            = 'knygos-igarsinimo-registracija';
    const GITHUB_REPOSITORY      = 'kiritoshiro/registracija';
    const RELEASE_TRANSIENT_PREFIX = 'kir_github_latest_release_';

    /** @var KIR_Plugin|null */
    private static $instance = null;

    /** @var string */
    private $table_name;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'kir_reservations';

        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_public_assets' ) );
        add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

        add_action( 'wp_ajax_kir_get_chapters', array( $this, 'ajax_get_chapters' ) );
        add_action( 'wp_ajax_nopriv_kir_get_chapters', array( $this, 'ajax_get_chapters' ) );
        add_action( 'wp_ajax_kir_submit_reservation', array( $this, 'ajax_submit_reservation' ) );
        add_action( 'wp_ajax_nopriv_kir_submit_reservation', array( $this, 'ajax_submit_reservation' ) );
        add_action( 'wp_ajax_kir_get_my_reservations', array( $this, 'ajax_get_my_reservations' ) );
        add_action( 'wp_ajax_nopriv_kir_get_my_reservations', array( $this, 'ajax_get_my_reservations' ) );
        add_action( 'wp_ajax_kir_cancel_my_reservations', array( $this, 'ajax_cancel_my_reservations' ) );
        add_action( 'wp_ajax_nopriv_kir_cancel_my_reservations', array( $this, 'ajax_cancel_my_reservations' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_kir_export_xlsx', array( $this, 'handle_export' ) );
        add_action( 'admin_post_kir_update_reservations', array( $this, 'handle_update_reservations' ) );
        add_action( 'admin_post_kir_update_assignments', array( $this, 'handle_update_assignments' ) );
        add_action( 'admin_post_kir_release_reservation', array( $this, 'handle_release_reservation' ) );
        add_action( 'admin_post_kir_sync_google_sheets', array( $this, 'handle_sync_google_sheets' ) );

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'normalize_update_source' ), 10, 4 );
        add_action( 'load-update-core.php', array( $this, 'force_update_check' ), 9 );
    }

    /**
     * "Check again" on Dashboard → Updates (force-check=1) only forces the core
     * check. Drop the release cache and WordPress' plugin update data before
     * wp_update_plugins runs (priority 10), so a new release shows at once.
     */
    public function force_update_check() {
        if ( empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache refresh.
            return;
        }
        delete_site_transient( $this->release_transient_key() );
        delete_site_transient( 'update_plugins' );
    }

    private function plugin_basename() {
        return plugin_basename( __FILE__ );
    }

    private function github_release_api_url() {
        return 'https://api.github.com/repos/' . self::GITHUB_REPOSITORY . '/releases/latest';
    }

    private function release_transient_key() {
        return self::RELEASE_TRANSIENT_PREFIX . self::VERSION;
    }

    private function get_latest_release() {
        $cached = get_site_transient( $this->release_transient_key() );
        if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['zipball_url'] ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            $this->github_release_api_url(),
            array(
                'timeout'     => 10,
                'redirection' => 3,
                'headers'     => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Knygos-igarsinimo-registracija/' . self::VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return new WP_Error( 'kir_github_release_error', 'GitHub leidimo informacijos gauti nepavyko.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'kir_github_release_invalid', 'GitHub grąžino netinkamą leidimo informaciją.' );
        }

        $tag_name = isset( $body['tag_name'] ) ? sanitize_text_field( $body['tag_name'] ) : '';
        $version  = preg_replace( '/^v/i', '', $tag_name );
        $zipball  = isset( $body['zipball_url'] ) ? esc_url_raw( $body['zipball_url'], array( 'https' ) ) : '';
        $zip_parts = $zipball ? wp_parse_url( $zipball ) : false;

        if ( ! preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
            return new WP_Error( 'kir_github_release_version', 'GitHub leidimo versija neatpažinta.' );
        }
        if ( ! is_array( $zip_parts ) || 'https' !== ( isset( $zip_parts['scheme'] ) ? strtolower( $zip_parts['scheme'] ) : '' ) || 'api.github.com' !== ( isset( $zip_parts['host'] ) ? strtolower( $zip_parts['host'] ) : '' ) || 0 !== strpos( isset( $zip_parts['path'] ) ? $zip_parts['path'] : '', '/repos/' . self::GITHUB_REPOSITORY . '/zipball/' ) ) {
            return new WP_Error( 'kir_github_release_package', 'GitHub leidimo paketas neatpažintas.' );
        }

        $release = array(
            'version'     => $version,
            'tag_name'    => $tag_name,
            'name'        => isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : $tag_name,
            'body'        => isset( $body['body'] ) ? wp_kses_post( (string) $body['body'] ) : '',
            'zipball_url' => $zipball,
            'html_url'    => isset( $body['html_url'] ) ? esc_url_raw( $body['html_url'], array( 'https' ) ) : '',
            'published_at'=> isset( $body['published_at'] ) ? sanitize_text_field( $body['published_at'] ) : '',
        );

        set_site_transient( $this->release_transient_key(), $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }

    public function check_for_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $plugin_file = $this->plugin_basename();
        $release     = $this->get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $transient;
        }

        if ( version_compare( $release['version'], self::VERSION, '>' ) ) {
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = array();
            }
            $transient->response[ $plugin_file ] = (object) array(
                'id'           => 'github.com/' . self::GITHUB_REPOSITORY,
                'slug'         => self::PLUGIN_SLUG,
                'plugin'       => $plugin_file,
                'new_version'  => $release['version'],
                'url'           => $release['html_url'],
                'package'      => $release['zipball_url'],
                'requires'     => '6.2',
                'requires_php' => '7.4',
            );
        } elseif ( isset( $transient->response[ $plugin_file ] ) ) {
            unset( $transient->response[ $plugin_file ] );
        }

        return $transient;
    }

    public function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::PLUGIN_SLUG !== sanitize_key( $args->slug ) ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Knygos įgarsinimo registracija',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => 'Lithuania Conference',
            'homepage'      => 'https://github.com/' . self::GITHUB_REPOSITORY,
            'requires'      => '6.2',
            'requires_php'  => '7.4',
            'last_updated'  => $release['published_at'],
            'download_link' => $release['zipball_url'],
            'sections'      => array(
                'description' => 'Bendruomenių narių registracija knygos skyrių įgarsinimui su rezervacijomis ir administravimo lentele.',
                'installation' => 'Atnaujinimą galima įdiegti WordPress administracijos skiltyje „Atnaujinimai“ arba įskiepių puslapyje.',
                'changelog'   => $release['body'],
            ),
        );
    }

    public function normalize_update_source( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( is_wp_error( $source ) || ! is_string( $source ) || ! is_string( $remote_source ) || empty( $hook_extra['plugin'] ) || $this->plugin_basename() !== $hook_extra['plugin'] ) {
            return $source;
        }

        $expected_source = trailingslashit( $remote_source ) . self::PLUGIN_SLUG;
        if ( untrailingslashit( $source ) === untrailingslashit( $expected_source ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $expected_source ) ) {
            return new WP_Error( 'kir_update_folder', 'Atnaujinimo paketo aplanko paruošti nepavyko.' );
        }

        return $expected_source;
    }

    public static function activate() {
        self::create_table();
        if ( false === get_option( self::OPTION_TEXTS, false ) ) {
            add_option( self::OPTION_TEXTS, self::default_texts(), '', false );
        }
        if ( false === get_option( self::OPTION_CONGREGATIONS, false ) ) {
            add_option( self::OPTION_CONGREGATIONS, self::congregations(), '', false );
        }
        update_option( self::OPTION_DB_VER, self::DB_VERSION, false );
    }

    private static function create_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'kir_reservations';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            full_name varchar(190) NOT NULL,
            email varchar(190) NOT NULL,
            congregation varchar(100) NOT NULL,
            chapter smallint(5) unsigned NOT NULL,
            owner_token_hash char(64) DEFAULT NULL,
            created_at datetime NOT NULL,
            summary_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
            audio_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY chapter (chapter),
            KEY congregation (congregation),
            KEY owner_token_hash (owner_token_hash),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    public function maybe_upgrade() {
        if ( self::DB_VERSION !== get_option( self::OPTION_DB_VER ) ) {
            self::create_table();
            $this->migrate_texts_to_current();
            if ( false === get_option( self::OPTION_CONGREGATIONS, false ) ) {
                add_option( self::OPTION_CONGREGATIONS, self::congregations(), '', false );
            }
            update_option( self::OPTION_DB_VER, self::DB_VERSION, false );
        }
    }

    private function migrate_texts_to_current() {
        $saved = get_option( self::OPTION_TEXTS, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        $legacy_defaults = array(
            'form_title'      => 'Knygos skyrių įgarsinimo registracija',
            'intro_text'      => 'Pasirinkite savo bendruomenę ir vieną iš jai priskirtų dar laisvų knygos skyrių.',
            'success_message' => 'Ačiū! {chapter} skyrius sėkmingai rezervuotas.',
        );

        $v110_defaults = array(
            'intro_text'      => 'Pasirinkite savo bendruomenę ir vieną jai priskirtą knygos „Marijos Sūnaus gyvenimas“ skyrių, kurį norite įgarsinti.',
            'chapter_label'   => 'Skyrius',
            'submit_button'   => 'Pasirinkti skyrių',
            'success_message' => 'Ačiū! {chapter} skyrius – {chapter_title} sėkmingai rezervuotas.',
        );
        $v120_defaults = array(
            'reader_hint'  => 'Pasirinkus skyrių, skaityklė automatiškai atvers to skyriaus pradžią.',
            'chapter_help' => 'Galite pasirinkti vieną arba kelis laisvus skyrius.',
        );
        $v151_defaults = array(
            'reader_hint'  => 'Paspauskite „Peržiūrėti“ prie skyriaus – skaityklė atvers jo pradžią.',
            'chapter_help' => 'Pažymėkite vieną arba kelis laisvus skyrius. „Peržiūrėti“ atvers pasirinkto skyriaus pradžią knygoje.',
        );
        $new_defaults = self::default_texts();

        // Nuo 1.5.0 PDF nebepakuojamas su įskiepiu. Naudojamas svetainėje jau esantis
        // atnaujintas Marijos-Sunaus-gyvenimas.pdf failas, todėl atnaujinant iš 1.3/1.4
        // automatiškai pakeičiame ankstesnį įskiepio failo URL į svetainės PDF URL.
        if ( isset( $saved['pdf_url'] ) && false !== strpos( (string) $saved['pdf_url'], '/knygos-igarsinimo-registracija/assets/Marijos-Sunaus-gyvenimas.pdf' ) ) {
            $saved['pdf_url'] = self::site_pdf_url();
        }

        foreach ( array( $legacy_defaults, $v110_defaults, $v120_defaults, $v151_defaults ) as $old_defaults ) {
            foreach ( $old_defaults as $key => $old_value ) {
                if ( isset( $saved[ $key ] ) && $saved[ $key ] === $old_value ) {
                    $saved[ $key ] = $new_defaults[ $key ];
                }
            }
        }

        $saved = wp_parse_args( $saved, $new_defaults );
        update_option( self::OPTION_TEXTS, $saved, false );
    }

    /**
     * Bendruomenių ir joms priskirtų skyrių duomenys.
     * Lankytojų skaičius sąmoningai nesaugomas ir nerodomas.
     */
    public static function congregations() {
        return array(
            'Biržai'       => array( 1, 2 ),
            'Daunoriai'    => array( 3, 4, 5, 6 ),
            'Jonava'       => array( 7 ),
            'Kaunas'       => range( 8, 27 ),
            'Kėdainiai'    => array( 28, 29 ),
            'Klaipėda'     => range( 30, 41 ),
            'Marijampolė'  => array( 42 ),
            'Mažeikiai'    => range( 43, 45 ),
            'Panevėžys'    => range( 46, 51 ),
            'Plungė'       => array( 52, 53 ),
            'Skuodas'      => array( 54, 55 ),
            'Telšiai'      => array( 56 ),
            'Šiauliai'     => range( 57, 65 ),
            'Tauragė'      => range( 66, 70 ),
            'Vilkaviškis'  => array( 71 ),
            'Vilnius'      => range( 72, 83 ),
            'Ukmergė'      => array( 84, 85 ),
            'Širvintos'    => array( 86, 87 ),
        );
    }

    /**
     * Grąžina administracijoje išsaugotą bendruomenių ir skyrių priskyrimą.
     * Jei senesnėje versijoje priskyrimas dar nebuvo išsaugotas arba dalis
     * duomenų yra netinkama, trūkstami skyriai grąžinami į numatytą vietą.
     */
    private function get_congregations() {
        $defaults = self::congregations();
        $saved    = get_option( self::OPTION_CONGREGATIONS, array() );

        if ( ! is_array( $saved ) ) {
            return $defaults;
        }

        $congregations = array();
        foreach ( array_keys( $defaults ) as $name ) {
            $congregations[ $name ] = array();
        }

        $assigned = array();
        foreach ( $saved as $name => $chapters ) {
            $name = sanitize_text_field( (string) $name );
            if ( ! isset( $congregations[ $name ] ) || ! is_array( $chapters ) ) {
                continue;
            }

            foreach ( $chapters as $chapter ) {
                $chapter = absint( $chapter );
                if ( $chapter < 1 || $chapter > count( self::chapter_data() ) || isset( $assigned[ $chapter ] ) ) {
                    continue;
                }
                $congregations[ $name ][] = $chapter;
                $assigned[ $chapter ]     = true;
            }
        }

        foreach ( $defaults as $name => $chapters ) {
            foreach ( $chapters as $chapter ) {
                if ( ! isset( $assigned[ $chapter ] ) ) {
                    $congregations[ $name ][] = $chapter;
                    $assigned[ $chapter ]     = true;
                }
            }
        }

        foreach ( $congregations as $name => $chapters ) {
            sort( $chapters, SORT_NUMERIC );
            $congregations[ $name ] = $chapters;
        }

        return $congregations;
    }

    private function get_congregation_by_chapter( $congregations ) {
        $by_chapter = array();
        foreach ( $congregations as $name => $chapters ) {
            foreach ( $chapters as $chapter ) {
                $by_chapter[ (int) $chapter ] = $name;
            }
        }
        return $by_chapter;
    }

    /**
     * Knygos „Marijos Sūnaus gyvenimas“ skyrių pavadinimai ir pradžios puslapiai.
     * Puslapiai paimti iš atnaujinto PDF outline (žymių) ir atitinka PDF puslapių numeraciją.
     */
    public static function chapter_data() {
        return array(
            1 => array( 'title' => '„Dievas su mumis“', 'page' => 8 ),
            2 => array( 'title' => 'Išrinktoji tauta', 'page' => 14 ),
            3 => array( 'title' => '„Laiko pilnatvė“', 'page' => 18 ),
            4 => array( 'title' => 'Jums gimė Išganytojas', 'page' => 22 ),
            5 => array( 'title' => 'Pašventimas', 'page' => 27 ),
            6 => array( 'title' => '„Mes matėme užtekant Jo žvaigždę“', 'page' => 32 ),
            7 => array( 'title' => 'Vaikystė', 'page' => 39 ),
            8 => array( 'title' => 'Velykinis Jeruzalės aplankymas', 'page' => 44 ),
            9 => array( 'title' => 'Kristaus vaikystės sunkumai', 'page' => 50 ),
            10 => array( 'title' => 'Balsas tyruose', 'page' => 56 ),
            11 => array( 'title' => 'Krikštas', 'page' => 66 ),
            12 => array( 'title' => 'Gundymas', 'page' => 70 ),
            13 => array( 'title' => 'Pergalė', 'page' => 76 ),
            14 => array( 'title' => '„Mes radome Mesiją!“', 'page' => 80 ),
            15 => array( 'title' => 'Vestuvių puotoje', 'page' => 88 ),
            16 => array( 'title' => 'Savo šventykloje', 'page' => 94 ),
            17 => array( 'title' => 'Nikodemas', 'page' => 102 ),
            18 => array( 'title' => '„Jam skirta augti, o man – mažėti“', 'page' => 108 ),
            19 => array( 'title' => 'Prie Jokūbo šulinio', 'page' => 112 ),
            20 => array( 'title' => '„Kol nepamatysite ženklų ir stebuklų“', 'page' => 121 ),
            21 => array( 'title' => 'Betzata ir aukščiausiojo teismo taryba', 'page' => 125 ),
            22 => array( 'title' => 'Jono įkalinimas ir mirtis', 'page' => 134 ),
            23 => array( 'title' => '„Prisiartino Dievo karalystė“', 'page' => 144 ),
            24 => array( 'title' => '„Ar Jis ne dailidės Sūnus?“', 'page' => 148 ),
            25 => array( 'title' => 'Mokinių pašaukimas', 'page' => 154 ),
            26 => array( 'title' => 'Kafarnaume', 'page' => 161 ),
            27 => array( 'title' => '„Tu gali mane apvalyti“', 'page' => 169 ),
            28 => array( 'title' => 'Levis Matas', 'page' => 177 ),
            29 => array( 'title' => 'Šabo (sabatos) diena', 'page' => 184 ),
            30 => array( 'title' => '„Jis paskyrė Dvylika“', 'page' => 190 ),
            31 => array( 'title' => 'Kalno pamokslas', 'page' => 196 ),
            32 => array( 'title' => '„Šimtininkas“', 'page' => 208 ),
            33 => array( 'title' => '„Kas yra Mano broliai?“', 'page' => 212 ),
            34 => array( 'title' => 'Pašaukimas', 'page' => 218 ),
            35 => array( 'title' => '„Nutilk, nurimk!“', 'page' => 222 ),
            36 => array( 'title' => 'Tikėjimo prisilietimas', 'page' => 228 ),
            37 => array( 'title' => 'Pirmieji evangelistai', 'page' => 232 ),
            38 => array( 'title' => '„Eikite ir truputį pailsėkite“', 'page' => 240 ),
            39 => array( 'title' => '„Jūs duokite jiems valgyti!“', 'page' => 244 ),
            40 => array( 'title' => 'Naktis ežere', 'page' => 250 ),
            41 => array( 'title' => 'Krizė Galilėjoje', 'page' => 256 ),
            42 => array( 'title' => 'Papročiai', 'page' => 266 ),
            43 => array( 'title' => 'Pralaužtos užtvaros', 'page' => 272 ),
            44 => array( 'title' => 'Tikrasis ženklas', 'page' => 276 ),
            45 => array( 'title' => 'Pranašavimas apie kryžių', 'page' => 280 ),
            46 => array( 'title' => 'Jėzaus atsimainymas', 'page' => 286 ),
            47 => array( 'title' => 'Tarnavimas', 'page' => 290 ),
            48 => array( 'title' => 'Kas didžiausias?', 'page' => 294 ),
            49 => array( 'title' => 'Palapinių šventėje', 'page' => 304 ),
            50 => array( 'title' => 'Spąstuose', 'page' => 310 ),
            51 => array( 'title' => '„Gyvenimo šviesa“', 'page' => 318 ),
            52 => array( 'title' => 'Dieviškasis Ganytojas', 'page' => 328 ),
            53 => array( 'title' => 'Paskutinė kelionė iš Galilėjos', 'page' => 334 ),
            54 => array( 'title' => 'Gailestingasis samarietis', 'page' => 342 ),
            55 => array( 'title' => 'Dievo karalystė ateina nepastebimai', 'page' => 348 ),
            56 => array( 'title' => 'Vaikų laiminimas', 'page' => 352 ),
            57 => array( 'title' => '„Vieno dalyko tau trūksta“', 'page' => 356 ),
            58 => array( 'title' => '„Lozoriau, išeik!“', 'page' => 360 ),
            59 => array( 'title' => 'Kunigų suokalbiai', 'page' => 370 ),
            60 => array( 'title' => 'Naujosios karalystės įstatymas', 'page' => 374 ),
            61 => array( 'title' => 'Zachiejus', 'page' => 378 ),
            62 => array( 'title' => 'Vaišės Simono namuose', 'page' => 382 ),
            63 => array( 'title' => '„Štai atvyksta tavo karalius“', 'page' => 392 ),
            64 => array( 'title' => 'Pasmerktoji tauta', 'page' => 400 ),
            65 => array( 'title' => 'Vėl apvalyta šventykla', 'page' => 406 ),
            66 => array( 'title' => 'Nesutarimas', 'page' => 416 ),
            67 => array( 'title' => 'Vargas fariziejams', 'page' => 422 ),
            68 => array( 'title' => 'Išoriniame kieme', 'page' => 430 ),
            69 => array( 'title' => 'Ant Alyvų kalno', 'page' => 436 ),
            70 => array( 'title' => '„Vienam iš šitų mažiausiųjų Mano brolių“', 'page' => 444 ),
            71 => array( 'title' => 'Tarnų Tarnas', 'page' => 448 ),
            72 => array( 'title' => '„Mano atminimui“', 'page' => 454 ),
            73 => array( 'title' => '„Tegul neišsigąsta jūsų širdys!“', 'page' => 462 ),
            74 => array( 'title' => 'Getsemanė', 'page' => 476 ),
            75 => array( 'title' => 'Priešais Aną ir Kajafo teisme', 'page' => 486 ),
            76 => array( 'title' => 'Judas', 'page' => 498 ),
            77 => array( 'title' => 'Piloto teismo salėje', 'page' => 504 ),
            78 => array( 'title' => 'Golgota', 'page' => 518 ),
            79 => array( 'title' => '„Atlikta“', 'page' => 530 ),
            80 => array( 'title' => 'Juozapo kape', 'page' => 536 ),
            81 => array( 'title' => '„Viešpats prisikėlė!“', 'page' => 546 ),
            82 => array( 'title' => '„Moterie, ko verki?“', 'page' => 552 ),
            83 => array( 'title' => 'Kelionė į Emausą', 'page' => 558 ),
            84 => array( 'title' => '„Ramybė jums!“', 'page' => 562 ),
            85 => array( 'title' => 'Vėl prie ežero', 'page' => 568 ),
            86 => array( 'title' => 'Eikite, mokykite visų tautų žmones', 'page' => 574 ),
            87 => array( 'title' => '„Pas savo Tėvą ir jūsų Tėvą“', 'page' => 582 ),
        );
    }

    private static function chapter_title( $chapter ) {
        $data = self::chapter_data();
        $chapter = absint( $chapter );
        return isset( $data[ $chapter ]['title'] ) ? $data[ $chapter ]['title'] : '';
    }

    private static function chapter_page( $chapter ) {
        $data = self::chapter_data();
        $chapter = absint( $chapter );
        return isset( $data[ $chapter ]['page'] ) ? (int) $data[ $chapter ]['page'] : 1;
    }

    private static function site_pdf_url() {
        $uploads = wp_upload_dir( null, false );
        if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) ) {
            return trailingslashit( $uploads['baseurl'] ) . 'Marijos-Sunaus-gyvenimas.pdf';
        }
        return content_url( 'uploads/Marijos-Sunaus-gyvenimas.pdf' );
    }

    private static function default_texts() {
        return array(
            'form_title'             => '„Marijos Sūnaus gyvenimas“ – įgarsinimo registracija',
            'intro_text'             => 'Pasirinkite savo bendruomenę ir vieną ar kelis jai priskirtus knygos „Marijos Sūnaus gyvenimas“ skyrius, kuriuos norite įgarsinti.',
            'book_link_text'         => 'Knygos puslapis Adventistai.lt',
            'book_page_url'          => 'https://adventistai.lt/marijos-sunaus-gyvenimas/',
            'pdf_url'                => self::site_pdf_url(),
            'reader_title'           => 'Knyga „Marijos Sūnaus gyvenimas“',
            'reader_hint'            => 'Žemiau galite peržiūrėti visą knygą ir susirasti savo pasirinktą skyrių.',
            'fullscreen_button'      => 'Per visą ekraną',
            'open_pdf_button'        => 'Atidaryti PDF',
            'name_label'             => 'Vardas ir pavardė',
            'email_label'            => 'El. paštas',
            'congregation_label'     => 'Bendruomenė',
            'chapter_label'          => 'Skyriai',
            'chapter_help'           => 'Pažymėkite vieną arba kelis laisvus skyrius.',
            'select_chapter_label'    => 'Pasirinkti',
            'choose_congregation'    => 'Pasirinkite bendruomenę',
            'choose_chapter'         => 'Pirmiausia pasirinkite bendruomenę',
            'loading_text'           => 'Kraunama…',
            'submit_button'          => 'Pasirinkti skyrius',
            'reserved_suffix'        => 'jau pasirinktas',
            'full_suffix'            => 'visos vietos pasirinktos',
            'success_message'        => 'Ačiū! Sėkmingai rezervavote: {chapter_list}.',
            'duplicate_message'      => 'Bent vieną iš pasirinktų skyrių ką tik pasirinko kitas žmogus. Nė vienas jūsų pasirinkimas nebuvo išsaugotas – pasirinkite dar kartą.',
            'selection_required'     => 'Pasirinkite bent vieną laisvą skyrių.',
            'invalid_message'        => 'Patikrinkite įvestus duomenis ir bandykite dar kartą.',
            'privacy_text'           => 'Jūsų vardas, pavardė ir el. pašto adresas bus naudojami tik šios įgarsinimo registracijos administravimui.',
            'my_selection_title'     => 'Jūsų pasirinkimas',
            'my_selection_intro'     => 'Šiame įrenginyje išsaugoti jūsų rezervuoti skyriai:',
            'cancel_button'          => 'Atsisakyti',
            'cancel_confirm'         => 'Ar tikrai norite atsisakyti visų šiame įrenginyje išsaugotų pasirinktų skyrių?',
            'cancel_success'         => 'Jūsų pasirinkimas atšauktas. Skyriai vėl laisvi.',
        );
    }

    private function get_texts() {
        $saved = get_option( self::OPTION_TEXTS, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, self::default_texts() );
    }

    private static function default_google_sheets_settings() {
        return array(
            'enabled'      => 0,
            'endpoint_url' => '',
            'secret'       => '',
        );
    }

    private function get_google_sheets_settings() {
        $saved = get_option( self::OPTION_GOOGLE_SHEETS, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, self::default_google_sheets_settings() );
    }

    public function sanitize_google_sheets_settings( $input ) {
        $current = $this->get_google_sheets_settings();
        $input   = is_array( $input ) ? $input : array();
        $secret  = isset( $input['secret'] ) ? sanitize_text_field( wp_unslash( $input['secret'] ) ) : '';

        return array(
            'enabled'      => ! empty( $input['enabled'] ) ? 1 : 0,
            'endpoint_url' => isset( $input['endpoint_url'] ) ? esc_url_raw( $input['endpoint_url'], array( 'https' ) ) : '',
            // Tuščias laukas nekeičia jau išsaugoto tokeno.
            'secret'       => '' !== $secret ? $secret : (string) $current['secret'],
        );
    }

    private function google_sheets_configured() {
        $settings = $this->get_google_sheets_settings();
        return ! empty( $settings['enabled'] ) && ! empty( $settings['endpoint_url'] ) && ! empty( $settings['secret'] );
    }

    private function set_google_sheets_status( $status, $message ) {
        update_option(
            self::OPTION_GOOGLE_SHEETS_STATUS,
            array(
                'status'  => sanitize_key( $status ),
                'message' => sanitize_text_field( $message ),
                'at'      => current_time( 'mysql' ),
            ),
            false
        );
    }

    private function google_sheets_request( $payload ) {
        if ( ! $this->google_sheets_configured() ) {
            return false;
        }

        $settings          = $this->get_google_sheets_settings();
        $payload['token']  = (string) $settings['secret'];
        $encoded_payload   = wp_json_encode( $payload );
        $response           = wp_remote_post(
            $settings['endpoint_url'],
            array(
                'timeout'     => 5,
                'redirection' => 3,
                'headers'     => array(
                    'Accept'     => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'User-Agent' => 'KIR-Google-Sheets/' . self::VERSION,
                ),
                'body'        => $encoded_payload,
                'data_format' => 'body',
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->set_google_sheets_status( 'error', 'Google Sheets sinchronizacija nepavyko: ' . $response->get_error_message() );
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['ok'] ) ) {
            $message = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'Nežinomas Apps Script atsakymas.';
            $this->set_google_sheets_status( 'error', 'Google Sheets sinchronizacija nepavyko: ' . $message );
            return false;
        }

        $this->set_google_sheets_status( 'success', 'Google Sheets sinchronizacija atlikta.' );
        return true;
    }

    private function reservation_to_google_sheet_row( $row ) {
        $get = static function ( $key ) use ( $row ) {
            if ( is_object( $row ) && isset( $row->{$key} ) ) {
                return $row->{$key};
            }
            if ( is_array( $row ) && isset( $row[ $key ] ) ) {
                return $row[ $key ];
            }
            return '';
        };

        $chapter = absint( $get( 'chapter' ) );
        return array(
            'id'            => absint( $get( 'id' ) ),
            'created_at'    => (string) $get( 'created_at' ),
            'full_name'     => (string) $get( 'full_name' ),
            'email'         => (string) $get( 'email' ),
            'congregation'  => (string) $get( 'congregation' ),
            'chapter'       => $chapter,
            'chapter_title' => self::chapter_title( $chapter ),
            'summary_sent'  => ! empty( $get( 'summary_sent' ) ) ? 'Taip' : 'Ne',
            'audio_sent'    => ! empty( $get( 'audio_sent' ) ) ? 'Taip' : 'Ne',
            'updated_at'    => current_time( 'mysql' ),
            'status'        => 'Rezervuota',
        );
    }

    private function sync_reservation_ids_to_google_sheets( $ids ) {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        if ( empty( $ids ) || ! $this->google_sheets_configured() ) {
            return false;
        }

        $id_list = implode( ',', $ids );
        $rows    = $wpdb->get_results( "SELECT id, created_at, full_name, email, congregation, chapter, summary_sent, audio_sent FROM {$this->table_name} WHERE id IN ({$id_list}) ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $items   = array();
        foreach ( (array) $rows as $row ) {
            $items[] = $this->reservation_to_google_sheet_row( $row );
        }

        return empty( $items ) ? true : $this->google_sheets_request( array( 'action' => 'upsert', 'rows' => $items ) );
    }

    private function sync_all_reservations_to_google_sheets() {
        global $wpdb;

        if ( ! $this->google_sheets_configured() ) {
            $this->set_google_sheets_status( 'error', 'Pirmiausia įrašykite Apps Script URL ir slaptą tokeną.' );
            return false;
        }

        $rows  = $wpdb->get_results( "SELECT id, created_at, full_name, email, congregation, chapter, summary_sent, audio_sent FROM {$this->table_name} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $items = array();
        foreach ( (array) $rows as $row ) {
            $items[] = $this->reservation_to_google_sheet_row( $row );
        }

        return $this->google_sheets_request( array( 'action' => 'upsert', 'rows' => $items ) );
    }

    private function delete_reservation_ids_from_google_sheets( $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        if ( empty( $ids ) || ! $this->google_sheets_configured() ) {
            return false;
        }

        return $this->google_sheets_request( array( 'action' => 'delete', 'ids' => $ids ) );
    }

    public function register_public_assets() {
        wp_register_style(
            'kir-public',
            plugins_url( 'assets/public.css', __FILE__ ),
            array(),
            self::VERSION
        );

        wp_register_script(
            'kir-public',
            plugins_url( 'assets/public.js', __FILE__ ),
            array(),
            self::VERSION,
            true
        );
    }

    public function enqueue_admin_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $page, array( 'kir-registrations', 'kir-assignments' ), true ) ) {
            return;
        }

        wp_enqueue_style(
            'kir-admin',
            plugins_url( 'assets/admin.css', __FILE__ ),
            array(),
            self::VERSION
        );

        if ( 'kir-registrations' === $page ) {
            wp_enqueue_script(
                'kir-admin',
                plugins_url( 'assets/admin.js', __FILE__ ),
                array(),
                self::VERSION,
                true
            );
        }
    }

    private function get_reserved_chapters() {
        global $wpdb;

        $rows = $wpdb->get_col( "SELECT chapter FROM {$this->table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        return array_map( 'intval', (array) $rows );
    }

    private function congregation_label( $name, $chapters, $is_full, $texts ) {
        $count = count( $chapters );
        $range = $this->format_chapter_range( $chapters );
        $label = sprintf( '%s (%d) — sk. %s', $name, $count, $range );

        if ( $is_full ) {
            $label .= ' — ' . $texts['full_suffix'];
        }

        return $label;
    }

    private function format_chapter_range( $chapters ) {
        $chapters = array_values( array_map( 'intval', $chapters ) );
        if ( 1 === count( $chapters ) ) {
            return (string) $chapters[0];
        }
        return $chapters[0] . '–' . $chapters[ count( $chapters ) - 1 ];
    }

    public function render_shortcode() {
        $texts         = $this->get_texts();
        $congregations = $this->get_congregations();
        $reserved      = array_flip( $this->get_reserved_chapters() );
        $pdf_url       = esc_url_raw( $texts['pdf_url'], array( 'http', 'https' ) );
        $reader_pdf_url = $pdf_url;
        $book_page_url = esc_url_raw( $texts['book_page_url'], array( 'http', 'https' ) );

        wp_enqueue_style( 'kir-public' );
        wp_enqueue_script( 'kir-public' );

        // Skyrių duomenis įdedame tiesiai prie formos. Taip bendruomenės pasirinkimas
        // suveikia iškart ir nepriklauso nuo admin-ajax atsako ar talpyklos/WAF taisyklių.
        // Serverio AJAX vis tiek atnaujina rezervacijų būseną ir galutinai validuoja įrašymą.
        $chapters_by_congregation = array();
        foreach ( $congregations as $congregation_name => $assigned_chapters ) {
            $items = array();
            foreach ( $assigned_chapters as $chapter_number ) {
                $items[] = array(
                    'number'   => (int) $chapter_number,
                    'title'    => self::chapter_title( $chapter_number ),
                    'page'     => self::chapter_page( $chapter_number ),
                    'reserved' => isset( $reserved[ $chapter_number ] ),
                );
            }
            $chapters_by_congregation[ $congregation_name ] = $items;
        }

        $public_config = array(
            'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
            'nonce'                  => wp_create_nonce( self::NONCE_ACTION ),
            'chooseChapter'          => $texts['choose_chapter'],
            'loadingText'            => $texts['loading_text'],
            'reservedSuffix'         => $texts['reserved_suffix'],
            'fullSuffix'             => $texts['full_suffix'],
            'invalidMessage'         => $texts['invalid_message'],
            'selectionRequired'      => $texts['selection_required'],
            'selectChapterLabel'     => $texts['select_chapter_label'],
            'cancelConfirm'          => $texts['cancel_confirm'],
            'cancelSuccess'          => $texts['cancel_success'],
            'chaptersByCongregation' => $chapters_by_congregation,
        );


        $form_id = wp_unique_id( 'kir_form_' );

        ob_start();
        ?>
        <div class="kir-wrap">
            <script type="application/json" class="kir-config"><?php echo wp_json_encode( $public_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
            <div class="kir-layout">
                <div class="kir-card kir-form-card">
                    <h2 class="kir-title"><?php echo esc_html( $texts['form_title'] ); ?></h2>

                    <?php if ( '' !== trim( $texts['intro_text'] ) ) : ?>
                        <div class="kir-intro"><?php echo nl2br( esc_html( $texts['intro_text'] ) ); ?></div>
                    <?php endif; ?>

                    <?php if ( $book_page_url && '' !== trim( $texts['book_link_text'] ) ) : ?>
                        <p class="kir-book-link">
                            <a href="<?php echo esc_url( $book_page_url ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html( $texts['book_link_text'] ); ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <form class="kir-form" id="<?php echo esc_attr( $form_id ); ?>" method="post" novalidate>
                        <div class="kir-field">
                            <label for="<?php echo esc_attr( $form_id . '_full_name' ); ?>"><?php echo esc_html( $texts['name_label'] ); ?></label>
                            <input id="<?php echo esc_attr( $form_id . '_full_name' ); ?>" class="kir-full-name" type="text" name="full_name" autocomplete="name" maxlength="190" required>
                        </div>

                        <div class="kir-field">
                            <label for="<?php echo esc_attr( $form_id . '_email' ); ?>"><?php echo esc_html( $texts['email_label'] ); ?></label>
                            <input id="<?php echo esc_attr( $form_id . '_email' ); ?>" type="email" name="email" autocomplete="email" maxlength="190" required>
                        </div>

                        <div class="kir-field">
                            <label for="<?php echo esc_attr( $form_id . '_congregation' ); ?>"><?php echo esc_html( $texts['congregation_label'] ); ?></label>
                            <select id="<?php echo esc_attr( $form_id . '_congregation' ); ?>" name="congregation" class="kir-congregation" required>
                                <option value=""><?php echo esc_html( $texts['choose_congregation'] ); ?></option>
                                <?php foreach ( $congregations as $name => $chapters ) : ?>
                                    <?php
                                    $all_reserved = true;
                                    foreach ( $chapters as $chapter ) {
                                        if ( ! isset( $reserved[ $chapter ] ) ) {
                                            $all_reserved = false;
                                            break;
                                        }
                                    }
                                    ?>
                                    <option
                                        value="<?php echo esc_attr( $name ); ?>"
                                        data-base-label="<?php echo esc_attr( sprintf( '%s (%d) — sk. %s', $name, count( $chapters ), $this->format_chapter_range( $chapters ) ) ); ?>"
                                        <?php disabled( $all_reserved ); ?>
                                    ><?php echo esc_html( $this->congregation_label( $name, $chapters, $all_reserved, $texts ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="kir-congregation-info" aria-live="polite"></div>
                        </div>

                        <fieldset class="kir-field kir-chapter-fieldset">
                            <legend><?php echo esc_html( $texts['chapter_label'] ); ?></legend>
                            <?php if ( '' !== trim( $texts['chapter_help'] ) ) : ?>
                                <p class="kir-chapter-help"><?php echo esc_html( $texts['chapter_help'] ); ?></p>
                            <?php endif; ?>
                            <div id="<?php echo esc_attr( $form_id . '_chapters' ); ?>" class="kir-chapters" aria-live="polite">
                                <p class="kir-chapters-placeholder"><?php echo esc_html( $texts['choose_chapter'] ); ?></p>
                            </div>
                        </fieldset>

                        <div class="kir-honeypot" aria-hidden="true">
                            <label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
                        </div>

                        <?php if ( '' !== trim( $texts['privacy_text'] ) ) : ?>
                            <div class="kir-privacy"><?php echo nl2br( esc_html( $texts['privacy_text'] ) ); ?></div>
                        <?php endif; ?>

                        <button type="submit" class="kir-submit"><?php echo esc_html( $texts['submit_button'] ); ?></button>
                        <div class="kir-message" role="status" aria-live="polite"></div>

                        <section class="kir-my-selection" hidden aria-live="polite">
                            <h3 class="kir-my-selection-title"><?php echo esc_html( $texts['my_selection_title'] ); ?></h3>
                            <?php if ( '' !== trim( $texts['my_selection_intro'] ) ) : ?>
                                <p class="kir-my-selection-intro"><?php echo esc_html( $texts['my_selection_intro'] ); ?></p>
                            <?php endif; ?>
                            <div class="kir-my-selection-list"></div>
                            <button type="button" class="kir-cancel-selection"><?php echo esc_html( $texts['cancel_button'] ); ?></button>
                        </section>
                    </form>
                </div>

                <?php if ( $pdf_url ) : ?>
                    <div class="kir-card kir-reader-card">
                        <div class="kir-reader-head">
                            <div>
                                <h2 class="kir-reader-title"><?php echo esc_html( $texts['reader_title'] ); ?></h2>
                                <?php if ( '' !== trim( $texts['reader_hint'] ) ) : ?>
                                    <p class="kir-reader-hint"><?php echo esc_html( $texts['reader_hint'] ); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="kir-reader-actions">
                                <button type="button" class="kir-reader-fullscreen"><?php echo esc_html( $texts['fullscreen_button'] ); ?></button>
                                <a class="kir-reader-open" href="<?php echo esc_url( $reader_pdf_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $texts['open_pdf_button'] ); ?></a>
                            </div>
                        </div>
                        <div class="kir-reader-frame-wrap">
                            <iframe
                                class="kir-reader-frame"
                                src="<?php echo esc_url( $reader_pdf_url ); ?>"
                                title="<?php echo esc_attr( $texts['reader_title'] ); ?>"
                                loading="eager"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen
                            ></iframe>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_get_chapters() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Saugumo patikra nepavyko. Atnaujinkite puslapį ir bandykite dar kartą.' ), 403 );
        }

        $congregation = isset( $_POST['congregation'] ) ? sanitize_text_field( wp_unslash( $_POST['congregation'] ) ) : '';
        $all           = $this->get_congregations();

        if ( ! isset( $all[ $congregation ] ) ) {
            wp_send_json_error( array( 'message' => $this->get_texts()['invalid_message'] ), 400 );
        }

        $chapters = $all[ $congregation ];
        $reserved = array_flip( $this->get_reserved_chapters() );
        $items    = array();
        $free     = 0;

        foreach ( $chapters as $chapter ) {
            $is_reserved = isset( $reserved[ $chapter ] );
            if ( ! $is_reserved ) {
                $free++;
            }
            $items[] = array(
                'number'   => (int) $chapter,
                'title'    => self::chapter_title( $chapter ),
                'page'     => self::chapter_page( $chapter ),
                'reserved' => $is_reserved,
            );
        }

        wp_send_json_success(
            array(
                'chapters' => $items,
                'count'    => count( $chapters ),
                'range'    => $this->format_chapter_range( $chapters ),
                'free'     => $free,
                'full'     => 0 === $free,
            )
        );
    }

    private function normalize_owner_token( $token ) {
        $token = is_string( $token ) ? strtolower( trim( $token ) ) : '';
        return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
    }

    private function new_owner_token() {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            return hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) . wp_rand() );
        }
    }

    private function owner_token_hash( $token ) {
        return hash( 'sha256', $token );
    }

    private function get_reservations_for_owner_token( $token ) {
        global $wpdb;

        $token = $this->normalize_owner_token( $token );
        if ( '' === $token ) {
            return array();
        }

        $hash = $this->owner_token_hash( $token );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT congregation, chapter FROM {$this->table_name} WHERE owner_token_hash = %s ORDER BY chapter ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
                $hash
            ),
            ARRAY_A
        );

        $items = array();
        foreach ( (array) $rows as $row ) {
            $chapter = absint( $row['chapter'] );
            $items[] = array(
                'congregation' => sanitize_text_field( $row['congregation'] ),
                'chapter'      => $chapter,
                'title'        => self::chapter_title( $chapter ),
            );
        }
        return $items;
    }

    public function ajax_get_my_reservations() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Saugumo patikra nepavyko. Atnaujinkite puslapį ir bandykite dar kartą.' ), 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_owner_token validates exact credential grammar below.
        $token_raw = isset( $_POST['owner_token'] ) ? wp_unslash( $_POST['owner_token'] ) : '';
        $token     = $this->normalize_owner_token( $token_raw );
        if ( '' === $token ) {
            wp_send_json_success( array( 'found' => false, 'reservations' => array() ) );
        }

        $items = $this->get_reservations_for_owner_token( $token );
        wp_send_json_success(
            array(
                'found'        => ! empty( $items ),
                'reservations' => $items,
            )
        );
    }

    public function ajax_cancel_my_reservations() {
        global $wpdb;

        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Saugumo patikra nepavyko. Atnaujinkite puslapį ir bandykite dar kartą.' ), 403 );
        }
        $texts = $this->get_texts();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_owner_token validates exact credential grammar below.
        $token_raw = isset( $_POST['owner_token'] ) ? wp_unslash( $_POST['owner_token'] ) : '';
        $token     = $this->normalize_owner_token( $token_raw );
        if ( '' === $token ) {
            wp_send_json_error( array( 'message' => $texts['invalid_message'] ), 400 );
        }

        $hash = $this->owner_token_hash( $token );
        $released_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, congregation, chapter FROM {$this->table_name} WHERE owner_token_hash = %s ORDER BY chapter ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
                $hash
            ),
            ARRAY_A
        );
        $congregations = array_values( array_unique( wp_list_pluck( (array) $released_rows, 'congregation' ) ) );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE owner_token_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
                $hash
            )
        );

        if ( false === $deleted ) {
            wp_send_json_error( array( 'message' => 'Pasirinkimo atšaukti nepavyko. Bandykite dar kartą.' ), 500 );
        }

        $this->delete_reservation_ids_from_google_sheets( wp_list_pluck( (array) $released_rows, 'id' ) );

        wp_send_json_success(
            array(
                'message'       => $texts['cancel_success'],
                'deleted'       => (int) $deleted,
                'congregations' => array_values( array_map( 'sanitize_text_field', (array) $congregations ) ),
                'released'      => array_values( array_map(
                    static function ( $row ) {
                        return array(
                            'congregation' => sanitize_text_field( $row['congregation'] ),
                            'chapter'      => absint( $row['chapter'] ),
                        );
                    },
                    (array) $released_rows
                ) ),
            )
        );
    }

    public function ajax_submit_reservation() {
        global $wpdb;

        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Saugumo patikra nepavyko. Atnaujinkite puslapį ir bandykite dar kartą.' ), 403 );
        }
        $texts = $this->get_texts();

        // Paprastas honeypot nuo automatinių formos pildymų.
        $honeypot = isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '';
        if ( '' !== $honeypot ) {
            wp_send_json_error( array( 'message' => $texts['invalid_message'] ), 400 );
        }

        $full_name     = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
        $email_raw     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $email         = sanitize_email( $email_raw );
        $congregation  = isset( $_POST['congregation'] ) ? sanitize_text_field( wp_unslash( $_POST['congregation'] ) ) : '';
        $chapters_raw  = isset( $_POST['chapters'] ) ? sanitize_text_field( wp_unslash( $_POST['chapters'] ) ) : '';
        $chapters      = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $chapters_raw ) ) ) ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact token grammar is validated below.
        $owner_token_raw = isset( $_POST['owner_token'] ) ? wp_unslash( $_POST['owner_token'] ) : '';
        if ( ! is_string( $owner_token_raw ) ) {
            wp_send_json_error( array( 'message' => $texts['invalid_message'] ), 400 );
        }
        $owner_token     = '' !== trim( (string) $owner_token_raw ) ? $this->normalize_owner_token( $owner_token_raw ) : '';

        if ( '' !== trim( (string) $owner_token_raw ) && '' === $owner_token ) {
            wp_send_json_error( array( 'message' => $texts['invalid_message'] ), 400 );
        }
        if ( '' === $owner_token ) {
            $owner_token = $this->new_owner_token();
        }
        $owner_token_hash = $this->owner_token_hash( $owner_token );

        sort( $chapters, SORT_NUMERIC );

        if ( '' === $full_name || ( function_exists( 'mb_strlen' ) ? mb_strlen( $full_name, 'UTF-8' ) : strlen( $full_name ) ) > 190 || ! is_email( $email ) || '' === $congregation || empty( $chapters ) ) {
            wp_send_json_error( array( 'message' => empty( $chapters ) ? $texts['selection_required'] : $texts['invalid_message'] ), 400 );
        }

        $congregations = $this->get_congregations();
        if ( ! isset( $congregations[ $congregation ] ) || count( $chapters ) > count( $congregations[ $congregation ] ) ) {
            wp_send_json_error( array( 'message' => $texts['invalid_message'] ), 400 );
        }

        foreach ( $chapters as $chapter ) {
            if ( ! in_array( $chapter, $congregations[ $congregation ], true ) ) {
                wp_send_json_error( array( 'message' => $texts['invalid_message'] ), 400 );
            }
        }

        // Keli skyriai registruojami kaip viena operacija. UNIQUE KEY ant chapter yra
        // galutinė apsauga nuo dvigubos rezervacijos net vienalaikių užklausų atveju.
        $wpdb->query( 'START TRANSACTION' );
        $inserted_ids = array();
        $failure      = null;

        foreach ( $chapters as $chapter ) {
            $inserted = $wpdb->insert(
                $this->table_name,
                array(
                    'full_name'    => $full_name,
                    'email'        => $email,
                    'congregation'    => $congregation,
                    'chapter'         => $chapter,
                    'owner_token_hash'=> $owner_token_hash,
                    'created_at'      => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%d', '%s', '%s' )
            );

            if ( false === $inserted ) {
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$this->table_name} WHERE chapter = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
                        $chapter
                    )
                );
                $failure = $exists ? 'duplicate' : 'database';
                break;
            }

            $inserted_ids[] = (int) $wpdb->insert_id;
        }

        if ( null !== $failure ) {
            $wpdb->query( 'ROLLBACK' );

            // Atsarginis valymas, jei DB lentelė būtų ne transakcinio tipo.
            foreach ( $inserted_ids as $inserted_id ) {
                $wpdb->delete( $this->table_name, array( 'id' => $inserted_id ), array( '%d' ) );
            }

            if ( 'duplicate' === $failure ) {
                wp_send_json_error(
                    array(
                        'message' => $texts['duplicate_message'],
                        'code'    => 'chapter_reserved',
                    ),
                    409
                );
            }

            wp_send_json_error( array( 'message' => 'Registracijos išsaugoti nepavyko. Bandykite dar kartą.' ), 500 );
        }

        $wpdb->query( 'COMMIT' );

        // Google Sheets ryšys yra papildomas veiksmas: jei jis laikinai nepasiekiamas,
        // pati WordPress registracija vis tiek lieka sėkminga.
        $this->sync_reservation_ids_to_google_sheets( $inserted_ids );

        $available_now = 0;
        $reserved      = array_flip( $this->get_reserved_chapters() );
        foreach ( $congregations[ $congregation ] as $assigned_chapter ) {
            if ( ! isset( $reserved[ $assigned_chapter ] ) ) {
                $available_now++;
            }
        }

        $titles     = array();
        $list_parts = array();
        foreach ( $chapters as $chapter ) {
            $title        = self::chapter_title( $chapter );
            $titles[]     = $title;
            $list_parts[] = $chapter . ' skyrius — ' . $title;
        }

        $success = strtr(
            $texts['success_message'],
            array(
                '{chapter}'        => (string) $chapters[0],
                '{chapter_title}'  => self::chapter_title( $chapters[0] ),
                '{chapters}'       => implode( ', ', array_map( 'strval', $chapters ) ),
                '{chapter_titles}' => implode( '; ', $titles ),
                '{chapter_list}'   => implode( '; ', $list_parts ),
                '{congregation}'   => $congregation,
                '{name}'           => $full_name,
            )
        );

        wp_send_json_success(
            array(
                'message'           => $success,
                'chapters'          => $chapters,
                'congregation'      => $congregation,
                'congregation_full' => 0 === $available_now,
                'remaining'         => $available_now,
                'owner_token'       => $owner_token,
            )
        );
    }

    public function admin_menu() {
        add_menu_page(
            'Įgarsinimo registracijos',
            'Įgarsinimo registracijos',
            'manage_options',
            'kir-registrations',
            array( $this, 'render_admin_registrations' ),
            'dashicons-microphone',
            58
        );

        add_submenu_page(
            'kir-registrations',
            'Registracijos',
            'Registracijos',
            'manage_options',
            'kir-registrations',
            array( $this, 'render_admin_registrations' )
        );

        add_submenu_page(
            'kir-registrations',
            'Formos tekstai',
            'Formos tekstai',
            'manage_options',
            'kir-texts',
            array( $this, 'render_admin_texts' )
        );

        add_submenu_page(
            'kir-registrations',
            'Skyrių priskyrimas',
            'Skyrių priskyrimas',
            'manage_options',
            'kir-assignments',
            array( $this, 'render_admin_assignments' )
        );

        add_submenu_page(
            'kir-registrations',
            'Google Sheets',
            'Google Sheets',
            'manage_options',
            'kir-google-sheets',
            array( $this, 'render_admin_google_sheets' )
        );
    }

    public function register_settings() {
        register_setting(
            'kir_texts_group',
            self::OPTION_TEXTS,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_text_settings' ),
                'default'           => self::default_texts(),
            )
        );

        register_setting(
            'kir_google_sheets_group',
            self::OPTION_GOOGLE_SHEETS,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_google_sheets_settings' ),
                'default'           => self::default_google_sheets_settings(),
            )
        );

    }

    public function sanitize_text_settings( $input ) {
        $defaults = self::default_texts();
        $clean    = array();
        $input    = is_array( $input ) ? $input : array();

        foreach ( $defaults as $key => $default ) {
            $value = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : $default;

            if ( in_array( $key, array( 'book_page_url', 'pdf_url' ), true ) ) {
                $clean[ $key ] = esc_url_raw( $value, array( 'http', 'https' ) );
            } elseif ( in_array( $key, array( 'intro_text', 'privacy_text' ), true ) ) {
                $clean[ $key ] = sanitize_textarea_field( $value );
            } else {
                $clean[ $key ] = sanitize_text_field( $value );
            }
        }

        return $clean;
    }

    public function sanitize_congregation_settings( $input ) {
        $current       = $this->get_congregations();
        $by_chapter    = $this->get_congregation_by_chapter( $current );
        $allowed_names = array_keys( self::congregations() );
        $input         = is_array( $input ) ? $input : array();
        $clean         = array_fill_keys( $allowed_names, array() );

        foreach ( self::chapter_data() as $chapter => $unused ) {
            $chapter = (int) $chapter;
            $name    = isset( $input[ $chapter ] ) ? sanitize_text_field( wp_unslash( $input[ $chapter ] ) ) : '';

            if ( ! in_array( $name, $allowed_names, true ) ) {
                $name = isset( $by_chapter[ $chapter ] ) ? $by_chapter[ $chapter ] : '';
            }
            if ( '' !== $name ) {
                $clean[ $name ][] = $chapter;
            }
        }

        foreach ( $clean as $name => $chapters ) {
            sort( $chapters, SORT_NUMERIC );
            $clean[ $name ] = $chapters;
        }

        return $clean;
    }

    private function admin_registration_sort_url( $key, $sort_key, $sort_order ) {
        $next_order = ( $key === $sort_key && 'ASC' === $sort_order ) ? 'DESC' : 'ASC';
        return add_query_arg(
            array(
                'page'    => 'kir-registrations',
                'orderby' => $key,
                'order'   => strtolower( $next_order ),
            ),
            admin_url( 'admin.php' )
        );
    }

    private function normalize_admin_datetime( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return false;
        }

        $formats = array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i' );
        foreach ( $formats as $format ) {
            $date = DateTime::createFromFormat( $format, $value );
            $errors = DateTime::getLastErrors();
            if ( $date instanceof DateTime && ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) ) ) {
                return $date->format( 'Y-m-d H:i:s' );
            }
        }

        return false;
    }

    private function show_reservation_conflict_confirmation( $updates, $conflicts ) {
        $form_action = admin_url( 'admin-post.php' );
        $back_url    = admin_url( 'admin.php?page=kir-registrations' );
        $html        = '<div class="wrap">';
        $html       .= '<h1>Patvirtinkite registracijos pakeitimą</h1>';
        $html       .= '<div class="notice notice-warning"><p>Keičiate jau rezervuoto skyriaus bendruomenę arba skyrių. Tai pakeis esamos rezervacijos duomenis ir bus perduota į Google Sheets, jei sinchronizacija įjungta.</p></div>';
        $html       .= '<ul>';

        foreach ( $conflicts as $conflict ) {
            $html .= '<li><strong>' . esc_html( $conflict['full_name'] ) . '</strong> — dabar: ' . esc_html( $conflict['old_congregation'] . ', ' . $conflict['old_chapter'] . ' skyrius' ) . '; naujai: ' . esc_html( $conflict['new_congregation'] . ', ' . $conflict['new_chapter'] . ' skyrius' ) . '.</li>';
        }

        $html .= '</ul>';
        $html .= '<p>Ar tikrai norite išsaugoti šį pakeitimą?</p>';
        $html .= '<form method="post" action="' . esc_url( $form_action ) . '">';
        $html .= '<input type="hidden" name="action" value="kir_update_reservations">';
        $html .= '<input type="hidden" name="confirm_conflicts" value="1">';

        foreach ( $updates as $update ) {
            $id = (int) $update['id'];
            foreach ( $update['fields'] as $field => $value ) {
                $html .= '<input type="hidden" name="reservations[' . esc_attr( (string) $id ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $value ) . '">';
            }
        }

        $html .= wp_nonce_field( 'kir_update_reservations', '_wpnonce', true, false );
        $html .= '<button type="submit" class="button button-primary">Taip, patvirtinti ir išsaugoti</button> ';
        $html .= '<a class="button" href="' . esc_url( $back_url ) . '">Atšaukti</a>';
        $html .= '</form></div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirmation form is assembled above with context-escaped values and a nonce field.
        wp_die( $html, 'Patvirtinkite registracijos pakeitimą', array( 'response' => 200 ) );
    }

    public function render_admin_registrations() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės peržiūrėti šio puslapio.', 'knygos-igarsinimo-registracija' ) );
        }

        global $wpdb;
        $texts         = $this->get_texts();
        $sort_columns  = array(
            'created_at'  => 'created_at',
            'full_name'   => 'full_name',
            'email'       => 'email',
            'congregation'=> 'congregation',
            'chapter'     => 'chapter',
            'summary_sent'=> 'summary_sent',
            'audio_sent'  => 'audio_sent',
        );
        $sort_key      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sort_key      = isset( $sort_columns[ $sort_key ] ) ? $sort_key : 'created_at';
        $sort_order    = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_sql     = $sort_columns[ $sort_key ] . ' ' . $sort_order;
        $rows          = $wpdb->get_results( "SELECT id, full_name, email, congregation, chapter, created_at, summary_sent, audio_sent FROM {$this->table_name} ORDER BY {$order_sql}, id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
        $congregations = $this->get_congregations();
        $chapter_data  = self::chapter_data();
        $reserved      = array_flip( $this->get_reserved_chapters() );

        $notice = isset( $_GET['kir_notice'] ) ? sanitize_key( wp_unslash( $_GET['kir_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1>Įgarsinimo registracijos</h1>

            <?php if ( 'released' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p>Rezervacija pašalinta, skyrius vėl laisvas.</p></div>
            <?php endif; ?>
            <?php if ( 'updated' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p>Registracijos duomenys išsaugoti.</p></div>
            <?php endif; ?>
            <?php if ( 'update_error' === $notice ) : ?>
                <div class="notice notice-error is-dismissible"><p>Registracijos duomenų išsaugoti nepavyko.</p></div>
            <?php endif; ?>

            <p>Formą į puslapį įdėkite naudodami shortcode:</p>
            <p><code>[<?php echo esc_html( self::SHORTCODE ); ?>]</code></p>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kir_export_xlsx' ), 'kir_export_xlsx' ) ); ?>">Eksportuoti į Excel (.xlsx)</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kir-texts' ) ); ?>">Redaguoti formos tekstus</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kir-assignments' ) ); ?>">Redaguoti skyrių priskyrimą</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kir-google-sheets' ) ); ?>">Google Sheets nustatymai</a>
            </p>

            <h2>Bendruomenių užimtumas</h2>
            <table class="widefat striped" style="max-width:900px;margin-bottom:24px">
                <thead>
                    <tr>
                        <th>Bendruomenė</th>
                        <th>Skyriai</th>
                        <th>Pasirinkta</th>
                        <th>Laisva</th>
                        <th>Būsena</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $congregations as $name => $chapters ) : ?>
                    <?php
                    $taken = 0;
                    foreach ( $chapters as $chapter ) {
                        if ( isset( $reserved[ $chapter ] ) ) {
                            $taken++;
                        }
                    }
                    $total = count( $chapters );
                    $free  = $total - $taken;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $name ); ?></strong></td>
                        <td><?php echo esc_html( $total . ' sk. (' . $this->format_chapter_range( $chapters ) . ')' ); ?></td>
                        <td><?php echo esc_html( (string) $taken ); ?></td>
                        <td><?php echo esc_html( (string) $free ); ?></td>
                        <td><?php echo 0 === $free ? esc_html( $texts['full_suffix'] ) : 'Yra laisvų vietų'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Registracijų duomenys</h2>
            <form id="kir-reservation-status-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kir_update_reservations">
                <?php wp_nonce_field( 'kir_update_reservations' ); ?>
            </form>
            <p>
                <button type="submit" form="kir-reservation-status-form" class="button button-primary">Išsaugoti visus pakeitimus</button>
                <span class="description">Pažymėjimai išsaugomi automatiškai juos pakeitus. Vardą, el. paštą ir kitus laukus išsaugokite šiuo mygtuku.</span>
            </p>
            <div class="kir-registrations-table-wrap">
                <table class="widefat striped kir-registrations-table">
                    <thead>
                        <tr>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'created_at' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'created_at', $sort_key, $sort_order ) ); ?>">Data <span class="screen-reader-text">rikiuoti</span></a></th>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'full_name' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'full_name', $sort_key, $sort_order ) ); ?>">Vardas ir pavardė</a></th>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'email' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'email', $sort_key, $sort_order ) ); ?>">El. paštas</a></th>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'congregation' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'congregation', $sort_key, $sort_order ) ); ?>">Bendruomenė</a></th>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'chapter' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'chapter', $sort_key, $sort_order ) ); ?>">Skyrius</a></th>
                            <th scope="col">Pavadinimas</th>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'summary_sent' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'summary_sent', $sort_key, $sort_order ) ); ?>">Santrauka išsiųsta</a></th>
                            <th scope="col" <?php /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request */ echo 'audio_sent' === $sort_key ? 'aria-sort="' . esc_attr( 'ASC' === $sort_order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="kir-sort-link" href="<?php echo esc_url( $this->admin_registration_sort_url( 'audio_sent', $sort_key, $sort_order ) ); ?>">Atsiuntė audio</a></th>
                            <th scope="col">Veiksmas</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="9">Registracijų dar nėra.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) : ?>
                            <?php $id = (int) $row->id; ?>
                            <tr>
                                <td>
                                    <input form="kir-reservation-status-form" class="kir-registration-edit kir-registration-edit--date" type="datetime-local" step="1" name="reservations[<?php echo esc_attr( (string) $id ); ?>][created_at]" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) $row->created_at, 0, 19 ) ) ); ?>" aria-label="Data pridėjimo" />
                                </td>
                                <td>
                                    <input form="kir-reservation-status-form" class="kir-registration-edit kir-registration-edit--name" type="text" name="reservations[<?php echo esc_attr( (string) $id ); ?>][full_name]" value="<?php echo esc_attr( $row->full_name ); ?>" maxlength="190" aria-label="Vardas ir pavardė" />
                                </td>
                                <td>
                                    <input form="kir-reservation-status-form" class="kir-registration-edit kir-registration-edit--email" type="email" name="reservations[<?php echo esc_attr( (string) $id ); ?>][email]" value="<?php echo esc_attr( $row->email ); ?>" maxlength="190" aria-label="El. paštas" />
                                </td>
                                <td>
                                    <select form="kir-reservation-status-form" class="kir-registration-edit kir-registration-edit--community" name="reservations[<?php echo esc_attr( (string) $id ); ?>][congregation]" aria-label="Bendruomenė">
                                        <?php foreach ( array_keys( $congregations ) as $congregation_name ) : ?>
                                            <option value="<?php echo esc_attr( $congregation_name ); ?>" <?php selected( $row->congregation, $congregation_name ); ?>><?php echo esc_html( $congregation_name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select form="kir-reservation-status-form" class="kir-registration-edit kir-registration-edit--chapter" name="reservations[<?php echo esc_attr( (string) $id ); ?>][chapter]" aria-label="Skyrius">
                                        <?php foreach ( $chapter_data as $chapter_number => $chapter ) : ?>
                                            <option value="<?php echo esc_attr( (string) $chapter_number ); ?>" <?php selected( (int) $row->chapter, (int) $chapter_number ); ?>><?php echo esc_html( $chapter_number . ' – ' . $chapter['title'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><?php echo esc_html( self::chapter_title( (int) $row->chapter ) ); ?><span class="description kir-registration-derived">automatiškai pagal skyrių</span></td>
                                <td>
                                    <label class="kir-status-toggle">
                                        <input form="kir-reservation-status-form" type="hidden" name="reservations[<?php echo esc_attr( (string) $id ); ?>][summary_sent]" value="0" />
                                        <input form="kir-reservation-status-form" type="checkbox" name="reservations[<?php echo esc_attr( (string) $id ); ?>][summary_sent]" value="1" data-kir-auto-save="1" <?php checked( 1, (int) $row->summary_sent ); ?> />
                                        <span class="kir-status-toggle__box" aria-hidden="true"></span>
                                        <span class="kir-status-toggle__state kir-status-toggle__state--yes">Taip</span>
                                        <span class="kir-status-toggle__state kir-status-toggle__state--no">Ne</span>
                                    </label>
                                </td>
                                <td>
                                    <label class="kir-status-toggle">
                                        <input form="kir-reservation-status-form" type="hidden" name="reservations[<?php echo esc_attr( (string) $id ); ?>][audio_sent]" value="0" />
                                        <input form="kir-reservation-status-form" type="checkbox" name="reservations[<?php echo esc_attr( (string) $id ); ?>][audio_sent]" value="1" data-kir-auto-save="1" <?php checked( 1, (int) $row->audio_sent ); ?> />
                                        <span class="kir-status-toggle__box" aria-hidden="true"></span>
                                        <span class="kir-status-toggle__state kir-status-toggle__state--yes">Taip</span>
                                        <span class="kir-status-toggle__state kir-status-toggle__state--no">Ne</span>
                                    </label>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Atlaisvinti šį skyrių?');">
                                        <input type="hidden" name="action" value="kir_release_reservation">
                                        <input type="hidden" name="reservation_id" value="<?php echo esc_attr( (string) $id ); ?>">
                                        <?php wp_nonce_field( 'kir_release_reservation_' . $id ); ?>
                                        <button type="submit" class="button button-small">Atlaisvinti</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="description">Skyriaus pavadinimas sugeneruojamas automatiškai pagal pasirinktą skyrių. Jei keičiate rezervuotą bendruomenę arba skyrių, sistema paprašys papildomo patvirtinimo.</p>
        </div>
        <?php
    }

    public function render_admin_assignments() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės peržiūrėti šio puslapio.', 'knygos-igarsinimo-registracija' ) );
        }

        $congregations      = $this->get_congregations();
        $by_chapter         = $this->get_congregation_by_chapter( $congregations );
        $congregation_names = array_keys( self::congregations() );
        $chapters           = self::chapter_data();
        $notice             = isset( $_GET['kir_notice'] ) ? sanitize_key( wp_unslash( $_GET['kir_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap kir-assignments-page">
            <h1>Skyrių priskyrimas bendruomenėms</h1>
            <?php if ( 'assignments_updated' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p>Skyrių priskyrimai išsaugoti. Bendruomenių suvestinė ir viešoji forma atnaujintos.</p></div>
            <?php endif; ?>
            <p>Pasirinkite, kuri bendruomenė įgarsins kiekvieną skyrių. Kiekvienas skyrius turi būti priskirtas vienai bendruomenei.</p>

            <h2>Bendruomenių suvestinė</h2>
            <table class="widefat striped kir-assignments-summary">
                <thead>
                    <tr>
                        <th scope="col">Bendruomenė</th>
                        <th scope="col">Skyrių skaičius</th>
                        <th scope="col">Priskirti skyriai</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $congregations as $name => $assigned_chapters ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $name ); ?></strong></td>
                        <td><?php echo esc_html( (string) count( $assigned_chapters ) ); ?></td>
                        <td><?php echo esc_html( implode( ', ', array_map( 'intval', $assigned_chapters ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Redaguoti skyrių priskyrimą</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kir_update_assignments">
                <?php wp_nonce_field( 'kir_update_assignments' ); ?>
                <table class="widefat striped kir-assignments-table">
                    <thead>
                        <tr>
                            <th scope="col">Skyrius</th>
                            <th scope="col">Pavadinimas</th>
                            <th scope="col">Bendruomenė</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $chapters as $chapter => $chapter_info ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( (string) intval( $chapter ) ); ?></strong></td>
                            <td><?php echo esc_html( $chapter_info['title'] ); ?></td>
                            <td>
                                <label class="screen-reader-text" for="kir-congregation-<?php echo esc_attr( (string) intval( $chapter ) ); ?>">Bendruomenė <?php echo esc_html( (string) intval( $chapter ) ); ?></label>
                                <select id="kir-congregation-<?php echo esc_attr( (string) intval( $chapter ) ); ?>" class="kir-assignment-select" name="assignments[<?php echo esc_attr( (string) intval( $chapter ) ); ?>]">
                                    <?php foreach ( $congregation_names as $name ) : ?>
                                        <option value="<?php echo esc_attr( $name ); ?>" <?php selected( isset( $by_chapter[ $chapter ] ) ? $by_chapter[ $chapter ] : '', $name ); ?>><?php echo esc_html( $name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Išsaugoti skyrių priskyrimą' ); ?>
            </form>
        </div>
        <?php
    }

    public function render_admin_texts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės peržiūrėti šio puslapio.', 'knygos-igarsinimo-registracija' ) );
        }

        $texts = $this->get_texts();
        $fields = array(
            'form_title'          => 'Formos antraštė',
            'intro_text'          => 'Įvadinis tekstas',
            'book_link_text'      => 'Knygos nuorodos tekstas',
            'book_page_url'       => 'Knygos puslapio URL',
            'pdf_url'             => 'PDF failo URL',
            'reader_title'        => 'PDF skaityklės antraštė',
            'reader_hint'         => 'PDF skaityklės paaiškinimas',
            'fullscreen_button'   => 'Mygtukas „Per visą ekraną“',
            'open_pdf_button'     => 'Mygtukas „Atidaryti PDF“',
            'name_label'          => 'Vardo lauko pavadinimas',
            'email_label'         => 'El. pašto lauko pavadinimas',
            'congregation_label'  => 'Bendruomenės lauko pavadinimas',
            'chapter_label'       => 'Skyrių lauko pavadinimas',
            'chapter_help'        => 'Paaiškinimas prie skyrių pasirinkimų',
            'select_chapter_label' => 'Tekstas prie skyriaus pasirinkimo',
            'choose_congregation' => 'Pradinis bendruomenės pasirinkimo tekstas',
            'choose_chapter'      => 'Pradinis skyriaus pasirinkimo tekstas',
            'loading_text'        => 'Krovimo tekstas',
            'submit_button'       => 'Mygtuko tekstas',
            'reserved_suffix'     => 'Tekstas prie jau pasirinkto skyriaus',
            'full_suffix'         => 'Tekstas prie pilnai užimtos bendruomenės',
            'success_message'     => 'Sėkmės pranešimas',
            'duplicate_message'   => 'Pranešimas, jei bent vieną skyrių ką tik pasirinko kitas žmogus',
            'selection_required'  => 'Pranešimas, jei nepasirinktas nė vienas skyrius',
            'invalid_message'     => 'Neteisingų duomenų pranešimas',
            'privacy_text'        => 'Privatumo / duomenų naudojimo tekstas',
            'my_selection_title'  => 'Išsaugoto pasirinkimo antraštė',
            'my_selection_intro'  => 'Išsaugoto pasirinkimo paaiškinimas',
            'cancel_button'       => 'Mygtukas „Atsisakyti“',
            'cancel_confirm'      => 'Atšaukimo patvirtinimo klausimas',
            'cancel_success'      => 'Sėkmingo atšaukimo pranešimas',
        );
        ?>
        <div class="wrap">
            <h1>Formos tekstai</h1>
            <p>Čia galima pakeisti visus pagrindinius lankytojui rodomus formos tekstus. Sėkmės pranešime galima naudoti <code>{chapter}</code>, <code>{chapter_title}</code>, <code>{chapters}</code>, <code>{chapter_titles}</code>, <code>{chapter_list}</code>, <code>{congregation}</code> ir <code>{name}</code>. Knygos ir PDF nuorodoms leidžiami tik <code>http</code> ir <code>https</code> adresai.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'kir_texts_group' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <?php foreach ( $fields as $key => $label ) : ?>
                        <tr>
                            <th scope="row"><label for="kir_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <?php if ( in_array( $key, array( 'intro_text', 'privacy_text' ), true ) ) : ?>
                                    <textarea class="large-text" rows="4" id="kir_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_TEXTS ); ?>[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $texts[ $key ] ); ?></textarea>
                                <?php else : ?>
                                    <input class="regular-text" type="<?php echo in_array( $key, array( 'book_page_url', 'pdf_url' ), true ) ? 'url' : 'text'; ?>" id="kir_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_TEXTS ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $texts[ $key ] ); ?>">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Išsaugoti tekstus' ); ?>
            </form>
        </div>
        <?php
    }

    public function render_admin_google_sheets() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės peržiūrėti šio puslapio.', 'knygos-igarsinimo-registracija' ) );
        }

        $settings = $this->get_google_sheets_settings();
        $status   = get_option( self::OPTION_GOOGLE_SHEETS_STATUS, array() );
        $notice   = isset( $_GET['kir_notice'] ) ? sanitize_key( wp_unslash( $_GET['kir_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1>Google Sheets sinchronizacija</h1>

            <?php if ( 'sheets_sync_success' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p>Registracijos išsiųstos į Google Sheets.</p></div>
            <?php elseif ( 'sheets_sync_error' === $notice ) : ?>
                <div class="notice notice-error is-dismissible"><p>Registracijų išsiųsti nepavyko. Patikrinkite URL, tokeną ir Apps Script diegimą.</p></div>
            <?php endif; ?>

            <p>Šis paprastas ryšys siunčia registracijas iš WordPress į vieną Google Sheets dokumentą. Google prisijungimo WordPress pusėje nereikia.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'kir_google_sheets_group' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">Įjungti sinchronizaciją</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[enabled]" value="1" <?php checked( 1, (int) $settings['enabled'] ); ?>> Siųsti naujas registracijas ir būsenos pakeitimus</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kir-google-sheets-endpoint">Apps Script Web App URL</label></th>
                        <td>
                            <input class="large-text" type="url" id="kir-google-sheets-endpoint" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[endpoint_url]" value="<?php echo esc_attr( $settings['endpoint_url'] ); ?>" placeholder="https://script.google.com/macros/s/.../exec">
                            <p class="description">Naudokite diegimo URL, kuris baigiasi <code>/exec</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kir-google-sheets-secret">Slaptas tokenas</label></th>
                        <td>
                            <input class="regular-text" type="password" id="kir-google-sheets-secret" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[secret]" value="" autocomplete="new-password" placeholder="Palikite tuščią, jei nekeičiate">
                            <p class="description"><?php echo ! empty( $settings['secret'] ) ? 'Tokenas išsaugotas. Įveskite naują tik norėdami jį pakeisti.' : 'Naudokite ilgą atsitiktinį tekstą ir tokį patį įrašykite Apps Script nustatymuose.'; ?></p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button( 'Išsaugoti Google Sheets nustatymus' ); ?>
            </form>

            <h2>Esamų registracijų sinchronizavimas</h2>
            <p>Po pirmojo nustatymo paspauskite šį mygtuką, kad į dokumentą būtų išsiųstos visos jau esančios registracijos.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kir_sync_google_sheets">
                <?php wp_nonce_field( 'kir_sync_google_sheets' ); ?>
                <button type="submit" class="button button-primary" <?php disabled( ! $this->google_sheets_configured() ); ?>>Sinchronizuoti visas registracijas dabar</button>
            </form>

            <?php if ( is_array( $status ) && ! empty( $status['at'] ) ) : ?>
                <p class="description">Paskutinis bandymas: <?php echo esc_html( $status['at'] ); ?> — <?php echo esc_html( $status['message'] ); ?></p>
            <?php endif; ?>

            <h2>Greita sąranka</h2>
            <ol>
                <li>Google Drive sukurkite Google Sheets dokumentą ir atidarykite <strong>Extensions → Apps Script</strong>.</li>
                <li>Įkelkite įskiepio aplanke esantį failą <code>google-apps-script/Code.gs</code>.</li>
                <li>Apps Script nustatymuose sukurkite Script property <code>KIR_SECRET</code> su tuo pačiu tokenu.</li>
                <li>Deploy → New deployment → Web app; vykdyti kaip save, prieiga <strong>Anyone</strong>; nukopijuokite <code>/exec</code> URL čia.</li>
                <li>Išsaugokite nustatymus ir vieną kartą paleiskite visų registracijų sinchronizaciją.</li>
            </ol>
        </div>
        <?php
    }

    private function get_reserved_assignment_rows( $chapters ) {
        global $wpdb;

        $chapters = array_values( array_unique( array_filter( array_map( 'absint', (array) $chapters ) ) ) );
        if ( empty( $chapters ) ) {
            return array();
        }

        $chapter_list = implode( ',', $chapters );
        return (array) $wpdb->get_results( "SELECT id, chapter, congregation, full_name FROM {$this->table_name} WHERE chapter IN ({$chapter_list})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
    }

    private function get_assignment_conflicts( $current, $proposed ) {
        $current_by_chapter  = $this->get_congregation_by_chapter( $current );
        $proposed_by_chapter = $this->get_congregation_by_chapter( $proposed );
        $changed_chapters    = array();

        foreach ( self::chapter_data() as $chapter => $unused ) {
            $chapter = (int) $chapter;
            if ( isset( $current_by_chapter[ $chapter ], $proposed_by_chapter[ $chapter ] ) && $current_by_chapter[ $chapter ] !== $proposed_by_chapter[ $chapter ] ) {
                $changed_chapters[] = $chapter;
            }
        }

        $reserved_rows = $this->get_reserved_assignment_rows( $changed_chapters );
        $conflicts     = array();
        foreach ( $reserved_rows as $row ) {
            $chapter = (int) $row['chapter'];
            $conflicts[] = array(
                'id'               => absint( $row['id'] ),
                'chapter'          => $chapter,
                'full_name'        => (string) $row['full_name'],
                'old_congregation' => (string) $row['congregation'],
                'new_congregation' => isset( $proposed_by_chapter[ $chapter ] ) ? $proposed_by_chapter[ $chapter ] : '',
            );
        }

        return $conflicts;
    }

    private function show_assignment_conflict_confirmation( $proposed, $conflicts ) {
        $proposed_by_chapter = $this->get_congregation_by_chapter( $proposed );
        $form_action         = admin_url( 'admin-post.php' );
        $back_url            = admin_url( 'admin.php?page=kir-assignments' );
        $html                = '<div class="wrap">';
        $html               .= '<h1>Patvirtinkite priskyrimo pakeitimą</h1>';
        $html               .= '<div class="notice notice-warning"><p>Šie skyriai jau rezervuoti. Pakeitus priskyrimą, esamos rezervacijos liks išsaugotos, tačiau bendruomenės priskyrimas nebesutaps su senesne registracija.</p></div>';
        $html               .= '<ul>';

        foreach ( $conflicts as $conflict ) {
            $html .= '<li><strong>' . esc_html( $conflict['chapter'] . ' skyrius' ) . '</strong> — rezervavo ' . esc_html( $conflict['full_name'] ) . '; dabar: ' . esc_html( $conflict['old_congregation'] ) . '; naujai priskirti: ' . esc_html( $conflict['new_congregation'] ) . '.</li>';
        }

        $html .= '</ul>';
        $html .= '<p>Ar tikrai norite išsaugoti šį pakeitimą?</p>';
        $html .= '<form method="post" action="' . esc_url( $form_action ) . '">';
        $html .= '<input type="hidden" name="action" value="kir_update_assignments">';
        $html .= '<input type="hidden" name="confirm_conflicts" value="1">';

        foreach ( self::chapter_data() as $chapter => $unused ) {
            $chapter = (int) $chapter;
            if ( isset( $proposed_by_chapter[ $chapter ] ) ) {
                $html .= '<input type="hidden" name="assignments[' . esc_attr( (string) $chapter ) . ']" value="' . esc_attr( $proposed_by_chapter[ $chapter ] ) . '">';
            }
        }

        $html .= wp_nonce_field( 'kir_update_assignments', '_wpnonce', true, false );
        $html .= '<button type="submit" class="button button-primary">Taip, patvirtinti ir išsaugoti</button> ';
        $html .= '<a class="button" href="' . esc_url( $back_url ) . '">Atšaukti</a>';
        $html .= '</form></div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirmation form is assembled above with context-escaped values and a nonce field.
        wp_die( $html, 'Patvirtinkite priskyrimo pakeitimą', array( 'response' => 200 ) );
    }

    public function handle_sync_google_sheets() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės atlikti šio veiksmo.', 'knygos-igarsinimo-registracija' ) );
        }

        check_admin_referer( 'kir_sync_google_sheets' );

        $success = $this->sync_all_reservations_to_google_sheets();
        wp_safe_redirect( admin_url( 'admin.php?page=kir-google-sheets&kir_notice=' . ( $success ? 'sheets_sync_success' : 'sheets_sync_error' ) ) );
        exit;
    }

    public function handle_update_assignments() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės atlikti šio veiksmo.', 'knygos-igarsinimo-registracija' ) );
        }

        check_admin_referer( 'kir_update_assignments' );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured fields are individually validated before persistence.
        $input    = isset( $_POST['assignments'] ) && is_array( $_POST['assignments'] ) ? wp_unslash( $_POST['assignments'] ) : array();
        $current  = $this->get_congregations();
        $proposed = $this->sanitize_congregation_settings( $input );
        $conflicts = $this->get_assignment_conflicts( $current, $proposed );

        if ( ! empty( $conflicts ) && empty( $_POST['confirm_conflicts'] ) ) {
            $this->show_assignment_conflict_confirmation( $proposed, $conflicts );
        }

        update_option( self::OPTION_CONGREGATIONS, $proposed, false );
        $this->sync_reservation_ids_to_google_sheets( wp_list_pluck( $conflicts, 'id' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=kir-assignments&kir_notice=assignments_updated' ) );
        exit;
    }

    public function handle_update_reservations() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės atlikti šio veiksmo.', 'knygos-igarsinimo-registracija' ) );
        }

        check_admin_referer( 'kir_update_reservations' );

        global $wpdb;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured fields are individually validated before persistence.
        $reservations = isset( $_POST['reservations'] ) && is_array( $_POST['reservations'] ) ? wp_unslash( $_POST['reservations'] ) : array();
        $allowed_congregations = array_keys( $this->get_congregations() );
        $chapter_data          = self::chapter_data();
        $congregation_by_chapter = $this->get_congregation_by_chapter( $this->get_congregations() );
        $updates               = array();
        $errors                = array();
        $seen_chapters         = array();

        foreach ( $reservations as $id => $fields ) {
            $id = absint( $id );
            if ( ! $id || ! is_array( $fields ) ) {
                continue;
            }

            $current = $wpdb->get_row(
                $wpdb->prepare( "SELECT id, full_name, email, congregation, chapter, created_at, summary_sent, audio_sent FROM {$this->table_name} WHERE id = %d LIMIT 1", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
            );
            if ( ! $current ) {
                $errors[] = 'Registracija #' . $id . ' neberasta.';
                continue;
            }

            $full_name    = isset( $fields['full_name'] ) ? trim( sanitize_text_field( $fields['full_name'] ) ) : (string) $current->full_name;
            $email        = isset( $fields['email'] ) ? sanitize_email( $fields['email'] ) : (string) $current->email;
            $congregation = isset( $fields['congregation'] ) ? sanitize_text_field( $fields['congregation'] ) : (string) $current->congregation;
            $chapter      = isset( $fields['chapter'] ) ? absint( $fields['chapter'] ) : (int) $current->chapter;
            $created_at   = isset( $fields['created_at'] ) ? $this->normalize_admin_datetime( $fields['created_at'] ) : (string) $current->created_at;

            if ( '' === $full_name || strlen( $full_name ) > 190 ) {
                $errors[] = 'Registracijos #' . $id . ': vardas ir pavardė turi būti nuo 1 iki 190 simbolių.';
            }
            if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
                $errors[] = 'Registracijos #' . $id . ': įrašykite tinkamą el. paštą.';
            }
            if ( ! in_array( $congregation, $allowed_congregations, true ) ) {
                $errors[] = 'Registracijos #' . $id . ': pasirinkta nežinoma bendruomenė.';
            }
            if ( ! isset( $chapter_data[ $chapter ] ) ) {
                $errors[] = 'Registracijos #' . $id . ': pasirinktas nežinomas skyrius.';
            }
            if ( false === $created_at ) {
                $errors[] = 'Registracijos #' . $id . ': data turi būti tinkama.';
                $created_at = (string) $current->created_at;
            }

            $pair_changed = $congregation !== (string) $current->congregation || $chapter !== (int) $current->chapter;
            if ( $pair_changed && ( ! isset( $congregation_by_chapter[ $chapter ] ) || $congregation_by_chapter[ $chapter ] !== $congregation ) ) {
                $errors[] = 'Registracijos #' . $id . ': pasirinktas skyrius nepriskirtas pasirinktai bendruomenei. Pirmiausia pakeiskite priskyrimą skyrių priskyrimo puslapyje.';
            }

            if ( isset( $seen_chapters[ $chapter ] ) && $seen_chapters[ $chapter ] !== $id ) {
                $errors[] = 'Registracijos #' . $id . ': tas pats skyrius jau pasirinktas kitoje šio išsaugojimo eilutėje.';
            }
            $seen_chapters[ $chapter ] = $id;

            $conflicting_id = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$this->table_name} WHERE chapter = %d AND id <> %d LIMIT 1", $chapter, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
            );
            if ( $conflicting_id ) {
                $errors[] = 'Registracijos #' . $id . ': šis skyrius jau rezervuotas kitoje registracijoje.';
            }

            $updates[] = array(
                'id'           => $id,
                'pair_changed' => $pair_changed,
                'current'      => $current,
                'fields'       => array(
                    'full_name'    => $full_name,
                    'email'        => $email,
                    'congregation' => $congregation,
                    'chapter'      => $chapter,
                    'created_at'   => $created_at,
                    'summary_sent' => isset( $fields['summary_sent'] ) && '1' === (string) $fields['summary_sent'] ? 1 : 0,
                    'audio_sent'   => isset( $fields['audio_sent'] ) && '1' === (string) $fields['audio_sent'] ? 1 : 0,
                ),
            );
        }

        if ( ! empty( $errors ) ) {
            $message = '<p>Nepavyko išsaugoti registracijų:</p><ul>';
            foreach ( $errors as $error ) {
                $message .= '<li>' . esc_html( $error ) . '</li>';
            }
            $message .= '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=kir-registrations' ) ) . '">Grįžti į registracijų lentelę</a></p>';
            wp_die( wp_kses_post( $message ), 'Registracijos neišsaugotos', array( 'response' => 400 ) );
        }

        $conflicts = array();
        foreach ( $updates as $update ) {
            if ( ! $update['pair_changed'] ) {
                continue;
            }
            $conflicts[] = array(
                'full_name'        => (string) $update['fields']['full_name'],
                'old_congregation' => (string) $update['current']->congregation,
                'old_chapter'      => (int) $update['current']->chapter,
                'new_congregation' => (string) $update['fields']['congregation'],
                'new_chapter'      => (int) $update['fields']['chapter'],
            );
        }

        if ( ! empty( $conflicts ) && empty( $_POST['confirm_conflicts'] ) ) {
            $this->show_reservation_conflict_confirmation( $updates, $conflicts );
        }

        $failed      = false;
        $updated_ids = array();
        $wpdb->query( 'START TRANSACTION' );

        foreach ( $updates as $update ) {
            $updated = $wpdb->update(
                $this->table_name,
                $update['fields'],
                array( 'id' => $update['id'] ),
                array( '%s', '%s', '%s', '%d', '%s', '%d', '%d' ),
                array( '%d' )
            );

            if ( false === $updated ) {
                $failed = true;
                break;
            }
            $updated_ids[] = (int) $update['id'];
        }

        $wpdb->query( $failed ? 'ROLLBACK' : 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.

        if ( ! $failed ) {
            $this->sync_reservation_ids_to_google_sheets( $updated_ids );
        }

        $notice = $failed ? 'update_error' : 'updated';
        wp_safe_redirect( admin_url( 'admin.php?page=kir-registrations&kir_notice=' . $notice ) );
        exit;
    }

    public function handle_release_reservation() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės atlikti šio veiksmo.', 'knygos-igarsinimo-registracija' ) );
        }

        $id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
        if ( ! $id ) {
            wp_die( esc_html__( 'Neteisingas rezervacijos ID.', 'knygos-igarsinimo-registracija' ) );
        }

        check_admin_referer( 'kir_release_reservation_' . $id );

        global $wpdb;
        $reservation_exists = (bool) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$this->table_name} WHERE id = %d LIMIT 1", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is a fixed WP-prefix name; values are prepared, integer-only lists, allowlisted sort identifiers, or literal transaction commands.
        );
        $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );

        if ( $reservation_exists ) {
            $this->delete_reservation_ids_from_google_sheets( array( $id ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=kir-registrations&kir_notice=released' ) );
        exit;
    }

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Neturite teisės eksportuoti duomenų.', 'knygos-igarsinimo-registracija' ) );
        }
        check_admin_referer( 'kir_export_xlsx' );

        global $wpdb;
        $rows = $wpdb->get_results( "SELECT created_at, full_name, email, congregation, chapter, summary_sent, audio_sent FROM {$this->table_name} ORDER BY chapter ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        $data = array();
        $data[] = array( 'Data', 'Vardas ir pavardė', 'El. paštas', 'Bendruomenė', 'Skyrius', 'Skyriaus pavadinimas', 'Santrauka išsiųsta', 'Atsiuntė audio' );
        foreach ( $rows as $row ) {
            $data[] = array(
                (string) $row['created_at'],
                (string) $row['full_name'],
                (string) $row['email'],
                (string) $row['congregation'],
                (int) $row['chapter'],
                self::chapter_title( (int) $row['chapter'] ),
                ! empty( $row['summary_sent'] ) ? 'Taip' : 'Ne',
                ! empty( $row['audio_sent'] ) ? 'Taip' : 'Ne',
            );
        }

        if ( class_exists( 'ZipArchive' ) ) {
            $this->output_xlsx( $data );
        }

        // Atsarginis variantas, jei serveryje nėra PHP ZipArchive plėtinio.
        $this->output_csv( $data );
    }

    private function output_csv( $data ) {
        $filename = 'igarsinimo-registracijos-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo "\xEF\xBB\xBF"; // UTF-8 BOM, kad Excel teisingai rodytų lietuviškas raides.
        $out = fopen( 'php://output', 'w' );
        foreach ( $data as $row ) {
            $safe_row = array();
            foreach ( $row as $cell ) {
                $cell = (string) $cell;
                // Apsauga nuo CSV/Excel formulės įterpimo per vartotojo įvestus laukus.
                if ( preg_match( '/^[=+\-@]/u', $cell ) ) {
                    $cell = "'" . $cell;
                }
                $safe_row[] = $cell;
            }
            fputcsv( $out, $safe_row, ';' );
        }
        fclose( $out );
        exit;
    }

    private function output_xlsx( $data ) {
        $tmp = wp_tempnam( 'kir-export.xlsx' );
        if ( ! $tmp ) {
            $this->output_csv( $data );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            @unlink( $tmp );
            $this->output_csv( $data );
        }

        $row_count = count( $data );
        $sheet_xml = $this->build_sheet_xml( $data );

        $zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>' );

        $zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>' );

        $zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Registracijos" sheetId="1" r:id="rId1"/></sheets>
</workbook>' );

        $zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>' );

        $zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>' );

        $zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );

        $created = gmdate( 'Y-m-d\TH:i:s\Z' );
        $zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
<dc:title>Įgarsinimo registracijos</dc:title><dc:creator>WordPress</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>
</cp:coreProperties>' );

        $zip->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>WordPress</Application></Properties>' );

        $zip->close();

        $filename = 'igarsinimo-registracijos-' . gmdate( 'Y-m-d' ) . '.xlsx';
        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $tmp ) );
        readfile( $tmp );
        @unlink( $tmp );
        exit;
    }

    private function build_sheet_xml( $data ) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $xml .= '<cols><col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="3" width="32" customWidth="1"/><col min="4" max="4" width="20" customWidth="1"/><col min="5" max="5" width="10" customWidth="1"/><col min="6" max="6" width="44" customWidth="1"/></cols>';
        $xml .= '<sheetData>';

        foreach ( $data as $r_index => $row ) {
            $excel_row = $r_index + 1;
            $xml .= '<row r="' . $excel_row . '">';
            foreach ( array_values( $row ) as $c_index => $value ) {
                $cell = $this->xlsx_column_name( $c_index + 1 ) . $excel_row;
                if ( $r_index > 0 && 4 === $c_index && is_numeric( $value ) ) {
                    $xml .= '<c r="' . $cell . '"><v>' . intval( $value ) . '</v></c>';
                } else {
                    $escaped = htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
                    $style   = 0 === $r_index ? ' s="1"' : '';
                    $xml    .= '<c r="' . $cell . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        $last_row = max( 1, count( $data ) );
        $xml .= '</sheetData><autoFilter ref="A1:F' . $last_row . '"/></worksheet>';

        return $xml;
    }

    private function xlsx_column_name( $number ) {
        $name = '';
        while ( $number > 0 ) {
            $number--;
            $name   = chr( 65 + ( $number % 26 ) ) . $name;
            $number = (int) floor( $number / 26 );
        }
        return $name;
    }
}

register_activation_hook( __FILE__, array( 'KIR_Plugin', 'activate' ) );
KIR_Plugin::instance();
