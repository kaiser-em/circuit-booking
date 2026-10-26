<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Ajax {
    public function __construct() {
        add_action( 'wp_ajax_etb_submit_booking', array( $this, 'handle_submit_booking' ) );
        add_action( 'wp_ajax_nopriv_etb_submit_booking', array( $this, 'handle_submit_booking' ) );
        add_action( 'wp_ajax_etb_validate_promo', array( $this, 'handle_validate_promo' ) );
        add_action( 'wp_ajax_nopriv_etb_validate_promo', array( $this, 'handle_validate_promo' ) );

        add_action( 'wp_ajax_etb_resync_booking', array( $this, 'handle_resync_booking' ) );

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
            'note'           => sanitize_textarea_field( $_POST['etb_note'] ?? '' ),
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

}