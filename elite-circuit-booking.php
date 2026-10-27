<?php
/**
 * Plugin Name: Elite Circuit Booking
 * Plugin URI: 
 * Description: Circuits touristiques et excursions : fiches circuit, options de départ, réservation.
 * Version:     1.0.4
 * Author:      Reich C
 * Text Domain: elite-transfer-booking
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Garde anti-collision : les deux plugins Elite (Circuit / Transfer) partagent le même moteur
// (mêmes classes ETB_*). Un seul des deux peut être actif sur un même site.
if ( defined( 'ETB_VERSION' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Elite Booking :</strong> un autre plugin Elite (Circuit ou Transfer) utilisant le même moteur est déjà actif. Désactivez-en un des deux.</p></div>';
    } );
} else {

define( 'ETB_VERSION', '1.0.4' );
define( 'ETB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ETB_URL', plugin_dir_url( __FILE__ ) );

class Elite_Transfer_Booking {

    private static $instance = null;
    private static $assets_enqueued = false;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }


        /**
     * Charge les assets publics uniquement si la page en a besoin.
     */
    public function maybe_enqueue_public_assets() {
        if ( $this->page_needs_assets() ) {
            $this->enqueue_public_assets();
        }
    }

    /**
     * Détecte si la page courante affiche un circuit ou un des shortcodes du plugin.
     * Le filtre permet de forcer le chargement (widget, page builder, template).
     */
    private function page_needs_assets() {
        $needs = false;

        if ( is_singular( 'circuit' ) ) {
            $needs = true;
        } elseif ( is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post ) {
                $needs = has_shortcode( $post->post_content, 'tour_booking' )
                      || has_shortcode( $post->post_content, 'circuit_view' );
            }
        }

        return (bool) apply_filters( 'etb_page_needs_assets', $needs );
    }
    

    public function enqueue_public_assets() {

        if ( self::$assets_enqueued ) {
            return;
        }
        self::$assets_enqueued = true;

        // 1. Ré-enregistrement sécurisé de dashicons si le thème l'a déchargé
        if ( ! wp_style_is( 'dashicons', 'registered' ) ) {
            wp_register_style( 'dashicons', includes_url( 'css/dashicons.min.css' ), array(), false, 'all' );
        }
        wp_enqueue_style( 'dashicons' );

        // 2. Module socle : Tokens globaux (ZÉRO dépendance bloquante)
        wp_enqueue_style( 'etb-tokens', ETB_URL . 'public/css/modules/etb-tokens.css', array(), ETB_VERSION );

        // 3. Modules thématiques
        wp_enqueue_style( 'font-awesome-6', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
        wp_enqueue_style( 'intl-tel-input-style', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/css/intlTelInput.css', array(), '18.2.1' );
        wp_enqueue_style( 'flatpickr-style', 'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css', array(), '4.6.13' );
        wp_enqueue_style( 'etb-circuits', ETB_URL . 'public/css/modules/etb-circuits.css', array( 'etb-tokens', 'font-awesome-6', 'intl-tel-input-style', 'flatpickr-style' ), ETB_VERSION );    
        
        $gen_settings    = get_option( 'etb_general_settings', array() );
        $currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
        $provider        = ! empty( $gen_settings['address_provider'] ) ? sanitize_text_field( $gen_settings['address_provider'] ) : 'google';
        $google_key      = ! empty( $gen_settings['google_maps_api_key'] ) ? sanitize_text_field( trim( $gen_settings['google_maps_api_key'] ) ) : '';

       

        $script_deps = array( 'intl-tel-input-script' );

        // Google Places : chargé seulement si une clé est renseignée
        if ( ! empty( $google_key ) ) {
            wp_enqueue_script( 'google-maps-places', 'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( $google_key ) . '&libraries=places&language=en', array(), null, true );
            $script_deps[] = 'google-maps-places';
        }

        // Librairie internationale de téléphone (245 pays)
        wp_enqueue_script( 'intl-tel-input-script', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/js/intlTelInput.min.js', array(), '18.2.1', true );
        
        // Librairie Flatpickr pour le calendrier
        wp_enqueue_script( 'flatpickr-script', 'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js', array(), '4.6.13', true );
        $script_deps[] = 'flatpickr-script';

        wp_enqueue_script( 'etb-booking-script', ETB_URL . 'public/js/booking-widget.js', $script_deps, ETB_VERSION, true );
       

        // Transmission des variables sécurisées à JavaScript
        wp_localize_script( 'etb-booking-script', 'etbAjax', array(
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'etb_booking_nonce' ),
            'currency'          => $currency_symbol,
            'address_provider'  => $provider,
            'google_key'        => $google_key,
            'home_url'          => esc_url( home_url( '/' ) ),
            'company_whatsapp'  => ! empty( $gen_settings['company_whatsapp'] ) ? preg_replace( '/[^0-9]/', '', $gen_settings['company_whatsapp'] ) : '',
            'admin_email'       => ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' ),
        ) );
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->instantiate_modules();
        $this->init_hooks();
    }

    private function load_dependencies() {
         require_once ETB_PATH . 'includes/class-etb-limoexpress.php'; // <-- AJOUT DE CETTE LIGNE
         require_once ETB_PATH . 'includes/class-etb-dispatcher-manager.php'; // <-- AJOUT DE CETTE LIGNE
        require_once ETB_PATH . 'includes/class-etb-security.php';
        require_once ETB_PATH . 'includes/class-etb-cpt-manager.php';
        require_once ETB_PATH . 'includes/class-etb-settings.php';
        
        require_once ETB_PATH . 'includes/class-etb-pricing-engine.php';
        require_once ETB_PATH . 'includes/class-etb-meta-manager.php';
        require_once ETB_PATH . 'includes/class-etb-ajax.php';
        require_once ETB_PATH . 'includes/class-etb-shortcode.php';
    }

    private function init_hooks() {
    add_action( 'init', array( $this, 'register_cpts' ), 0 );
    add_action( 'init', array( $this, 'register_circuit_taxonomy' ), 1 );
    add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_public_assets' ), 99 );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
}

    public function register_cpts() {
        $cpt_manager = new ETB_CPT_Manager();
        $cpt_manager->register_all_cpts();
    }

    /**
 * Enregistre la taxonomie « Catégories de circuits ».
 * Chargée après le CPT (priorité init 1) pour garantir que 'circuit' existe.
 */
    public function register_circuit_taxonomy() {
        if ( ! post_type_exists( 'circuit' ) ) {
            return;
        }

        register_taxonomy(
            'circuit_category',
            'circuit',
            array(
                'labels'            => array(
                    'name'              => __( 'Catégories de circuits', 'elite-transfer-booking' ),
                    'singular_name'     => __( 'Catégorie de circuit', 'elite-transfer-booking' ),
                    'search_items'      => __( 'Rechercher une catégorie', 'elite-transfer-booking' ),
                    'all_items'         => __( 'Toutes les catégories', 'elite-transfer-booking' ),
                    'parent_item'       => __( 'Catégorie parente', 'elite-transfer-booking' ),
                    'parent_item_colon' => __( 'Catégorie parente :', 'elite-transfer-booking' ),
                    'edit_item'         => __( 'Modifier la catégorie', 'elite-transfer-booking' ),
                    'update_item'       => __( 'Mettre à jour la catégorie', 'elite-transfer-booking' ),
                    'add_new_item'      => __( 'Ajouter une catégorie', 'elite-transfer-booking' ),
                    'new_item_name'     => __( 'Nom de la nouvelle catégorie', 'elite-transfer-booking' ),
                    'menu_name'         => __( 'Catégories', 'elite-transfer-booking' ),
                ),
                'hierarchical'      => true,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => array( 'slug' => 'circuit-category' ),
            )
        );
    }

    public function instantiate_modules() {
        new ETB_Settings();
        new ETB_Meta_Manager();
        new ETB_Pricing_Engine();
        new ETB_Ajax();
        new ETB_Shortcode();
        
        // Branchement du module LimoExpress sur la Prise Universelle ETB
        if ( class_exists( 'ETB_LimoExpress' ) ) {
            ETB_LimoExpress::init_hooks();
        }
    }

    public function enqueue_admin_assets( $hook ) {
        global $post_type;
        if ( 'circuit' === $post_type ) {
            // Active la médiathèque officielle WordPress pour la galerie photo du circuit
            wp_enqueue_style( 'font-awesome-6', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );

            wp_enqueue_media();

            if ( file_exists( ETB_PATH . 'admin/css/etb-admin.css' ) ) {
                wp_enqueue_style( 'etb-admin-style', ETB_URL . 'admin/css/etb-admin.css', array(), ETB_VERSION );
            }
            if ( file_exists( ETB_PATH . 'admin/js/etb-admin.js' ) ) {
                wp_enqueue_script( 'etb-admin-script', ETB_URL . 'admin/js/etb-admin.js', array( 'jquery', 'jquery-ui-sortable' ), ETB_VERSION, true );
            }
        }
    }

}


// Activation / Désactivation avec flush des permaliens
function etb_activate_plugin() {
    $cpt_manager = new ETB_CPT_Manager();
    $cpt_manager->register_all_cpts();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'etb_activate_plugin' );

function etb_deactivate_plugin() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'etb_deactivate_plugin' );

// Lancement
function etb_init_app() {
    return Elite_Transfer_Booking::get_instance();
}
etb_init_app();

}
