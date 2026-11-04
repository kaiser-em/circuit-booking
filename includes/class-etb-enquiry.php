<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Enquiry {

    public function __construct() {
        add_shortcode( 'etb_enquiry_form', array( $this, 'render_form' ) );
        add_action( 'wp_ajax_etb_submit_enquiry', array( $this, 'handle_submit' ) );
        add_action( 'wp_ajax_nopriv_etb_submit_enquiry', array( $this, 'handle_submit' ) );
        add_action( 'init', array( $this, 'register_cpt' ) );
    }

    public function register_cpt() {
        register_post_type( 'tour_enquiry', array(
            'labels' => array(
                'name'          => 'Enquiries',
                'singular_name' => 'Enquiry',
                'menu_name'     => 'Enquiries',
            ),
            'public'    => false,
            'show_ui'   => true,
            'menu_icon' => 'dashicons-email-alt',
            'supports'  => array( 'title', 'custom-fields' ),
            'show_in_menu' => 'edit.php?post_type=tour_booking',
        ));
    }

    public function render_form() {
        ob_start();
        include ETB_PATH . 'templates/enquiry-form.php';
        return ob_get_clean();
    }

    public function handle_submit() {
        check_ajax_referer( 'etb_booking_nonce', 'nonce' );

        // Honeypot anti-spam
        if ( ! empty( $_POST['etb_hp_email'] ) ) {
            wp_send_json_error( array( 'message' => 'Spam detected.' ) );
        }

        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $email      = sanitize_email( $_POST['email'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone'] ?? '' );
        $date       = sanitize_text_field( $_POST['date'] ?? '' );
        $pickup     = sanitize_text_field( $_POST['pickup'] ?? '' );
        $adults     = absint( $_POST['adults'] ?? 0 );
        $kids       = absint( $_POST['kids'] ?? 0 );
        $trip_type  = sanitize_text_field( $_POST['trip_type'] ?? '' );
        $destination= sanitize_text_field( $_POST['destination'] ?? '' );
        $budget     = sanitize_text_field( $_POST['budget'] ?? '' );
        $message    = sanitize_textarea_field( $_POST['message'] ?? '' );

        if ( empty( $name ) || empty( $email ) || ! is_email( $email ) || empty( $message ) ) {
            wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
        }

        $post_id = wp_insert_post( array(
            'post_title'   => sprintf( 'Enquiry from %s - %s', $name, date('Y-m-d') ),
            'post_type'    => 'tour_enquiry',
            'post_status'  => 'publish',
        ) );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Error saving request.' ) );
        }

        // Sauvegarde des données
        update_post_meta( $post_id, '_etb_enq_name', $name );
        update_post_meta( $post_id, '_etb_enq_email', $email );
        update_post_meta( $post_id, '_etb_enq_phone', $phone );
        update_post_meta( $post_id, '_etb_enq_date', $date );
        update_post_meta( $post_id, '_etb_enq_pickup', $pickup );
        update_post_meta( $post_id, '_etb_enq_adults', $adults );
        update_post_meta( $post_id, '_etb_enq_kids', $kids );
        update_post_meta( $post_id, '_etb_enq_trip_type', $trip_type );
        update_post_meta( $post_id, '_etb_enq_destination', $destination );
        update_post_meta( $post_id, '_etb_enq_budget', $budget );
        update_post_meta( $post_id, '_etb_enq_message', $message );

        // 3. Envoi des emails
        $email_data = array(
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'date'        => $date,
            'pickup'      => $pickup,
            'adults'      => $adults,
            'kids'        => $kids,
            'trip_type'   => $trip_type,
            'destination' => $destination,
            'budget'      => $budget,
            'message'     => $message
        );

        $this->send_enquiry_emails( $post_id, $email_data );

        wp_send_json_success( array( 'message' => 'Your enquiry has been sent successfully. We will contact you shortly.' ) );
    }

        /**
     * Envoie l'email d'alerte Admin et l'email de confirmation Client.
     */
    private function send_enquiry_emails( $post_id, $data ) {
        $gen_settings    = get_option( 'etb_general_settings', array() );
        $company_name    = get_bloginfo( 'name' );
        $admin_email     = ! empty( $gen_settings['admin_email'] ) && is_email( $gen_settings['admin_email'] ) ? sanitize_email( $gen_settings['admin_email'] ) : get_option( 'admin_email' );

        // --- 1. EMAIL ADMIN (Notification) ---
        $subject_admin = 'New Custom Tour enquiry from ' . $data['name'] . ' - ' . $data['trip_type'];
        
        $message_admin = '<div style="background: #f1f5f9; padding: 5px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; line-height: 1.6; color: #1e293b;">'
            . '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">'
            . '<div style="background: #0f172a; padding: 20px; text-align: center;">'
            . '<h2 style="color: #ba6827; margin: 0; font-size: 20px;">New Enquiry Received</h2>'
            . '</div>'
            . '<div style="padding: 20px;">'
            . '<p style="margin-top: 0; font-size: 15px;">You have a new request for a customized trip.</p>'
            
            // Bloc Client
            . '<h3 style="font-size: 14px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 5px;">Client Info</h3>'
            . '<p style="margin: 5px 0;"><strong>Name:</strong> ' . esc_html( $data['name'] ) . '</p>'
            . '<p style="margin: 5px 0;"><strong>Email:</strong> <a href="mailto:' . esc_attr( $data['email'] ) . '">' . esc_html( $data['email'] ) . '</a></p>'
            . '<p style="margin: 5px 0;"><strong>Phone:</strong> <a href="tel:' . esc_attr( $data['phone'] ) . '">' . esc_html( $data['phone'] ) . '</a></p>'
            
            // Bloc Voyage
            . '<h3 style="font-size: 14px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 5px; margin-top: 20px;">Trip Details</h3>'
            . '<p style="margin: 5px 0;"><strong>Trip Type:</strong> ' . esc_html( $data['trip_type'] ) . '</p>'
            . '<p style="margin: 5px 0;"><strong>Destination:</strong> ' . esc_html( $data['destination'] ) . '</p>'
            . '<p style="margin: 5px 0;"><strong>Pickup:</strong> ' . esc_html( $data['pickup'] ) . '</p>'
            . '<p style="margin: 5px 0;"><strong>Date:</strong> ' . esc_html( $data['date'] ) . '</p>'
            . '<p style="margin: 5px 0;"><strong>Passengers:</strong> ' . esc_html( $data['adults'] ) . ' Adults, ' . esc_html( $data['kids'] ) . ' Kids</p>'
            . '<p style="margin: 5px 0;"><strong>Budget:</strong> ' . esc_html( $data['budget'] ) . ' €</p>'
            
            // Message
            . '<h3 style="font-size: 14px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 5px; margin-top: 20px;">Message</h3>'
            . '<div style="background: #f8fafc; border-left: 4px solid #ba6827; padding: 15px; font-style: italic; color: #334155;">' . nl2br( esc_html( $data['message'] ) ) . '</div>'
            . '<div style="text-align: center; margin-top: 30px;">'
            . '<a href="' . admin_url( 'post.php?post=' . $post_id . '&action=edit' ) . '" style="display: inline-block; background: #0f172a; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">View in Admin</a>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';

        $headers_admin = class_exists('ETB_Settings') ? ETB_Settings::get_mail_headers( $data['email'], $data['name'] ) : array();
        wp_mail( $admin_email, $subject_admin, $message_admin, $headers_admin );

        // --- 2. EMAIL CLIENT (Confirmation) ---
        if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
            $subject_client = 'We have received your enquiry - ' . $company_name;

            $message_client = '<div style="background: #f1f5f9; padding: 5px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; line-height: 1.6; color: #1e293b;">'
                . '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">'
                . '<div style="padding: 30px 20px; text-align: center; border-bottom: 1px solid #e2e8f0;">'
                . '<h1 style="color: #ba6827; margin: 0; font-size: 24px; font-weight: 800;">Thank You, ' . esc_html( $data['name'] ) . '!</h1>'
                . '</div>'
                . '<div style="padding: 30px 20px;">'
                . '<p style="font-size: 15px; margin-top: 0;">We have successfully received your enquiry. Our team is currently reviewing your request and will get back to you shortly with a customized proposal.</p>'
                
                . '<h3 style="font-size: 14px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 5px; margin-top: 30px;">Your Request Summary</h3>'
                . '<ul style="padding-left: 20px; color: #334155; font-size: 14px;">'
                . '<li style="margin-bottom: 8px;"><strong>Trip Type:</strong> ' . esc_html( $data['trip_type'] ) . '</li>'
                . '<li style="margin-bottom: 8px;"><strong>Destination:</strong> ' . esc_html( $data['destination'] ) . '</li>'
                . '<li style="margin-bottom: 8px;"><strong>Date:</strong> ' . esc_html( $data['date'] ) . '</li>'
                . '<li style="margin-bottom: 8px;"><strong>Passengers:</strong> ' . esc_html( $data['adults'] ) . ' Adults, ' . esc_html( $data['kids'] ) . ' Kids</li>'
                . '</ul>'
                
                . '<p style="margin-top: 30px; font-size: 14px; color: #64748b; text-align: center;">If you have any urgent questions, feel free to contact us.</p>'
                . '</div>'
                . '<div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 15px; text-align: center; font-size: 12px; color: #64748b;">'
                . esc_html( $company_name ) . ' — The Mediterranean coastline within your reach'
                . '</div>'
                . '</div>'
                . '</div>';

            $headers_client = class_exists('ETB_Settings') ? ETB_Settings::get_mail_headers( $admin_email, $company_name ) : array();
            wp_mail( $data['email'], $subject_client, $message_client, $headers_client );
        }
    }
}