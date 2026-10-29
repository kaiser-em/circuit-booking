<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Shortcode {

    public function __construct() {
        add_shortcode( 'tour_booking', array( $this, 'render_booking_form' ) );
        add_shortcode( 'circuit_view', array( $this, 'render_circuit_view' ) );
        add_shortcode( 'etb_payment', array( $this, 'render_payment_view' ) ); // <-- NOUVEAU
        add_filter( 'the_content', array( $this, 'auto_append_to_circuit_single' ), 20 );
    }

    /**
     * Shortcode [etb_checkout] : Formulaire de réservation détaillée & Checkout VIP Blacklane
     */
    public function render_checkout_view( $atts = array() ) {
        ob_start();
        $template_file = ETB_PATH . 'templates/checkout-view.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<p>Gabarit checkout-view.php introuvable.</p>';
        }
        return ob_get_clean();
    }

    /**
     * Shortcode [etb_payment] : Page de paiement dédiée et sécurisée pour solde et devis validés
     */
    public function render_payment_view( $atts = array() ) {
        // 1. Chargement forcé du CSS (Zéro dépendance au thème)
        wp_enqueue_style( 'etb-payment-css', ETB_URL . 'public/css/modules/etb-payment.css', array(), ETB_VERSION );
        
        // 2. Vérification de la configuration Stripe
        $gen_settings   = get_option( 'etb_general_settings', array() );
        $stripe_enabled = ! empty( $gen_settings['stripe_enabled'] ) && '1' === $gen_settings['stripe_enabled'];
        $stripe_pk      = ! empty( $gen_settings['stripe_publishable_key'] ) ? trim( $gen_settings['stripe_publishable_key'] ) : '';

        // 3. Chargement des scripts Stripe (Uniquement si configuré)
        if ( $stripe_enabled && ! empty( $stripe_pk ) ) {
            wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
            wp_enqueue_script( 'etb-payment-js', ETB_URL . 'public/js/payment-widget.js', array( 'stripe-js' ), ETB_VERSION, true );
            
            wp_localize_script( 'etb-payment-js', 'etbPayData', array(
                'ajax_url'  => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'etb_booking_nonce' ),
                'stripe_pk' => $stripe_pk,
                'currency'  => $gen_settings['currency'] ?? '€'
            ) );
        }

        // 4. Inclusion du template
        ob_start();
        $template_file = ETB_PATH . 'templates/payment-view.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<p>Gabarit payment-view.php introuvable.</p>';
        }
        return ob_get_clean();
    }

    /**
     * Shortcode [circuit_view id="..."]
     */
  
    public function render_circuit_view( $atts ) {
        // Filet de sécurité : forcer la mise en file des styles du circuit
        if ( class_exists( 'Elite_Transfer_Booking' ) ) {
            Elite_Transfer_Booking::get_instance()->enqueue_public_assets();
        }

        $atts = shortcode_atts( array( 'id' => 0, 'related' => 3, 'related_category' => '' ), $atts, 'circuit_view' );
        $circuit_id = absint( $atts['id'] );

        if ( ! $circuit_id ) {
            if ( get_post_type() === 'circuit' ) {
                $circuit_id = get_the_ID();
            } else {
                $circuits = get_posts( array(
                    'post_type'   => 'circuit',
                    'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
                    'numberposts' => 1,
                    'fields'      => 'ids',
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                ) );
                if ( ! empty( $circuits ) ) {
                    $circuit_id = $circuits[0];
                }
            }
        }

        if ( ! $circuit_id ) {
            return '<p style="padding: 15px; background: #fee2e2; border: 1px solid #f87171; border-radius: 6px; color: #991b1b;"><strong>[circuit_view] :</strong> Aucun circuit trouvé.</p>';
        }

        $options = get_post_meta( $circuit_id, '_circuit_options_data', true );
        if ( empty( $options ) || ! is_array( $options ) ) {
            return '<p style="padding: 15px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; color: #92400e;"><strong>[circuit_view] :</strong> Aucune option de départ configurée pour ce circuit #' . esc_html( $circuit_id ) . '.</p>';
        }

        ob_start();
            include ETB_PATH . 'templates/circuit-view.php';

                       // Section « Autres circuits » (désactivable avec related="0").
            $related_count = absint( $atts['related'] );

            // Interrupteur par circuit : si explicitement désactivé, on force le masquage.
            // Absence de méta = affichée (rétrocompatibilité).
            if ( $circuit_id ) {
                $related_enabled = get_post_meta( $circuit_id, '_circuit_related_enabled', true );
                if ( '0' === $related_enabled ) {
                    $related_count = 0;
                }
            }

            // Filtrage par catégorie :
            // - si 'related_category' est passé au shortcode, on l'utilise ;
            // - sinon, on reprend la 1ʳᵉ catégorie du circuit courant (défaut) ;
            // - si le circuit n'a aucune catégorie, on affiche tous les circuits publiés.
            $related_category = sanitize_title( $atts['related_category'] );

            if ( '' === $related_category && $circuit_id ) {
                $current_terms = wp_get_post_terms( $circuit_id, 'circuit_category', array( 'fields' => 'slugs' ) );
                if ( ! is_wp_error( $current_terms ) && ! empty( $current_terms ) ) {
                    $related_category = sanitize_title( $current_terms[0] );
                }
            }

            $related_file = ETB_PATH . 'templates/circuit-related.php';
            if ( $related_count > 0 && file_exists( $related_file ) ) {
                include $related_file;
            }
            return ob_get_clean();
    }

    /**
     * Affichage automatique sur les pages de circuit
     */
    public function auto_append_to_circuit_single( $content ) {
        if ( is_singular( 'circuit' ) && in_the_loop() && is_main_query() ) {
            if ( has_shortcode( $content, 'circuit_view' ) ) {
                return $content;
            }
            return $content . $this->render_circuit_view( array( 'id' => get_the_ID() ) );
        }
        return $content;
    }

    /**
     * Shortcode [tour_booking]
     */
    public function render_booking_form( $atts = array() ) {
        // Filet de sécurité : si un page builder a masqué le shortcode au chargement, on charge les assets ici
        if ( class_exists( 'Elite_Transfer_Booking' ) ) {
            Elite_Transfer_Booking::get_instance()->enqueue_public_assets();
        }
        $gen_settings  = get_option( 'etb_general_settings', array() );
        $currency      = $gen_settings['currency'] ?? '€';
        
        // Récupération des réglages administrateur avec valeurs par défaut
        $saved_form_settings = get_option( 'etb_form_settings', array() );
        $default_settings    = array(
            'show_vehicle'   => '0', // Masqué par défaut dans le split layout
            'show_adults'    => '1',
            'show_children'  => '1',
            'show_phone'     => '1',
            'show_pickup'    => '1',
            'show_extras'    => '1',
            'show_name'      => '1',
            'show_email'     => '1',
            'show_date'      => '1',
            'show_time'      => '1',
            'show_total_bag' => '1',
            'show_promo'     => '1',
            'show_note'      => '1',
        );

        $form_settings = ( ! empty( $saved_form_settings ) && is_array( $saved_form_settings ) )
            ? wp_parse_args( $saved_form_settings, $default_settings )
            : $default_settings;

        $vehicles = get_posts( array( 'post_type' => 'tour_vehicle', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'menu_order', 'order' => 'ASC' ) );
        $extras   = get_posts( array( 'post_type' => 'tour_extra', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'menu_order', 'order' => 'ASC' ) );
        $pickups  = array();

        ob_start();
        include ETB_PATH . 'templates/booking-form.php';
        return ob_get_clean();
    }

    /**
     * Shortcode [etb_transfer] : Widget minimaliste pour transferts et mise à disposition
     */
    public function render_transfer_widget( $atts = array() ) {
        ob_start();
        $template_file = ETB_PATH . 'templates/transfer-widget.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<p>Gabarit transfer-widget.php introuvable.</p>';
        }
        return ob_get_clean();
    }
}