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
// Récupération dynamique de tous les véhicules publiés (Code restauré)
$vehicles = get_posts( array(
    'post_type'      => 'tour_vehicle',
    'post_status'    => 'publish',
    'numberposts'    => -1,
    'orderby'        => 'menu_order',
    'order'          => 'ASC',
) );
?>

<?php
// Instagram embed : ne charge le SDK que si au moins une étape contient un embed.
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
    $highlights_title = ! empty( $active_option['highlights_title'] ) ? $active_option['highlights_title'] : ( ! empty( $active_option['hero_subtitle'] ) ? $active_option['hero_subtitle'] : 'highlights :' );
    $highlights_items = ! empty( $active_option['highlights'] ) ? $active_option['highlights'] : ( ! empty( $active_option['hero_bullets'] ) ? array_filter( array_map( 'trim', explode( "\n", $active_option['hero_bullets'] ) ) ) : array() );
     $map_title = ! empty ( $active_option['map_title']) ? $active_option['map_title'] : 'Map';

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

        <!-- NOUVEAU LAYOUT GLOBAL 2 COLONNES (DÉMARRE ICI) -->
    <div class="co-page-with-aside">
        
        <!-- COLONNE GAUCHE : Overview + Timeline + Inclusions -->
        <div class="co-main-content-col">

            <!-- 1. BANDEAU TRIP INFO -->
            <?php if ( ! empty( $trip_facts ) ) : ?>
                <div class="co-vip-tripinfo-section" style="margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 22px; width: 100%; clear: both;">
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
                                        <span class="co-vip-tripinfo-label" style="font-size: 12.5px; color: var(--etb-theme-text, #797979); line-height: 1.2; margin-bottom: 3px;"><?php echo esc_html( $f_label ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( $f_value ) : ?>
                                        <span class="co-vip-tripinfo-value" style="font-family: var(--etb-theme-font-head, 'Sunsive-Medium', sans-serif); font-size: 15px; font-weight: 600; color: var(--etb-theme-secondary, #5b3516); line-height: 1.3;"><?php echo esc_html( $f_value ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 2. OVERVIEW -->
            <div class="co-hero-overview-grid" style="display: block; margin-bottom: 40px; width: 100%; clear: both;">
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
            </div>

            <!-- 3. TIMELINE -->
            <div class="co-itinerary-section">
                <div class="co-left-details">
                    
                    <!-- ONGLETS VILLES -->
                    <div class="co-departure-nav">
                        <?php 
                        // On vérifie si le nom de la ville existe et n'est pas vide
                        if ( ! empty( $opt_data['city_name'] ) ) : 
                        ?>
                            <div class="co-departure-city-wrapper">
                                <span class="co-nav-label">Departure city:</span>
                                <span><?php echo esc_html( $opt_data['city_name'] ); ?>
                                    <?php if ( ! empty( $opt_data['additional_price'] ) && $opt_data['additional_price'] > 0 ) : ?>
                                        +<?php echo esc_html( $opt_data['additional_price'] ); ?> €
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="co-nav-buttons" role="tablist" aria-label="Departure city">                    
                            <?php 
                            $is_first = true;
                            foreach ( $options as $opt_id => $opt_data ) : 
                                $city = ! empty( $opt_data['city_name'] ) ? esc_html( $opt_data['city_name'] ) : '';
                                $add_price = floatval( $opt_data['additional_price'] ?? 0 );
                                $price_suffix = ( $add_price > 0 ) ? ' (+' . $add_price . ' ' . $currency . ')' : '';
                            ?>
                            <button type="button" 
                                    class="co-pub-tab-btn <?php echo $is_first ? 'active' : ''; ?>" 
                                    id="etb-tab-<?php echo esc_attr( $opt_id ); ?>"
                                    role="tab" aria-selected="<?php echo $is_first ? 'true' : 'false'; ?>"
                                    aria-controls="co-pane-<?php echo esc_attr( $opt_id ); ?>"
                                    tabindex="<?php echo $is_first ? '0' : '-1'; ?>"
                                    data-target="<?php echo esc_attr( $opt_id ); ?>"
                                    data-duration="<?php echo esc_attr( $opt_data['duration_hours'] ?? 1 ); ?>"
                                    data-price="<?php echo esc_attr( $add_price ); ?>"
                                    data-time="<?php echo esc_attr( $opt_data['departure_time'] ?? '09:00' ); ?>">
                        
                                <?php echo $city . esc_html( $price_suffix ); ?>
                            </button>
                            <?php $is_first = false; endforeach; ?>
                        </div>
                    </div>

                    <?php 
                    $is_first = true;
                    foreach ( $options as $opt_id => $opt_data ) : 
                        $timeline        = $opt_data['timeline'] ?? array();
                        $inclusions      = ! empty( $opt_data['inclusions'] ) ? explode( "\n", trim( $opt_data['inclusions'] ) ) : array();
                        $exclusions      = ! empty( $opt_data['exclusions'] ) ? explode( "\n", trim( $opt_data['exclusions'] ) ) : array();
                    ?>
                        <div class="co-option-pane <?php echo $is_first ? 'active' : ''; ?>" 
                             id="co-pane-<?php echo esc_attr( $opt_id ); ?>" 
                             role="tabpanel" aria-labelledby="etb-tab-<?php echo esc_attr( $opt_id ); ?>"
                             aria-hidden="<?php echo $is_first ? 'false' : 'true'; ?>"
                             data-base-departure="<?php echo esc_attr( $opt_data['departure_time'] ?? '09:00' ); ?>">
                           
                            <?php if ( ! empty( $timeline ) ) : ?>
                                <div class="co-timeline-block">
                                    <!-- EN-TÊTE DE L'ITINÉRAIRE (Titre + Desc + Durée + Expand) -->
                                    <div class="co-timeline-head-bar">
                                        
                                        <!-- Partie Gauche : Titre et Description -->

                                        <div class="co-th-left">
                                            <h3 class="co-vip-title">
                                                <?php echo esc_html( ! empty( $opt_data['itinerary_title'] ) ? $opt_data['itinerary_title'] : 'Itinerary' ); ?>
                                            </h3>
                                            
                                            <?php if ( ! empty( $opt_data['itinerary_intro'] ) ) : ?>
                                                <p class="co-th-desc"><?php echo esc_html( $opt_data['itinerary_intro'] ); ?></p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Partie Droite : Badge de durée + Bouton Expand -->
                                        <div class="co-th-right">
                                            <?php 
                                            // Calcul de la durée totale
                                            $dur_hours = floatval( $opt_data['duration_hours'] ?? 1 ); 
                                            $hours = floor($dur_hours);
                                            $minutes = ($dur_hours - $hours) * 60;
                                            $time_str = ($minutes > 0) ? "{$hours}h" . str_pad($minutes, 2, '0', STR_PAD_LEFT) : "{$hours}h";
                                            ?>
                                            <div class="co-duration-badge">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                <span>Estimated duration: <?php echo esc_html( $time_str ); ?></span>
                                            </div>
                                            
                                            <div class="co-expand-all-wrap">
                                                <span class="co-expand-all-label">Expand all</span>
                                                <button type="button" class="co-expand-toggle-switch" aria-label="Toggle all steps" aria-expanded="false">
                                                    <span class="co-expand-switch-thumb"></span>
                                                </button>
                                            </div>
                                        </div>

                                    </div>
                                    
                                    <div class="co-vip-timeline">
                                        <?php foreach ( $timeline as $index => $step ) : 
                                            $is_first_step = ($index === 0);
                                            
                                            // --- NOUVEAU : FORMATAGE DE L'HEURE EN 12H AM/PM ---
                                            $raw_time = $step['time'] ?? '09:00';
                                            $formatted_time = date('h:i A', strtotime($raw_time));
                                            // --------------------------------------------------
                                            
                                            $step_photos = array();
                                            $step_photos = array();
                                            foreach ( (array) ( $step['photo_ids'] ?? array() ) as $photo_id ) {
                                                $photo_id = absint( $photo_id );
                                                if ( $photo_id && wp_attachment_is_image( $photo_id ) ) { $step_photos[] = $photo_id; }
                                            }
                                            $thumb_id = !empty($step_photos) ? $step_photos[0] : 0;
                                            $embed_code = ! empty( $step['embed_code'] ) ? $step['embed_code'] : '';
                                        ?>
                                            <div class="co-vip-step <?php echo $is_first_step ? 'is-open' : ''; ?>">
                                                <div class="co-vip-step-row">
                                                    
                                                 <!-- COLONNE GAUCHE : Numéro -->
                                                    <div class="co-timeline-left-col">
                                                        <div class="co-vip-step-marker <?php echo $is_first_step ? 'filled' : ''; ?>">
                                                            <?php echo $index + 1; ?>
                                                        </div>
                                                        <div class="co-timeline-line"></div>   <!-- 👈 à ajouter -->
                                                    </div>

                                                    <!-- COLONNE DROITE : LE BLOC UNIFIÉ -->
                                                    <div class="co-vip-step-card-wrapper">
                                                        
                                                        <button type="button"
                                                                class="co-vip-step-header"
                                                                aria-expanded="<?php echo $is_first_step ? 'true' : 'false'; ?>"
                                                                aria-controls="etb-step-body-<?php echo esc_attr( $opt_id . '-' . $index ); ?>">
                                                            
                                                            <div class="co-vip-step-card">
                                                                <!-- L'HEURE EST MAINTENANT TOUJOURS AFFICHÉE -->
                                                                <div class="co-time-wrap">
                                                                    <div class="co-card-time" data-base-time="<?php echo esc_attr( $step['time'] ?? '' ); ?>">
                                                                        <?php echo esc_html( $formatted_time ); ?>
                                                                    </div>
                                                                    
                                                                    <!-- L'IMAGE S'AFFICHE UNIQUEMENT SI ELLE EXISTE -->
                                                                    <?php if ( $thumb_id ) : ?>
                                                                        <div class="co-card-img">
                                                                            <?php echo wp_get_attachment_image( $thumb_id, 'medium', false, array('alt' => $step['title'] ?? '') ); ?>
                                                                         </div>
                                                                    <?php endif; ?>
                                                                </div>

                                                                <div class="co-card-content">
                                                                    <div class="co-head-detail">
                                                                        <h4 class="co-card-title"><?php echo esc_html( $step['title'] ?? '' ); ?></h4>
                                                                        <?php if ( ! empty( $step['desc'] ) ) : ?>
                                                                            <p class="co-card-desc"><?php echo wp_trim_words( wp_strip_all_tags( $step['desc'] ), 15 ); ?></p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="co-card-chevron">
                                                                        <svg class="co-vip-step-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                                                    </div>
                                                                </div>

                                                                
                                                            </div>
                                                        </button>
                                                        
                                                

                                                        <!-- Le contenu déplié (Mode Magazine) -->
                                                        <div class="co-vip-step-body" id="etb-step-body-<?php echo esc_attr( $opt_id . '-' . $index ); ?>">
                                                            
                                                            <?php 
                                                            $has_images = !empty($step_photos);
                                                            $extra_photos = array_slice($step_photos, 1);
                                                            ?>
                                                            
                                                            <!-- PARTIE HAUTE : Image Hero (Pleine largeur) puis Texte -->
                                                            <?php if ( $has_images ) : ?>
                                                                <div class="co-step-hero-wrapper">
                                                                    <?php echo wp_get_attachment_image( $step_photos[0], 'large', false, array('loading' => 'lazy', 'class' => 'co-hero-img') ); ?>
                                                                </div>
                                                            <?php endif; ?>

                                                            <div class="co-step-intro-wrapper">
                                                                <?php if ( ! empty( $step['desc'] ) ) : ?>
                                                                    <div class="co-step-main-desc">
                                                                        <?php echo wp_kses_post( wpautop( $step['desc'] ) ); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>

                                                            <!-- PARTIE MILIEU : Sous-blocs avec photos -->
                                                            <?php if ( ! empty( $step['subblocks'] ) && is_array( $step['subblocks'] ) ) : ?>
                                                                <div class="co-step-callouts-grid">
                                                                    <?php foreach ( $step['subblocks'] as $sub ) : 
                                                                        if( empty($sub['title']) && empty($sub['desc']) && empty($sub['photo_ids']) ) continue;
                                                                    ?>
                                                                        <div class="co-callout-card" style="flex-direction: column;">
                                                                            <?php if ( ! empty( $sub['photo_ids'] ) && is_array( $sub['photo_ids'] ) ) : ?>
                                                                                <div class="co-subblock-gallery">
                                                                                    <?php foreach ( $sub['photo_ids'] as $photo_id ) : ?>
                                                                                        <div class="co-subblock-img">
                                                                                            <?php echo wp_get_attachment_image( $photo_id, 'medium', false, array('loading' => 'lazy') ); ?>
                                                                                        </div>
                                                                                    <?php endforeach; ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                            <div class="co-callout-text-wrap">
                                                                                <div class="co-callout-icon">
                                                                                    <svg width="20" height="20" viewBox="0 0 512 512" fill="#ccac8f" stroke="none" stroke-linecap="round" stroke-linejoin="round"><path d="M256,0C114.615,0,0,114.615,0,256s114.615,256,256,256s256-114.615,256-256S397.385,0,256,0z M350.797,328.86 L275.9,403.757C270.622,409.035,263.464,412,256,412c-7.464,0-14.622-2.965-19.9-8.243l-74.896-74.897 c-52.355-52.355-52.355-137.239,0-189.594l0,0c52.354-52.355,137.238-52.355,189.593,0l0,0 C403.151,191.621,403.151,276.505,350.797,328.86z"></path>
                                                                                 <circle cx="256" cy="234.062" r="48.75"></circle></svg>
                                                                                </div>
                                                                                <div class="co-callout-content">
                                                                                    <?php if ( ! empty( $sub['title'] ) ) : ?><h5 class="co-callout-title"><?php echo esc_html( $sub['title'] ); ?></h5><?php endif; ?>
                                                                                    <?php if ( ! empty( $sub['desc'] ) ) : ?><div class="co-callout-desc"><?php echo wp_kses_post( wpautop( $sub['desc'] ) ); ?></div><?php endif; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>

                                                            <!-- PARTIE BASSE : Galerie d'images supplémentaires -->
                                                            <?php if ( count( $step_photos ) > 1 || $embed_code ) : ?>
                                                                <div class="co-step-media-section">
                                                                    <h4 class="co-media-title"><?php echo esc_html( ! empty( $opt_data['timeline_media_title'] ) ? $opt_data['timeline_media_title'] : 'Médias & Découvertes' ); ?></h4>
                                                                    <?php if ( count( $step_photos ) > 1 ) : ?>
                                                                        <div class="co-step-gallery">
                                                                            <?php foreach ( $extra_photos as $photo_id ) : ?>
                                                                                <div class="co-gallery-item"><?php echo wp_get_attachment_image( $photo_id, 'medium', false, array('loading' => 'lazy') ); ?></div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <?php if ( $embed_code ) : ?>
                                                                        <div class="co-step-social-embed"><?php echo $embed_code; ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>

                                                        </div><!-- /.co-vip-step-body -->
                                                    </div><!-- /.co-vip-step-card-wrapper -->
                                                </div><!-- /.co-vip-step-row -->
                        
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- INCLUS / NON INCLUS -->
                            <?php if ( ! empty( $inclusions ) || ! empty( $exclusions ) ) : ?>
                                <div class="co-vip-inclusions-section">
                                    <div class="co-vip-inclusions-wrap">
                                        <?php if ( ! empty( $inclusions ) ) : ?>
                                            <div>
                                                <h4>Included:</h4>
                                                <ul class="co-vip-inc-list">
                                                    <?php foreach ( $inclusions as $item ) : if ( empty( trim( $item ) ) ) continue; ?>
                                                        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="16 9 10 15 7 12"></polyline></svg><span><?php echo esc_html( trim( $item ) ); ?></span></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $exclusions ) ) : ?>
                                            <div>
                                                <h4>Not included:</h4>
                                                <ul class="co-vip-inc-list">
                                                    <?php foreach ( $exclusions as $item ) : if ( empty( trim( $item ) ) ) continue; ?>
                                                        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg><span><?php echo esc_html( trim( $item ) ); ?></span></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php $is_first = false; endforeach; ?>
                </div> <!-- Fermeture de .co-left-details -->
            </div> <!-- Fermeture de .co-itinerary-section -->
            
        </div> <!-- Fermeture de .co-main-content-col -->


        <!-- COLONNE DROITE : L'ASIDE -->
        <aside class="co-circuit-aside">
            
            <!-- ========================================================= -->
            <!-- BLOC 1 : MONOBLOC INFOS (Hero + Highlights + Trip Facts) -->
            <!-- ========================================================= -->
            <div class="co-aside-master-card">
                
                <!-- A. Hero (Image + Titre + Desc) -->
                <div class="co-am-section co-am-hero">
                    <?php 
                    $featured_img_url = get_the_post_thumbnail_url( $circuit_id, 'large' );
                    if ( $featured_img_url ) : 
                    ?>
                        <div class="co-aside-hero-img">
                            <img src="<?php echo esc_url( $featured_img_url ); ?>" alt="<?php echo esc_attr( get_the_title( $circuit_id ) ); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="co-am-content">
                        <h3 class="co-aside-hero-title"><?php echo esc_html( get_the_title( $circuit_id ) ); ?></h3>
                        <?php 
                        $hero_desc = ! empty( $active_option['aside_hero_desc'] ) ? $active_option['aside_hero_desc'] : 'A wonderfull City, a wide open door to a breathtaking experience.';
                        ?>
                        <p class="co-aside-hero-desc"><?php echo wp_kses_post( $hero_desc ); ?></p>
                    </div>
                </div>

                <!-- B. Highlights -->
                <?php if ( ! empty( $highlights_items ) ) : ?>
                    <div class="co-am-section co-am-highlights">
                        <h3 class="co-aside-title"><?php echo esc_html( $highlights_title ); ?></h3>
                        <ul class="co-aside-list">
                            <?php foreach ( $highlights_items as $hl ) : ?>
                                <li>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ba6827" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                    <span><?php echo esc_html( $hl ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- C. Infos Pratiques -->
                <?php if ( ! empty( $trip_facts ) ) : ?>
                    <div class="co-am-section co-am-facts">
                        <h3 class="co-aside-title">Practical information</h3>
                        <ul class="co-aside-facts-list">
                            <?php foreach ( $trip_facts as $fact ) : 
                                $f_icon  = ! empty( $fact['icon'] ) ? $fact['icon'] : 'fas fa-info-circle';
                                $f_label = ! empty( $fact['label'] ) ? $fact['label'] : '';
                                $f_value = ! empty( $fact['value'] ) ? $fact['value'] : '';
                                if ( empty( $f_label ) && empty( $f_value ) ) continue;
                            ?>
                                <li>
                                    <div class="co-fact-icon"><i class="<?php echo esc_attr( $f_icon ); ?>"></i></div>
                                    <div class="co-fact-text">
                                        <span class="co-fact-label"><?php echo esc_html( $f_label ); ?></span>
                                        <span class="co-fact-value"><?php echo esc_html( $f_value ); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <!-- D. MAP (Intégrée dans le même bloc) -->
                <?php if ( ! empty( $active_option['map_iframe'] ) ) : 
                    $raw_map = trim( $active_option['map_iframe'] );
                    $map_src = '';
                    if ( preg_match( '/src="([^"]+)"/', $raw_map, $matches ) ) {
                        $map_src = $matches[1];
                    } elseif ( filter_var( $raw_map, FILTER_VALIDATE_URL ) ) {
                        $map_src = $raw_map;
                    }
                    if ( $map_src ) :
                ?>
                    <div class="co-am-section co-am-map-section">
                        <h3 class="co-aside-title"><?php echo esc_html( $map_title ); ?></h3>
                        <div class="co-am-map-container">
                            <iframe src="<?php echo esc_url( $map_src ); ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                        </div>
                    </div>
                <?php endif; endif; ?>

            </div> <!-- /.co-aside-master-card -->

         
        
            <!-- ========================================================= -->
            <!-- BLOC 3 : MONOBLOC AVIS + IMAGE + CTA -->
            <!-- ========================================================= -->
            <div class="co-aside-card co-aside-monobloc">
                <!-- A. L'Avis (Témoignage) -->
                <?php if ( ( $active_option['aside_review_enabled'] ?? '1' ) === '1' ) : 
                    $rev_name   = $active_option['aside_review_name'] ?? 'Sophie L.';
                    $rev_text   = $active_option['aside_review_text'] ?? '« Une journée parfaite ! »';
                    $rev_rating = absint( $active_option['aside_review_rating'] ?? 5 );
                    $stars      = str_repeat('★', $rev_rating) . str_repeat('☆', 5 - $rev_rating);
                ?>
                <div class="co-review-inner">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode( $rev_name ); ?>&background=ba6827&color=fff" alt="Client" class="co-review-avatar">
                    <div class="co-review-info">
                        <p class="co-review-text"><?php echo esc_html( $rev_text ); ?></p>
                        <span class="co-review-name"><?php echo esc_html( $rev_name ); ?></span>
                        <div class="co-review-stars"><?php echo $stars; ?> <span class="co-review-count"><?php echo $rev_rating; ?>/5</span></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- B. L'Image Secondaire -->
                <?php if ( ( $active_option['aside_image_enabled'] ?? '1' ) === '1' && ! empty( $active_option['aside_image_id'] ) ) : 
                    $aside_img_id = absint( $active_option['aside_image_id'] );
                ?>
                <div class="co-monobloc-img">
                    <?php echo wp_get_attachment_image( $aside_img_id, 'large', false, array( 'alt' => 'Image secondaire', 'style' => 'width:100%; height:100%; object-fit:cover;' ) ); ?>
                </div>
                <?php endif; ?>

                <!-- C. Le Bouton d'action (CTA) -->
                                <div class="co-monobloc-cta">
                    <div class="co-cta-icon">
                            <svg width="22" height="22" fill="none" stroke="#ba6827" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 -4.19 50.375 50.375" xmlns="http://www.w3.org/2000/svg" fill="#000000">
                            <g id="SVGRepo_bgCarrier" stroke-width="0"></g>
                            <g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g>
                            <g id="SVGRepo_iconCarrier">
                            <g id="Group_51" data-name="Group 51" transform="translate(-1214.766 -1789.472)">
                            <path id="Path_134"
                                    data-name="Path 134"
                                    d="M1216.766,1829.464h46.375l-9-16.831-9.455,5.4-12.607-17.11Z"
                                    fill="none"
                                    stroke="#ba6827"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="4"
                            ></path>
                            <path
                                    id="Path_135"
                                    data-name="Path 135"
                                    d="M1257.737,1798.9a7.429,7.429,0,1,1-7.428-7.429A7.428,7.428,0,0,1,1257.737,1798.9Z"
                                    fill="#ffffff"
                                    stroke="#ba6827"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="4"
                            ></path>
                            </g>
                            </g>
                        </svg>
                    </div>
                    <p class="co-cta-text">Ready for an unforgettable experience on the French Riviera?</p>
                    <a href="#co-vehicles-grid" class="co-btn-primary" id="etb-scroll-to-vehicles">Book this circuit</a>
                </div>
            </div>

        </aside>
       

    </div> <!-- Fermeture de .co-page-with-aside -->

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
                    <button class="nexora-filter-btn active" data-filter="all">All</button>
                    <button class="nexora-filter-btn" data-filter="sedan">Sedan</button>
                    <button class="nexora-filter-btn" data-filter="van">Van</button>
                    <button class="nexora-filter-btn" data-filter="sprinter">Sprinter</button>
                </div>
                
                <div class="co-vehicles-grid" id="co-vehicles-grid">
                    <?php foreach ( $vehicles as $vehicle ) :
                        $vehicle_type  = get_post_meta( $vehicle->ID, '_etb_vehicle_type', true ) ?: 'all'; 
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
                            data-type="<?php echo esc_attr( $vehicle_type ); ?>"
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
                 
                <!-- En-tête avec bouton de retour -->
                <div class="nexora-section-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                    <div>
                        <h2 class="nexora-main-title">Configure your trip</h2>
                        <p class="nexora-sub-title">Please enter your trip details below.</p>
                    </div>
                    
                </div>
                <form class="etb-booking-widget" id="etb-booking-app" onsubmit="return false;">

                    <button type="button" id="etb-back-to-vehicles" class="etb-btn-back-to-vehicles inside">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Change Vehicle
                    </button>
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
                    <!--
                    <div id="etb-global-submit-error" class="etb-global-error" role="alert" aria-live="assertive" style="display:none; margin: 0 0 20px 0; padding: 14px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #b91c1c; font-size: 14px; line-height: 1.5;"></div>
                     -->
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
                                            <input type="text" name="etb_date" id="etb-date-input" placeholder="DD/MM/YYYY" autocomplete="off" readonly style="cursor: pointer;">
                                        </div>
                                        <p class="etb-error-message" id="etb-date-error" style="display:none;">⚠ Please select a date.</p>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ( $form_settings['show_time'] ?? '1' ) !== '0' ) : ?>
                                    <div class="etb-field">
                                        <label style="font-size:13px; color:#64748b; margin-bottom:6px; display:block;">Time *</label>
                                        
                                        <!-- Custom Dropdown Premium -->
                                        <div class="etb-custom-select-wrapper" id="etb-time-wrapper">
                                            <input type="hidden" name="etb_time" id="etb-time-value" value="">
                                            
                                            <div class="nexora-input-wrapper etb-custom-select-trigger" id="etb-time-trigger">
                                                <span id="etb-time-label" style="color: #94a3b8;">Select time</span>
                                                <span class="dashicons dashicons-arrow-down-alt2" style="color: #64748b; font-size: 18px;"></span>
                                            </div>
                                            
                                            <ul class="etb-custom-select-options" id="etb-time-options">
                                                <?php
                                                for ( $i = 0; $i < 24; $i++ ) {
                                                    for ( $j = 0; $j < 60; $j += 15 ) {
                                                        $h = sprintf( '%02d', $i );
                                                        $m = sprintf( '%02d', $j );
                                                        $val = "$h:$m";
                                                        
                                                        $display_h = $i % 12;
                                                        if ( $display_h == 0 ) $display_h = 12;
                                                        $ampm = $i < 12 ? 'AM' : 'PM';
                                                        $display = sprintf( '%02d:%02d %s', $display_h, $j, $ampm );
                                                        
                                                        echo '<li class="etb-time-option" data-value="' . esc_attr( $val ) . '">' . esc_html( $display ) . '</li>';
                                                    }
                                                }
                                                ?>
                                            </ul>
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
                                            <button type="button" class="etb-qty-btn etb-minus">-</button>
                                            <input type="number" name="etb_total_luggage" value="0" min="0" readonly>
                                            <button type="button" class="etb-qty-btn etb-plus">+</button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- NOUVEAU : Sous-section Child Seats -->
                                <div class="etb-child-seats-section" style="margin-top: 24px;">
                                    
                                    <!-- Titre de sous-section -->
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px;">
                                        <span class="dashicons dashicons-buddicons-buddypress-logo" style="color: #ba6827; font-size: 18px; width: 18px; height: 18px;"></span>
                                        <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #1e293b; letter-spacing: 0.02em;">Child Seats</h4>
                                        <span style="font-size: 12px; color: #94a3b8; font-weight: 500;">Optional</span>
                                    </div>

                                    <!-- Siège bébé (0-2 ans) -->
                                    <div class="nexora-pax-row">
                                        <div class="etb-pax-info">
                                            <strong>Baby Seat</strong>
                                            <span>0-2 years — 1st free</span>
                                        </div>
                                        <div class="nexora-stepper">
                                            <button type="button" class="etb-qty-btn etb-minus">-</button>
                                            <input type="number" name="etb_baby_seat_count" value="0" min="0" max="2" readonly>
                                            <button type="button" class="etb-qty-btn etb-plus">+</button>
                                        </div>
                                    </div>

                                    <!-- Rehausseur (2-10 ans) -->
                                    <div class="nexora-pax-row">
                                        <div class="etb-pax-info">
                                            <strong>Booster Seat</strong>
                                            <span>2-10 years — 2 first free</span>
                                        </div>
                                        <div class="nexora-stepper">
                                            <button type="button" class="etb-qty-btn etb-minus">-</button>
                                            <input type="number" name="etb_booster_seat_count" value="0" min="0" max="3" readonly>
                                            <button type="button" class="etb-qty-btn etb-plus">+</button>
                                        </div>
                                    </div>
                                </div>
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
                                        <input type="tel" name="etb_phone" id="etb-phone" class="etb-phone-input" autocomplete="tel">
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

                                                        <!-- NOUVEAU : GRILLE PREMIUM EXTRAS (ÉTAPE 3) -->
                            <?php 
                            $extras_posts = get_posts( array( 
                                'post_type'      => 'tour_extra', 
                                'numberposts'    => -1, 
                                'post_status'    => 'publish',
                                'orderby'        => 'menu_order',
                                'order'          => 'ASC'
                            ) ); 
                            if ( ! empty( $extras_posts ) ) : ?>
                            <div class="etb-enhance-section" style="margin-top: 24px; padding-top: 20px; border-top: 1px dashed #e2e8f0;">
                                <div style="margin-bottom: 16px;">
                                    <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                        <span class="dashicons dashicons-star-filled" style="color: #ba6827;"></span>
                                        Enhance your experience
                                    </h4>
                                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;">Add a premium touch to your trip.</p>
                                </div>

                                <div class="etb-extras-grid">
                                    <?php foreach ( $extras_posts as $extra ) :
                                        $extra_price = (float) get_post_meta( $extra->ID, '_etb_price', true );
                                        $extra_max   = absint( get_post_meta( $extra->ID, '_etb_max_qty', true ) ) ?: 10;
                                        $extra_type  = get_post_meta( $extra->ID, '_etb_price_type', true ) ?: 'fixed';
                                        $is_stepper  = ( $extra_type === 'per_quantity' );
                                    ?>
                                    <div class="etb-extra-card" data-extra-id="<?php echo esc_attr( $extra->ID ); ?>" data-type="<?php echo $is_stepper ? 'stepper' : 'toggle'; ?>">
                                        
                                        <!-- Titre & Prix -->
                                        <div class="etb-extra-card-header">
                                            <strong class="etb-extra-card-title"><?php echo esc_html( $extra->post_title ); ?></strong>
                                            <span class="etb-extra-card-price">+<?php echo esc_html( $extra_price ); ?> <?php echo esc_html( $currency ); ?></span>
                                        </div>

                                        <!-- Contrôles (Bouton Add OU Stepper) -->
                                        <div class="etb-extra-card-actions">
                                            <!-- Bouton Add (visible quand non sélectionné) -->
                                            <button type="button" class="etb-extra-add-btn">Add</button>
                                            
                                            <?php if ( $is_stepper ) : ?>
                                                <!-- Mini Stepper (visible uniquement si type=stepper ET sélectionné) -->
                                                <div class="etb-extra-mini-stepper" style="display: none;">
                                                    <button type="button" class="etb-qty-btn etb-minus">-</button>
                                                    <input type="number" name="etb_extra_<?php echo esc_attr( $extra->ID ); ?>" value="0" min="0" max="<?php echo esc_attr( $extra_max ); ?>" readonly>
                                                    <button type="button" class="etb-qty-btn etb-plus">+</button>
                                                </div>
                                            <?php else : ?>
                                                <!-- Input caché pour le type toggle -->
                                                <input type="hidden" name="etb_extra_<?php echo esc_attr( $extra->ID ); ?>" value="0">
                                            <?php endif; ?>
                                            
                                            <!-- Checkmark (visible quand sélectionné) -->
                                            <span class="etb-extra-checkmark" style="display: none;"><span class="dashicons dashicons-yes"></span> Added</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Conditions Générales -->
                            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
                                <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 13.5px; color: #475569; line-height: 1.5;">
                                    <input type="checkbox" name="etb_terms" required style="margin-top: 3px; width: 16px; height: 16px; accent-color: #ba6827;">
                                    <span>I agree to the <a href="#" style="color: #ba6827; text-decoration: underline;">Terms and Conditions</a> and <a href="#" style="color: #ba6827; text-decoration: underline;">Privacy Policy</a>.</span>
                                </label>
                            </div>
                        </div>

                        <div class="etb-step-nav etb-step-nav-split" style="flex-wrap: wrap;">
                            <button type="button" class="etb-btn-back" data-back="2">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                <span>Back</span>
                            </button>

                            <div style="display: flex; flex-direction: column; align-items: stretch; gap: 10px; flex: 1;">
                                <!-- Bouton principal : Proceed to Payment -->
                                <button type="button" class="etb-btn-next" id="etb-final-confirm-btn" style="background: var(--etb-theme-primary, #ba6827); justify-content: center; width: 100%;">
                                    <span>Proceed to Payment</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                                </button>

                                <!-- Lien discret : Book now and pay later -->
                                <button type="button" id="etb-pay-later-btn" style="background: none; border: none; color: #64748b; font-size: 13px; text-decoration: underline; cursor: pointer; padding: 6px 0; font-weight: 500;">
                                    Book now and pay later
                                </button>
                            </div>
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
    <div id="etb-vip-lightbox" class="etb-lightbox-overlay" role="dialog" aria-modal="true" aria-label="Photo gallery">
        <button type="button" class="etb-lightbox-close" aria-label="Close gallery (Escape)">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        <button type="button" class="etb-lightbox-nav etb-lightbox-prev" aria-label="Previous photo">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </button>
        <button type="button" class="etb-lightbox-nav etb-lightbox-next" aria-label="Next photo">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
        <img src="" alt="Gallery Zoom" class="etb-lightbox-img">
        <div class="etb-lightbox-counter" aria-live="polite"><span id="etb-lb-current">1</span> / <span id="etb-lb-total">1</span></div>
    </div>


    <?php if ( $etb_has_embed ) : ?>
        <script async src="//www.instagram.com/embed.js"></script>
    <?php endif; ?>

</div>