<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Ajax {
    public function __construct() {
        add_action( 'wp_ajax_etb_submit_booking', array( $this, 'handle_submit_booking' ) );
        add_action( 'wp_ajax_nopriv_etb_submit_booking', array( $this, 'handle_submit_booking' ) );
        add_action( 'wp_ajax_etb_validate_promo', array( $this, 'handle_validate_promo' ) );
        add_action( 'wp_ajax_nopriv_etb_validate_promo', array( $this, 'handle_validate_promo' ) );

        add_action( 'wp_ajax_etb_resync_booking', array( $this, 'handle_resync_booking' ) );
                add_action( 'wp_ajax_etb_create_payment_intent', array( $this, 'handle_create_payment_intent' ) );
        add_action( 'wp_ajax_nopriv_etb_create_payment_intent', array( $this, 'handle_create_payment_intent' ) );
        add_action( 'wp_ajax_etb_settle_payment', array( $this, 'handle_settle_payment' ) );
        add_action( 'wp_ajax_nopriv_etb_settle_payment', array( $this, 'handle_settle_payment' ) );
        add_action( 'etb_abandoned_cart_cron', array( $this, 'send_abandoned_cart_email' ) );


    }
    
    

    public function handle_validate_promo() {
        check_ajax_referer( 'etb_booking_nonce', 'nonce' );

        // 1. Anti Brute-Force Rate Limiting (Max 15 échecs / 10 min)
        if ( ETB_Security::is_promo_bruteforce_blocked( 15 ) ) {
            wp_send_json_error( array( 'message' => 'Trop de tentatives de code promo. Veuillez patienter 10 minutes.' ) );
        }

        $raw_code   = sanitize_text_field( $_POST['promo_code'] ?? '' );
        $promo_code = strtoupper( trim( $raw_code ) );

        if ( empty( $promo_code ) ) {
            wp_send_json_error( array( 'message' => 'Veuillez saisir un code promo.' ) );
        }

        $query = new WP_Query( array(
            'post_type'      => 'tour_promo',
            'post_status'    => 'publish',
            'title'          => $promo_code,
            'posts_per_page' => 1,
        ) );

        if ( ! $query->have_posts() ) {
            $query = new WP_Query( array(
                'post_type'      => 'tour_promo',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array( 'key' => '_etb_promo_code', 'value' => $promo_code, 'compare' => '=' ),
                ),
            ) );
        }

        if ( $query->have_posts() ) {
            $query->the_post();
            $promo_id = get_the_ID();

            // 2. Vérification de l'état actif du code promo
            $is_active = get_post_meta( $promo_id, '_etb_promo_active', true );
            if ( '0' === $is_active ) {
                wp_reset_postdata();
                ETB_Security::record_failed_promo_attempt();
                wp_send_json_error( array( 'message' => 'Code promo invalide ou désactivé.' ) );
            }

            // 2bis. Contrôle des dates de validité
            $today      = current_time( 'Y-m-d' );
            $valid_from = get_post_meta( $promo_id, '_etb_promo_valid_from', true );
            $valid_to   = get_post_meta( $promo_id, '_etb_promo_valid_to', true );

            if ( ! empty( $valid_from ) && $today < $valid_from ) {
                wp_reset_postdata();
                ETB_Security::record_failed_promo_attempt();
                wp_send_json_error( array( 'message' => 'Ce code promo n\'est pas encore actif.' ) );
            }

            if ( ! empty( $valid_to ) && $today > $valid_to ) {
                wp_reset_postdata();
                ETB_Security::record_failed_promo_attempt();
                wp_send_json_error( array( 'message' => 'Ce code promo a expiré.' ) );
            }

            // 2ter. Contrôle du quota d'utilisations restantes
            $remaining = get_post_meta( $promo_id, '_etb_promo_remaining', true );
            if ( '' !== $remaining && is_numeric( $remaining ) && (int) $remaining <= 0 ) {
                wp_reset_postdata();
                ETB_Security::record_failed_promo_attempt();
                wp_send_json_error( array( 'message' => 'Ce code promo a atteint sa limite maximale d\'utilisations.' ) );
            }

            $discount_type = get_post_meta( $promo_id, '_etb_discount_type', true );

            if ( empty( $discount_type ) ) {
                $discount_type = get_post_meta( $promo_id, '_etb_promo_type', true ) ?: 'fixed';
            }

            $raw_value = get_post_meta( $promo_id, '_etb_discount_value', true );
            if ( '' === $raw_value || false === $raw_value ) {
                $raw_value = get_post_meta( $promo_id, '_etb_promo_value', true );
            }
            if ( '' === $raw_value || false === $raw_value ) {
                $raw_value = get_post_meta( $promo_id, '_etb_value', true );
            }
            $discount_value = floatval( $raw_value );

            wp_reset_postdata();

            wp_send_json_success( array(
                'code'           => $promo_code,
                'discount_type'  => $discount_type,
                'discount_value' => $discount_value,
                'message'        => 'Code promo appliqué avec succès !',
            ) );
        } else {
            wp_reset_postdata();
            ETB_Security::record_failed_promo_attempt();
            wp_send_json_error( array( 'message' => 'Code promo invalide ou expiré.' ) );
        }
    }

    public function handle_submit_booking() {
        check_ajax_referer( 'etb_booking_nonce', 'nonce' );

        // 1. Contrôle Anti-Spam Honeypot
        if ( ! ETB_Security::verify_honeypot( 'etb_hp_email' ) ) {
            wp_send_json_error( array( 'message' => 'Validation de sécurité échouée.' ) );
        }

        // 2. Contrôle de vélocité (Minimum 3 secondes)
        $sec_time  = absint( $_POST['etb_sec_time'] ?? 0 );
        $sec_token = sanitize_text_field( $_POST['etb_sec_token'] ?? '' );
        if ( ! ETB_Security::verify_timestamp_token( $sec_time, $sec_token, 3, 86400 ) ) {
            wp_send_json_error( array( 'message' => 'Soumission trop rapide ou session expirée. Veuillez patienter quelques secondes et réessayer.' ) );
        }

        // 3. Rate Limiting Réservation (10 réservations / 10 minutes par IP)
        if ( ! ETB_Security::check_rate_limit( 'booking', 10, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Trop de demandes de réservation effectuées récemment. Veuillez patienter quelques minutes.' ) );
        }

        // --- Réception et sanitization avec plafonnement ---
        $vehicles = array();
        if ( ! empty( $_POST['etb_car_qty'] ) && is_array( $_POST['etb_car_qty'] ) ) {
            foreach ( $_POST['etb_car_qty'] as $vehicle_id => $qty ) {
                $clean_qty = min( 50, max( 0, absint( $qty ) ) ); // Plafonné à 50
                if ( $clean_qty > 0 ) {
                    $vehicles[ absint( $vehicle_id ) ] = $clean_qty;
                }
            }
        }

        $extras = array();
        foreach ( $_POST as $key => $value ) {
            if ( strpos( $key, 'etb_extra_' ) === 0 ) {
                $extra_id = absint( str_replace( 'etb_extra_', '', $key ) );
                $extras[ $extra_id ] = min( 20, max( 0, absint( $value ) ) ); // Plafonné à 20
            }
        }

        $data = array(
            'vehicles'           => $vehicles,
            'adults'             => min( 200, absint( $_POST['etb_adults'] ?? 0 ) ),
            'children'           => min( 200, absint( $_POST['etb_children'] ?? 0 ) ),
            'baby_seat_count'    => min( 2, absint( $_POST['etb_baby_seat_count'] ?? 0 ) ),
            'booster_seat_count' => min( 3, absint( $_POST['etb_booster_seat_count'] ?? 0 ) ),
            'pickup_id'          => absint( $_POST['etb_pickup_id'] ?? 0 ),
            'pickup_address' => sanitize_text_field( $_POST['etb_pickup_address'] ?? '' ),
            'dropoff_info'   => sanitize_textarea_field( $_POST['etb_dropoff_info'] ?? '' ),
            'option_id'      => sanitize_text_field( $_POST['etb_option_id'] ?? '' ),
            'circuit_id'     => absint( $_POST['etb_circuit_id'] ?? 0 ),
            'extras'         => $extras,
            'name'           => sanitize_text_field( $_POST['etb_name'] ?? '' ),
            'email'          => sanitize_email( $_POST['etb_email'] ?? '' ),
            'phone'          => preg_replace( '/[\s().\-]/', '', sanitize_text_field( $_POST['etb_phone'] ?? '' ) ),            'date'           => sanitize_text_field( $_POST['etb_date'] ?? '' ),
            'time'           => sanitize_text_field( $_POST['etb_time'] ?? '' ),
            'luggage'        => min( 500, absint( $_POST['etb_total_luggage'] ?? 0 ) ),
            'promo'          => sanitize_text_field( $_POST['etb_promo'] ?? '' ),
            'note'           => sanitize_textarea_field( $_POST['etb_notes'] ?? '' ), // Correction : etb_notes avec un S
        );

        // Validation métier
        if ( empty( $data['name'] ) ) {
            wp_send_json_error( array( 'message' => 'Le nom complet est obligatoire.' ) );
        }
        if ( empty( $data['email'] ) || ! is_email( $data['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Une adresse email valide est obligatoire.' ) );
        }
        // Téléphone : obligatoire (sauf champ masqué dans les réglages) et au format international E.164
        $phone_form_settings = get_option( 'etb_form_settings', array() );
        $phone_required      = ( $phone_form_settings['show_phone'] ?? '1' ) !== '0';
        if ( $phone_required && '' === $data['phone'] ) {
            wp_send_json_error( array( 'message' => 'Le numéro de téléphone est obligatoire.' ) );
        }
        if ( '' !== $data['phone'] && ! preg_match( '/^\+[1-9][0-9]{6,14}$/', $data['phone'] ) ) {
            wp_send_json_error( array( 'message' => 'Le numéro de téléphone doit être au format international (ex : +33612345678).' ) );
        }


        if ( empty( $data['date'] ) ) {
            wp_send_json_error( array( 'message' => 'La date est obligatoire.' ) );
        }
        if ( strtotime( $data['date'] ) < strtotime( current_time( 'Y-m-d' ) ) ) {
            wp_send_json_error( array( 'message' => 'La date de réservation ne peut pas être dans le passé.' ) );
        }
        if ( empty( $data['time'] ) ) {
            wp_send_json_error( array( 'message' => 'L\'heure de départ est obligatoire.' ) );
        }
        if ( empty( $data['pickup_address'] ) && empty( $data['pickup_id'] ) ) {
            wp_send_json_error( array( 'message' => 'L\'adresse de prise en charge est obligatoire.' ) );
        }
        if ( empty( $data['vehicles'] ) || array_sum( $data['vehicles'] ) === 0 ) {
            wp_send_json_error( array( 'message' => 'Veuillez sélectionner au moins un véhicule.' ) );
        }
        if ( $data['adults'] < 1 ) {
            wp_send_json_error( array( 'message' => 'Au moins 1 adulte est requis.' ) );
        }

        // Capacités réelles
        $total_capacity_pax     = 0;
        $total_capacity_baggage = 0;

        foreach ( $data['vehicles'] as $vehicle_id => $qty ) {
            if ( $qty <= 0 ) continue;
            if ( get_post_type( $vehicle_id ) !== 'tour_vehicle' || get_post_status( $vehicle_id ) !== 'publish' ) {
                wp_send_json_error( array( 'message' => 'Un véhicule sélectionné est invalide.' ) );
            }
            $total_capacity_pax     += absint( get_post_meta( $vehicle_id, '_etb_max_pax', true ) ) * $qty;
            $total_capacity_baggage += absint( get_post_meta( $vehicle_id, '_etb_max_baggage', true ) ) * $qty;
        }

        if ( ( $data['adults'] + $data['children'] ) > $total_capacity_pax ) {
            wp_send_json_error( array( 'message' => 'Le nombre de passagers dépasse la capacité des véhicules sélectionnés.' ) );
        }


        // Calcul tarifaire
        $pricing_engine  = new ETB_Pricing_Engine();
        $pricing_details = $pricing_engine->calculate_total( $data );
        $data['pricing'] = $pricing_details;

        // Création du CPT tour_booking
        $post_title = sprintf( 'Réservation #%s - %s', $data['name'], $data['date'] );
        $booking_id = wp_insert_post( array(
            'post_title'   => $post_title,
            'post_type'    => 'tour_booking',
            'post_status'  => 'pending',
        ) );

        if ( is_wp_error( $booking_id ) || ! $booking_id ) {
            wp_send_json_error( array( 'message' => 'Impossible de sauvegarder la réservation.' ) );
        }

        // Sauvegarde des métadonnées
        update_post_meta( $booking_id, '_etb_customer_name', $data['name'] );
        update_post_meta( $booking_id, '_etb_customer_email', $data['email'] );
        update_post_meta( $booking_id, '_etb_customer_phone', $data['phone'] );
        update_post_meta( $booking_id, '_etb_booking_date', $data['date'] );
        update_post_meta( $booking_id, '_etb_booking_time', $data['time'] );
        update_post_meta( $booking_id, '_etb_pickup_id', $data['pickup_id'] );
        update_post_meta( $booking_id, '_etb_pickup_address', $data['pickup_address'] );
        update_post_meta( $booking_id, '_etb_dropoff_info', $data['dropoff_info'] );
        update_post_meta( $booking_id, '_etb_circuit_id', $data['circuit_id'] );
        update_post_meta( $booking_id, '_etb_circuit_option_id', $data['option_id'] );
        update_post_meta( $booking_id, '_etb_duration_hours', $pricing_details['duration_hours'] ?? 1 );
        update_post_meta( $booking_id, '_etb_adults', $data['adults'] );
        update_post_meta( $booking_id, '_etb_children', $data['children'] );
        update_post_meta( $booking_id, '_etb_luggage', $data['luggage'] );
        update_post_meta( $booking_id, '_etb_vehicles', $data['vehicles'] );
        update_post_meta( $booking_id, '_etb_extras', $data['extras'] );

        // Nouveaux champs enfants
        $data['total_child_seats'] = $data['baby_seat_count'] + $data['booster_seat_count'];
        $child_seat_fee = $pricing_details['child_seat_fee'] ?? 0;

        update_post_meta( $booking_id, '_etb_baby_seat_count', $data['baby_seat_count'] );
        update_post_meta( $booking_id, '_etb_booster_seat_count', $data['booster_seat_count'] );
        update_post_meta( $booking_id, '_etb_total_child_seats', $data['total_child_seats'] );
        update_post_meta( $booking_id, '_etb_child_seat_fee', $child_seat_fee );

        update_post_meta( $booking_id, '_etb_note', $data['note'] );
        update_post_meta( $booking_id, '_etb_total_price', $pricing_details['grand_total'] );
        update_post_meta( $booking_id, '_etb_pricing_details', $pricing_details );
        
        update_post_meta( $booking_id, '_etb_promo_code', $pricing_details['promo_code'] );
        update_post_meta( $booking_id, '_etb_discount_amount', $pricing_details['discount_amount'] );

        // Décrémentation automatique du quota d'utilisations restantes
        if ( ! empty( $pricing_details['promo_code'] ) && ! empty( $pricing_details['discount_amount'] ) && $pricing_details['discount_amount'] > 0 ) {
            $applied_code = strtoupper( trim( $pricing_details['promo_code'] ) );
            
            $p_query = new WP_Query( array(
                'post_type'      => 'tour_promo',
                'post_status'    => 'publish',
                'title'          => $applied_code,
                'posts_per_page' => 1,
            ) );
            if ( ! $p_query->have_posts() ) {
                $p_query = new WP_Query( array(
                    'post_type'      => 'tour_promo',
                    'post_status'    => 'publish',
                    'meta_key'       => '_etb_promo_code',
                    'meta_value'     => $applied_code,
                    'posts_per_page' => 1,
                ) );
            }

            if ( $p_query->have_posts() ) {
                $used_promo_id = $p_query->posts[0]->ID;
                $rem = get_post_meta( $used_promo_id, '_etb_promo_remaining', true );
                if ( '' !== $rem && is_numeric( $rem ) && (int) $rem > 0 ) {
                    update_post_meta( $used_promo_id, '_etb_promo_remaining', max( 0, (int) $rem - 1 ) );
                }
            }
        }

        // ─────────────────────────────────────────────────────────────
        // GESTION DU PAIEMENT (Pay Later vs Proceed)
        // ─────────────────────────────────────────────────────────────
        $gen_settings     = get_option( 'etb_general_settings', array() );
        $stripe_enabled   = ! empty( $gen_settings['stripe_enabled'] ) && '1' === $gen_settings['stripe_enabled'];
        $is_pay_later     = ( isset( $_POST['pay_later'] ) && '1' === $_POST['pay_later'] );
        
        update_post_meta( $booking_id, '_etb_pay_later', $is_pay_later ? '1' : '0' );

        $payment_url = '';
        $grand_total = $pricing_details['grand_total'] ?? 0;

        // Génération du lien Stripe
        if ( $stripe_enabled && $grand_total > 0 ) {
            $pay_token = 'q_pay_' . bin2hex( random_bytes( 16 ) );
            
            $pay_data = array(
                'booking_id'   => $booking_id,
                'client_name'  => $data['name'],
                'client_email' => $data['email'],
                'client_phone' => $data['phone'],
                'amount'       => floatval( $grand_total ),
            );
            
            set_transient( 'etb_pay_' . $pay_token, $pay_data, 14 * DAY_IN_SECONDS );
            update_post_meta( $booking_id, '_etb_pay_token', $pay_token );

            $payment_base_url = ! empty( $gen_settings['payment_page_url'] ) ? esc_url_raw( $gen_settings['payment_page_url'] ) : home_url( '/payment/' );
            $payment_url = add_query_arg( 'ref', $pay_token, $payment_base_url );
        }
        
        // On injecte l'URL dans $data pour que le JS puisse la lire
        $data['payment_url'] = $payment_url;

        // ─────────────────────────────────────────────────────────────
        // CONSTRUCTION DE LA NOTE LIMOEXPRESS
        // ─────────────────────────────────────────────────────────────
        $payment_status_note = '';
        $currency_symbol     = $gen_settings['currency'] ?? '€';

        if ( ! empty( $payment_url ) ) {
            $status_label = $is_pay_later ? 'PENDING (PAY LATER)' : 'PENDING (ON PAYMENT PAGE)';
            $payment_status_note = "\n═ ⏳ PAYMENT STATUS: {$status_label} ═\n";
            $payment_status_note .= "Amount to pay: " . number_format_i18n( $grand_total, 2 ) . " " . $currency_symbol . "\n";
            $payment_status_note .= "🔗 SECURE PAYMENT LINK READY TO FORWARD:\n";
            $payment_status_note .= "👉 " . $payment_url . "\n";
            $payment_status_note .= "══════════════════════════\n\n";
        } else {
            $payment_status_note = "\n═ ✅ PAYMENT STATUS: NO PAYMENT REQUIRED ═\n\n";
        }

        // ─────────────────────────────────────────────────────────────
        // DISPATCH VERS LIMOEXPRESS
        // ─────────────────────────────────────────────────────────────
        if ( class_exists( 'ETB_Dispatcher_Manager' ) ) {
            $data['trip_mode']         = 'hourly'; // Circuit = toujours horaire
            $data['client_note']       = ! empty( $data['note'] ) ? $data['note'] : 'Aucune';
            $data['paid']              = false;
            $data['payment_logs']      = array();
            $data['payment_intent_id'] = '';
            
            // Injection de la note
            $data['dispatcher_alert']  = $payment_status_note; 

            // Préparation des frais supplémentaires (sièges)
            $limo_extra_fees = array();
            if ( $child_seat_fee > 0 ) {
                $limo_extra_fees[] = array(
                    'category' => 'child_seat_fee',
                    'amount'   => (float) round( $child_seat_fee, 2 ),
                    'value'    => (float) round( $child_seat_fee, 2 ),
                    'active'   => true,
                );
            }
            $data['extra_fees'] = $limo_extra_fees;

            $dispatch_result = ETB_Dispatcher_Manager::dispatch_booking( $booking_id, $data );

            if ( $dispatch_result['success'] ) {
                $limo_id_real = get_post_meta( $booking_id, '_etb_limo_booking_id', true );
                if ( ! empty( $limo_id_real ) ) {
                    update_post_meta( $booking_id, '_etb_limo_status', 'synced' );
                } else {
                    $active_disp = $gen_settings['active_dispatcher'] ?? 'none';
                    if ( 'none' === $active_disp ) {
                        update_post_meta( $booking_id, '_etb_limo_status', 'autonomous' );
                    }
                }
            } else {
                update_post_meta( $booking_id, '_etb_limo_status', 'failed' );
                update_post_meta( $booking_id, '_etb_limo_error', $dispatch_result['message'] );
                $this->send_limo_error_alert( $booking_id, 'Échec lors de la création initiale de la réservation.', $dispatch_result['message'] );
            }
        }

        // ─────────────────────────────────────────────────────────────
        // ENVOI DES E-MAILS
        // ─────────────────────────────────────────────────────────────
        $this->send_booking_emails( $booking_id, $data, $pricing_details, $is_pay_later );

        // ÉTAPE 3 : Programmation de la relance si le client a choisi "Proceed to Payment"
        if ( ! $is_pay_later ) {
            wp_schedule_single_event( time() + 1800, 'etb_abandoned_cart_cron', array( $booking_id ) );
        }

        // ─────────────────────────────────────────────────────────────
        // RÉPONSE JSON FINALE
        // ─────────────────────────────────────────────────────────────
        $data['booking_id'] = $booking_id;
        wp_send_json_success( $data );
    }

    /**
     * Traitement AJAX du bouton "Transférer vers LimoExpress"
     */
    public function handle_resync_booking() {
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        check_ajax_referer( 'etb_resync_nonce_' . $booking_id, 'nonce' );

        if ( ! current_user_can( 'edit_post', $booking_id ) ) {
            wp_send_json_error( array( 'message' => 'Autorisation refusée.' ) );
        }

        // Reconstitution des données de la réservation depuis la base WordPress
        $pricing = get_post_meta( $booking_id, '_etb_pricing_details', true );
        if ( ! is_array( $pricing ) ) {
            $pricing = array(
                'grand_total'     => floatval( get_post_meta( $booking_id, '_etb_total_price', true ) ),
                'duration_hours'  => floatval( get_post_meta( $booking_id, '_etb_duration_hours', true ) ?: 1 ),
                'discount_amount' => floatval( get_post_meta( $booking_id, '_etb_discount_amount', true ) ?: 0 ),
                'promo_code'      => get_post_meta( $booking_id, '_etb_promo_code', true ) ?: '',
            );
        }

        // Récupération des données exhaustives du Checkout & Circuit
        $baby_seat_count    = absint( get_post_meta( $booking_id, '_etb_baby_seat_count', true ) );
        $booster_seat_count = absint( get_post_meta( $booking_id, '_etb_booster_seat_count', true ) );
        $total_child_seats  = absint( get_post_meta( $booking_id, '_etb_total_child_seats', true ) ?: ($baby_seat_count + $booster_seat_count) );
        $tip_amount         = floatval( get_post_meta( $booking_id, '_etb_tip_amount', true ) );
        $child_seat_fee     = floatval( get_post_meta( $booking_id, '_etb_child_seat_fee', true ) );
        $payment_intent_id  = get_post_meta( $booking_id, '_etb_stripe_payment_intent_id', true );
        $status             = get_post_meta( $booking_id, '_etb_status', true );
        $is_paid            = ( 'confirmed' === $status || ! empty( $payment_intent_id ) );

        $extra_fees = array();
        if ( $child_seat_fee > 0 ) {
            $extra_fees[] = array(
                'category' => 'child_seat_fee',
                'amount'   => (float) round( $child_seat_fee, 2 ),
                'value'    => (float) round( $child_seat_fee, 2 ),
                'active'   => true,
            );
        }
        if ( $tip_amount > 0 ) {
            $extra_fees[] = array(
                'category' => 'gratuity_amount',
                'amount'   => (float) round( $tip_amount, 2 ),
                'value'    => (float) round( $tip_amount, 2 ),
                'active'   => true,
            );
        }

        $data = array(
            'vehicles'           => get_post_meta( $booking_id, '_etb_vehicles', true ) ?: array(),
            'adults'             => absint( get_post_meta( $booking_id, '_etb_adults', true ) ),
            'children'           => absint( get_post_meta( $booking_id, '_etb_children', true ) ),
            'pickup_id'          => absint( get_post_meta( $booking_id, '_etb_pickup_id', true ) ),
            'pickup_address'     => get_post_meta( $booking_id, '_etb_pickup_address', true ) ?: '',
            'dropoff_info'       => get_post_meta( $booking_id, '_etb_dropoff_info', true ) ?: '',
            'option_id'          => get_post_meta( $booking_id, '_etb_circuit_option_id', true ) ?: '',
            'circuit_id'         => absint( get_post_meta( $booking_id, '_etb_circuit_id', true ) ),
            'extras'             => get_post_meta( $booking_id, '_etb_extras', true ) ?: array(),
            'name'               => get_post_meta( $booking_id, '_etb_customer_name', true ) ?: '',
            'email'              => get_post_meta( $booking_id, '_etb_customer_email', true ) ?: '',
            'phone'              => get_post_meta( $booking_id, '_etb_customer_phone', true ) ?: '',
            'date'               => get_post_meta( $booking_id, '_etb_booking_date', true ) ?: '',
            'time'               => get_post_meta( $booking_id, '_etb_booking_time', true ) ?: '',
            'luggage'            => absint( get_post_meta( $booking_id, '_etb_luggage', true ) ),
            'checked_luggage'    => absint( get_post_meta( $booking_id, '_etb_checked_luggage', true ) ),
            'cabin_bags'         => absint( get_post_meta( $booking_id, '_etb_cabin_bags', true ) ),
            'baby_seat_count'    => $baby_seat_count,
            'booster_seat_count' => $booster_seat_count,
            'total_child_seats'  => $total_child_seats,
            'tip_amount'         => $tip_amount,
            'extra_fees'         => $extra_fees,
            'paid'               => $is_paid,
            'payment_logs'       => get_post_meta( $booking_id, '_etb_payment_logs', true ) ?: array(),
            'payment_intent_id'  => $payment_intent_id,
            'flight_number'      => get_post_meta( $booking_id, '_etb_flight_number', true ) ?: '',
            'waiting_board_text' => get_post_meta( $booking_id, '_etb_waiting_board_text', true ) ?: '',
            'cost_center'        => get_post_meta( $booking_id, '_etb_cost_center', true ) ?: '',
            'note'               => get_post_meta( $booking_id, '_etb_note', true ) ?: '',
            'pricing'            => $pricing,
        );

        // ─────────────────────────────────────────────────────────────
        // ÉTAPE 4 : Alerte de paiement dans la note LimoExpress
        // ─────────────────────────────────────────────────────────────
        if ( $is_paid ) {
            $data['dispatcher_alert'] = "\n═ ✅ STATUT DE PAIEMENT : PAYÉ EN LIGNE ═\n\n";
        } else {
            $data['dispatcher_alert'] = "\n═ ⚠️ STATUT DE PAIEMENT : NON PAYÉ (En attente) ═\n\n";
        }

        if ( ! class_exists( 'ETB_Dispatcher_Manager' ) ) {
            wp_send_json_error( array( 'message' => 'Gestionnaire de dispatch introuvable.' ) );
        }

        $dispatch_result = ETB_Dispatcher_Manager::dispatch_booking( $booking_id, $data );

        if ( $dispatch_result['success'] ) {
            $limo_id = get_post_meta( $booking_id, '_etb_limo_booking_id', true );
            if ( empty( $limo_id ) ) {
                // Dispatch "réussi" mais rien envoyé (ex: mode autonome)
                wp_send_json_error( array(
                    'message' => 'Aucune synchronisation externe n\'a été effectuée (mode autonome actif).',
                ) );
                return;
            }
            $client_debug = get_post_meta( $booking_id, '_etb_limo_client_debug', true );
            wp_send_json_success( array(
                'limo_id'      => $limo_id,
                'client_debug' => $client_debug,
            ) );
        } else {
            wp_send_json_error( array( 'message' => $dispatch_result['message'] ) );
        }
    }

        /**
     * Crée un PaymentIntent Stripe
     */
    public function handle_create_payment_intent() {
        check_ajax_referer( 'etb_booking_nonce', 'nonce' );

        // ─────────────────────────────────────────────────────────────
        // C-1 SÉCURITÉ : le montant N'EST JAMAIS celui envoyé par le client.
        // On recalcule côté serveur depuis la source de vérité (_etb_total_price
        // + _etb_tip_amount éventuel) et on rejette toute tentative de manipulation.
        // ─────────────────────────────────────────────────────────────
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $name       = sanitize_text_field( $_POST['name'] ?? 'VIP Customer' );
        $email      = sanitize_email( $_POST['email'] ?? '' );

        if ( ! $booking_id ) {
            wp_send_json_error( array( 'message' => 'Booking ID manquant.' ) );
        }

        // Vérification d'existence et d'état du booking
        $booking_post = get_post( $booking_id );
        if ( ! $booking_post || 'tour_booking' !== $booking_post->post_type ) {
            wp_send_json_error( array( 'message' => 'Réservation introuvable.' ) );
        }

        // Blocage si le booking est déjà payé/annulé (évite les doubles intents)
        $current_status = get_post_meta( $booking_id, '_etb_status', true );
        if ( in_array( $current_status, array( 'confirmed', 'cancelled', 'completed' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Cette réservation est déjà traitée.' ) );
        }
        if ( ! empty( get_post_meta( $booking_id, '_etb_stripe_payment_intent_id', true ) ) ) {
            wp_send_json_error( array( 'message' => 'Un paiement existe déjà pour cette réservation.' ) );
        }

        // Calcul du montant serveur : base + pourboire éventuel
        $base_total  = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
        $tip_amount  = floatval( get_post_meta( $booking_id, '_etb_tip_amount', true ) );
        $server_total = round( $base_total + max( 0.0, $tip_amount ), 2 );

        if ( $server_total <= 0 ) {
            wp_send_json_error( array( 'message' => 'Montant de réservation invalide.' ) );
        }

        // Comparaison informative (on ne bloque PAS si écart, on log)
        $client_amount = floatval( $_POST['amount'] ?? 0 );
        if ( abs( $client_amount - $server_total ) > 0.01 ) {
            error_log( sprintf(
                '[ETB Stripe] Écart montant détecté booking #%d — client: %.2f, serveur: %.2f (tentative ignorée)',
                $booking_id, $client_amount, $server_total
            ) );
        }

// ON UTILISE LE MONTANT SERVEUR (pas le montant client)
$amount = $server_total;

        // Création du client Stripe
        $customer_res = ETB_Stripe::create_customer( $name, $email );
        $customer_id  = '';
        if ( ! is_wp_error( $customer_res ) && ! empty( $customer_res['id'] ) ) {
            $customer_id = $customer_res['id'];
        }

        // Création du PaymentIntent (avec capture manuelle si tu veux faire comme Blacklane)
        $gen_settings = get_option( 'etb_general_settings', array() );
        $capture_mode = ! empty( $gen_settings['stripe_capture_method'] ) ? $gen_settings['stripe_capture_method'] : 'manual';
        $intent_res   = ETB_Stripe::create_payment_intent( $amount, 'eur', $customer_id, array( 'booking_id' => $booking_id ), $capture_mode );

        if ( is_wp_error( $intent_res ) ) {
            wp_send_json_error( array( 'message' => $intent_res->get_error_message() ) );
        }

        if ( empty( $intent_res['client_secret'] ) ) {
            wp_send_json_error( array( 'message' => 'Failed to get Stripe client secret.' ) );
        }

        wp_send_json_success( array(
            'client_secret'     => $intent_res['client_secret'],
            'payment_intent_id' => $intent_res['id']
        ) );
    }

    
    /**
     * Finalise la réservation après confirmation du paiement Stripe
     */
    public function handle_settle_payment() {
        check_ajax_referer( 'etb_booking_nonce', 'nonce' );

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $intent_id  = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );

        if ( ! $booking_id || empty( $intent_id ) ) {
            wp_send_json_error( array( 'message' => 'Missing data to settle payment.' ) );
        }

        // 1. Vérification Stripe
        if ( ! class_exists( 'ETB_Stripe' ) ) {
            require_once ETB_PATH . 'includes/class-etb-stripe.php';
        }
        
        $intent_check = ETB_Stripe::retrieve_payment_intent( $intent_id );
        if ( is_wp_error( $intent_check ) ) {
            wp_send_json_error( array( 'message' => 'Stripe Error: ' . $intent_check->get_error_message() ) );
        }

        $intent_status = $intent_check['status'] ?? '';
        if ( ! in_array( $intent_status, array( 'succeeded', 'requires_capture' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Stripe transaction not valid (Status: ' . $intent_status . ').' ) );
        }

        // 2. Mise à jour de WordPress
        update_post_meta( $booking_id, '_etb_status', 'confirmed' );

        // ÉTAPE 3 : Annulation de la relance (le client a payé)
        wp_clear_scheduled_hook( 'etb_abandoned_cart_cron', array( $booking_id ) );
        // Extraction du moyen de paiement exact (Visa, Apple Pay, Google Pay...)
        $payment_method_label = 'Online Payment';
        if ( ! empty( $intent_check['latest_charge']['payment_method_details'] ) ) {
            $pm_details = $intent_check['latest_charge']['payment_method_details'];
            $pm_type    = $pm_details['type'] ?? 'card';

            if ( 'card' === $pm_type ) {
                $brand  = isset( $pm_details['card']['brand'] ) ? ucfirst( $pm_details['card']['brand'] ) : 'Card';
                $last4  = isset( $pm_details['card']['last4'] ) ? $pm_details['card']['last4'] : '';
                $wallet = $pm_details['card']['wallet']['type'] ?? '';

                if ( 'apple_pay' === $wallet ) {
                    $payment_method_label = 'Apple Pay (•••• ' . $last4 . ')';
                } elseif ( 'google_pay' === $wallet ) {
                    $payment_method_label = 'Google Pay (•••• ' . $last4 . ')';
                } else {
                    $payment_method_label = $brand . ' •••• ' . $last4;
                }
            } elseif ( 'apple_pay' === $pm_type ) {
                $payment_method_label = 'Apple Pay';
            } elseif ( 'google_pay' === $pm_type ) {
                $payment_method_label = 'Google Pay';
            }
        }
        update_post_meta( $booking_id, '_etb_payment_method_label', $payment_method_label );
        update_post_meta( $booking_id, '_etb_stripe_payment_intent_id', $intent_id );
        update_post_meta( $booking_id, '_etb_paid_at', current_time( 'mysql' ) );

        // 3. Préparation du Payload COMPLET pour LimoExpress
        $gen_settings    = get_option( 'etb_general_settings', array() );
        $limo_uuid       = get_post_meta( $booking_id, '_etb_limo_uuid', true );
        $limo_token      = $gen_settings['limo_api_token'] ?? '';
        $current_note    = get_post_meta( $booking_id, '_etb_note', true );
        
        if ( ! empty( $limo_token ) && ! empty( $limo_uuid ) ) {
            
            // Construction de la nouvelle note
            $total_amount = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
            $currency_sym = $gen_settings['currency'] ?? '€';
            $stripe_mode  = ( ! empty( $gen_settings['stripe_mode'] ) && 'live' === $gen_settings['stripe_mode'] ) ? 'live' : 'test';
            $stripe_url   = ( 'live' === $stripe_mode ) 
                ? 'https://dashboard.stripe.com/payments/' . $intent_id
                : 'https://dashboard.stripe.com/test/payments/' . $intent_id;

            $paid_stamp = "\n═ ✅ ONLINE PAYMENT COLLECTED ═\n"
                . "💳 Amount Paid: " . number_format_i18n( $total_amount, 2 ) . " " . $currency_sym . "\n"
                . "📅 Paid on: " . date( 'd M Y \a\t H:i' ) . "\n"
                . "🔗 DIRECT STRIPE LINK:\n" . $stripe_url . "\n"
                . "═══════════════════════════════\n\n";

            // H-2 : Approche d'ingénierie robuste (String Slicing)
            $current_note_str = (string) $current_note;
            $start_marker     = '═ ⏳ PAYMENT STATUS: PENDING';
            $end_marker       = '═DÉTAILS RÉSERVATION═';

            $start_pos = strpos( $current_note_str, $start_marker );
            $end_pos   = strpos( $current_note_str, $end_marker );

            if ( false !== $start_pos && false !== $end_pos && $start_pos < $end_pos ) {
                // On garde ce qu'il y a avant le statut PENDING (souvent vide) 
                // et on recolle avec ce qu'il y a à partir de "DÉTAILS RÉSERVATION"
                $clean_note = substr( $current_note_str, 0, $start_pos ) . substr( $current_note_str, $end_pos );
            } else {
                // Si les marqueurs ne sont pas trouvés, on garde la note intacte par sécurité
                $clean_note = $current_note_str;
            }
            $updated_note = $paid_stamp . trim( $clean_note );
            
            update_post_meta( $booking_id, '_etb_note', $updated_note );

            // Récupération des infos nécessaires pour le payload
            $pickup_addr  = get_post_meta( $booking_id, '_etb_pickup_address', true ) ?: 'Pickup';
            $dropoff_addr = get_post_meta( $booking_id, '_etb_dropoff_info', true ) ?: $pickup_addr;
            $booking_dt   = get_post_meta( $booking_id, '_etb_booking_date', true );
            $booking_tm   = get_post_meta( $booking_id, '_etb_booking_time', true ) ?: '09:00';
            $pickup_iso   = sprintf( '%s %s:00', $booking_dt, substr( $booking_tm, 0, 5 ) );

            // Type de réservation (on prend celui des réglages ou par défaut)
            $type_id = $gen_settings['limo_booking_type_id'] ?? '';
            if ( strpos( $dropoff_addr, 'By the hour' ) !== false && ! empty( $gen_settings['limo_hourly_type_id'] ) ) {
                $type_id = $gen_settings['limo_hourly_type_id'];
            }

            // Statut initial (ex: Pending)
            $status_id = $gen_settings['limo_booking_status_id'] ?? '';

            // Classe de véhicule
            $vehicle_class_id = '';
            $booked_vehicles  = get_post_meta( $booking_id, '_etb_vehicles', true );
            if ( ! empty( $booked_vehicles ) && is_array( $booked_vehicles ) ) {
                foreach ( $booked_vehicles as $v_id => $qty ) {
                    if ( $qty > 0 ) {
                        $v_class = get_post_meta( $v_id, '_etb_limo_class_id', true );
                        if ( ! empty( $v_class ) ) {
                            $vehicle_class_id = trim( $v_class );
                            break;
                        }
                    }
                }
            }

            // On construit un Payload complet pour satisfaire l'API LimoExpress
            $update_payload = array(
                'id'                 => (string) $limo_uuid,
                'booking_type_id'    => (string) $type_id,
                'booking_status_id'  => (string) $status_id,
                'from_location'      => array( 'name' => $pickup_addr ),
                'to_location'        => array( 'name' => $dropoff_addr ),
                'pickup_time'        => $pickup_iso,
                'note'               => $updated_note,
                'paid'               => true,
                'confirmed'          => true,
            );

            if ( ! empty( $vehicle_class_id ) ) {
                $update_payload['vehicle_class_id'] = $vehicle_class_id;
            }

            $headers = array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $limo_token,
            );

            // 1. Envoi de la mise à jour complète (NOTE + STATUT PAYÉ)
            $res_update = wp_remote_post( 'https://api.limoexpress.me/api/integration/bookings', array(
                'headers' => $headers,
                'body'    => wp_json_encode( $update_payload ),
                'timeout' => 15,
            ) );
            
            // 2. Déclenchement formel du statut "Payé"
            $res_mark_paid = wp_remote_post( 'https://api.limoexpress.me/api/integration/mark-booking-as-paid', array(
                'headers' => $headers,
                'body'    => wp_json_encode( array( 'id' => $limo_uuid ) ),
                'timeout' => 15,
            ) );

            // ─────────────────────────────────────────────────────────────
            // H-1 : VÉRIFICATION DES RÉPONSES LIMOEXPRESS
            // On ne bloque pas le client (il a payé), mais on logge l'erreur admin.
            // ─────────────────────────────────────────────────────────────
            $sync_error = '';

            if ( is_wp_error( $res_update ) ) {
                $sync_error .= 'Update error: ' . $res_update->get_error_message() . ' | ';
            } else {
                $code_update = wp_remote_retrieve_response_code( $res_update );
                if ( $code_update < 200 || $code_update >= 300 ) {
                    $sync_error .= 'Update HTTP ' . $code_update . ' | ';
                }
            }

            if ( is_wp_error( $res_mark_paid ) ) {
                $sync_error .= 'Mark Paid error: ' . $res_mark_paid->get_error_message() . ' | ';
            } else {
                $code_paid = wp_remote_retrieve_response_code( $res_mark_paid );
                if ( $code_paid < 200 || $code_paid >= 300 ) {
                    $sync_error .= 'Mark Paid HTTP ' . $code_paid . ' | ';
                }
            }

            if ( ! empty( $sync_error ) ) {
                // On enregistre l'échec pour que l'admin le voie dans la fiche de la réservation
                update_post_meta( $booking_id, '_etb_limo_status', 'payment_sync_failed' );
                update_post_meta( $booking_id, '_etb_limo_error', 'Paiement Stripe OK, mais échec synchro LimoExpress : ' . $sync_error );
                error_log( '[ETB Limo] Échec de synchro post-paiement pour le booking #' . $booking_id . ' : ' . $sync_error );
                
                // ALERTE EMAIL ADMIN
                $this->send_limo_error_alert( $booking_id, 'Le client a payé, mais la mise à jour LimoExpress a échoué.', $sync_error );
                
            } else {
                // Si tout va bien, on s'assure que le statut est à jour (optionnel mais propre)
                update_post_meta( $booking_id, '_etb_limo_status', 'synced' );
            }
        }

                // ÉTAPE 1 : Envoi du Reçu de Paiement final au client
        $this->send_payment_receipt_email( $booking_id );
        

        // 4. Redirection succès
        $pay_token = get_post_meta( $booking_id, '_etb_pay_token', true );
        $payment_base_url = ! empty( $gen_settings['payment_page_url'] ) ? esc_url_raw( $gen_settings['payment_page_url'] ) : home_url( '/payment/' );
        $redirect_url = add_query_arg( array( 'ref' => $pay_token, 'status' => 'success' ), $payment_base_url );

        wp_send_json_success( array( 'redirect_url' => $redirect_url ) );
    }

    /**
     * Envoie les e-mails transactionnels (Client + Admin) après création de la réservation.
     */
    private function send_booking_emails( $booking_id, $data, $pricing_details, $is_pay_later ) {
        $gen_settings    = get_option( 'etb_general_settings', array() );
        $currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
        $company_name    = get_bloginfo( 'name' );
        $admin_email     = ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' );

        $customer_name    = $data['name'];
        $display_id = $this->get_formatted_booking_id( $booking_id );
        $customer_email   = $data['email'];
        $formatted_amount = number_format_i18n( $pricing_details['grand_total'], 2 ) . ' ' . $currency_symbol;

        // Date & Time
        $date_ts = strtotime( $data['date'] );
        $formatted_date = $date_ts ? date( 'd M Y', $date_ts ) : $data['date'];
        $time_ts = strtotime( '1970-01-01 ' . $data['time'] );
        $formatted_time = $time_ts ? date( 'h:i A', $time_ts ) : $data['time'];
        $datetime_display = $formatted_date . ' at ' . $formatted_time;

        // Circuit & Véhicule
        $circuit_title = ! empty( $data['circuit_id'] ) ? get_the_title( $data['circuit_id'] ) : 'VIP Circuit';
        $vehicle_title = 'Luxury Vehicle';
        foreach ( $data['vehicles'] as $v_id => $qty ) {
            if ( $qty > 0 ) { $vehicle_title = get_the_title( $v_id ); break; }
        }

        $payment_url    = $data['payment_url'] ?? '';
        $headers_client = ETB_Settings::get_mail_headers( $admin_email, $company_name );
        $headers_admin  = ETB_Settings::get_mail_headers( $customer_email, $customer_name );

        $theme_primary = '#ba6827';
        $text_dark     = '#1e293b';
        $text_light    = '#64748b';
        $bg_light      = '#f8fafc';
        $border_color  = '#e2e8f0';

        // Styles Responsives (Mobile First)
        $style_body   = "background-color: #f1f5f9; padding: 10px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: {$text_dark};";
        $style_card   = "max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid {$border_color};";
        $style_header = "padding: 20px; text-align: center; border-bottom: 1px solid {$border_color}; background: {$bg_light};";
        $style_btn    = "display: inline-block; background: {$theme_primary}; color: #ffffff; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; text-decoration: none; margin-top: 10px; text-align: center; width: 100%; box-sizing: border-box;";
        
        $style_label  = "font-size: 11px; color: {$text_light}; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; display: block; margin-bottom: 4px;";
        $style_value  = "font-size: 14px; font-weight: 600; color: {$text_dark}; display: block; margin-bottom: 15px;";

        // ══════════════════════════════════════════════════════════════
        // 1. EMAIL CLIENT (Envoyé UNIQUEMENT si "Pay Later")
        // ══════════════════════════════════════════════════════════════
        if ( $is_pay_later && ! empty( $customer_email ) && is_email( $customer_email ) ) {
            $subject_client = $is_pay_later ? 'Reservation Confirmed ' . $display_id . ' - Payment Pending' : 'Action Required: Complete Your Booking ' . $display_id;

            
            $btn_html = '';
            if ( ! empty( $payment_url ) ) {
                $btn_html = '<div style="margin-top: 25px; text-align: center;"><a href="' . esc_url( $payment_url ) . '" style="' . $style_btn . '">Proceed to Payment</a></div>';
            }

            $message_client = '<div style="' . $style_body . '">'
                . '<div style="' . $style_card . '">'
                . '<div style="' . $style_header . '">'
                . '<p style="font-size: 14px; color: ' . $theme_primary . '; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; margin: 0;">' . esc_html( $company_name ) . '</p>'
                . '<h1 style="font-size: 20px; font-weight: 800; margin: 8px 0 0 0; color: ' . $text_dark . ';">' . ( $is_pay_later ? 'Booking Received' : 'Complete Your Payment' ) . '</h1>'
                . '<span>'. esc_html( $limo_id ) . '</span>'
                . '</div>'
                . '<div style="padding: 20px;">'
                . '<p style="font-size: 15px; margin-top: 0; color: ' . $text_dark . ';">Dear <strong>' . esc_html( $customer_name ) . '</strong>,</p>'
                . '<p style="font-size: 14px; color: ' . $text_light . '; margin-bottom: 20px;">' . ( $is_pay_later ? 'Your reservation is saved. Please complete the payment to confirm your ride.' : 'We saved your reservation. Please finalize your payment to secure your vehicle.' ) . '</p>'
                
                // Nom du circuit mis en avant
                . '<h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">' . esc_html( strtoupper( $circuit_title ) ) . '</h3>'

                // Bloc détails
                . '<div style="background: ' . $bg_light . '; border-radius: 8px; border: 1px solid ' . $border_color . '; padding: 15px;">'
                . '<div><span style="' . $style_label . '">Date & Time</span><span style="' . $style_value . '">' . esc_html( $datetime_display ) . '</span></div>'
                . '<div><span style="' . $style_label . '">Vehicle</span><span style="' . $style_value . '">' . esc_html( $vehicle_title ) . '</span></div>'
                . '<div><span style="' . $style_label . '">Pickup</span><span style="' . $style_value . '">' . esc_html( $data['pickup_address'] ) . '</span></div>'
                . '<div style="margin-bottom: 0;"><span style="' . $style_label . '">Total Amount</span><span style="font-size: 20px; font-weight: 800; color: ' . $theme_primary . '; display: block;">' . $formatted_amount . '</span></div>'
                . '</div>'
                
                . $btn_html
                . '</div>'
                . '<div style="background: ' . $bg_light . '; border-top: 1px solid ' . $border_color . '; padding: 15px; text-align: center;">'
                . '<p style="margin: 0; font-size: 12px; color: ' . $text_light . ';">' . esc_html( $company_name ) . ' — The Mediterranean coastline within your reach</p>'
                . '</div>'
                . '</div></div>';

            wp_mail( $customer_email, $subject_client, $message_client, $headers_client );
        }

        // ══════════════════════════════════════════════════════════════
        // 2. EMAIL ADMIN (Notification simple)
        // ══════════════════════════════════════════════════════════════
        if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
            $payment_tag = $is_pay_later ? 'PAY LATER' : ( empty($payment_url) ? 'CONFIRMED' : 'PENDING PAYMENT' );
            $subject_admin  = '[NEW BOOKING] ' . $display_id . ' - ' . $payment_tag . ' - ' . esc_html( $customer_name );
            
            $admin_link = ! empty( $payment_url ) ? '<div style="margin-top: 15px; text-align: center;"><a href="' . esc_url( $payment_url ) . '" style="' . $style_btn . '">View Payment Link</a></div>' : '';

            $message_admin = '<div style="' . $style_body . '">'
                . '<div style="' . $style_card . '">'
                . '<div style="' . $style_header . '; text-align: left;">'
                . '<h2 style="font-size: 18px; margin: 0; color: ' . $text_dark . ';">New Booking: <span style="color: ' . $theme_primary . ';">' . $payment_tag . '</span></h2>'
                . '</div>'
                . '<div style="padding: 10px;">'
                . '<div style="margin-bottom: 15px;"><span style="' . $style_label . '">Mission ID</span><span style="' . $style_value . '">' . esc_html( $display_id ) . '</span></div>'
                
                // Nom du circuit
                . '<h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">' . esc_html( strtoupper( $circuit_title ) ) . '</h3>'

                . '<div style="background: ' . $bg_light . '; border-radius: 8px; border: 1px solid ' . $border_color . '; padding: 15px;">'
                . '<div><span style="' . $style_label . '">Customer</span><span style="' . $style_value . '">' . esc_html( $customer_name ) . ' (' . esc_html( $data['phone'] ) . ')</span></div>'
                . '<div><span style="' . $style_label . '">Vehicle</span><span style="' . $style_value . '">' . esc_html( $vehicle_title ) . '</span></div>'
                . '<div><span style="' . $style_label . '">Date & Time</span><span style="' . $style_value . '">' . esc_html( $datetime_display ) . '</span></div>'
                . '<div style="margin-bottom: 0;"><span style="' . $style_label . '">Total Due</span><span style="font-size: 20px; font-weight: 800; color: ' . $text_dark . '; display: block;">' . $formatted_amount . '</span></div>'
                . '</div>'
                
                . $admin_link
                . '</div>'
                . '</div>'
                . '</div>';

            wp_mail( $admin_email, $subject_admin, $message_admin, $headers_admin );
        }
    }


        /**
     * Envoie un email d'alerte à l'admin en cas d'échec de synchro LimoExpress.
     */
    private function send_limo_error_alert( $booking_id, $context_message, $error_detail ) {
        $gen_settings = get_option( 'etb_general_settings', array() );
        $admin_email  = ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' );
        
        if ( empty( $admin_email ) || ! is_email( $admin_email ) ) return;

        $customer_name = get_post_meta( $booking_id, '_etb_customer_name', true ) ?: 'Client Inconnu';
        $total_price   = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
        $currency      = ! empty( $gen_settings['currency'] ) ? $gen_settings['currency'] : '€';
        $edit_link     = admin_url( 'post.php?post=' . $booking_id . '&action=edit' );

        $subject = '🚨 ALERTE : Échec LimoExpress - Réservation #' . $booking_id;
        
        $message = '<h2>Échec de synchronisation LimoExpress</h2>';
        $message .= '<p><strong>Contexte :</strong> ' . esc_html( $context_message ) . '</p>';
        $message .= '<p><strong>Erreur :</strong> <code style="color:red;">' . esc_html( $error_detail ) . '</code></p>';
        $message .= '<hr>';
        $message .= '<p><strong>Client :</strong> ' . esc_html( $customer_name ) . '</p>';
        $message .= '<p><strong>Montant :</strong> ' . number_format_i18n( $total_price, 2 ) . ' ' . esc_html( $currency ) . '</p>';
        $message .= '<p><a href="' . esc_url( $edit_link ) . '" style="display:inline-block; padding:10px 10px; background:#dc2626; color:#fff; text-decoration:none; border-radius:5px; font-weight:bold;">Voir la réservation dans WordPress</a></p>';

        $headers = ETB_Settings::get_mail_headers( $admin_email, 'Système LimoExpress' );
        wp_mail( $admin_email, $subject, $message, $headers );
    }


        /**
     * Envoie le reçu de paiement final au client après succès Stripe.
     */
        /**
     * Envoie le reçu de paiement final au client après succès Stripe.
     */
    private function send_payment_receipt_email( $booking_id ) {
        $gen_settings    = get_option( 'etb_general_settings', array() );
        $currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
        $payment_method_label = get_post_meta( $booking_id, '_etb_payment_method_label', true ) ?: 'Card / Wallet';
        $company_name    = get_bloginfo( 'name' );
        $admin_email     = ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' );

        $customer_email   = get_post_meta( $booking_id, '_etb_customer_email', true );
        $display_id = $this->get_formatted_booking_id( $booking_id );
        $customer_name    = get_post_meta( $booking_id, '_etb_customer_name', true );
        $booking_date     = get_post_meta( $booking_id, '_etb_booking_date', true );
        $booking_time     = get_post_meta( $booking_id, '_etb_booking_time', true );
        $pickup_address   = get_post_meta( $booking_id, '_etb_pickup_address', true );
        $dropoff_info     = get_post_meta( $booking_id, '_etb_dropoff_info', true ) ?: $pickup_address;
        $total_price      = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
        $vehicles         = get_post_meta( $booking_id, '_etb_vehicles', true ) ?: array();
        $adults           = absint( get_post_meta( $booking_id, '_etb_adults', true ) );
        $children         = absint( get_post_meta( $booking_id, '_etb_children', true ) );
        $luggage          = absint( get_post_meta( $booking_id, '_etb_luggage', true ) );
        $flight_number    = get_post_meta( $booking_id, '_etb_flight_number', true );
        $baby_seats       = absint( get_post_meta( $booking_id, '_etb_baby_seat_count', true ) );
        $booster_seats    = absint( get_post_meta( $booking_id, '_etb_booster_seat_count', true ) );
        $limo_id          = get_post_meta( $booking_id, '_etb_limo_booking_id', true );
        $intent_id        = get_post_meta( $booking_id, '_etb_stripe_payment_intent_id', true );
        
        $circuit_id       = get_post_meta( $booking_id, '_etb_circuit_id', true );
        $circuit_title    = $circuit_id ? get_the_title( $circuit_id ) : 'VIP Circuit';

        if ( empty( $customer_email ) || ! is_email( $customer_email ) ) return;

        $formatted_amount = number_format_i18n( $total_price, 2 ) . ' ' . $currency_symbol;
        $date_ts          = strtotime( $booking_date );
        $formatted_date   = $date_ts ? date( 'd M Y', $date_ts ) : $booking_date;
        $time_ts          = strtotime( '1970-01-01 ' . $booking_time );
        $formatted_time   = $time_ts ? date( 'h:i A', $time_ts ) : $booking_time;
        $datetime_display = $formatted_date . ' at ' . $formatted_time;

        $vehicle_title = 'Luxury Vehicle';
        foreach ( $vehicles as $v_id => $qty ) {
            if ( $qty > 0 ) { $vehicle_title = get_the_title( $v_id ); break; }
        }

        // Avant : $subject_client = 'Receipt - Booking #' . $booking_id . ' Confirmed & Paid';
        // Après :
        $subject_client = 'Receipt - Mission ' . $display_id . ' Confirmed & Paid';
        $headers_client = ETB_Settings::get_mail_headers( $admin_email, $company_name );

        // Construction du bloc des préférences
        $preferences_html = '';
        if ( $baby_seats > 0 ) $preferences_html .= '<li>👶 ' . $baby_seats . ' Baby Seat(s)</li>';
        if ( $booster_seats > 0 ) $preferences_html .= '<li>🧒 ' . $booster_seats . ' Booster Seat(s)</li>';
        if ( ! empty( $flight_number ) ) $preferences_html .= '<li>✈️ Flight Number: ' . esc_html( $flight_number ) . '</li>';
        if ( ! empty( $limo_id ) ) $preferences_html .= '<li>🚘 Mission Ref: ' . esc_html( $limo_id ) . '</li>';

        // HTML de l'email avec Responsive Design (Mobile First)
        $message_client = '
        <div style="background-color: #f1f5f9; padding: 10px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1e293b;">
            <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
                
                <div style="padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                    <h1 style="font-size: 20px; font-weight: 800; margin: 0; color: #1e293b;">Payment Received</h1>
                </div>

                <div style="padding: 20px;">
                    <p style="font-size: 15px; margin-top: 0; color: #1e293b;">Dear <strong>' . esc_html( $customer_name ) . '</strong>,</p>
                    <p style="font-size: 14px; color: #64748b; margin-bottom: 20px;">Your payment has been successfully authorized and confirmed. Your mission is locked in dispatch.</p>
                    
                    <!-- BLOC VERT : DÉTAILS DU PAIEMENT -->
                    <div style="background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                        <div style="margin-bottom: 15px;">
                            <span style="font-size: 13px; color: #4b5563; display: block;">Total Amount Paid:</span>
                            <span style="font-size: 26px; font-weight: 800; color: #15803d; display: block;">' . $formatted_amount . '</span>
                        </div>
                        <div style="border-top: 1px solid #c8e6c9; padding-top: 15px; margin-bottom: 15px;">
                            <span style="font-size: 13px; color: #4b5563; display: block;">Payment Method:</span>
                           <span style="font-size: 15px; font-weight: 600; color: #1e293b; display: block;">' . esc_html( $payment_method_label ) . '</span>
                        </div>
                        <div>
                            <span style="font-size: 13px; color: #4b5563; display: block;">Transaction ID:</span>
                            <span style="font-size: 13px; font-weight: 600; color: #15803d; display: block; word-break: break-all;">' . esc_html( $intent_id ) . '</span>
                        </div>
                    </div>

                    <!-- DÉTAILS DU CIRCUIT -->
                    <h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">' . esc_html( strtoupper( $circuit_title ) ) . '</h3>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px;">
                        <div style="margin-bottom: 12px;">
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Date & Time</span><br>
                            <span style="font-size: 14px; font-weight: 600;">' . esc_html( $datetime_display ) . '</span>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Vehicle</span><br>
                            <span style="font-size: 14px; font-weight: 600;">' . esc_html( $vehicle_title ) . '</span>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Pickup</span><br>
                            <span style="font-size: 14px; font-weight: 600;">' . esc_html( $pickup_address ) . '</span>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Drop-off</span><br>
                            <span style="font-size: 14px; font-weight: 600;">' . esc_html( $dropoff_info ) . '</span>
                        </div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Passengers & Luggage</span><br>
                            <span style="font-size: 14px; font-weight: 600;">' . $adults . ' Adults, ' . $children . ' Children • ' . $luggage . ' Luggage</span>
                        </div>
                    </div>';

        if ( ! empty( $preferences_html ) ) {
            $message_client .= '
            <h3 style="font-size: 14px; font-weight: 700; color: #1e293b; margin: 20px 0 10px 0;">Client Preferences:</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px; color: #1e293b;">' . $preferences_html . '</ul>';
        }

        $message_client .= '
                </div>
                <div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 15px; text-align: center;">
                    <p style="margin: 0; font-size: 12px; color: #64748b;">' . esc_html( $company_name ) . ' — The Mediterranean coastline within your reach</p>
                </div>
            </div>
        </div>';

        wp_mail( $customer_email, $subject_client, $message_client, $headers_client );
    }

        /**
     * ÉTAPE 3 : Envoie un email de relance si la réservation est toujours en attente.
     */
    public function send_abandoned_cart_email( $booking_id ) {
        // 1. Vérification du statut
        $status = get_post_meta( $booking_id, '_etb_status', true );
        if ( 'confirmed' === $status || 'completed' === $status ) {
            return; // Le client a déjà payé, on ne fait rien.
        }

        $gen_settings    = get_option( 'etb_general_settings', array() );
        $currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
        $company_name    = get_bloginfo( 'name' );
        $admin_email     = ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' );

        $customer_email = get_post_meta( $booking_id, '_etb_customer_email', true );
        $display_id = $this->get_formatted_booking_id( $booking_id );
        $customer_name  = get_post_meta( $booking_id, '_etb_customer_name', true );
        $total_price    = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
        $pay_token      = get_post_meta( $booking_id, '_etb_pay_token', true );
        $circuit_id     = get_post_meta( $booking_id, '_etb_circuit_id', true );
        $circuit_title  = $circuit_id ? get_the_title( $circuit_id ) : 'VIP Circuit';

        if ( empty( $customer_email ) || ! is_email( $customer_email ) || empty( $pay_token ) ) {
            return;
        }

        $formatted_amount = number_format_i18n( $total_price, 2 ) . ' ' . $currency_symbol;
        $payment_base_url = ! empty( $gen_settings['payment_page_url'] ) ? esc_url_raw( $gen_settings['payment_page_url'] ) : home_url( '/payment/' );
        $payment_url      = add_query_arg( 'ref', $pay_token, $payment_base_url );

        $subject_client = 'Did you forget to complete your booking? ' . $display_id;
        $headers_client = ETB_Settings::get_mail_headers( $admin_email, $company_name );

        $theme_primary = '#ba6827';
        $text_dark     = '#1e293b';
        $text_light    = '#64748b';
        
        $message_client = '
        <div style="background-color: #f1f5f9; padding: 10px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: ' . $text_dark . ';">
            <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
                <div style="padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                    <h1 style="font-size: 20px; font-weight: 800; margin: 0; color: #1e293b;">Action Required</h1>
                </div>
                <div style="padding: 20px;">
                    <p style="font-size: 15px; margin-top: 0; color: #1e293b;">Dear <strong>' . esc_html( $customer_name ) . '</strong>,</p>
                    <p style="font-size: 14px; color: #64748b; margin-bottom: 20px;">We noticed you started your booking but didn\'t finish the payment. Your reservation is not secured yet!</p>
                    
                    <h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">' . esc_html( strtoupper( $circuit_title ) ) . '</h3>
                    
                    <div style="background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; padding: 15px; text-align: center;">
                        <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 5px;">Amount Pending</span>
                        <span style="font-size: 22px; font-weight: 800; color: ' . $theme_primary . '; display: block; margin-bottom: 15px;">' . $formatted_amount . '</span>
                        <a href="' . esc_url( $payment_url ) . '" style="display: inline-block; background: ' . $theme_primary . '; color: #ffffff; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; text-decoration: none; text-align: center; width: 100%; box-sizing: border-box;">Complete My Payment</a>
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin-top: 20px; text-align: center;">If you have any questions, reply to this email.</p>
                </div>
                <div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 15px; text-align: center;">
                    <p style="margin: 0; font-size: 12px; color: #64748b;">' . esc_html( $company_name ) . ' — The Mediterranean coastline within your reach</p>
                </div>
            </div>
        </div>';

        wp_mail( $customer_email, $subject_client, $message_client, $headers_client );
    }

        /**
     * Retourne l'ID formaté pour l'affichage (LimoExpress en priorité, sinon WP).
     */
    private function get_formatted_booking_id( $booking_id ) {
        $limo_id = get_post_meta( $booking_id, '_etb_limo_booking_id', true );
        if ( ! empty( $limo_id ) && 'OK' !== $limo_id ) {
            return '#' . $limo_id; // ex: #350/2026
        }
        return '#WP-' . $booking_id; // Fallback ex: #WP-606
    }

}