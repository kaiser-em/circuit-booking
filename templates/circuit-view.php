<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables d'environnement & Réglages
$gen_settings  = get_option( 'etb_general_settings', array() );
$form_settings = get_option( 'etb_form_settings', array() );
$currency      = ! empty( $gen_settings['currency'] ) ? sanitize_text_field( $gen_settings['currency'] ) : '€';
$sec_token     = class_exists( 'ETB_Security' ) ? ETB_Security::generate_timestamp_token() : array( 'time' => time(), 'token' => '' );
$extras        = get_posts( array( 'post_type' => 'tour_extra', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
?>

<?php
// Instagram embed : ne charge le SDK que si au moins une étape du circuit
// contient un embed. Évite une requête tierce sur toutes les pages circuit.
$etb_has_embed = false;
if ( ! empty( $options ) && is_array( $options ) ) {
    foreach ( $options as $opt_data ) {
        if ( empty( $opt_data['timeline'] ) || ! is_array( $opt_data['timeline'] ) ) continue;
        foreach ( $opt_data['timeline'] as $step ) {
            if ( ! empty( $step['embed_code'] ) ) { $etb_has_embed = true; break 2; }
        }
    }
}
?>
<div class="co-circuit-wrapper" id="co-circuit-app" data-circuit-id="<?php echo esc_attr( $circuit_id ); ?>" data-circuit-title="<?php echo esc_attr( get_the_title( $circuit_id ) ); ?>" style="position: relative; width: 100%; clear: both; display: block;">
   <!-- ============================================================
        SPRITE SVG — Icônes vectorielles (remplace les emojis)
        Style : trait 2px, 24x24, hérite de currentColor
        ============================================================ -->
   <svg width="0" height="0" style="position:absolute;" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
       <symbol id="etb-ico-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
           <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
           <circle cx="12" cy="7" r="4"/>
       </symbol>
       <symbol id="etb-ico-luggage" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
           <rect x="6" y="8" width="12" height="13" rx="2"/>
           <path d="M9 8V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v3"/>
           <path d="M10 12v5"/>
           <path d="M14 12v5"/>
       </symbol>
       <symbol id="etb-ico-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
           <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
           <line x1="12" y1="9" x2="12" y2="13"/>
           <line x1="12" y1="17" x2="12.01" y2="17"/>
       </symbol>
   </svg>

   <?php
    // 0. EXTRACTION DES DONNÉES DE L'OPTION ACTIVE
    $active_option = ! empty( $options ) && is_array( $options ) ? reset( $options ) : array();
    
    // Titre de base pour les balises alt des images
    $hero_title = get_the_title( $circuit_id );

    // Rétrocompatibilité et récupération des sections dynamiques de présentation
    $overview_sections = ! empty( $active_option['overview_sections'] ) ? $active_option['overview_sections'] : array();
    if ( empty( $overview_sections ) ) {
        // Fallback si la page n'a pas encore été sauvegardée depuis la mise à jour
        if ( ! empty( $active_option['hero_desc'] ) ) {
            $overview_sections[] = array( 'title' => $active_option['hero_title'] ?? '', 'desc' => $active_option['hero_desc'] );
        }
        if ( ! empty( $active_option['overview_desc'] ) ) {
            $overview_sections[] = array( 'title' => $active_option['overview_title'] ?? 'Overview', 'desc' => $active_option['overview_desc'] );
        }
    }
    
    // Variables Trip Info
    $trip_info_title = ! empty( $active_option['trip_info_title'] ) ? $active_option['trip_info_title'] : 'Trip Info';
    $trip_facts      = ! empty( $active_option['trip_facts'] ) && is_array( $active_option['trip_facts'] ) ? $active_option['trip_facts'] : array();
    
    // Titre des points forts
    $highlights_title = ! empty( $active_option['highlights_title'] ) ? $active_option['highlights_title'] : ( ! empty( $active_option['hero_subtitle'] ) ? $active_option['hero_subtitle'] : 'Points forts :' );
    $highlights_items = ! empty( $active_option['highlights'] ) ? $active_option['highlights'] : ( ! empty( $active_option['hero_bullets'] ) ? array_filter( array_map( 'trim', explode( "\n", $active_option['hero_bullets'] ) ) ) : array() );

    // Récupération des URLs de la galerie photo
    $gallery_enabled = ! empty( $active_option['gallery_enabled'] ) && '1' === (string) $active_option['gallery_enabled'];
    $gallery_raw_ids = $active_option['gallery_image_ids'] ?? '';
    $gallery_ids     = array_filter( array_map( 'absint', explode( ',', (string) $gallery_raw_ids ) ) );
    $gallery_urls    = array();
    if ( $gallery_enabled && ! empty( $gallery_ids ) ) {
        foreach ( $gallery_ids as $gid ) {
            $url = wp_get_attachment_image_url( $gid, 'large' );
            if ( $url ) $gallery_urls[] = $url;
        }
    }
    ?>

    <!-- 0. MOSAÏQUE PHOTO VIP (BENTO GRID CALIBRÉ 360PX) -->
    <?php if ( ! empty( $gallery_urls ) ) : 
        $count = count( $gallery_urls );
        $alt_label = $hero_title ?: 'Circuit';
    ?>
        <div class="co-vip-bento-gallery" data-gallery="<?php echo esc_attr( wp_json_encode( $gallery_urls ) ); ?>" style="width: 100%; display: block; clear: both; margin-bottom: 35px;">
            <?php if ( $count === 1 ) : ?>
                <div class="co-bento-item co-bento-single" data-index="0">
                    <img src="<?php echo esc_url( $gallery_urls[0] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                </div>
            <?php elseif ( $count === 2 ) : ?>
                <div class="co-bento-duo">
                    <div class="co-bento-item" data-index="0">
                        <img src="<?php echo esc_url( $gallery_urls[0] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                    </div>
                    <div class="co-bento-item" data-index="1">
                        <img src="<?php echo esc_url( $gallery_urls[1] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                    </div>
                </div>
            <?php else : ?>
                <div class="co-bento-grid">
                    <div class="co-bento-item co-bento-main" data-index="0">
                        <img src="<?php echo esc_url( $gallery_urls[0] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                    </div>
                    <div class="co-bento-side">
                        <div class="co-bento-item co-bento-sub-top" data-index="1">
                            <img src="<?php echo esc_url( $gallery_urls[1] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                        </div>
                        <div class="co-bento-sub-bottom">
                            <div class="co-bento-item co-bento-thumb" data-index="2">
                                <img src="<?php echo esc_url( $gallery_urls[2] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                            </div>
                            <div class="co-bento-item co-bento-thumb co-bento-last" data-index="3">
                                <img src="<?php echo esc_url( $gallery_urls[3] ?? $gallery_urls[0] ); ?>" alt="<?php echo esc_attr( $alt_label ); ?>">
                                <span class="co-bento-badge" data-index="3">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Gallery
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- 1. GRAND TITRE DU CIRCUIT (S'affiche uniquement si vous le tapez manuellement dans la metabox) -->
    <?php if ( $hero_title ) : ?>
        <h1 style="font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); color: var(--etb-theme-secondary, #5b3516); font-size: 32px; font-weight: 700; margin: 15px 0 20px 0; line-height: 1.25; clear: both; display: none;">
            <?php echo esc_html( $hero_title ); ?>
        </h1>
    <?php endif; ?>

    <!-- 2. BANDEAU TRIP INFO -->
    <?php if ( ! empty( $trip_facts ) ) : ?>
        <div class="co-vip-tripinfo-section" style="margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 22px; width: 100%; clear: both;">
            
            <!-- CORRECTION : RESTAURATION DU VRAI TITRE "TRIP INFO" -->
            <?php if ( $trip_info_title ) : ?>
                <h3 class="co-vip-title" style="margin: 0 0 18px 0; border: none; padding: 0; font-size: 24px !important; font-weight: 600 !important; line-height: 1.25 !important; font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); color: var(--etb-theme-secondary, #5b3516); text-transform: capitalize;">
                    <?php echo esc_html( $trip_info_title ); ?>
                </h3>
            <?php endif; ?>

            <div class="co-vip-tripinfo-row" style="display: flex; flex-wrap: wrap; gap: 24px 40px; align-items: center;">
           
                <?php foreach ( $trip_facts as $fact ) : 
                    $f_icon  = ! empty( $fact['icon'] ) ? $fact['icon'] : 'fas fa-info-circle';
                    $f_label = ! empty( $fact['label'] ) ? $fact['label'] : '';
                    $f_value = ! empty( $fact['value'] ) ? $fact['value'] : '';
                    if ( empty( $f_label ) && empty( $f_value ) ) continue;
                ?>
                    <div class="co-vip-tripinfo-item" style="display: flex; align-items: center; gap: 14px;">
                        <span class="co-vip-tripinfo-icon" style="color: var(--etb-theme-primary, #ba6827); font-size: 26px; line-height: 1; display: inline-flex; align-items: center; justify-content: center; width: 32px; flex-shrink: 0;">
                            <i class="<?php echo esc_attr( $f_icon ); ?>"></i>
                        </span>
                        <div class="co-vip-tripinfo-text" style="display: flex; flex-direction: column;">
                            <?php if ( $f_label ) : ?>
                                <span class="co-vip-tripinfo-label" style="font-size: 12.5px; color: var(--etb-theme-text, #797979); line-height: 1.2; margin-bottom: 3px;">
                                    <?php echo esc_html( $f_label ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $f_value ) : ?>
                                <span class="co-vip-tripinfo-value" style="font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); font-size: 15px; font-weight: 600; color: var(--etb-theme-secondary, #5b3516); line-height: 1.3;">
                                    <?php echo esc_html( $f_value ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. SECTION 2 COLONNES : OVERVIEW (GAUCHE) & CARTE POINTS FORTS SANS BADGE (DROITE) -->
    <div class="co-hero-overview-grid" style="display: grid; grid-template-columns: 1.35fr 1fr; gap: 40px; align-items: start; margin-bottom: 40px; width: 100%; clear: both;">
        
       <!-- COLONNE GAUCHE : SECTIONS DE PRÉSENTATION DYNAMIQUES -->
        <div class="co-hero-text-col">
            <?php foreach ( $overview_sections as $sec ) : ?>
                <div class="co-vip-overview-section" style="margin-bottom: 30px;">
                    <?php if ( ! empty( $sec['title'] ) ) : ?>
                        <h2 style="font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); color: var(--etb-theme-secondary, #5b3516); font-size: 26px; font-weight: 700; margin: 0 0 14px 0; line-height: 1.25;">
                            <?php echo esc_html( $sec['title'] ); ?>
                        </h2>
                    <?php endif; ?>

                    <?php if ( ! empty( $sec['desc'] ) ) : ?>
                        <div class="co-hero-desc" style="color: var(--etb-theme-text, #797979); font-size: 15.5px; line-height: 1.75; font-weight: 500;">
                            <?php echo wp_kses_post( wpautop( $sec['desc'] ) ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- COLONNE DROITE : CARTE POINTS FORTS ÉPURÉE SANS BADGE DE DURÉE -->
        <?php if ( ! empty( $highlights_items ) ) : ?>
            <div class="co-hero-highlights-col">
                <div class="co-vip-highlights-card" style="background: #ffffff; border: 1.5px solid #ebd9cc; border-radius: 12px; padding: 22px 24px; box-shadow: 0 4px 20px rgba(91, 53, 22, 0.04);">
                    
                    <h3 style="display: flex; align-items: center; gap: 10px; font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); color: var(--etb-theme-secondary, #5b3516); font-size: 20px; font-weight: 700; margin: 0 0 18px 0; padding-bottom: 12px; border-bottom: 1px solid #f8fafc;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--etb-theme-primary, #ba6827)" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                        <span><?php echo esc_html( $highlights_title ); ?></span>
                    </h3>

                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px;">
                        <?php foreach ( $highlights_items as $hl ) : ?>
                            <li style="display: flex; align-items: flex-start; gap: 12px; color: var(--etb-theme-text, #797979); font-size: 14.5px; line-height: 1.5;">
                                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="var(--etb-theme-primary, #ba6827)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="16 9 10 15 7 12"></polyline>
                                </svg>
                                <span><?php echo esc_html( $hl ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div> <!-- Fin de co-hero-overview-grid -->

    <?php
    // Récupération dynamique de tous les véhicules publiés
    $vehicles = get_posts( array(
        'post_type'      => 'tour_vehicle',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ) );
    ?>

    <!-- SECTION ITINÉRAIRE & INCLUSIONS (PLEINE LARGEUR) -->
    <div class="co-itinerary-section">
        
       <!-- ONGLETS VILLES + TIMELINE + INCLUSIONS -->
        <div class="co-left-details">
            
            <!-- 1. ONGLETS DES VILLES DE DÉPART (DANS LA COLONNE GAUCHE) -->
            <div class="co-departure-nav">
                <span class="co-nav-label">Departure city:</span>
                <div class="co-nav-buttons">
                    <?php 
                    $is_first = true;
                    foreach ( $options as $opt_id => $opt_data ) : 
                        $city = ! empty( $opt_data['city_name'] ) ? esc_html( $opt_data['city_name'] ) : 'Departure';
                        $add_price = floatval( $opt_data['additional_price'] ?? 0 );
                        $price_suffix = ( $add_price > 0 ) ? ' (+' . $add_price . ' ' . $currency . ')' : '';
                    ?>
                    <button type="button" 
                            class="co-pub-tab-btn <?php echo $is_first ? 'active' : ''; ?>" 
                            data-target="<?php echo esc_attr( $opt_id ); ?>"
                            data-duration="<?php echo esc_attr( $opt_data['duration_hours'] ?? 1 ); ?>"
                            data-price="<?php echo esc_attr( $add_price ); ?>"
                            data-time="<?php echo esc_attr( $opt_data['departure_time'] ?? '09:00' ); ?>">
                        <span class="dashicons dashicons-location"></span>
                        <?php echo $city . esc_html( $price_suffix ); ?>
                    </button>
                    <?php 
                        $is_first = false;
                    endforeach; 
                    ?>
                </div>
            </div>

           <?php 
            $is_first = true;
            foreach ( $options as $opt_id => $opt_data ) : 
                $timeline        = $opt_data['timeline'] ?? array();
                $inclusions      = ! empty( $opt_data['inclusions'] ) ? explode( "\n", trim( $opt_data['inclusions'] ) ) : array();
                $exclusions      = ! empty( $opt_data['exclusions'] ) ? explode( "\n", trim( $opt_data['exclusions'] ) ) : array();
                $overview_title  = $opt_data['overview_title'] ?? 'Circuit Overview';
                $overview_desc   = $opt_data['overview_desc'] ?? '';
                $trip_info_title = ! empty( $opt_data['trip_info_title'] ) ? $opt_data['trip_info_title'] : 'trip info';
                $trip_facts      = ! empty( $opt_data['trip_facts'] ) && is_array( $opt_data['trip_facts'] ) ? $opt_data['trip_facts'] : array();
                
                // Rétrocompatibilité : on récupère les highlights, sinon on utilise les anciens badges
                $highlights = $opt_data['highlights'] ?? array();
                if ( empty( $highlights ) ) {
                    $highlights = array_filter( array(
                        $opt_data['badge_1'] ?? '',
                        $opt_data['badge_2'] ?? '',
                        $opt_data['badge_3'] ?? '',
                        $opt_data['badge_4'] ?? '',
                    ) );
                }
            ?>
                <div class="co-option-pane <?php echo $is_first ? 'active' : ''; ?>" 
                     id="co-pane-<?php echo esc_attr( $opt_id ); ?>" 
                     data-base-departure="<?php echo esc_attr( $opt_data['departure_time'] ?? '09:00' ); ?>">

                   <!-- PROGRAMME / TIMELINE (ACCORDÉON AVEC COMMANDE GLOBALE) -->
                    <?php if ( ! empty( $timeline ) ) : ?>
                        <div class="co-timeline-block">
                            
                            <!-- En-tête Itinéraire avec switch Expand all / Collapse all -->
                            <div class="co-timeline-head-bar" style="display: flex; justify-content: space-between; align-items: center; margin-top: 40px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
                                <h3 class="co-vip-title" style="margin: 0; padding: 0; border: none;">Itinerary</h3>
                                
                                <div class="co-expand-all-wrap" style="display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none;">
                                    <span class="co-expand-all-label" style="font-size: 13.5px; font-weight: 600; color: #5b3516;">Expand all</span>
                                    <button type="button" class="co-expand-toggle-switch" aria-label="Toggle all steps" style="position: relative; width: 40px; height: 22px; background: #cbd5e1; border-radius: 50px; border: none; cursor: pointer; padding: 2px; transition: background 0.25s ease;">
                                        <span class="co-expand-switch-thumb" style="display: block; width: 18px; height: 18px; background: #ffffff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 0.25s ease;"></span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="co-vip-timeline">
                                <?php foreach ( $timeline as $index => $step ) : 
                                    $is_first_step = ($index === 0);
                                    $time_label = ! empty( $step['time'] ) ? $step['time'] . ' : ' : '';

                                    // Photos de l'étape (IDs choisis dans l'éditeur du circuit) : on écarte les images supprimées
                                    $step_photos = array();
                                    foreach ( (array) ( $step['photo_ids'] ?? array() ) as $photo_id ) {
                                        $photo_id = absint( $photo_id );
                                        if ( $photo_id && wp_attachment_is_image( $photo_id ) ) {
                                            $step_photos[] = $photo_id;
                                        }
                                    }
                                ?>
                                                                    <div class="co-vip-step <?php echo $is_first_step ? 'is-open' : ''; ?>">
                                        
                                        <div class="co-vip-step-row">
                                            <div class="co-vip-step-marker <?php echo $is_first_step ? 'filled' : ''; ?>"></div>
                                            
                                            <div class="co-vip-step-header">
                                                <h4 class="co-vip-step-title" data-base-time="<?php echo esc_attr( $step['time'] ?? '' ); ?>">
                                                    <span class="co-timeline-time-display"><?php echo esc_html( $time_label ); ?></span><?php echo esc_html( $step['title'] ?? '' ); ?>
                                                </h4>
                                                <svg class="co-vip-step-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="6 9 12 15 18 9"></polyline>
                                                </svg>
                                            </div>
                                        </div><!-- /.co-vip-step-row -->

                                                                                <!-- 2. LE BODY : Contient les photos, le texte et le post -->
                                        <div class="co-vip-step-body">

                                            <!-- A. LES PHOTOS : Tout en haut du body pour qu'elles soient masquées avec l'accordéon -->
                                            <?php if ( ! empty( $step_photos ) ) : 
                                                $media_class = ( count( $step_photos ) === 1 ) ? 'etb-single-media' : 'etb-gallery-media';
                                            ?>
                                                <div class="etb-step-media-grid <?php echo esc_attr( $media_class ); ?>">
                                                    <?php foreach ( $step_photos as $photo_id ) :
                                                        $photo_alt = trim( (string) get_post_meta( $photo_id, '_wp_attachment_image_alt', true ) );
                                                    ?>
                                                        <figure class="etb-step-photo-premium">
                                                            <?php
                                                            echo wp_get_attachment_image( $photo_id, 'large', false, array(
                                                                'alt'      => '' !== $photo_alt ? $photo_alt : ( $step['title'] ?? '' ),
                                                                'loading'  => 'lazy',
                                                                'decoding' => 'async',
                                                            ) );
                                                            ?>
                                                        </figure>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if ( ! empty( $step['caption'] ) ) : ?>
                                                    <p class="etb-step-caption"><?php echo esc_html( $step['caption'] ); ?></p>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <!-- B. LE POST (EMBED) : Flotte à droite, le texte s'enroule autour -->
                                            <?php 
                                            $embed_code = ! empty( $step['embed_code'] ) ? $step['embed_code'] : '';
                                            if ( $embed_code ) : 
                                            ?>
                                                <div class="etb-social-embed-float">
                                                    <?php echo $embed_code; ?>
                                                </div>
                                            <?php endif; ?>

                                            <!-- C. LE TEXTE (Paragraphes & Sous-blocs) -->
                                            <div class="co-vip-step-text">
                                                <?php if ( ! empty( $step['desc'] ) ) : ?>
                                                    <div style="margin: 0 0 24px 0; color: var(--etb-theme-text, #797979); font-size: 15.5px; line-height: 1.8;">
                                                        <?php echo wp_kses_post( wpautop( $step['desc'] ) ); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ( ! empty( $step['subblocks'] ) && is_array( $step['subblocks'] ) ) : ?>
                                                    <div class="co-vip-step-subblocks" style="display: flex; flex-direction: column; gap: 20px; margin-top: 20px; margin-bottom: 24px;">
                                                        <?php foreach ( $step['subblocks'] as $sub ) : 
                                                            $sub_title = ! empty( $sub['title'] ) ? $sub['title'] : '';
                                                            $sub_desc  = ! empty( $sub['desc'] ) ? $sub['desc'] : '';
                                                        ?>
                                                            <div class="co-vip-subblock-item">
                                                                <?php if ( $sub_title ) : ?>
                                                                    <h5 style="font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); color: var(--etb-theme-secondary, #5b3516); font-size: 17px; font-weight: 700; margin: 0 0 8px 0; line-height: 1.35;">
                                                                        <?php echo esc_html( $sub_title ); ?>
                                                                    </h5>
                                                                <?php endif; ?>
                                                                <?php if ( $sub_desc ) : ?>
                                                                    <div style="margin: 0; color: var(--etb-theme-text, #797979); font-size: 15px; line-height: 1.8;">
                                                                        <?php echo wp_kses_post( wpautop( $sub_desc ) ); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div><!-- /.co-vip-step-text -->

                                        </div><!-- /.co-vip-step-body -->
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- INCLUS / NON INCLUS (STYLE PHOTO 2) -->
                    <?php if ( ! empty( $inclusions ) || ! empty( $exclusions ) ) : ?>
                        <div class="co-vip-inclusions-section" style="display: block; width: 100%; border-top: 1px solid #f1f5f9; padding-top: 35px; margin-top: 35px;">
                            
                            
                            <div class="co-vip-inclusions-wrap">
                                
                                <!-- BLOC INCLUS (CERCLE VERT) -->
                                <?php if ( ! empty( $inclusions ) ) : ?>
                                    <div>
                                        <h4 style="font-family: 'Sunsive-Medium', Sans-serif; color: #5b3516; font-size: 18px; font-weight: 600; margin: 0 0 16px 0;">Included:</h4>
                                        <ul class="co-vip-inc-list" style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px;">
                                            <?php foreach ( $inclusions as $item ) : 
                                                if ( empty( trim( $item ) ) ) continue;
                                            ?>
                                                <li style="display: flex; align-items: flex-start; gap: 12px; color: #797979; font-size: 15px; line-height: 1.5;">
                                                    <!-- Icône Cercle Coche Verte (Photo 2) -->
                                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <polyline points="16 9 10 15 7 12"></polyline>
                                                    </svg>
                                                    <span><?php echo esc_html( trim( $item ) ); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <!-- BLOC NON INCLUS (CERCLE ROUGE) -->
                                <?php if ( ! empty( $exclusions ) ) : ?>
                                    <div>
                                        <h4 style="font-family: 'Sunsive-Medium', Sans-serif; color: #5b3516; font-size: 18px; font-weight: 600; margin: 0 0 16px 0;">Not included:</h4>
                                        <ul class="co-vip-inc-list" style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px;">
                                            <?php foreach ( $exclusions as $item ) : 
                                                if ( empty( trim( $item ) ) ) continue;
                                            ?>
                                                <li style="display: flex; align-items: flex-start; gap: 12px; color: #797979; font-size: 15px; line-height: 1.5;">
                                                    <!-- Icône Cercle Croix Rouge (Photo 2) -->
                                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <line x1="15" y1="9" x2="9" y2="15"></line>
                                                        <line x1="9" y1="9" x2="15" y2="15"></line>
                                                    </svg>
                                                    <span><?php echo esc_html( trim( $item ) ); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            <?php 
                $is_first = false;
            endforeach; 
            ?>
                </div> <!-- Fermeture de .co-left-details -->
    </div> <!-- Fermeture de .co-itinerary-section -->

    <!-- LAYOUT GLOBAL (VÉHICULES + FORMULAIRE) -->
    <div class="etb-booking-layout">
        
        <!-- COLONNE GAUCHE : VÉHICULES + FORMULAIRE -->
        <div class="etb-booking-main">
            
            <!-- SECTION VÉHICULES -->
            <?php if ( ! empty( $vehicles ) ) : ?>
            <div class="co-top-vehicles-section">
                <div class="nexora-section-header">
                    <h2 class="nexora-main-title">Choose your vehicle</h2>
                    <p class="nexora-sub-title">Premium vehicles for optimal comfort.</p>
                </div>

                <!-- Filtres (Visuel pour l'instant) -->
                <div class="nexora-filters">
                    <button class="nexora-filter-btn active">All</button>
                    <button class="nexora-filter-btn">Sedan</button>
                    <button class="nexora-filter-btn">Van</button>
                    <button class="nexora-filter-btn">SUV</button>
                </div>
                
                <div class="co-vehicles-grid" id="co-vehicles-grid">
                    <?php foreach ( $vehicles as $vehicle ) : 
                        $base_price    = get_post_meta( $vehicle->ID, '_etb_base_price', true );
                        $hourly_rate   = get_post_meta( $vehicle->ID, '_etb_hourly_rate', true );
                        $pack_10h      = get_post_meta( $vehicle->ID, '_etb_pack_10h', true );
                        $sup_hour_rate = get_post_meta( $vehicle->ID, '_etb_sup_hour_rate', true );
                        $display_price = ( $hourly_rate > 0 ) ? $hourly_rate : $base_price;
                        $max_pax       = get_post_meta( $vehicle->ID, '_etb_max_pax', true ) ?: 1;
                        $max_bag       = get_post_meta( $vehicle->ID, '_etb_max_baggage', true ) ?: 0;
                        $img_url       = get_the_post_thumbnail_url( $vehicle->ID, 'full' ) ?: ( defined('ETB_URL') ? ETB_URL . 'public/images/default-car.png' : '' );
                        $is_featured   = ( get_post_meta( $vehicle->ID, '_etb_vehicle_featured', true ) === '1' );
                    ?>
                    <div class="etb-vehicle-card<?php echo $is_featured ? ' etb-featured' : ''; ?>" 
                             data-id="<?php echo $vehicle->ID; ?>" 
                             data-hourly-rate="<?php echo esc_attr( $hourly_rate ); ?>"
                             data-pack-10h="<?php echo esc_attr( $pack_10h ); ?>"
                             data-sup-hour-rate="<?php echo esc_attr( $sup_hour_rate ); ?>"
                             data-base-price="<?php echo esc_attr( $base_price ); ?>"
                             data-max-pax="<?php echo esc_attr( $max_pax ); ?>" 
                             data-max-baggage="<?php echo esc_attr( $max_bag ); ?>">
                            
                            <span class="etb-selection-check"><i class="dashicons dashicons-yes"></i></span>
                            
                            <div class="etb-vehicle-image-wrapper">
                                <img src="<?php echo esc_url( $img_url ); ?>" class="etb-vehicle-image etb-img-static" alt="<?php echo esc_attr( $vehicle->post_title ); ?>">
                            </div>
                            
                            <div class="etb-card-content">
                                <h3 class="etb-vehicle-name"><?php echo esc_html( $vehicle->post_title ); ?></h3>
                                <span class="etb-vehicle-subtitle"><?php echo $is_featured ? 'Signature Collection' : 'Premium Class'; ?></span>
                                
                                <div class="etb-vehicle-specs">
                                    <span class="etb-spec-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" class="etb-ico"><use href="#etb-ico-user"/></svg>
                                        <?php echo esc_html( $max_pax ); ?> passengers
                                    </span>
                                    <span class="etb-spec-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" class="etb-ico"><use href="#etb-ico-luggage"/></svg>
                                        <?php echo esc_html( $max_bag ); ?> luggage
                                    </span>
                                </div>
                            </div>

                            <div class="etb-card-footer">
                                <p class="etb-vehicle-price"><strong><?php echo esc_html( $display_price ); ?> <?php echo esc_html( $currency ); ?></strong> / hour</p>
                                <?php if ( $is_featured ) : ?>
                                    <span class="etb-popular-badge">Popular</span>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="etb_car_qty[<?php echo $vehicle->ID; ?>]" value="0">
                        </div>
                    <?php endforeach; ?>
                </div> <!-- Fin de co-vehicles-grid -->

                
                <!-- BOUTON CONTINUE (Caché par défaut, apparaît au clic sur un véhicule) -->
                <div class="etb-vehicle-continue-wrap" id="etb-vehicle-continue-wrap" style="display: none;">
                    <button type="button" class="etb-btn-continue-vehicle" id="etb-btn-continue-vehicle">
                        <span>Continue to Details</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- FORMULAIRE (Caché au début, apparaît après sélection) -->
            <div class="co-booking-form-reveal is-hidden" id="etb-booking-form-wrapper" style="margin-top: 30px;">
                 <!-- 🔧 NOUVEAU : Titre de la section -->
                <div class="nexora-section-header">
                    <h2 class="nexora-main-title">Configure your trip</h2>
                    <p class="nexora-sub-title">Please enter your trip details below.</p>
                </div>

                <form class="etb-booking-widget" id="etb-booking-app" onsubmit="return false;">
                    
                    <div style="position: absolute !important; left: -9999px !important; opacity: 0 !important;" aria-hidden="true">
                        <input type="text" name="etb_hp_email" value="" tabindex="-1" autocomplete="off">
                        <input type="hidden" name="etb_sec_time" value="<?php echo esc_attr( $sec_token['time'] ); ?>">
                        <input type="hidden" name="etb_sec_token" value="<?php echo esc_attr( $sec_token['token'] ); ?>">
                    </div>

                    <div class="etb-progress-bar" id="etb-progress-bar">
                        <div class="etb-progress-step is-active" data-step="1">
                            <span class="etb-progress-num">1</span><span class="etb-progress-label">Trip Details</span>
                        </div>
                        <div class="etb-progress-divider" aria-hidden="true"></div>
                        <div class="etb-progress-step" data-step="2">
                            <span class="etb-progress-num">2</span><span class="etb-progress-label">Your Info</span>
                        </div>
                        <div class="etb-progress-divider" aria-hidden="true"></div>
                        <div class="etb-progress-step" data-step="3">
                            <span class="etb-progress-num">3</span><span class="etb-progress-label">Confirm</span>
                        </div>
                    </div>

                                        <!-- ÉTAPE 1 : TRIP DETAILS -->
                    <div class="etb-step etb-step-1" data-step="1">
                        
                        <!-- CARTE 1 : ITINÉRAIRE -->
                        <?php if ( ( $form_settings['show_pickup'] ?? '1' ) !== '0' ) : ?>
                        <div class="nexora-card">
                            <div class="nexora-card-header">
                                <span class="dashicons dashicons-location"></span>
                                <h3>Itinerary</h3>
                            </div>
                            <div class="nexora-field-group">
                                <label style="font-size:13px; color:#64748b;">Pickup location</label>
                                <div class="nexora-input-wrapper">
                                    <span class="dashicons dashicons-location"></span>
                                    <input type="text" name="etb_pickup_address" id="etb-pickup-address" placeholder="Airport, Hotel..." autocomplete="off">
                                </div>
                                <p class="etb-error-message" id="etb-pickup-error" style="display:none; margin: 0;">⚠ Please provide the pickup address.</p>
                                
                                <div class="etb-field etb-field-full etb-dropoff-toggle-wrapper" style="margin-top: 10px; margin-bottom: 10px;">
                                    <label id="etb-dropoff-label" class="etb-toggle-dropoff-label" for="etb-diff-dropoff-cb">
                                        <input type="checkbox" id="etb-diff-dropoff-cb" class="hidden-input">
                                        <span class="etb-custom-box"><span class="dashicons dashicons-yes"></span></span>
                                        <span class="etb-dropoff-label-text">Different drop-off location</span>
                                    </label>
                                </div>
                                
                                <div class="etb-field etb-field-full" id="etb-dropoff-container" style="display: none;">
                                    <label style="font-size:13px; color:#64748b;">Destination</label>
                                    <div class="nexora-input-wrapper">
                                        <span class="dashicons dashicons-location"></span>
                                        <input type="text" name="etb_dropoff_info" id="etb-dropoff-info" placeholder="Destination..." autocomplete="off">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- CARTE 2 : DATE & TIME -->
                        <div class="nexora-card">
                            <div class="nexora-card-header">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                <h3>Date & Time</h3>
                            </div>
                            <div class="etb-grid-2" style="gap: 16px;">
                                <?php if ( ( $form_settings['show_date'] ?? '1' ) !== '0' ) : ?>
                                    <div class="etb-field">
                                        <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Date *</label>
                                        <div class="nexora-input-wrapper">
                                            <input type="date" name="etb_date" lang="en">
                                        </div>
                                        <p class="etb-error-message" id="etb-date-error" style="display:none;">⚠ Please select a date.</p>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ( $form_settings['show_time'] ?? '1' ) !== '0' ) : ?>
                                    <div class="etb-field">
                                        <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Time *</label>
                                        <div class="nexora-input-wrapper">
                                            
                                            <input type="time" name="etb_time">
                                        </div>
                                        <p class="etb-error-message" id="etb-time-error" style="display:none;">⚠ Please select a time.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- CARTE 3 : PASSAGERS & BAGAGES -->
                        <div class="nexora-card">
                            <div class="nexora-card-header">
                                <span class="dashicons dashicons-groups"></span>
                                <h3>Passengers & Luggage</h3>
                            </div>
                            <p class="etb-error-message" id="etb-vehicle-selection-error" style="display:none; margin-top: 0; margin-bottom: 16px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" class="etb-ico etb-ico-alert" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><use href="#etb-ico-alert" xlink:href="#etb-ico-alert"/></svg>Please select at least one vehicle.
                            </p>
                            
                            <div class="nexora-field-group">
                                <?php if ( ( $form_settings['show_adults'] ?? '1' ) !== '0' ) : ?>
                                    <div class="nexora-pax-row">
                                        <div class="etb-pax-info"><strong>Adults</strong><span>12+ years</span></div>
                                        <div class="nexora-stepper">
                                            <button type="button" class="etb-qty-btn etb-minus">-</button>
                                            <input type="number" name="etb_adults" value="1" min="1" readonly>
                                            <button type="button" class="etb-qty-btn etb-plus">+</button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ( ( $form_settings['show_children'] ?? '1' ) !== '0' ) : ?>
                                    <div class="nexora-pax-row">
                                        <div class="etb-pax-info"><strong>Children</strong><span>2-11 years</span></div>
                                        <div class="nexora-stepper">
                                            <button type="button" class="etb-qty-btn etb-minus">-</button>
                                            <input type="number" name="etb_children" value="0" min="0" readonly>
                                            <button type="button" class="etb-qty-btn etb-plus">+</button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ( ( $form_settings['show_total_bag'] ?? '1' ) !== '0' ) : ?>
                                    <div class="nexora-pax-row">
                                        <div class="etb-pax-info"><strong>Luggage</strong><span>Bagages</span></div>
                                        <div class="nexora-stepper">
                                            <button type="button" class="etb-qty-btn etb-minus" onclick="let i=document.querySelector('input[name=\'etb_total_luggage\']'); if(i.value>0)i.value--; i.dispatchEvent(new Event('input'));">-</button>
                                            <input type="number" name="etb_total_luggage" value="0" min="0" readonly>
                                            <button type="button" class="etb-qty-btn etb-plus" onclick="let i=document.querySelector('input[name=\'etb_total_luggage\']'); i.value++; i.dispatchEvent(new Event('input'));">+</button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p class="etb-error-message" id="etb-pax-capacity-error" style="display:none; margin-top: 16px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" class="etb-ico etb-ico-alert" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><use href="#etb-ico-alert" xlink:href="#etb-ico-alert"/></svg> Passenger capacity exceeded
                            </p>
                        </div>

                        <div class="etb-step-nav" style="margin-top: 30px; border-top: none;">
                            <div class="etb-step-nav-error" data-nav-error="1" style="display:none;"></div>
                            <button type="button" class="etb-btn-next" data-next="2" style="width: 100%; justify-content: center; border-radius: 12px; padding: 18px;">
                                <span>Continue to Your Info</span>
                            </button>
                        </div>
                    </div>
                    <!-- ÉTAPE 2 -->
                    <div class="etb-step etb-step-2 is-hidden" data-step="2">
                        
                        <!-- CARTE : VOS COORDONNÉES -->
                        <div class="nexora-card">
                            <div class="nexora-card-header">
                                <span class="dashicons dashicons-admin-users"></span>
                                <h3>Your Information</h3>
                            </div>
                            <div class="nexora-field-group">
                                
                                <div class="etb-field">
                                    <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Full Name *</label>
                                    <div class="nexora-input-wrapper">
                                        <!-- J'ai retiré le <span class="dashicons dashicons-user"></span> ici -->
                                        <input type="text" name="etb_name" id="etb-name" placeholder="John Doe" autocomplete="name">
                                    </div>
                                    <p class="etb-error-message" id="etb-name-error" style="display:none; margin-top:6px; margin-bottom:0;">⚠ Please enter your name.</p>
                                </div>

                                <div class="etb-field" style="margin-top: 15px;">
                                    <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Email Address *</label>
                                    <div class="nexora-input-wrapper">
                                        <span class="dashicons dashicons-email"></span>
                                        <input type="email" name="etb_email" id="etb-email" placeholder="john@example.com" autocomplete="email">
                                    </div>
                                    <p class="etb-error-message" id="etb-email-error" style="display:none; margin-top:6px; margin-bottom:0;">⚠ Please enter a valid email.</p>
                                </div>

                                <div class="etb-field" style="margin-top: 15px;">
                                    <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Phone Number *</label>
                                    <!-- Le JS s'occupe d'initialiser intl-tel-input sur ce champ -->
                                    <div class="nexora-input-wrapper">
                                        <input type="tel" name="etb_phone" id="etb-phone" class="etb-phone-input" placeholder="+33 6 12 34 56 78" autocomplete="tel">
                                    </div>
                                    <p class="etb-error-message" id="etb-phone-error" style="display:none; margin-top:6px; margin-bottom:0;">⚠ Please enter a valid phone number.</p>
                                </div>

                                <!-- Optionnel : Message spécial -->
                                <div class="etb-field" style="margin-top: 15px;">
                                    <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Special Requests (Optional)</label>
                                    <textarea name="etb_notes" rows="3" placeholder="Flight number, special needs..." style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; resize: vertical;"></textarea>
                                </div>

                            </div>
                        </div>

                        <div class="etb-step-nav etb-step-nav-split">
                            <button type="button" class="etb-btn-back" data-back="1">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                <span>Back</span>
                            </button>
                            <button type="button" class="etb-btn-next" data-next="3">
                                <span>Review Booking</span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </button>
                        </div>
                    </div>

                     <!-- ÉTAPE 3 -->
                    <div class="etb-step etb-step-3 is-hidden" data-step="3">
                        
                        <!-- CARTE : RÉCAPITULATIF -->
                        <div class="nexora-card">
                            <div class="nexora-card-header">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <h3>Review & Confirm</h3>
                            </div>
                            
                            <div class="etb-summary-details" style="margin-bottom: 20px;">
                                <div class="etb-summary-row" style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0;">
                                    <span style="color: #64748b; font-size: 14px;">Date & Time</span>
                                    <strong id="etb-review-datetime" style="color: #1e293b; font-size: 14px;">Not set</strong>
                                </div>
                                <div class="etb-summary-row" style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0;">
                                    <span style="color: #64748b; font-size: 14px;">Passengers</span>
                                    <strong id="etb-review-pax" style="color: #1e293b; font-size: 14px;">1 Adult(s)</strong>
                                </div>
                                <div class="etb-summary-row" style="padding: 10px 0;">
                                    <span style="color: #64748b; font-size: 14px;">Pickup Location</span>
                                    <strong id="etb-review-pickup" style="color: #1e293b; font-size: 14px;">Not set</strong>
                                </div>
                            </div>

                            <!-- Conditions Générales -->
                            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
                                <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 13.5px; color: #475569; line-height: 1.5;">
                                    <input type="checkbox" name="etb_terms" required style="margin-top: 3px; width: 16px; height: 16px; accent-color: #ba6827;">
                                    <span>I agree to the <a href="#" style="color: #ba6827; text-decoration: underline;">Terms and Conditions</a> and <a href="#" style="color: #ba6827; text-decoration: underline;">Privacy Policy</a>.</span>
                                </label>
                            </div>
                        </div>

                        <div class="etb-step-nav etb-step-nav-split">
                            <button type="button" class="etb-btn-back" data-back="2">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                <span>Back</span>
                            </button>
                            <!-- Le bouton de soumission finale (ou tu peux laisser celui de la sidebar) -->
                            <button type="button" class="etb-btn-next" id="etb-final-confirm-btn" style="background: var(--etb-theme-primary, #ba6827);">
                                <span>Confirm Booking</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div> <!-- Fin du formulaire -->
        </div> <!-- /etb-booking-main -->

        <!-- COLONNE DROITE : LE SIDEBAR STICKY -->
        <div class="etb-booking-sidebar">
            
            <div class="etb-sidebar-sticky">
                
                <!-- Titre déplacé à l'intérieur de la carte -->
                <div class="etb-sidebar-header">
                    <h3 class="etb-sidebar-title">Your Selection</h3>
                </div>

                <div class="etb-mission-card" id="etb-mission-card">
                    <div class="etb-mission-row">
                        <div class="etb-mission-thumb"><img id="etb-mc-img" alt=""></div>
                        <div class="etb-mission-info">
                            <div class="etb-mission-name" id="etb-mc-name">—</div>
                            <div class="etb-mission-meta" id="etb-mc-meta"></div>
                        </div>
                    </div>
                </div>

                <div class="etb-summary-receipt">
                    <div id="etb-summary-text">
                        <p style="opacity: 0.7; font-size: 14px;">Select a vehicle to see the price.</p>
                    </div>
                </div>

                <button type="button" class="etb-submit-button" id="etb-submit-button" style="display: none;">
                    <span class="etb-submit-label">Confirm Booking</span>
                    <span class="etb-submit-price"><span id="etb-submit-price-val">0</span> <?php echo esc_html( $currency ); ?></span>
                </button>
            </div>
        </div>

    </div> <!-- /etb-booking-layout -->
    
    <!-- BARRE FLOTTANTE UNIVERSELLE (Sticky Dock) -->
    <div class="etb-mobile-sticky-bar" id="etb-mobile-sticky-bar">
        <div class="etb-msb-price" id="etb-msb-price-wrap">
            <span class="etb-msb-label" id="etb-msb-label">Total</span>
            <span class="etb-msb-amount" style="display: flex; flex-direction: column; line-height: 1.2;">
                <span>
                    <span id="etb-msb-total">0</span> <span id="etb-msb-currency"><?php echo esc_html( $currency ); ?></span>
                </span>
                <span id="etb-msb-duration" style="display:none; font-size: 12px; color: #797979; font-weight: 600;"></span>
            </span>
        </div>
        <button type="button" class="etb-msb-btn" id="etb-msb-btn">
            Book now
        </button>
    </div>

     <!-- CONTENEUR HTML DE LA LIGHTBOX (GALERIE PHOTO VIP) -->
    <div id="etb-vip-lightbox" class="etb-lightbox-overlay">
        <div class="etb-lightbox-close" title="Close (Esc)">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </div>
        <div class="etb-lightbox-nav etb-lightbox-prev" title="Previous">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </div>
        <div class="etb-lightbox-nav etb-lightbox-next" title="Next">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </div>
        <img src="" alt="Gallery Zoom" class="etb-lightbox-img">
        <div class="etb-lightbox-counter"><span id="etb-lb-current">1</span> / <span id="etb-lb-total">1</span></div>
    </div>

    <?php if ( $etb_has_embed ) : ?>
        <script async src="//www.instagram.com/embed.js"></script>
    <?php endif; ?>

</div>