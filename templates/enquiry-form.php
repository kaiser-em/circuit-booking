<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$options = get_option( 'etb_general_settings', array() );

// Récupération des listes depuis l'admin
$trip_types_raw   = ! empty( $options['enquiry_trip_types'] ) ? $options['enquiry_trip_types'] : "Sightseeing\nWine tours\nPrivate Transfer";
$destinations_raw = ! empty( $options['enquiry_destinations'] ) ? $options['enquiry_destinations'] : "Cannes\nNice\nMonaco";
$budgets_raw      = ! empty( $options['enquiry_budgets'] ) ? $options['enquiry_budgets'] : "Under 500\n500-1000\n1000+";

$trip_types   = array_filter( array_map( 'trim', explode( "\n", $trip_types_raw ) ) );
$destinations = array_filter( array_map( 'trim', explode( "\n", $destinations_raw ) ) );
$budgets      = array_filter( array_map( 'trim', explode( "\n", $budgets_raw ) ) );
?>

<div class="etb-enquiry-wrapper">
    <h2 class="etb-enq-title">Fill out the form to customize your trip</h2>
    
    <form id="etb-enquiry-form" class="etb-enq-form" onsubmit="return false;">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('etb_booking_nonce'); ?>">
        
        <!-- Honeypot Anti-Spam -->
        <div style="position: absolute !important; left: -9999px !important; opacity: 0 !important;" aria-hidden="true">
            <input type="text" name="etb_hp_email" value="" tabindex="-1" autocomplete="off">
        </div>
        <div id="etb-enq-feedback" class="etb-enq-feedback">

        </div>
        <div class="etb-enq-grid">
            <!-- COLONNE GAUCHE -->
            <div class="etb-enq-col">
                <div class="etb-enq-group">
                    <label>Full name *</label>
                    <input type="text" name="name" placeholder="Enter your full name" required>
                </div>
                <div class="etb-enq-group">
                    <label>Email *</label>
                    <input type="email" name="email" placeholder="Your email address" required>
                </div>
                <div class="etb-enq-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" placeholder="Phone number">
                </div>
                <div class="etb-enq-group">
                    <label>Date</label>
                    <input type="date" name="date">
                </div>
                <div class="etb-enq-row-2">
                    <div class="etb-enq-group">
                        <label>Number of adults</label>
                        <input type="number" name="adults" value="1" min="1">
                    </div>
                    <div class="etb-enq-group">
                        <label>Number of kids</label>
                        <input type="number" name="kids" value="0" min="0">
                    </div>
                </div>
                <div class="etb-enq-group">
                    <label>Pickup address</label>
                    <input type="text" name="pickup" placeholder="Hotel, Airport, Address...">
                </div>
            </div>

            <!-- COLONNE DROITE -->
            <div class="etb-enq-col">
                <div class="etb-enq-group">
                    <label>Trip type</label>
                    <select name="trip_type">
                        <?php foreach ( $trip_types as $type ) echo '<option value="'.esc_attr($type).'">'.esc_html($type).'</option>'; ?>
                    </select>
                </div>
                <div class="etb-enq-group">
                    <label>Destination</label>
                    <select name="destination">
                        <?php foreach ( $destinations as $dest ) echo '<option value="'.esc_attr($dest).'">'.esc_html($dest).'</option>'; ?>
                    </select>
                </div>
                <div class="etb-enq-group">
                    <label>Total budget in Euro</label>
                    <select name="budget">
                        <?php foreach ( $budgets as $budget ) echo '<option value="'.esc_attr($budget).'">'.esc_html($budget).'</option>'; ?>
                    </select>
                </div>
                <div class="etb-enq-group etb-enq-full">
                    <label>Your Enquiry *</label>
                    <textarea name="message" rows="8" placeholder="Enter your message here" required></textarea>
                </div>
                
                <div class="etb-enq-submit-wrap">
                    <button type="submit" id="etb-enq-submit-btn" class="etb-enq-btn">Send Enquiry <span>> ><span></button>
                </div>
                
            </div>
        </div>
    </form>
</div>