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
        update_post_meta( $booking_id, '_etb_customer_phone', $data['phone'] ); // <-- AJOUT DE CETTE LIGNE
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
            
            // Recherche du coupon utilisé
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

        // ─────────────────────────────────────────────────────────────────────
        // DISPATCH AUTOMATIQUE VERS LIMOEXPRESS (Phase 1)
        //
        // Corrige le bug : la métadonnée _etb_limo_status restait vide après
        // une réservation, ce qui affichait « Non synchronisé / En attente »
        // dans l'admin WordPress.
        //
        // Reproduit le pattern éprouvé de [ETB] handle_submit_checkout()
        // (branche mono-trajets), adapté aux spécificités Circuit :
        //   - Un circuit = prestation à durée fixe ⇒ trip_mode = 'hourly'
        //     (LimoExpress reçoit to_location = "As Directed (À disposition)").
        //   - Pas de Stripe en Circuit v1.0.4 ⇒ paid = false, logs vides.
        //   - Pas de champs Blacklane (flight/waiting_board/cost_center).
        //   - Sièges bébé déjà gérés nativement par send_booking() via les
        //     extras dont le nom contient « bébé / bebe / baby ».
        // ─────────────────────────────────────────────────────────────────────
        if ( class_exists( 'ETB_Dispatcher_Manager' ) ) {
            $data['trip_mode']         = 'hourly';
            $data['client_note']       = ! empty( $data['note'] ) ? $data['note'] : 'Aucune';
            $data['paid']              = false;
            $data['payment_logs']      = array();
            $data['payment_intent_id'] = '';

            // Préparation des frais supplémentaires pour LimoExpress
            $limo_extra_fees = array();
            
            // 1. Ajout du supplément Siège Enfant
            if ( $child_seat_fee > 0 ) {
                $limo_extra_fees[] = array(
                    'category' => 'child_seat_fee',
                    'amount'   => (float) round( $child_seat_fee, 2 ),
                    'value'    => (float) round( $child_seat_fee, 2 ),
                    'active'   => true,
                );
            }

            // 2. On ne passe QUE les frais que LimoExpress reconnaît (Sièges enfants).
            // Les autres extras sont déjà inclus dans le prix de base.
            $data['extra_fees'] = $limo_extra_fees;

            $dispatch_result = ETB_Dispatcher_Manager::dispatch_booking( $booking_id, $data );

            if ( $dispatch_result['success'] ) {
                // Ne déclarer 'synced' QUE si LimoExpress a réellement retourné un ID.
                // En mode autonome ('none'), dispatch_booking retourne success=true
                // mais AUCUN envoi externe n'a eu lieu : on ne doit pas afficher
                // « Synchronisé » pour ne pas induire l'admin en erreur.
                $limo_id_real = get_post_meta( $booking_id, '_etb_limo_booking_id', true );

                if ( ! empty( $limo_id_real ) ) {
                    update_post_meta( $booking_id, '_etb_limo_status', 'synced' );
                } else {
                    $active_disp = get_option( 'etb_general_settings', array() )['active_dispatcher'] ?? 'none';
                    if ( 'none' === $active_disp ) {
                        update_post_meta( $booking_id, '_etb_limo_status', 'autonomous' );
                    }
                }
            } else {
                update_post_meta( $booking_id, '_etb_limo_status', 'failed' );
                update_post_meta( $booking_id, '_etb_limo_error', $dispatch_result['message'] );
            }
        }
        // ─────────────────────────────────────────────────────────────
        // GÉNÉRATION DU LIEN DE PAIEMENT STRIPE
        // ─────────────────────────────────────────────────────────────
        $gen_settings = get_option( 'etb_general_settings', array() );
        $stripe_enabled = ! empty( $gen_settings['stripe_enabled'] ) && '1' === $gen_settings['stripe_enabled'];
        
        // On ne génère le lien que si Stripe est activé et que le total est > 0
        if ( $stripe_enabled && $pricing_details['grand_total'] > 0 ) {
            $pay_token = 'q_pay_' . bin2hex( random_bytes( 16 ) );
            
            $pay_data = array(
                'booking_id'   => $booking_id,
                'client_name'  => $data['name'],
                'client_email' => $data['email'],
                'client_phone' => $data['phone'],
                'amount'       => floatval( $pricing_details['grand_total'] ),
            );
            
            // Sauvegarde du token en base (Transient valide 14 jours)
            set_transient( 'etb_pay_' . $pay_token, $pay_data, 14 * DAY_IN_SECONDS );
            update_post_meta( $booking_id, '_etb_pay_token', $pay_token );

            // Construction de l'URL de la page de paiement
            $payment_base_url = ! empty( $gen_settings['payment_page_url'] ) ? esc_url_raw( $gen_settings['payment_page_url'] ) : home_url( '/payment/' );
            $data['payment_url'] = add_query_arg( 'ref', $pay_token, $payment_base_url );
        } else {
            $data['payment_url'] = ''; // Pas de paiement requis
        }

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

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $amount     = floatval( $_POST['amount'] ?? 0 );
        $name       = sanitize_text_field( $_POST['name'] ?? 'VIP Customer' );
        $email      = sanitize_email( $_POST['email'] ?? '' );

        if ( ! $booking_id || $amount <= 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid booking or amount.' ) );
        }

        // Création du client Stripe
        $customer_res = ETB_Stripe::create_customer( $name, $email );
        $customer_id  = '';
        if ( ! is_wp_error( $customer_res ) && ! empty( $customer_res['id'] ) ) {
            $customer_id = $customer_res['id'];
        }

        // Création du PaymentIntent (avec capture manuelle si tu veux faire comme Blacklane)
        $intent_res = ETB_Stripe::create_payment_intent( $amount, 'eur', $customer_id, array( 'booking_id' => $booking_id ), 'manual' );

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
     * Finalise la réservation après confirmation du paiement
     */
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

        // 1. Sécurité : Vérifier que la réservation n'est pas déjà payée
        $current_status = get_post_meta( $booking_id, '_etb_status', true );
        if ( 'confirmed' === $current_status ) {
            wp_send_json_success( array( 'redirect_url' => home_url('/?booking_success=' . $booking_id) ) );
        }

        // 2. Vérification serveur auprès de Stripe (Anti-fraude)
        if ( ! class_exists( 'ETB_Stripe' ) ) {
            require_once ETB_PATH . 'includes/class-etb-stripe.php';
        }
        
        $intent_check = ETB_Stripe::retrieve_payment_intent( $intent_id );
        
        if ( is_wp_error( $intent_check ) ) {
            wp_send_json_error( array( 'message' => 'Erreur de vérification Stripe : ' . $intent_check->get_error_message() ) );
        }

        $intent_status = $intent_check['status'] ?? '';
        if ( ! in_array( $intent_status, array( 'succeeded', 'requires_capture' ), true ) ) {
            wp_send_json_error( array( 'message' => 'La transaction Stripe n\'est pas valide (Statut : ' . $intent_status . ').' ) );
        }

        // 3. Mise à jour de la réservation WordPress
        update_post_meta( $booking_id, '_etb_status', 'confirmed' );
        update_post_meta( $booking_id, '_etb_stripe_payment_intent_id', $intent_id );
        update_post_meta( $booking_id, '_etb_paid_at', current_time( 'mysql' ) );

        // Récupération des infos pour les emails
        $gen_settings    = get_option( 'etb_general_settings', array() );
        $currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
        $customer_email  = get_post_meta( $booking_id, '_etb_customer_email', true );
        $customer_name   = get_post_meta( $booking_id, '_etb_customer_name', true );
        $total_amount    = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );

        // 4. Synchronisation du statut "Payé" vers LimoExpress (Si actif)
        if ( class_exists( 'ETB_LimoExpress' ) && 'limoexpress' === ( $gen_settings['active_dispatcher'] ?? 'none' ) ) {
            $limo_uuid = get_post_meta( $booking_id, '_etb_limo_uuid', true );
            $limo_id   = get_post_meta( $booking_id, '_etb_limo_booking_id', true );
            $target_uuid = ! empty( $limo_uuid ) ? $limo_uuid : $limo_id;
            $api_token   = $gen_settings['limo_api_token'] ?? '';

            if ( ! empty( $api_token ) && ! empty( $target_uuid ) ) {
                $headers = array(
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $api_token,
                );

                // Marquer comme payé
                wp_remote_post( 'https://api.limoexpress.me/api/integration/mark-booking-as-paid', array(
                    'headers' => $headers,
                    'body'    => wp_json_encode( array( 'id' => $target_uuid ) ),
                    'timeout' => 15,
                ) );
            }
        }

        // 5. Envoi des Emails (Client + Admin)
        $admin_email    = ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' );
        $company_name   = get_bloginfo( 'name' );
        $headers_client = ETB_Settings::get_mail_headers( $admin_email, $company_name );
        $headers_admin  = ETB_Settings::get_mail_headers( $customer_email, $customer_name );

        $formatted_amount = number_format_i18n( $total_amount, 2 ) . ' ' . $currency_symbol;
        $payment_method   = strtoupper( $intent_check['charges']['data'][0]['payment_method_details']['card']['brand'] ?? 'CARD' ) . ' ****' . ( $intent_check['charges']['data'][0]['payment_method_details']['card']['last4'] ?? '0000' );

        // --- EMAIL CLIENT : REÇU OFFICIEL ---
        if ( ! empty( $customer_email ) && is_email( $customer_email ) ) {
            $subject_client = 'Official Payment Receipt #' . $booking_id . ' — ' . $company_name;
            $message_client = '<div style="background-color: #f1f5f9; padding: 30px 15px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; line-height: 1.6; color: #1e293b;">'
                . '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">'
                . '<div style="background: #0f172a; padding: 25px 30px;">'
                . '<h1 style="margin: 0; color: #fbac18; font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">' . esc_html( $company_name ) . '</h1>'
                . '<p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em;">Official Paid Receipt #' . $booking_id . '</p>'
                . '</div>'
                . '<div style="padding: 30px;">'
                . '<p style="font-size: 15px; margin-top: 0; margin-bottom: 16px;">Dear <strong>' . esc_html( $customer_name ) . '</strong>,</p>'
                . '<p style="font-size: 14px; margin-bottom: 20px;">Your payment has been successfully authorized. Your mission is now confirmed and locked in dispatch.</p>'
                . '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">'
                . '<p style="margin: 0 0 4px 0; font-size: 13px; color: #166534;">Total Amount Paid:</p>'
                . '<p style="margin: 0; font-size: 26px; font-weight: 900; color: #15803d;">' . $formatted_amount . '</p>'
                . '<p style="margin: 10px 0 0 0; font-size: 13px; color: #166534;">Method: ' . esc_html( $payment_method ) . '</p>'
                . '</div>'
                . '<p style="font-size: 13px; color: #475569; margin: 0;">Your chauffeur will send an SMS update prior to pickup. Thank you for your trust.</p>'
                . '</div>'
                . '<div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 30px; text-align: center; font-size: 11.5px; color: #64748b;">'
                . '<p style="margin: 0;">' . esc_html( $company_name ) . ' — VIP Chauffeur & Private Transfers</p>'
                . '</div>'
                . '</div>'
                . '</div>';

            wp_mail( $customer_email, $subject_client, $message_client, $headers_client );
        }

        // --- EMAIL ADMIN : ALERTE PAIEMENT ---
        if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
            $subject_admin = '💰 [PAYMENT RECEIVED] ' . $formatted_amount . ' collected for Booking #' . $booking_id;
            $message_admin = '<div style="background-color: #f1f5f9; padding: 30px 15px; font-family: sans-serif;">'
                . '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 30px;">'
                . '<h2 style="color: #16a34a; margin-top: 0;">Payment Successfully Collected</h2>'
                . '<p><strong>Booking:</strong> #' . $booking_id . '</p>'
                . '<p><strong>Client:</strong> ' . esc_html( $customer_name ) . ' (' . esc_html( $customer_email ) . ')</p>'
                . '<p><strong>Amount:</strong> ' . $formatted_amount . '</p>'
                . '<p><strong>Method:</strong> ' . esc_html( $payment_method ) . '</p>'
                . '<p><strong>Stripe Intent:</strong> ' . esc_html( $intent_id ) . '</p>'
                . '</div>'
                . '</div>';

            wp_mail( $admin_email, $subject_admin, $message_admin, $headers_admin );
        }

        // 6. Succès : Redirection vers la page de paiement avec statut de succès
        $ref_token = get_post_meta( $booking_id, '_etb_pay_token', true );
        $payment_base_url = ! empty( $gen_settings['payment_page_url'] ) ? esc_url_raw( $gen_settings['payment_page_url'] ) : home_url( '/payment/' );
        
        $redirect_url = add_query_arg( array(
            'ref'    => $ref_token,
            'status' => 'success'
        ), $payment_base_url );

        wp_send_json_success( array(
            'redirect_url' => $redirect_url
        ) );
    }

}