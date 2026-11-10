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

    <div class="etb-enq-header">
        <span class="etb-enq-header-icon" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
        <div class="etb-enq-header-text">
            <h2 class="etb-enq-title">Fill out the form to customize your trip</h2>
            <p class="etb-enq-subtitle">Tell us about your dream trip — our team will craft a tailor-made proposal for you.</p>
        </div>
    </div>

    <div id="etb-enq-feedback" class="etb-enq-feedback" role="status" aria-live="polite"></div>

    <form id="etb-enquiry-form" class="etb-enq-form" onsubmit="return false;">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('etb_booking_nonce'); ?>">
        
        <!-- Honeypot Anti-Spam -->
        <div style="position: absolute !important; left: -9999px !important; opacity: 0 !important;" aria-hidden="true">
            <input type="text" name="etb_hp_email" value="" tabindex="-1" autocomplete="off">
        </div>

        <div class="etb-enq-grid">
            <!-- COLONNE GAUCHE : COORDONNÉES -->
            <div class="etb-enq-col">
                <div class="etb-enq-section-head">
                    <span class="etb-enq-section-icon" aria-hidden="true"><i class="fas fa-address-card"></i></span>
                    <span class="etb-enq-section-label">Your Details</span>
                    <span class="etb-enq-section-line" aria-hidden="true"></span>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-name">Full name <span class="etb-enq-req">*</span></label>
                    <div class="etb-enq-input-wrap">
                        <i class="fas fa-user etb-enq-field-icon" aria-hidden="true"></i>
                        <input type="text" id="etb-enq-name" name="name" placeholder="Enter your full name" autocomplete="name" required>
                    </div>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-email">Email <span class="etb-enq-req">*</span></label>
                    <div class="etb-enq-input-wrap">
                        <i class="fas fa-envelope etb-enq-field-icon" aria-hidden="true"></i>
                        <input type="email" id="etb-enq-email" name="email" placeholder="Your email address" autocomplete="email" required>
                    </div>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-phone">Phone</label>
                    <div class="etb-enq-input-wrap etb-enq-phone-wrap">
                        <input type="tel" id="etb-enq-phone" name="phone" placeholder="Phone number" autocomplete="tel">
                    </div>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-date">Date</label>
                    <div class="etb-enq-input-wrap">
                        <i class="fas fa-calendar-days etb-enq-field-icon" aria-hidden="true"></i>
                        <input type="text" id="etb-enq-date" name="date" placeholder="MM/DD/YYYY" autocomplete="off">
                    </div>
                </div>

                <div class="etb-enq-row-2">
                    <div class="etb-enq-group">
                        <label for="etb-enq-adults">Adults</label>
                        <div class="etb-enq-input-wrap">
                            <i class="fas fa-user-group etb-enq-field-icon" aria-hidden="true"></i>
                            <input type="number" id="etb-enq-adults" name="adults" value="1" min="1" max="30" inputmode="numeric">
                            <span class="etb-enq-stepper">
                                <button type="button" class="etb-enq-step-btn" data-step="up" aria-label="Increase adults"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>
                                <button type="button" class="etb-enq-step-btn" data-step="down" aria-label="Decrease adults"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                            </span>
                        </div>
                    </div>
                    <div class="etb-enq-group">
                        <label for="etb-enq-kids">Kids</label>
                        <div class="etb-enq-input-wrap">
                            <i class="fas fa-child etb-enq-field-icon" aria-hidden="true"></i>
                            <input type="number" id="etb-enq-kids" name="kids" value="0" min="0" max="30" inputmode="numeric">
                            <span class="etb-enq-stepper">
                                <button type="button" class="etb-enq-step-btn" data-step="up" aria-label="Increase kids"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>
                                <button type="button" class="etb-enq-step-btn" data-step="down" aria-label="Decrease kids"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-pickup">Pickup address</label>
                    <div class="etb-enq-input-wrap">
                        <i class="fas fa-location-dot etb-enq-field-icon" aria-hidden="true"></i>
                        <input type="text" id="etb-enq-pickup" name="pickup" placeholder="Hotel, Airport, Address...">
                    </div>
                </div>
            </div>

            <!-- COLONNE DROITE : LE VOYAGE -->
            <div class="etb-enq-col">
                <div class="etb-enq-section-head">
                    <span class="etb-enq-section-icon" aria-hidden="true"><i class="fas fa-route"></i></span>
                    <span class="etb-enq-section-label">Your Trip</span>
                    <span class="etb-enq-section-line" aria-hidden="true"></span>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-trip-type">Trip type</label>
                    <div class="etb-enq-input-wrap etb-enq-select-wrap">
                        <i class="fas fa-compass etb-enq-field-icon" aria-hidden="true"></i>
                        <select id="etb-enq-trip-type" name="trip_type">
                            <?php foreach ( $trip_types as $type ) echo '<option value="'.esc_attr($type).'">'.esc_html($type).'</option>'; ?>
                        </select>
                    </div>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-destination">Destination</label>
                    <div class="etb-enq-input-wrap etb-enq-select-wrap">
                        <i class="fas fa-map-location-dot etb-enq-field-icon" aria-hidden="true"></i>
                        <select id="etb-enq-destination" name="destination">
                            <?php foreach ( $destinations as $dest ) echo '<option value="'.esc_attr($dest).'">'.esc_html($dest).'</option>'; ?>
                        </select>
                    </div>
                </div>

                <div class="etb-enq-group">
                    <label for="etb-enq-budget">Total budget in Euro</label>
                    <div class="etb-enq-input-wrap etb-enq-select-wrap">
                        <i class="fas fa-wallet etb-enq-field-icon" aria-hidden="true"></i>
                        <select id="etb-enq-budget" name="budget">
                            <?php foreach ( $budgets as $budget ) echo '<option value="'.esc_attr($budget).'">'.esc_html($budget).'</option>'; ?>
                        </select>
                    </div>
                </div>

                <div class="etb-enq-group etb-enq-full">
                    <label for="etb-enq-message">Your Enquiry <span class="etb-enq-req">*</span></label>
                    <div class="etb-enq-input-wrap etb-enq-textarea-wrap">
                        <i class="fas fa-comment-dots etb-enq-field-icon" aria-hidden="true"></i>
                        <textarea id="etb-enq-message" name="message" rows="7" placeholder="Tell us what you have in mind: places to visit, pace, special occasions..." required></textarea>
                    </div>
                </div>

                <div class="etb-enq-submit-wrap">
                    <button type="submit" id="etb-enq-submit-btn" class="etb-enq-btn">
                        <span class="etb-enq-btn-label">Send Enquiry</span>
                        <span class="etb-enq-btn-arrow" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
                    </button>
                    <p class="etb-enq-privacy"><i class="fas fa-lock" aria-hidden="true"></i> Your information stays private — no spam, ever.</p>
                </div>
            </div>
        </div>
    </form>
</div>