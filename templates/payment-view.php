<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$gen_settings    = get_option( 'etb_general_settings', array() );
$currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
$stripe_enabled  = ! empty( $gen_settings['stripe_enabled'] ) && '1' === $gen_settings['stripe_enabled'];
$stripe_pk       = ! empty( $gen_settings['stripe_publishable_key'] ) ? trim( $gen_settings['stripe_publishable_key'] ) : '';

// 1. Détection
$ref_token  = ! empty( $_GET['ref'] ) ? sanitize_key( $_GET['ref'] ) : '';
$pay_data   = false;
$booking_id = 0;

if ( ! empty( $ref_token ) ) {
    $pay_data = get_transient( 'etb_pay_' . $ref_token );
    if ( is_array( $pay_data ) && ! empty( $pay_data['booking_id'] ) ) {
        $booking_id = absint( $pay_data['booking_id'] );
    }
}
if ( ! $booking_id && ! empty( $_GET['booking_id'] ) ) {
    $booking_id = absint( $_GET['booking_id'] );
}

$has_valid_booking = ( $booking_id > 0 && 'tour_booking' === get_post_type( $booking_id ) );
$is_already_paid   = false;
$show_success_page = ( isset( $_GET['status'] ) && 'success' === $_GET['status'] );
$settled_amount    = 0.0;

if ( $has_valid_booking ) {
    $current_status  = get_post_meta( $booking_id, '_etb_status', true );
    $stripe_intent   = get_post_meta( $booking_id, '_etb_stripe_payment_intent_id', true );
    $is_already_paid = ( 'confirmed' === $current_status || ! empty( $stripe_intent ) );
    $settled_amount  = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
}

// 2. Récupération exhaustive des détails
if ( $has_valid_booking ) {
    $customer_name   = get_post_meta( $booking_id, '_etb_customer_name', true ) ?: '';
    $customer_email  = get_post_meta( $booking_id, '_etb_customer_email', true ) ?: '';
    $pickup_address  = get_post_meta( $booking_id, '_etb_pickup_address', true ) ?: '—';
    $dropoff_info    = get_post_meta( $booking_id, '_etb_dropoff_info', true ) ?: '';
    $booking_date    = get_post_meta( $booking_id, '_etb_booking_date', true );
    $booking_time    = get_post_meta( $booking_id, '_etb_booking_time', true );
    $duration_hours  = get_post_meta( $booking_id, '_etb_duration_hours', true ) ?: 1;
    
    $adults   = absint( get_post_meta( $booking_id, '_etb_adults', true ) );
    $children = absint( get_post_meta( $booking_id, '_etb_children', true ) );
    $luggage  = absint( get_post_meta( $booking_id, '_etb_luggage', true ) );
    
    $baby_seats    = absint( get_post_meta( $booking_id, '_etb_baby_seat_count', true ) );
    $booster_seats = absint( get_post_meta( $booking_id, '_etb_booster_seat_count', true ) );
    $child_seat_fee= floatval( get_post_meta( $booking_id, '_etb_child_seat_fee', true ) );
    
    $extras        = get_post_meta( $booking_id, '_etb_extras', true ) ?: array();
    
    // 1. Récupération du Nom du Circuit (Titre principal)
    $circuit_id    = get_post_meta( $booking_id, '_etb_circuit_id', true );
    $circuit_title = $circuit_id ? get_the_title( $circuit_id ) : 'Your VIP Circuit';
    
    // 2. Récupération du Véhicule
    $vehicles = get_post_meta( $booking_id, '_etb_vehicles', true ) ?: array();
    $vehicle_title = 'Luxury Vehicle';
    if ( ! empty( $vehicles ) && is_array( $vehicles ) ) {
        foreach ( $vehicles as $v_id => $q ) {
            if ( $q > 0 ) { 
                $vehicle_title = get_the_title( $v_id ); 
                break; 
            }
        }
    }

    // 3. Récupération de la ventilation des prix (Pricing Details)
    $pricing = get_post_meta( $booking_id, '_etb_pricing_details', true ) ?: array();
    
    // Si les détails n'existent pas (ancienne résa), on calcule par déduction
    $vehicles_total   = isset($pricing['vehicles_total']) ? floatval($pricing['vehicles_total']) : floatval(get_post_meta( $booking_id, '_etb_base_price', true ));
    $additional_price = isset($pricing['circuit_additional_price']) ? floatval($pricing['circuit_additional_price']) : 0;
    $child_seat_fee   = isset($pricing['child_seat_fee']) ? floatval($pricing['child_seat_fee']) : floatval( get_post_meta( $booking_id, '_etb_child_seat_fee', true ) );
} else {
    $customer_name = $customer_email = $vehicle_title = '';
    $pickup_address = $dropoff_info = ''; $booking_date = $booking_time = '';
    $duration_hours = 1; $adults = $children = $luggage = 0;
    $baby_seats = $booster_seats = $child_seat_fee = 0; $extras = array();
}

$formatted_amount = number_format_i18n( $settled_amount, 2 ) . ' ' . $currency_symbol;

// Formatage de la date avec AM/PM
$formatted_datetime = '—';
if ( $booking_date ) {
    $dt_ts = strtotime( $booking_date );
    $formatted_date = $dt_ts ? date( 'd M Y', $dt_ts ) : $booking_date;
    
    $formatted_time = '—';
    if ( ! empty( $booking_time ) ) {
        $time_ts = strtotime( '1970-01-01 ' . $booking_time ); // On force une fausse date pour avoir l'heure
        $formatted_time = $time_ts ? date( 'h:i A', $time_ts ) : $booking_time;
    }
    $formatted_datetime = $formatted_date . ' at ' . $formatted_time;
}
?>

<div class="etb-payment-wrapper">
    
    <?php if ( $show_success_page ) : ?>
        <div class="etb-pay-status-card" style="border-color: #bbf7d0; background: #f0fdf4; padding: 60px 30px;">
            <div style="width: 80px; height: 80px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px auto;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h2 class="etb-pay-status-title success" style="font-size: 32px; margin-bottom: 12px;">Payment Successful!</h2>
            <p class="etb-pay-status-text" style="font-size: 16px; color: #166534; max-width: 500px; margin: 0 auto 30px auto;">
                Your booking <strong>#<?php echo esc_html( $booking_id ); ?></strong> has been confirmed and paid successfully.<br>
                A detailed receipt has been sent to your email.
            </p>
            <div style="display: flex; gap: 15px; justify-content: center;">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="etb-pay-status-btn">Return to Home</a>
            </div>
        </div>
    <?php elseif ( ! $stripe_enabled || empty( $stripe_pk ) ) : ?>
        <div class="etb-pay-status-card" style="border-color: #fca5a5; background: #fef2f2;">
            <h2 class="etb-pay-status-title error">Stripe Not Configured</h2>
            <p class="etb-pay-status-text">Please enable Stripe and enter your Publishable Key in the Elite Settings to accept payments.</p>
        </div>
    <?php elseif ( $is_already_paid ) : ?>
        <div class="etb-pay-status-card">
            <h2 class="etb-pay-status-title success">Booking Already Paid</h2>
            <p class="etb-pay-status-text">Your reservation #<?php echo esc_html( $booking_id ); ?> has been successfully settled.</p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="etb-pay-status-btn">Return to Home</a>
        </div>
    <?php elseif ( ! $has_valid_booking || $settled_amount <= 0 ) : ?>
        <div class="etb-pay-status-card">
            <h2 class="etb-pay-status-title error">Invalid Booking</h2>
            <p class="etb-pay-status-text">The payment details could not be found.</p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="etb-pay-status-btn">Return to Home</a>
        </div>
    <?php else : ?>
        
        <div class="etb-pay-row">
            <!-- COLONNE GAUCHE : FACTURE DÉTAILLÉE -->
            <div class="etb-pay-col">
                <h2 class="etb-pay-title">Booking Summary</h2>
                <p class="etb-pay-subtitle">Please review your full itinerary and pricing below.</p>

                <!-- 1. DÉTAILS DU VOYAGE -->
                <div class="etb-invoice-section">
                    <div class="etb-invoice-header">
                        <strong class="etb-pay-summary-vehicle"><?php echo esc_html( $circuit_title ); ?></strong>
                        <span class="etb-invoice-duration"><?php echo esc_html( $duration_hours ); ?> Hours</span>
                    </div>
                    <div class="etb-invoice-trip-details">
                        <div class="etb-invoice-row">
                            <span class="etb-pay-label">Date & Time</span>
                            <strong class="etb-pay-value"><?php echo esc_html( $formatted_datetime ); ?></strong>
                        </div>
                        <div class="etb-invoice-row">
                            <span class="etb-pay-label">Pickup Location</span>
                            <strong class="etb-pay-value"><?php echo esc_html( $pickup_address ); ?></strong>
                        </div>
                        <div class="etb-invoice-row">
                            <span class="etb-pay-label">Drop-off</span>
                            <strong class="etb-pay-value"><?php echo ! empty( $dropoff_info ) ? esc_html( $dropoff_info ) : esc_html( $pickup_address ); ?></strong>
                        </div>
                        <div class="etb-invoice-row">
                            <span class="etb-pay-label">Passengers & Luggage</span>
                            <strong class="etb-pay-value"><?php echo esc_html( $adults ); ?> Adults, <?php echo esc_html( $children ); ?> Children • <?php echo esc_html( $luggage ); ?> Luggage</strong>
                        </div>
                    </div>
                </div>

                <!-- 2. VENTILATION DU PRIX -->
                <div class="etb-invoice-section" style="border-top: 1px dashed #cbd5e1; padding-top: 20px;">
                    <h3 class="etb-invoice-title">Price Details</h3>
                    
                    <!-- Ligne 1 : Le Véhicule et son prix -->
                    <div class="etb-price-row" style="font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                        <span><?php echo esc_html( $vehicle_title ); ?> (<?php echo esc_html( $duration_hours ); ?>h)</span>
                        <span><?php echo number_format_i18n( $vehicles_total, 2 ); ?> <?php echo esc_html( $currency_symbol ); ?></span>
                    </div>

                    <!-- Ligne 2 : Surcharge de départ (si > 0) -->
                    <?php if ( $additional_price > 0 ) : ?>
                        <div class="etb-price-row">
                            <span>Departure Surcharge</span>
                            <span>+<?php echo number_format_i18n( $additional_price, 2 ); ?> <?php echo esc_html( $currency_symbol ); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Sièges enfants -->
                    <?php if ( $baby_seats > 0 || $booster_seats > 0 ) : ?>
                        <div class="etb-price-row">
                            <span>
                                Child Seats 
                                <?php 
                                $seats_recap = array();
                                if ( $baby_seats > 0 ) $seats_recap[] = $baby_seats . 'x Baby';
                                if ( $booster_seats > 0 ) $seats_recap[] = $booster_seats . 'x Booster';
                                echo '<small>(' . implode(', ', $seats_recap) . ')</small>';
                                ?>
                            </span>
                            <span>
                                <?php echo $child_seat_fee > 0 ? '+' . number_format_i18n( $child_seat_fee, 2 ) . ' ' . esc_html( $currency_symbol ) : 'Free'; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Extras -->
                    <?php if ( ! empty( $extras ) ) : foreach ( $extras as $e_id => $qty ) : 
                        if ( $qty <= 0 ) continue;
                        $e_name = get_the_title( $e_id );
                        $e_price = floatval( get_post_meta( $e_id, '_etb_price', true ) );
                        $line_total = $e_price * $qty;
                    ?>
                        <div class="etb-price-row">
                            <span><?php echo esc_html( $qty ); ?>x <?php echo esc_html( $e_name ); ?></span>
                            <span>+<?php echo number_format_i18n( $line_total, 2 ); ?> <?php echo esc_html( $currency_symbol ); ?></span>
                        </div>
                    <?php endforeach; endif; ?>

                    <!-- TOTAL -->
                    <div class="etb-price-total">
                        <span>Total Amount</span>
                        <span class="etb-price-total-val"><?php echo esc_html( $formatted_amount ); ?></span>
                    </div>
                </div>

                <p class="etb-pay-secure-text" style="margin-top: 20px;">
                    <span class="dashicons dashicons-lock"></span> Secure 256-bit SSL Encrypted Payment
                </p>
            </div>
            
            <!-- COLONNE DROITE : PAIEMENT (Inchangé) -->
            <div class="etb-pay-col">
                <h2 class="etb-pay-title">Payment Details</h2>

                <!-- ZONE APPLE PAY / GOOGLE PAY -->
                <div id="etb-wallet-container" style="display: none; margin-bottom: 20px;">
                    <div id="etb-wallet-mount"></div>
                </div>
                
                <!-- SÉPARATEUR "OR" -->
                <div id="etb-wallet-divider" style="display: none; align-items: center; text-align: center; margin: 20px 0; color: #94a3b8; font-size: 13px; font-weight: 600;">
                    <div style="flex: 1; height: 1px; background: #e2e8f0;"></div>
                    <span style="padding: 0 10px;">OR PAY WITH CARD</span>
                    <div style="flex: 1; height: 1px; background: #e2e8f0;"></div>
                </div>

                <form id="etb-standalone-payment-form" onsubmit="return false;">
                    <input type="hidden" name="booking_id" id="etb-pay-booking-id" value="<?php echo esc_attr( $booking_id ); ?>">
                    <input type="hidden" name="amount" id="etb-pay-amount" value="<?php echo esc_attr( $settled_amount ); ?>">
                    <input type="hidden" name="client_name" id="etb-pay-client-name" value="<?php echo esc_attr( $customer_name ); ?>">
                    <input type="hidden" name="client_email" id="etb-pay-client-email" value="<?php echo esc_attr( $customer_email ); ?>">

                    <div class="etb-pay-form-group">
                        <label class="etb-pay-form-label">Email Receipt To *</label>
                        <input type="email" id="etb-pay-email" name="client_email_input" value="<?php echo esc_attr( $customer_email ); ?>" placeholder="email@domain.com" required class="etb-pay-input">
                    </div>

                    <div class="etb-pay-form-group">
                        <label class="etb-pay-form-label">Name on Card *</label>
                        <input type="text" id="etb-pay-cardholder" name="cardholder_name" value="<?php echo esc_attr( $customer_name ); ?>" placeholder="Full Name" required class="etb-pay-input">
                    </div>

                    <div class="etb-pay-form-group">
                        <label class="etb-pay-form-label">Card Information *</label>
                        <div class="etb-pay-stripe-box">
                            <div id="etb-card-number-mount"></div>
                            <div class="etb-pay-stripe-bottom-row">
                                <div id="etb-card-expiry-mount"></div>
                                <div id="etb-card-cvc-mount"></div>
                            </div>
                        </div>
                    </div>

                    <div id="etb-standalone-pay-feedback" class="etb-pay-alert"></div>

                    <button type="button" id="etb-standalone-pay-btn" class="etb-pay-button">
                        <span id="etb-standalone-pay-text">Pay <?php echo esc_html( $formatted_amount ); ?></span>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>