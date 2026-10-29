<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$gen_settings    = get_option( 'etb_general_settings', array() );
$currency_symbol = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
$stripe_enabled  = ! empty( $gen_settings['stripe_enabled'] ) && '1' === $gen_settings['stripe_enabled'];
$stripe_pk       = ! empty( $gen_settings['stripe_publishable_key'] ) ? trim( $gen_settings['stripe_publishable_key'] ) : '';

// Détection
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

// Détails
if ( $has_valid_booking ) {
    $customer_name   = get_post_meta( $booking_id, '_etb_customer_name', true ) ?: '';
    $customer_email  = get_post_meta( $booking_id, '_etb_customer_email', true ) ?: '';
    $pickup_address  = get_post_meta( $booking_id, '_etb_pickup_address', true ) ?: '—';
    $booking_date    = get_post_meta( $booking_id, '_etb_booking_date', true );
    $booking_time    = get_post_meta( $booking_id, '_etb_booking_time', true );
    
    $vehicles = get_post_meta( $booking_id, '_etb_vehicles', true ) ?: array();
    $vehicle_title = 'VIP Service';
    if ( ! empty( $vehicles ) && is_array( $vehicles ) ) {
        foreach ( $vehicles as $v_id => $q ) {
            if ( $q > 0 ) { $vehicle_title = get_the_title( $v_id ); break; }
        }
    }
} else {
    $customer_name = $customer_email = $vehicle_title = '';
    $pickup_address = ''; $booking_date = $booking_time = '';
}

$formatted_amount = number_format_i18n( $settled_amount, 2 ) . ' ' . $currency_symbol;
$formatted_datetime = $booking_date ? date( 'd M Y', strtotime( $booking_date ) ) . ' at ' . $booking_time : '—';
?>

<div class="etb-payment-wrapper">
    
    <?php if ( $show_success_page ) : ?>
        <!-- ÉCRAN DE SUCCÈS PREMIUM -->
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
            <!-- COLONNE GAUCHE -->
            <div class="etb-pay-col">
                <h2 class="etb-pay-title">Your Reservation</h2>
                <p class="etb-pay-subtitle">Please review your details before payment.</p>

                <div class="etb-pay-summary-box">
                    <div class="etb-pay-summary-header">
                        <strong class="etb-pay-summary-vehicle"><?php echo esc_html( $vehicle_title ); ?></strong>
                        <span class="etb-pay-summary-price"><?php echo esc_html( $formatted_amount ); ?></span>
                    </div>
                    <div class="etb-pay-grid-2">
                        <div>
                            <span class="etb-pay-label">Date & Time</span>
                            <strong class="etb-pay-value"><?php echo esc_html( $formatted_datetime ); ?></strong>
                        </div>
                        <div>
                            <span class="etb-pay-label">Pickup Location</span>
                            <strong class="etb-pay-value"><?php echo esc_html( $pickup_address ); ?></strong>
                        </div>
                    </div>
                </div>
                <p class="etb-pay-secure-text">
                    <span class="dashicons dashicons-lock"></span> Secure 256-bit SSL Encrypted Payment
                </p>
            </div>
            
            <!-- COLONNE DROITE -->
            <div class="etb-pay-col">
                <h2 class="etb-pay-title">Payment Details</h2>

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