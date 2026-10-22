/**
 * Elite Circuit Booking - Frontend Engine
 * Issu de la séparation du booking-widget.js monolithique (v2.2.4).
 */
(function () {
    'use strict';



    // ==========================================================================
    // MOTEUR UNIVERSEL DE GESTION DARK / LIGHT MODE (INFAILLIBLE)
    // ==========================================================================
    const initThemeDetector = function () {
        
        // 1. Détection intelligente du mode à appliquer (Manuel -> Thème du site -> Système OS)
        const detectOptimalTheme = () => {
            const saved = localStorage.getItem('etb_theme_mode');
            if (saved === 'dark' || saved === 'light') return saved;

            // Détection via les classes courantes des thèmes WordPress sur <html> ou <body>
            const rootClasses = (document.documentElement.className + ' ' + document.body.className).toLowerCase();
            if (rootClasses.includes('dark') || rootClasses.includes('night') || rootClasses.includes('black')) {
                return 'dark';
            }
            if (rootClasses.includes('light') || rootClasses.includes('day') || rootClasses.includes('white')) {
                return 'light';
            }

            // Détection selon la préférence du système / navigateur
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                return 'light';
            }

            return 'dark'; // Repli par défaut : VIP Dark Mode
        };

        // 2. Application de l'attribut au DOM (sur <html> et sur chaque conteneur ETB)
        const applyTheme = (themeName) => {
            // Applique au conteneur racine <html> pour que les popups globales comme .pac-container en profitent
            document.documentElement.setAttribute('data-etb-theme', themeName);

           
            const targets = document.querySelectorAll('#etb-quick-widget-app, #etb-checkout-app, .co-circuit-wrapper');
            targets.forEach(el => {
                el.setAttribute('data-etb-theme', themeName);
            });

            // Déclenche un événement global pour avertir tous les modules (y compris Stripe) du changement de thème
            document.dispatchEvent(new CustomEvent('etb_theme_changed', { detail: { theme: themeName } }));
                if ( window.etbDebug ) {
                    console.log( '[ETB] Theme applied:', themeName );
                }
 
        };

        // 3. Lancement immédiat
        applyTheme(detectOptimalTheme());

        // 4. Fonction d'attachement direct du clic sur les boutons
        const bindToggleButtons = () => {
            const buttons = document.querySelectorAll('.etb-theme-toggle-btn');
            buttons.forEach(btn => {
                if (btn.dataset.bound) return; // Évite les doubles clics
                btn.dataset.bound = 'true';
                
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Lecture du thème actuel et inversion
                    const currentTheme = localStorage.getItem('etb_theme_mode') || 'dark';
                    const nextTheme    = (currentTheme === 'dark') ? 'light' : 'dark';

                    localStorage.setItem('etb_theme_mode', nextTheme);
                    applyTheme(nextTheme);
                });
            });
        };

        bindToggleButtons();

        // 5. RADAR (MutationObserver) si le thème charge le widget en décalé
                // Observateur scopé : n'agit que si le widget est présent sur la page.
        // Debounce via requestAnimationFrame pour coalescer les mutations
        // déclenchées par nos propres innerHTML (updateSummary).
        const widgetRoot = document.querySelector(
            '#etb-booking-app, #etb-quick-widget-app, #etb-checkout-app'
        );

        if ( widgetRoot ) {
            let observerScheduled = false;
            const themeObserver = new MutationObserver( () => {
                if ( observerScheduled ) return;
                observerScheduled = true;
                window.requestAnimationFrame( () => {
                    observerScheduled = false;
                    bindToggleButtons();
                    const unstyledWidgets = document.querySelectorAll(
                        '#etb-quick-widget-app:not([data-etb-theme]), ' +
                        '#etb-checkout-app:not([data-etb-theme])'
                    );
                    if ( unstyledWidgets.length > 0 ) {
                        applyTheme( detectOptimalTheme() );
                    }
                } );
            } );
            themeObserver.observe( widgetRoot.parentElement || document.body, {
                childList: true,
                subtree: true
            } );
        }
    };
    const escapeHtml = function (str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const init = function () {
        // 1. Recherche du conteneur racine ETB
        const root = document.querySelector('#etb-booking-app');
        if (!root) return;

        // 2. Cache des éléments du DOM
        const circuitApp = document.querySelector('#co-circuit-app');
        const tabButtons = document.querySelectorAll('.co-pub-tab-btn');
        const panes = document.querySelectorAll('.co-option-pane');

        const vehicleInput = root.querySelector('#etb-vehicle-input');
        const pickupInput = root.querySelector('input[name="etb_pickup_address"]');
        const totalValEl = document.querySelector('#etb-total-val');
        const summaryTextEl = document.querySelector('#etb-summary-text');
        const paxCapacityErrorEl = root.querySelector('#etb-pax-capacity-error');
        const submitButton = document.querySelector('.etb-submit-button');        const capacityDisplayEl = root.querySelector('#etb-capacity-display');
        const baggageCapacityErrorEl = root.querySelector('#etb-baggage-capacity-error');
        const nameInput = root.querySelector('input[name="etb_name"]');
        const emailInput = root.querySelector('input[name="etb_email"]');
        const dateInput = root.querySelector('input[name="etb_date"]');
        const timeInput = root.querySelector('input[name="etb_time"]');
        const nameErrorEl = root.querySelector('#etb-name-error');
        const emailErrorEl = root.querySelector('#etb-email-error');
        const dateErrorEl = root.querySelector('#etb-date-error');
        const timeErrorEl = root.querySelector('#etb-time-error');
        const vehicleSelectionErrorEl = root.querySelector('#etb-vehicle-selection-error');
        const pickupErrorEl = root.querySelector('#etb-pickup-error');
        const globalSubmitErrorEl = root.querySelector('#etb-global-submit-error') || document.querySelector('#etb-global-submit-error');

                const phoneInput   = root.querySelector('input[name="etb_phone"]');
        const phoneErrorEl = root.querySelector('#etb-phone-error');

        // Téléphone international : drapeau, indicatif, formatage (intl-tel-input)
        let phoneIti = null;
        if (phoneInput && typeof window.intlTelInput !== 'undefined') {
            phoneIti = window.intlTelInput(phoneInput, {
                initialCountry: 'auto',
                preferredCountries: ['fr', 'mc', 'gb', 'us', 'ch', 'ae', 'de', 'it'],
                excludeCountries: ['gg', 'je', 'im'],
                separateDialCode: true,
                autoPlaceholder: 'aggressive',
                utilsScript: 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/js/utils.js',
                geoIpLookup: function (callback) {
                    fetch('https://ipapi.co/json/')
                        .then(function (res) { return res.json(); })
                        .then(function (data) { callback(data && data.country_code ? data.country_code.toLowerCase() : 'fr'); })
                        .catch(function () { callback('fr'); });
                }
            });
        }

        const isPhoneValid = () => {
            if (!phoneInput) return true;
            if (phoneInput.value.trim() === '') return false;
            if (phoneIti && typeof window.intlTelInputUtils !== 'undefined') return phoneIti.isValidNumber();
            return /^\+?[0-9\s().-]{7,20}$/.test(phoneInput.value.trim());
        };

        // En-tête de prix latéral (Header Sidebar)
        const sidebarHeaderEl = document.querySelector('#etb-sidebar-price-header');
        const sidebarValEl = document.querySelector('#etb-sidebar-val');
        const sidebarMaxPaxEl = document.querySelector('#etb-sidebar-max-pax');


           // Carte unifiée Véhicule + Total (Étape 3)
                const missionCardEl   = document.querySelector('#etb-mission-card');
        const missionImg      = document.querySelector('#etb-mc-img');
        const missionName     = document.querySelector('#etb-mc-name');
        const missionMeta     = document.querySelector('#etb-mc-meta');
        const missionTotal    = document.querySelector('#etb-mc-total');
        const missionChange   = document.querySelector('#etb-mc-change');
        const submitPriceVal  = document.querySelector('#etb-submit-price-val');
        const mobileStickyBar = document.getElementById('etb-mobile-sticky-bar');
        const mobileStickyTotal = document.getElementById('etb-msb-total');
        const mobileStickyBtn = document.getElementById('etb-msb-btn');

        // Éléments Drop-off
        const diffDropoffCb = root.querySelector('#etb-diff-dropoff-cb');
        const dropoffContainer = root.querySelector('#etb-dropoff-container');
        const dropoffInfo = root.querySelector('#etb-dropoff-info');

        // État interne global
        let state = {
            activeIndex: 0,
            pickup: { name: '', price: 0 },
            currency: (typeof etbAjax !== 'undefined' && etbAjax.currency) ? etbAjax.currency : '$',
            promo: { code: '', discount_type: 'fixed', discount_value: 0 },
            circuit: { duration: 1, additionalPrice: 0, optionId: '', cityName: '' }
        };

        let hasAttemptedSubmit = false;
        let hasSelectedVehicle = false;
        let hasRequiredFieldsMissing = false;

        // Date minimum : aujourd'hui (bloque les dates passées)
        const _today = new Date();
        const todayStr = `${_today.getFullYear()}-${String(_today.getMonth() + 1).padStart(2, '0')}-${String(_today.getDate()).padStart(2, '0')}`;
        if (dateInput) {
            dateInput.setAttribute('min', todayStr);
        }

        const parsePrice = (str) => {
            if (!str) return 0;
            const clean = str.replace(',', '.').replace(/[^-0-9.]/g, '');
            return parseFloat(clean) || 0;
        };

        // ------------------------------------------------------------------------
        // 3. FONCTIONS DE CALCUL ET MISES À JOUR (DÉCLARÉES EN PREMIER)
        // ------------------------------------------------------------------------

        

        // Moteur de calcul en direct (Récapitulatif & En-tête)
        const updateSummary = () => {
            let total = 0;
            let vehiclesHtml = '';
            let circuitPriceHtml = '';
            let totalCapacityPax = 0;
            let totalCapacityBaggage = 0;
            hasSelectedVehicle = false;

            const duration = state.circuit.duration || 1;
            const allCards = Array.from(document.querySelectorAll('.etb-vehicle-card'));

          
            // 1. Calcul des véhicules avec algorithme intelligent des paliers horaires (Pack 10h, Heures Sup)
            allCards.forEach(card => {
                const qtyInput = card.querySelector('input[name^="etb_car_qty"]');
                const qty = parseInt(qtyInput ? qtyInput.value : 0);
                const name = card.querySelector('h3')?.textContent.trim() || 'Véhicule';
                const maxPax = parseInt(card.dataset.maxPax) || 0;
                const maxBaggage = parseInt(card.dataset.maxBaggage) || 0;

                card.classList.toggle('etb-selected', qty > 0);

                if (qty > 0) {
                    hasSelectedVehicle = true;
                    
                    // Extraction stricte des tarifs depuis les attributs de la carte
                    const hRate   = parseFloat(card.dataset.hourlyRate) || 0;
                    const bPrice  = parseFloat(card.dataset.basePrice) || 0;
                    const pack10h = parseFloat(card.dataset.pack10h) || 0;
                    const supRate = parseFloat(card.dataset.supHourRate) || 0;
                    
                    const standardHourly = hRate > 0 ? hRate : bPrice;
                    let vehicleUnitPrice = 0;

                    // Application exacte du moteur de calcul PHP
                    if (pack10h > 0 && duration >= 10.0) {
                        if (duration === 10.0) {
                            vehicleUnitPrice = pack10h;
                        } else {
                            const extraHours = duration - 10.0;
                            const extraRate  = supRate > 0 ? supRate : standardHourly;
                            vehicleUnitPrice = pack10h + (extraHours * extraRate);
                        }
                    } else {
                        vehicleUnitPrice = standardHourly * duration;
                    }

                    const subtotal = vehicleUnitPrice * qty;
                    total += subtotal;
                    totalCapacityPax += maxPax * qty;
                    totalCapacityBaggage += maxBaggage * qty;
                    
                    const durationLabel = duration > 1 ? ` (${duration}h)` : '';
                    vehiclesHtml += `<div class="etb-summary-row"><strong>${qty}x ${name}${durationLabel}</strong><strong>${subtotal.toFixed(0)} ${state.currency}</strong></div>`;
                }
            });

            if (vehiclesHtml === "") {
                const activeCard = allCards[state.activeIndex];
                const name = activeCard ? activeCard.querySelector('h3')?.textContent.trim() : 'Véhicule';
                vehiclesHtml = `<div class="etb-summary-row"><strong>${name}</strong><strong>0 ${state.currency}</strong></div>`;
            }

            // 2. Supplément éventuel du circuit
            if (state.circuit.additionalPrice > 0) {
                total += state.circuit.additionalPrice;
                const cityLabel = state.circuit.cityName ? ` (${state.circuit.cityName})` : '';
                circuitPriceHtml = `<div class="etb-summary-row"><span>Departure surcharge${cityLabel}</span><span>+ ${state.circuit.additionalPrice.toFixed(0)} ${state.currency}</span></div>`;
            }

            total += state.pickup.price;

            // Passagers
            const adults = parseInt(root.querySelector('input[name="etb_adults"]')?.value || 1);
            const children = parseInt(root.querySelector('input[name="etb_children"]')?.value || 0);

            // Validation de la capacité des passagers
            const totalPassengers = adults + children;
            const isPaxCapacityExceeded = totalPassengers > totalCapacityPax;

            if (capacityDisplayEl) {
                capacityDisplayEl.textContent = totalCapacityPax > 0 ? `👤 ${totalCapacityPax} passagers max` : '';
            }

            if (paxCapacityErrorEl) {
                paxCapacityErrorEl.style.display = (hasSelectedVehicle && isPaxCapacityExceeded) ? 'block' : 'none';
            }

            // Validation de la capacité des bagages
            const totalLuggage = parseInt(root.querySelector('input[name="etb_total_luggage"]')?.value || 0);
            const isBaggageCapacityExceeded = totalLuggage > totalCapacityBaggage;

            if (baggageCapacityErrorEl) {
                baggageCapacityErrorEl.style.display = (hasSelectedVehicle && isBaggageCapacityExceeded) ? 'block' : 'none';
            }

            // Validation des champs obligatoires
            const isNameMissing = !!nameInput && nameInput.value.trim() === '';
            if (nameErrorEl) nameErrorEl.style.display = (hasAttemptedSubmit && isNameMissing) ? 'block' : 'none';
            
            const isEmailMissing = !!emailInput && (!emailInput.validity.valid || emailInput.value.trim() === '');
            if (emailErrorEl) emailErrorEl.style.display = (hasAttemptedSubmit && isEmailMissing) ? 'block' : 'none';
                        const isPhoneInvalid = !!phoneInput && !isPhoneValid();
            if (phoneErrorEl) phoneErrorEl.style.display = (hasAttemptedSubmit && isPhoneInvalid) ? 'block' : 'none';

            const isDateMissing = !!dateInput && dateInput.value.trim() === '';
            const isDatePast    = !!dateInput && dateInput.value !== '' && dateInput.value < todayStr;
            
            if (dateErrorEl) {
                if (hasAttemptedSubmit && isDateMissing) {
                    dateErrorEl.textContent = '⚠ Veuillez sélectionner une date.';
                    dateErrorEl.style.display = 'block';
                } else if (isDatePast) {
                    dateErrorEl.textContent = '⚠ La date ne peut pas être dans le passé.';
                    dateErrorEl.style.display = 'block';
                } else {
                    dateErrorEl.style.display = 'none';
                }
            }

            const isTimeMissing = !!timeInput && timeInput.value.trim() === '';
            if (timeErrorEl) timeErrorEl.style.display = (hasAttemptedSubmit && isTimeMissing) ? 'block' : 'none';
            
            const isVehicleMissing = !hasSelectedVehicle;
            if (vehicleSelectionErrorEl) {
                if (hasSelectedVehicle) {
                    vehicleSelectionErrorEl.style.display = 'none';
                } else if (hasAttemptedSubmit && isVehicleMissing) {
                    vehicleSelectionErrorEl.style.display = 'block';
                }
            }

            const isPickupMissing = !!pickupInput && pickupInput.value.trim() === '';
            if (pickupErrorEl) pickupErrorEl.style.display = (hasAttemptedSubmit && isPickupMissing) ? 'block' : 'none';

            hasRequiredFieldsMissing = isNameMissing || isEmailMissing || isPhoneInvalid || isDateMissing || isDatePast || isTimeMissing || isVehicleMissing || isPickupMissing || isBaggageCapacityExceeded;
            // Alerte globale au-dessus du récapitulatif
            if (globalSubmitErrorEl) {
                globalSubmitErrorEl.style.display = (hasAttemptedSubmit && hasRequiredFieldsMissing) ? 'block' : 'none';
            }

            // Blocage du bouton : uniquement si dépassement du nombre de passagers
            if (submitButton) {
                const isBlocked = isPaxCapacityExceeded;
                submitButton.disabled = isBlocked;
                submitButton.classList.toggle('etb-disabled', isBlocked);
            }

            // Options supplémentaires (Extras) : calcul et illumination visuelle dynamique
            let extrasHtml = '';
            root.querySelectorAll('input[name^="etb_extra_"]').forEach(inp => {
                const qty    = parseInt(inp.value) || 0;
                const parent = inp.closest('.etb-extra-item') || inp.closest('.etb-pax-card');

                // Illumination orange dès que la quantité est >= 1, extinction si 0
                if (parent) {
                    parent.classList.toggle('etb-selected', qty > 0);
                }

                if (qty > 0) {
                    const name      = parent?.querySelector('strong')?.textContent.trim() || 'Option';
                    const priceSpan = parent?.querySelector('.etb-price-tag');
                    const price     = parsePrice(priceSpan?.textContent || '0');
                    
                    total += (price * qty);
                    extrasHtml += `<div class="etb-summary-row"><span>${qty}x ${name}</span><span>+ ${(price * qty).toFixed(0)} ${state.currency}</span></div>`;
                }
            });

            // Remise Promo
            let discountAmount = 0;
            let promoHtml = '';
            const promoVal = parseFloat(state.promo.discount_value) || 0;

            if (promoVal > 0) {
                const promoType = String(state.promo.discount_type).toLowerCase();
                const isPercent = ['percentage', 'percent', 'pourcentage', '%'].includes(promoType);

                if (isPercent) {
                    discountAmount = total * (promoVal / 100);
                } else {
                    discountAmount = promoVal;
                }
                
                total = Math.max(0, total - discountAmount);
                promoHtml = `<div class="etb-summary-row etb-promo-row" id="promo-line"><strong>Code promo (${state.promo.code})</strong><strong>- ${discountAmount.toFixed(0)} ${state.currency}</strong></div>`;
            }

            // Mise à jour du Récapitulatif sombre
            if (summaryTextEl) {
                summaryTextEl.innerHTML = `
                    <div class="etb-summary-details">
                        ${vehiclesHtml}
                        <div class="etb-summary-row"><span>${root.querySelector('input[name="etb_adults"]')?.value || 1} Adult(s), ${root.querySelector('input[name="etb_children"]')?.value || 0} Child(ren)</span></div>
                        ${state.pickup.name ? `<div class="etb-summary-row"><span>Pickup: ${state.pickup.name}</span></div>` : ''}
                        ${circuitPriceHtml}
                        ${extrasHtml}
                        ${promoHtml}
                    </div>
                    <div class="etb-summary-row etb-total-row">
                        <span>Total</span>
                        <strong>${total.toFixed(0)} ${state.currency}</strong>
                    </div>
                `;
            }

            if (totalValEl) totalValEl.textContent = total.toFixed(0);

            // Mise à jour de l'en-tête de prix latéral "À PARTIR DE"
            if (sidebarValEl) {
                sidebarValEl.textContent = total.toFixed(0);
            }
            if (sidebarMaxPaxEl) {
                sidebarMaxPaxEl.textContent = totalCapacityPax;
            }
                       if (sidebarHeaderEl) {
                sidebarHeaderEl.classList.toggle('etb-visible', hasSelectedVehicle && totalCapacityPax > 0);
            }


            // 🔧 NOUVEAU : Gérer l'affichage du bouton "Continue" de la grille véhicule
            const btnContinueVehicle = document.getElementById('etb-btn-continue-vehicle');
            const continueWrap = document.getElementById('etb-vehicle-continue-wrap');
            if (continueWrap) {
                continueWrap.classList.toggle('is-visible', hasSelectedVehicle);
            }

            

            // ────────────────────────────────────────────────────────────────
            // CARTE UNIFIÉE — Véhicule + Total (Étape 3)
            // ────────────────────────────────────────────────────────────────
            if (missionCardEl) {
                if (hasSelectedVehicle) {
                    const activeCard = Array.from(allCards).find(c => {
                        const inp = c.querySelector('input[name^="etb_car_qty"]');
                        return inp && parseInt(inp.value) > 0;
                    });

                    if (activeCard) {
                        const name    = activeCard.querySelector('h3')?.textContent.trim() || 'Vehicle';
                        const imgEl   = activeCard.querySelector('.etb-vehicle-image-wrapper img'); // Cible toutes les images
                        const imgSrc  = imgEl && imgEl.src ? imgEl.src : '';
                        const maxPax  = activeCard.dataset.maxPax || '0';
                        const maxBag  = activeCard.dataset.maxBaggage || '0';

                        if (missionImg && imgSrc) missionImg.src = imgSrc;
                        if (missionName) missionName.textContent = name;
                        if (missionMeta) {
                            missionMeta.innerHTML =
                                '<span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' + maxPax + ' pax</span>' +
                                '<span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="8" width="12" height="13" rx="2"/><path d="M9 8V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v3"/></svg>' + maxBag + ' luggage</span>';
                        }
                    }
                }
                if (missionTotal) missionTotal.textContent = total.toFixed(0);
            }

            // ────────────────────────────────────────────────────────────────
            // BOUTON FINAL — Prix mis à jour en temps réel
            // ────────────────────────────────────────────────────────────────
             if (submitPriceVal) {
                submitPriceVal.textContent = total.toFixed(0);
            }

            // Mise à jour de la Sticky Bar Mobile
            if (mobileStickyTotal) {
                mobileStickyTotal.textContent = total.toFixed(0);
            }

            // Gestion de la Sticky Dock Universelle (Sécurisée)
            if (mobileStickyBar) {
                if (mobileStickyBtn) mobileStickyBtn.textContent = 'Book now';
                
                const labelEl = document.getElementById('etb-msb-label');
                const totalEl = document.getElementById('etb-msb-total');
                const currencyEl = document.getElementById('etb-msb-currency');
                const durationEl = document.getElementById('etb-msb-duration');
                const circuitApp = document.querySelector('#co-circuit-app');
                
                if (hasSelectedVehicle) {
                    // Cas 1 : Véhicule sélectionné -> ON AFFICHE LE PRIX
                    if (labelEl) labelEl.textContent = 'TOTAL';
                    if (totalEl) { totalEl.style.display = 'inline'; totalEl.textContent = total.toFixed(0); }
                    if (currencyEl) currencyEl.style.display = 'inline';
                    if (durationEl) durationEl.style.display = 'none';
                } else {
                    // Cas 2 : Pas de véhicule -> ON AFFICHE LE CIRCUIT
                    let cTitle = 'Your Trip';
                    if (circuitApp) {
                        const dataTitle = circuitApp.getAttribute('data-circuit-title');
                        if (dataTitle && dataTitle.trim() !== '') {
                            cTitle = dataTitle;
                        }
                    }
                    const dur = state.circuit.duration || 1;
                    
                    if (labelEl) labelEl.textContent = cTitle; // On ne met plus en majuscule de force
                    if (totalEl) totalEl.style.display = 'none';
                    if (currencyEl) currencyEl.style.display = 'none';
                    if (durationEl) {
                        durationEl.style.display = 'block';
                        durationEl.textContent = `${dur} Hours`;
                    }
                }
            }
        };

        const refreshAll = () => {
            updateSummary();
        };


        /**
         * Recalcule dynamiquement les horaires de la timeline selon l'heure de départ choisie
         */
        const updateTimelineTimes = (chosenTime) => {
            const activePane = document.querySelector('.co-option-pane.active');
            if (!activePane || !chosenTime) return;

            const baseDepStr = activePane.dataset.baseDeparture || '09:00';
            
            // Conversion HH:MM en minutes
            const parseMinutes = (tStr) => {
                if (!tStr || !tStr.includes(':')) return 0;
                const p = tStr.split(':');
                return (parseInt(p[0], 10) * 60) + parseInt(p[1], 10);
            };

            // Formatage minutes en HH:MM
            const formatTime = (totalMin) => {
                const normalized = ((totalMin % 1440) + 1440) % 1440; // Gestion des 24h
                const h = Math.floor(normalized / 60);
                const m = normalized % 60;
                return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
            };

            const baseDepMin   = parseMinutes(baseDepStr);
            const chosenDepMin = parseMinutes(chosenTime);
            const deltaMin     = chosenDepMin - baseDepMin;

            // Décalage de chaque étape de la timeline
            // Note : le template place `data-base-time` sur <h4 class="co-vip-step-title">
            // et le texte de l'heure dans <span class="co-timeline-time-display">.
            // On préserve le format "HH:MM : " utilisé à l'origine.
                        // Cible le nouveau conteneur de l'heure
            // Dans booking-widget.js
            activePane.querySelectorAll('.co-card-time[data-base-time]').forEach(timeEl => {
                const originalStepTime = timeEl.dataset.baseTime;
                if (!originalStepTime || !originalStepTime.includes(':')) return;
                
                const originalMin = parseMinutes(originalStepTime);
                const updatedTime = formatTime(originalMin + deltaMin);
                
                // On reformate en 12h pour l'affichage
                const dateObj = new Date('1970-01-01T' + updatedTime + ':00');
                const formatted = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute:'2-digit', hour12: true });
                timeEl.textContent = formatted;
            });
        };
        // ------------------------------------------------------------------------
        // 4. GESTION DES ONGLETS DE VILLES (DÉCLARÉE APRÈS UPDATESUMMARY)
        // ------------------------------------------------------------------------
        const syncCircuitOption = (btn) => {
            const optionId        = btn.dataset.target || '';
            const duration        = parseFloat(btn.dataset.duration) || 1;
            const additionalPrice = parseFloat(btn.dataset.price) || 0;
            const departureTime   = btn.dataset.time || '';
            const cityName        = btn.textContent.trim();

            state.circuit = {
                duration: duration,
                additionalPrice: additionalPrice,
                optionId: optionId,
                cityName: cityName
            };

            let optionInput = root.querySelector('input[name="etb_option_id"]');
            if (!optionInput) {
                optionInput = document.createElement('input');
                optionInput.type = 'hidden';
                optionInput.name = 'etb_option_id';
                root.appendChild(optionInput);
            }
            optionInput.value = optionId;

            const circuitId = circuitApp ? (circuitApp.dataset.circuitId || 0) : 0;
            let circuitInput = root.querySelector('input[name="etb_circuit_id"]');
            if (!circuitInput) {
                circuitInput = document.createElement('input');
                circuitInput.type = 'hidden';
                circuitInput.name = 'etb_circuit_id';
                root.appendChild(circuitInput);
            }
            circuitInput.value = circuitId;

            if (timeInput && departureTime) {
                timeInput.value = departureTime;
            }

            // Mise à jour immédiate des horaires de la timeline
            updateTimelineTimes(departureTime || (timeInput ? timeInput.value : '09:00'));

            // Appel sécurisé maintenant que updateSummary est déclarée
            updateSummary();
        };

                // Écouteurs d'onglets de ville (pattern WAI-ARIA Tabs + clavier)
        if (tabButtons.length > 0) {
            const tabArray = Array.from(tabButtons);

            const activateTab = function (btn) {
                tabButtons.forEach(b => {
                    const isActive = (b === btn);
                    b.classList.toggle('active', isActive);
                    b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    b.setAttribute('tabindex', isActive ? '0' : '-1');
                });

                panes.forEach(pane => {
                    const isTarget = (pane.id === 'co-pane-' + btn.dataset.target);
                    pane.classList.toggle('active', isTarget);
                    pane.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
                });

                syncCircuitOption(btn);
            };

            tabButtons.forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    activateTab(this);
                });

                btn.addEventListener('keydown', function (e) {
                    const currentIdx = tabArray.indexOf(this);
                    let nextIdx = -1;

                    switch (e.key) {
                        case 'ArrowRight':
                            nextIdx = (currentIdx + 1) % tabArray.length;
                            break;
                        case 'ArrowLeft':
                            nextIdx = (currentIdx - 1 + tabArray.length) % tabArray.length;
                            break;
                        case 'Home':
                            nextIdx = 0;
                            break;
                        case 'End':
                            nextIdx = tabArray.length - 1;
                            break;
                        case 'Enter':
                        case ' ':
                            e.preventDefault();
                            activateTab(this);
                            return;
                        default:
                            return;
                    }

                    if (nextIdx >= 0 && nextIdx !== currentIdx) {
                        e.preventDefault();
                        tabArray[nextIdx].focus();
                        activateTab(tabArray[nextIdx]);
                    }
                });
            });

            const initialActiveBtn = document.querySelector('.co-pub-tab-btn.active') || tabButtons[0];
            if (initialActiveBtn) {
                activateTab(initialActiveBtn);
            }
        }

        // ------------------------------------------------------------------------
        // 4bis. GESTION ACCORDÉON TIMELINE & INTERRUPTEUR EXPAND / COLLAPSE ALL
        // ------------------------------------------------------------------------
        
        // 1. Clic sur une étape individuelle (Ouvrir / Fermer)
        document.addEventListener('click', function (e) {
            const header = e.target.closest('.co-vip-step-header');
            if (!header) return;

            const step = header.closest('.co-vip-step');
            if (step) {
                const isOpen = step.classList.toggle('is-open');
                header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

                // Met à jour l'état visuel du bouton global "Expand all"
                const activePane = header.closest('.co-option-pane');
                if (activePane) {
                    updateExpandAllSwitchState(activePane);
                }
            }
        });

        // 2. Clic sur le switch global "Expand all / Collapse all"
        document.addEventListener('click', function (e) {
            const wrap = e.target.closest('.co-expand-all-wrap');
            if (!wrap) return;

            e.preventDefault();
            const activePane = wrap.closest('.co-option-pane') || document.querySelector('.co-option-pane.active');
            if (!activePane) return;

            const steps = activePane.querySelectorAll('.co-vip-step');
            const switchBtn = wrap.querySelector('.co-expand-toggle-switch');
           // const thumb = wrap.querySelector('.co-expand-switch-thumb');
            const label = wrap.querySelector('.co-expand-all-label');

            const isCurrentlyExpanded = switchBtn && switchBtn.classList.contains('is-active');

            if (isCurrentlyExpanded) {
                // Tout fermer
                steps.forEach(s => {
                    s.classList.remove('is-open');
                    const stepBtn = s.querySelector('.co-vip-step-header');
                    if (stepBtn) stepBtn.setAttribute('aria-expanded', 'false');
                });
                if (switchBtn) {
                    switchBtn.classList.remove('is-active');
                    switchBtn.setAttribute('aria-expanded', 'false');
                }
                if (label) label.textContent = 'Expand all';
            } else {
                // Tout ouvrir
                steps.forEach(s => {
                    s.classList.add('is-open');
                    const stepBtn = s.querySelector('.co-vip-step-header');
                    if (stepBtn) stepBtn.setAttribute('aria-expanded', 'true');
                });
                if (switchBtn) {
                    switchBtn.classList.add('is-active');
                    switchBtn.setAttribute('aria-expanded', 'true');
                }
                if (label) label.textContent = 'Collapse all';
            }
        });

        // Fonction utilitaire pour synchroniser le switch si l'utilisateur ouvre/ferme manuellement
        function updateExpandAllSwitchState(pane) {
            const wrap = pane.querySelector('.co-expand-all-wrap');
            if (!wrap) return;

            const steps = pane.querySelectorAll('.co-vip-step');
            const openSteps = pane.querySelectorAll('.co-vip-step.is-open');
            const switchBtn = wrap.querySelector('.co-expand-toggle-switch');
            //const thumb = wrap.querySelector('.co-expand-switch-thumb');
            const label = wrap.querySelector('.co-expand-all-label');

            const allAreOpen = (steps.length > 0 && steps.length === openSteps.length);

            if (allAreOpen) {
                if (switchBtn) {
                    switchBtn.classList.add('is-active');
                    switchBtn.setAttribute('aria-expanded', 'true');
                }
                if (label) label.textContent = 'Collapse all';
            } else {
                if (switchBtn) {
                    switchBtn.classList.remove('is-active');
                    switchBtn.setAttribute('aria-expanded', 'false');
                }
                if (label) label.textContent = 'Expand all';
            }
        }

        // ------------------------------------------------------------------------
        // 5. GESTION DU DROP-OFF ET DU PICKUP
        // ------------------------------------------------------------------------
        if (diffDropoffCb && dropoffContainer && dropoffInfo) {
            diffDropoffCb.addEventListener('change', function () {
                if (this.checked) {
                    dropoffContainer.style.display = 'block';
                } else {
                    dropoffContainer.style.display = 'none';
                    dropoffInfo.value = '';
                }
            });
        }

        if (pickupInput) {
            pickupInput.addEventListener('input', function () {
                state.pickup = {
                    name: this.value.trim(),
                    price: 0
                };
                updateSummary();
            });
        }

                // Google Places : autocomplétion des adresses de prise en charge et de dépose (Europe)
        if (typeof google !== 'undefined' && google.maps && google.maps.places) {
            const europeBounds = new google.maps.LatLngBounds(
                new google.maps.LatLng(34.5, -25.0),  // sud-ouest
                new google.maps.LatLng(71.5, 40.0)    // nord-est
            );

            const attachPlaces = function (inputEl) {
                if (!inputEl) return;

                const autocomplete = new google.maps.places.Autocomplete(inputEl, {
                    fields: ['formatted_address', 'name'],
                    bounds: europeBounds,
                    strictBounds: true
                });

                autocomplete.addListener('place_changed', function () {
                    const place = autocomplete.getPlace();
                    if (!place || (!place.formatted_address && !place.name)) return;

                    const name = place.name || '';
                    const addr = place.formatted_address || '';
                    // Lieu nommé (hôtel...) : « Nom (adresse) » ; adresse postale : adresse seule
                    inputEl.value = (addr && name && addr.indexOf(name) !== 0) ? name + ' (' + addr + ')' : (addr || name);

                    // Mise à jour directe, sans événement « input » : il ferait rouvrir la liste Google
                    if (inputEl === pickupInput) {
                        state.pickup = { name: inputEl.value.trim(), price: 0 };
                    }
                    refreshAll();
                });

                // Entrée sert à choisir une suggestion, pas à envoyer le formulaire
                inputEl.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') e.preventDefault();
                });
            };

            attachPlaces(pickupInput);
            attachPlaces(dropoffInfo);
        }

        // ------------------------------------------------------------------------
        // 6. VALIDATION DU CODE PROMO (AJAX AVEC FEEDBACK ET LOADING)
        // ------------------------------------------------------------------------
        const promoBtn = root.querySelector('#etb-apply-promo-btn');
        if (promoBtn) {
            promoBtn.addEventListener('click', function (e) {
                e.preventDefault();

                const promoInput = root.querySelector('#etb-promo-input');
                const promoMsg   = root.querySelector('#etb-promo-message');
                const promoCode  = promoInput ? promoInput.value.trim() : '';

                if (!promoCode) {
                    if (promoMsg) {
                        promoMsg.style.display = 'block';
                        promoMsg.className = 'etb-field-feedback etb-error';
                        promoMsg.textContent = '⚠ Veuillez saisir un code promo.';
                    }
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'etb_validate_promo');
                formData.append('nonce', etbAjax.nonce);
                formData.append('promo_code', promoCode);

                // Animation de chargement
                const originalBtnText = promoBtn.textContent;
                promoBtn.disabled = true;
                promoBtn.classList.add('etb-loading');
                promoBtn.textContent = 'Vérification...';

                fetch(etbAjax.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(res => {
                    promoBtn.disabled = false;
                    promoBtn.classList.remove('etb-loading');
                    promoBtn.textContent = originalBtnText;
                    if (promoMsg) promoMsg.style.display = 'block';

                    if (res.success) {
                        state.promo = {
                            code: res.data.code,
                            discount_type: res.data.discount_type,
                            discount_value: res.data.discount_value
                        };
                        if (promoMsg) {
                            promoMsg.className = 'etb-field-feedback etb-success';
                            promoMsg.textContent = '✓ ' + res.data.message;
                        }
                    } else {
                        state.promo = { code: '', discount_type: 'fixed', discount_value: 0 };
                        if (promoMsg) {
                            promoMsg.className = 'etb-field-feedback etb-error';
                            promoMsg.textContent = '⚠ ' + res.data.message;
                        }
                    }
                    refreshAll();
                })
                .catch(() => {
                    promoBtn.disabled = false;
                    promoBtn.classList.remove('etb-loading');
                    promoBtn.textContent = originalBtnText;
                    if (promoMsg) {
                        promoMsg.style.display = 'block';
                        promoMsg.className = 'etb-field-feedback etb-error';
                        promoMsg.textContent = '⚠ Erreur réseau lors de la vérification.';
                    }
                });
            });
        }

        // ------------------------------------------------------------------------
        // 7. ÉCOUTEURS D'ÉVÉNEMENTS UTILISATEURS (CLIC CARTES & QUANTITÉ)
        // ------------------------------------------------------------------------

        // 1. Sélection exclusive d'un véhicule unique (1 réservation = 1 véhicule)
        document.addEventListener('click', (e) => {
            const card = e.target.closest('.etb-vehicle-card');
            if (!card) return;

            // Si clic dans les boutons +/- ou l'input quantité, ne pas basculer
            if (e.target.closest('.etb-qty-control')) {
                return;
            }

            const input = card.querySelector('input[name^="etb_car_qty"]');
            if (!input) return;

            const currentQty = parseInt(input.value) || 0;
            const allCards   = document.querySelectorAll('.etb-vehicle-card');

            if (currentQty > 0) {
                // Si le véhicule était déjà sélectionné, on le désélectionne (remise à zéro)
                input.value = 0;
                card.classList.remove('etb-selected');
            } else {
                // DÉSÉLECTION AUTOMATIQUE DE TOUS LES AUTRES VÉHICULES
                allCards.forEach(c => {
                    const otherInput = c.querySelector('input[name^="etb_car_qty"]');
                    if (otherInput) otherInput.value = 0;
                    c.classList.remove('etb-selected');
                });

                // Sélection exclusive du véhicule cliqué (quantité = 1)
                input.value = 1;
                card.classList.add('etb-selected');
            }

            refreshAll();
        });
        

        // 2. Boutons de quantité +/- (Gestion isolée sans conflit)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.etb-qty-btn');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            const input = btn.parentElement.querySelector('input');
            if (!input) return;

            const isPassengerInput = input.name === 'etb_adults' || input.name === 'etb_children';
            if (isPassengerInput && btn.classList.contains('etb-plus') && !hasSelectedVehicle) {
                if (vehicleSelectionErrorEl) vehicleSelectionErrorEl.style.display = 'block';
                return;
            }

            let val = parseInt(input.value) || 0;
            const min = parseInt(input.getAttribute('min') || 0);

            if (btn.classList.contains('etb-plus')) {
                val++;
            } else if (btn.classList.contains('etb-minus')) {
                if (val > min) val--;
            }

            input.value = val;
            refreshAll();
        });

        // 3. Toggle Extras simples
        root.addEventListener('click', (e) => {
            const item = e.target.closest('.etb-extra-item.etb-type-toggle');
            if (!item) return;

            const input = item.querySelector('input[type="hidden"]');
            const isSelected = item.classList.contains('etb-selected');
            
            item.classList.toggle('etb-selected');
            if (input) input.value = isSelected ? "0" : "1";
            
            refreshAll(); 
        });

        // 4. Écouteurs de saisie formulaire
        const luggageInput = root.querySelector('input[name="etb_total_luggage"]');
        if (luggageInput) luggageInput.addEventListener('input', refreshAll);
        if (nameInput) nameInput.addEventListener('input', refreshAll);
        if (emailInput) emailInput.addEventListener('input', refreshAll);
        if (phoneInput) {
            // Pays où le 0 initial fait partie du numéro : on ne le retire jamais
            const KEEP_ZERO = ['it', 'va', 'sm'];

            const stripTrunkZero = function (digits, iso2) {
                return (digits.startsWith('0') && KEEP_ZERO.indexOf(iso2) === -1) ? digits.substring(1) : digits;
            };

            const formatPhoneDigitsByCountry = function (digits, iso2) {
                if (!digits) return '';
                iso2 = (iso2 || 'fr').toLowerCase();

                // 1. Formatage officiel libphonenumber (utils.js), propre à chaque pays
                if (phoneIti && typeof window.intlTelInputUtils !== 'undefined') {
                    try {
                        const countryData = phoneIti.getSelectedCountryData();
                        const dialCode    = countryData ? ('+' + countryData.dialCode) : '';
                        const formatted   = window.intlTelInputUtils.formatNumber(
                            dialCode + digits,
                            iso2,
                            window.intlTelInputUtils.numberFormat.NATIONAL
                        );
                        if (formatted) {
                            return (KEEP_ZERO.indexOf(iso2) === -1 ? formatted.replace(/^0/, '') : formatted).trim();
                        }
                    } catch (e) {}
                }

                // 2. Repli si utils.js n'est pas encore chargé : blocs de 3 chiffres
                if (digits.length > 6) {
                    return digits.slice(0, 3) + ' ' + digits.slice(3, 6) + ' ' + digits.slice(6);
                }
                return digits.replace(/(\d{3})(?=\d)/g, '$1 ').trim();
            };

            const handlePhoneInputLive = function (e) {
                                // Ne pas reformater pendant l'effacement, mais rafraîchir la validation
                if (e && (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward')) {
                    refreshAll();
                    return;
                }
                if (!phoneIti) { refreshAll(); return; }

                const cursorPosition = this.selectionStart;
                const oldLength      = this.value.length;
                let val              = this.value;

                // 1. Numéro international complet collé (+ ou 00)
                if (val.includes('+') || val.startsWith('00')) {
                    let cleanPasted = val.replace(/[^\d+]/g, '');
                    if (cleanPasted.startsWith('00')) cleanPasted = '+' + cleanPasted.substring(2);

                    // +44 reste sur le Royaume-Uni, +1 sur les États-Unis
                    if (cleanPasted.startsWith('+44')) {
                        phoneIti.setCountry('gb');
                    } else if (cleanPasted.startsWith('+1')) {
                        phoneIti.setCountry('us');
                    } else {
                        phoneIti.setNumber(cleanPasted);
                    }

                    const countryData = phoneIti.getSelectedCountryData();
                    const dialCode    = countryData ? countryData.dialCode : '';
                    const iso2        = countryData ? countryData.iso2 : 'fr';
                    let nationalDigits = cleanPasted.replace(/\D/g, '');
                    if (dialCode && nationalDigits.startsWith(dialCode)) {
                        nationalDigits = nationalDigits.substring(dialCode.length);
                    }
                    nationalDigits = stripTrunkZero(nationalDigits, iso2).substring(0, 15);
                    this.value = formatPhoneDigitsByCountry(nationalDigits, iso2);
                    refreshAll();
                    return;
                }

                // 2. Saisie normale : chiffres uniquement, sans le 0 initial, plafond E.164
                val = val.replace(/\(0\)/g, '');
                const countryData = phoneIti.getSelectedCountryData();
                const iso2        = countryData ? countryData.iso2 : 'fr';
                let digits        = stripTrunkZero(val.replace(/\D/g, ''), iso2).substring(0, 15);

                // 3. Formatage en direct, puis restauration du curseur
                const formatted = formatPhoneDigitsByCountry(digits, iso2);
                this.value = formatted;
                if (cursorPosition !== null) {
                    const newCursor = Math.max(0, cursorPosition + (formatted.length - oldLength));
                    this.setSelectionRange(newCursor, newCursor);
                }
                refreshAll();
            };

            phoneInput.addEventListener('input', handlePhoneInputLive);

            // Reformatage automatique quand on change de drapeau
            phoneInput.addEventListener('countrychange', function () {
                const iso2   = phoneIti ? phoneIti.getSelectedCountryData().iso2 : 'fr';
                const digits = stripTrunkZero(phoneInput.value.replace(/\D/g, ''), iso2);
                phoneInput.value = formatPhoneDigitsByCountry(digits, iso2);
                refreshAll();
            });
        }
        if (dateInput) {
            dateInput.addEventListener('change', refreshAll);
            dateInput.addEventListener('input', refreshAll);
        }
        if (timeInput) {
            const handleTimeChange = function () {
                updateTimelineTimes(this.value);
                refreshAll();
            };
            timeInput.addEventListener('change', handleTimeChange);
            timeInput.addEventListener('input', handleTimeChange);
        }




        // ------------------------------------------------------------------------
        // 8. SOUMISSION FINALE AJAX (FETCH)
        // ------------------------------------------------------------------------
        if (submitButton) {
            submitButton.addEventListener('click', (e) => {
                hasAttemptedSubmit = true;
                refreshAll();
                if (hasRequiredFieldsMissing) {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();

                const formData = new FormData();
                
                // Collecte globale des champs
                document.querySelectorAll('#co-circuit-app input, #co-circuit-app select, #co-circuit-app textarea, #etb-booking-app input, #etb-booking-app select, #etb-booking-app textarea').forEach(field => {
                    if (!field.name || field.disabled) return;

                    if (field.type === 'checkbox' || field.type === 'radio') {
                        if (field.checked) {
                            formData.append(field.name, field.value);
                        }
                    } else {
                        formData.append(field.name, field.value);
                    }
                });


                // Numéro international certifié E.164 (ex: +33612345678), nécessaire pour LimoExpress
                if (phoneIti) {
                    let fullPhone = phoneIti.getNumber();
                    // Repli si utils.js n'est pas chargé : indicatif du drapeau + chiffres saisis
                    if (!fullPhone && phoneInput.value.trim() !== '') {
                        const countryData = phoneIti.getSelectedCountryData();
                        const digits      = phoneInput.value.replace(/\D/g, '');
                        if (countryData && countryData.dialCode && digits) {
                            fullPhone = '+' + countryData.dialCode + digits;
                        }
                    }
                    if (fullPhone) formData.set('etb_phone', fullPhone);
                }

                formData.append('action', 'etb_submit_booking');
                formData.append('nonce', etbAjax.nonce);

                const originalBtnText = submitButton.textContent;
                submitButton.disabled = true;
                submitButton.textContent = 'Traitement en cours...';

                fetch(etbAjax.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then((response) => response.json())
                .then((result) => {
                    if (result.success) {
                        // 1. Affichage du message de confirmation vert
                        const formElement = root.querySelector('form') || root;
                        formElement.innerHTML = `
                            <div class="etb-success-notice" style="padding: 25px; text-align: center; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--etb-radius-md, 12px); margin: 10px 0;">
                                <div style="font-size: 38px; margin-bottom: 10px;">✅</div>
                                <h3 style="color: #166534; margin-top: 0; font-size: 18px; font-weight: 800;">Votre demande de réservation a bien été reçue !</h3>
                                <p style="color: #15803d; font-size: 16px; margin: 10px 0;">
                                    Numéro de dossier : <strong>#${result.data.booking_id}</strong>
                                </p>
                                 <p style="color: #374151; font-size: 14px; margin-bottom: 0;">
                                    Un accusé de réception avec les détails de votre prestation a été envoyé à l'adresse <strong>${escapeHtml(result.data.email)}</strong>.
                                </p>
                            </div>
                        `;

                        // 2. Remise à zéro des cartes véhicules (quantités = 0 et fermeture des pilules)
                        document.querySelectorAll('.etb-vehicle-card input[name^="etb_car_qty"]').forEach(inp => {
                            inp.value = 0;
                        });
                        document.querySelectorAll('.etb-vehicle-card').forEach(card => {
                            card.classList.remove('etb-selected');
                        });

                        // 3. Rétractation animée de l'en-tête de prix latéral
                        if (sidebarHeaderEl) {
                            sidebarHeaderEl.classList.remove('etb-visible');
                        }

                        // 4. Auto-scroll fluide vers la grille des véhicules en haut
                        const scrollTarget = document.querySelector('.co-top-vehicles-section') || document.querySelector('#co-circuit-app') || root;
                        if (scrollTarget) {
                            scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    } else {
                        submitButton.disabled = false;
                        submitButton.textContent = originalBtnText;
                        if (globalSubmitErrorEl) {
                            globalSubmitErrorEl.textContent = (result.data && result.data.message) || 'Une erreur est survenue lors de la réservation.';
                            globalSubmitErrorEl.style.display = 'block';
                            globalSubmitErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                })
                .catch((error) => {
                    console.error('ETB AJAX error:', error);
                    submitButton.disabled = false;
                    submitButton.textContent = originalBtnText;
                    if (globalSubmitErrorEl) {
                        globalSubmitErrorEl.textContent = 'Une erreur réseau est survenue. Veuillez vérifier votre connexion et réessayer.';
                        globalSubmitErrorEl.style.display = 'block';
                        globalSubmitErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            });
        }

                // ────────────────────────────────────────────────────────────────
        // STICKY BAR MOBILE — Bouton d'action
        // ────────────────────────────────────────────────────────────────
        if (mobileStickyBar && mobileStickyBtn) {
            // --- LOGIQUE DE SCROLL ---
            window.addEventListener('scroll', () => {
                const timelineBlock = document.querySelector('.co-timeline-block');
                const vehiclesSection = document.querySelector('.co-top-vehicles-section');
                
                if (!timelineBlock) return;

                // Règle 1 : Si un véhicule est sélectionné, on garde la barre visible pour voir le prix
                if (hasSelectedVehicle) {
                    mobileStickyBar.classList.add('is-visible');
                    return;
                }

                // Règle 2 : Sinon, on gère l'affichage en fonction de la position
                const timelineRect = timelineBlock.getBoundingClientRect();
                const isPastTimelineStart = timelineRect.top < window.innerHeight - 100;
                
                let isAtVehicles = false;
                if (vehiclesSection) {
                    const vehiclesRect = vehiclesSection.getBoundingClientRect();
                    // Si le haut de la section véhicule entre dans l'écran, on cache la barre
                    isAtVehicles = vehiclesRect.top < window.innerHeight - 100;
                }

                if (isPastTimelineStart && !isAtVehicles) {
                    mobileStickyBar.classList.add('is-visible');
                } else {
                    mobileStickyBar.classList.remove('is-visible');
                }
            }, { passive: true });
            
            // --- LOGIQUE DU CLIC ---
            mobileStickyBtn.addEventListener('click', (e) => {
                e.preventDefault();
                
                if (!hasSelectedVehicle) {
                    const vehiclesSection = document.querySelector('.co-top-vehicles-section');
                    if (vehiclesSection) {
                        // 🔧 CORRECTION : block: 'start' pour scroller pile sur le titre
                        vehiclesSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                } else {
                    const activeStep = document.querySelector('.etb-progress-step.is-active')?.dataset.step || '1';
                    if (activeStep === 3) {
                        if (submitButton) submitButton.click();
                    } else {
                        const nextBtn = document.querySelector('.etb-btn-next[data-next]');
                        if (nextBtn) nextBtn.click();
                    }
                }
            });
        }
        // ────────────────────────────────────────────────────────────────
        // BOUTON "CHANGE VEHICLE" — Retour à la grille
        // ────────────────────────────────────────────────────────────────
        if (missionChange) {
            missionChange.addEventListener('click', (e) => {
                e.preventDefault();
                
                // 1. Cacher le formulaire
                const formWrapper = document.getElementById('etb-booking-form-wrapper');
                if (formWrapper) {
                    formWrapper.classList.add('is-hidden');
                }

                // 2. Retirer le layout 2 colonnes (cache le sticky)
                const bookingLayout = document.querySelector('.etb-booking-layout');
                if (bookingLayout) {
                    bookingLayout.classList.remove('has-selection');
                }

                // 3. Réafficher la grille de véhicules
                const vehiclesSection = document.querySelector('.co-top-vehicles-section');
                if (vehiclesSection) {
                    vehiclesSection.style.display = ''; // Réinitialise l'affichage
                    setTimeout(() => {
                        vehiclesSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 50);
                }
            });
        }

        // 🔧 Clic sur le bouton "Continue" sous la grille de véhicules
        const btnContinueVehicle = document.getElementById('etb-btn-continue-vehicle');
        if (btnContinueVehicle) {
            btnContinueVehicle.addEventListener('click', function() {
                // 1. Cacher le bouton
                document.getElementById('etb-vehicle-continue-wrap').classList.remove('is-visible');
                
                // 2. Cacher la grille de véhicules
                const vehiclesSection = document.querySelector('.co-top-vehicles-section');
                if (vehiclesSection) {
                    vehiclesSection.style.display = 'none';
                }

                // 3. Afficher le formulaire
                const formWrapper = document.getElementById('etb-booking-form-wrapper');
                if (formWrapper) {
                    formWrapper.classList.remove('is-hidden');
                }
                
                // 4. Activer le layout 2 colonnes avec le Sidebar
                const bookingLayout = document.querySelector('.etb-booking-layout');
                if (bookingLayout) {
                    bookingLayout.classList.add('has-selection');
                }

                // 5. Scroll doux vers le formulaire
                setTimeout(() => {
                    if (formWrapper) {
                        formWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 100);
            });
        }


        // 9. Animation automatique au centre de l'écran pour Mobile & Tablette
        if ('IntersectionObserver' in window) {
            const observerOptions = {
                root: null,
                rootMargin: '-40% 0px -40% 0px', // Cible la bande centrale de 50% de l'écran
                threshold: 0.2
            };

            const vehicleObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    entry.target.classList.toggle('etb-in-viewport', entry.isIntersecting);
                });
            }, observerOptions);

            document.querySelectorAll('.etb-vehicle-card').forEach(card => {
                vehicleObserver.observe(card);
            });
        }

                // ────────────────────────────────────────────────────────────────
        // WIZARD — Navigation par étapes (Phase 2)
        // ────────────────────────────────────────────────────────────────
        const initBookingWizard = () => {
            const progressBar  = root.querySelector('#etb-progress-bar');
            const progressSteps = root.querySelectorAll('.etb-progress-step');
            const steps        = root.querySelectorAll('.etb-step');
            const nextButtons  = root.querySelectorAll('.etb-btn-next');
            const backButtons  = root.querySelectorAll('.etb-btn-back');
            const formWrapperEl = document.getElementById('etb-booking-form-wrapper');

            if (!steps.length || !progressBar) return;

            // Tableau des messages d'erreur par étape
            const stepErrors = {
                1: 'Please select a vehicle and provide a pickup address to continue.',
                2: 'Please complete all required fields before continuing.'
            };

                        // 🔧 Mettre à jour la carte de récapitulatif
            const updateReviewCard = () => {
                const dateVal = root.querySelector('input[name="etb_date"]')?.value;
                const timeVal = root.querySelector('input[name="etb_time"]')?.value;
                const adults = root.querySelector('input[name="etb_adults"]')?.value || 1;
                const children = root.querySelector('input[name="etb_children"]')?.value || 0;

                const revDatetime = document.getElementById('etb-review-datetime');
                const revPax = document.getElementById('etb-review-pax');

                // ... (à l'intérieur de updateReviewCard, après revPax)
                const revPickup = document.getElementById('etb-review-pickup');
                if (revPickup) {
                    const pickupVal = root.querySelector('input[name="etb_pickup_address"]')?.value;
                    revPickup.textContent = pickupVal ? pickupVal : 'Not set';
                }
                if (revDatetime) {
                    if (dateVal && timeVal) {
                        // Formater la date proprement (ex: Oct 20, 2026)
                        const dateObj = new Date(dateVal);
                        const formattedDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        revDatetime.textContent = `${formattedDate} at ${timeVal}`;
                    } else {
                        revDatetime.textContent = 'Not set';
                    }
                }

                if (revPax) {
                    let paxStr = `${adults} Adult(s)`;
                    if (parseInt(children) > 0) paxStr += `, ${children} Child(ren)`;
                    revPax.textContent = paxStr;
                }
            };

                        // Positionne la barre de progression et l'état visuel des étapes
            const setActiveStep = (stepNum, skipScroll = false) => {
                // Barre de progression
                progressSteps.forEach(el => {
                    const s = parseInt(el.dataset.step, 10);
                    el.classList.toggle('is-active', s === stepNum);
                    el.classList.toggle('is-complete', s < stepNum);
                });

                 // 🔧 Met à jour les données du reçu avant d'afficher l'étape
                updateReviewCard();

                // Bascule des étapes : on masque tout sauf l'étape cible
                steps.forEach(el => {
                    const s = parseInt(el.dataset.step, 10);
                    if (s === stepNum) {
                        // Entrée : retirer is-hidden, forcer le reflow, puis retirer is-entering
                        el.classList.remove('is-hidden');
                        el.classList.add('is-entering');
                        // Force le navigateur à recalculer le layout pour déclencher la transition
                        void el.offsetWidth;
                        el.classList.remove('is-entering');
                    } else {
                        el.classList.add('is-hidden');
                    }
                });

                // Scroll doux vers la barre de progression (sauf si demandé de ne pas le faire)
                if (!skipScroll) {
                    setTimeout(() => {
                        if (formWrapperEl) {
                            formWrapperEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }, 80);
                }

                                // 🔧 Afficher le bouton de confirmation uniquement à l'étape 3
                const finalSubmitBtn = document.getElementById('etb-submit-button');
                if (finalSubmitBtn) {
                    finalSubmitBtn.style.display = (stepNum === 3) ? 'flex' : 'none';
                }
            };

            // Validation ciblée par étape (sans toucher à hasAttemptedSubmit)
            const validateStep = (stepNum) => {
                if (stepNum === 1) {
                    // Au moins un véhicule sélectionné
                    if (!hasSelectedVehicle) return false;

                    // Pickup obligatoire si le champ est visible
                    if (pickupInput && pickupInput.offsetParent !== null && pickupInput.value.trim() === '') {
                        return false;
                    }

                    // Date obligatoire et non passée si le champ est visible
                    if (dateInput && dateInput.offsetParent !== null) {
                        if (dateInput.value.trim() === '') return false;
                        if (dateInput.value < todayStr) return false;
                    }

                    // Heure obligatoire si le champ est visible
                    if (timeInput && timeInput.offsetParent !== null && timeInput.value.trim() === '') return false;

                    return true;
                }

                if (stepNum === 2) {
                    // Nom obligatoire si visible
                    if (nameInput && nameInput.offsetParent !== null && nameInput.value.trim() === '') return false;

                    // Email obligatoire si visible
                    if (emailInput && emailInput.offsetParent !== null) {
                        if (emailInput.value.trim() === '' || !emailInput.validity.valid) return false;
                    }

                    // Téléphone valide si visible
                    if (phoneInput && phoneInput.offsetParent !== null && !isPhoneValid()) return false;

                    return true;
                }
                return true;
            };

            // Affiche / masque le message d'erreur du nav
            const setNavError = (stepNum, message) => {
                const errEl = root.querySelector('.etb-step-nav-error[data-nav-error="' + stepNum + '"]');
                if (!errEl) return;
                if (message) {
                    errEl.textContent = message;
                    errEl.style.display = 'block';
                } else {
                    errEl.textContent = '';
                    errEl.style.display = 'none';
                }
            };

            // Clic sur "Continue"
            nextButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const targetStep = parseInt(btn.dataset.next, 10);
                    const currentStep = targetStep - 1;

                    // Efface l'erreur précédente
                    setNavError(currentStep, '');

                    // Validation de l'étape en cours
                    if (!validateStep(currentStep)) {
                        setNavError(currentStep, stepErrors[currentStep] || 'Please complete this step.');
                        // Met en évidence les erreurs individuelles déjà gérées par updateSummary
                        hasAttemptedSubmit = true;
                        refreshAll();
                        return;
                    }

                    // Réinitialise le flag d'erreur pour que l'étape suivante démarre propre
                    hasAttemptedSubmit = false;

                    // Rafraîchit le récap et le prix avant de passer à l'étape suivante
                    refreshAll();

                    // Bascule
                    setActiveStep(targetStep);
                });
            });

            // Clic sur "Back"
            backButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const targetStep = parseInt(btn.dataset.back, 10);
                    // Efface les erreurs résiduelles du nav
                    setNavError(targetStep + 1, '');
                    setNavError(targetStep, '');
                    setActiveStep(targetStep);
                });
            });

                        // État initial : étape 1
            setActiveStep(1, true); // true = Ne pas scroller au chargement
        };

        // Lier le bouton de confirmation de l'étape 3 au bouton de soumission global
        const finalConfirmBtn = document.getElementById('etb-final-confirm-btn');
        const sidebarSubmitBtn = document.getElementById('etb-submit-button');
        if (finalConfirmBtn && sidebarSubmitBtn) {
            finalConfirmBtn.addEventListener('click', function(e) {
                e.preventDefault();
                sidebarSubmitBtn.click();
            });
        }
        // Lancement initial

        refreshAll();
        initBookingWizard();
    };

    // Lancement au chargement de la page
    const startApp = function () {
        initThemeDetector();
        init();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startApp);
    } else {
        startApp();
    }

    // ==========================================================================
    // MODULE LIGHTBOX : VISIONNEUSE PHOTO VIP (BENTO GALLERY)
    // ==========================================================================
    var lightbox = document.getElementById('etb-vip-lightbox');
    if (lightbox) {
        var lbImg     = lightbox.querySelector('.etb-lightbox-img');
        var lbPrev    = lightbox.querySelector('.etb-lightbox-prev');
        var lbNext    = lightbox.querySelector('.etb-lightbox-next');
        var lbClose   = lightbox.querySelector('.etb-lightbox-close');
        var lbCurrent = document.getElementById('etb-lb-current');
        var lbTotal   = document.getElementById('etb-lb-total');
        
        var galleryImages = [];
        var currentIndex = 0;

        var galleryContainer = document.querySelector('.co-vip-bento-gallery');
        if (galleryContainer) {
            var rawGallery = galleryContainer.getAttribute('data-gallery');
            if (rawGallery) {
                try { 
                    galleryImages = JSON.parse(rawGallery); 
                } catch (e) {
                    galleryImages = [];
                }
            }

            if (galleryImages.length > 0) {
                // Liaison directe sur les conteneurs Bento
                var bentoItems = galleryContainer.querySelectorAll('.co-bento-item');
                bentoItems.forEach(function(item, fallbackIdx) {
                    item.style.cursor = 'pointer';
                    item.addEventListener('click', function(e) {
                        var idxAttr = item.getAttribute('data-index');
                        var idx = (idxAttr !== null) ? parseInt(idxAttr, 10) : fallbackIdx;
                        openLightbox(isNaN(idx) ? 0 : idx);
                    });
                });
                
                // Liaison sur le badge "Gallery"
                var galleryBadge = galleryContainer.querySelector('.co-bento-badge');
                if (galleryBadge) {
                    galleryBadge.style.cursor = 'pointer';
                    galleryBadge.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var badgeIdxAttr = galleryBadge.getAttribute('data-index');
                        var targetIdx = (badgeIdxAttr !== null) ? parseInt(badgeIdxAttr, 10) : (galleryImages.length > 3 ? 3 : 0);
                        openLightbox(isNaN(targetIdx) ? 0 : targetIdx);
                    });
                }
            }
        }

        var previouslyFocused = null;

        function openLightbox(index) {
            if (!galleryImages || galleryImages.length === 0) return;
            if (index < 0) index = 0;
            if (index >= galleryImages.length) index = galleryImages.length - 1;
            previouslyFocused = document.activeElement;
            currentIndex = index;
            updateLightboxContent();
            lightbox.classList.add('etb-lightbox-active');
            document.body.style.overflow = 'hidden';
            if (lbClose) lbClose.focus();
        }

        function closeLightbox() {
            lightbox.classList.remove('etb-lightbox-active');
            document.body.style.overflow = '';
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
            previouslyFocused = null;
        }

        function updateLightboxContent() {
            if (!galleryImages[currentIndex]) return;
            lbImg.style.opacity = '0';
            setTimeout(function() {
                lbImg.src = galleryImages[currentIndex];
                lbImg.style.opacity = '1';
            }, 120);
            
            if (lbCurrent) lbCurrent.textContent = currentIndex + 1;
            if (lbTotal) lbTotal.textContent = galleryImages.length;
            
            if (lbPrev) lbPrev.style.display = (currentIndex === 0) ? 'none' : 'flex';
            if (lbNext) lbNext.style.display = (currentIndex === galleryImages.length - 1) ? 'none' : 'flex';
        }

        if (lbClose) lbClose.addEventListener('click', closeLightbox);
        if (lbNext) lbNext.addEventListener('click', function() { 
            if (currentIndex < galleryImages.length - 1) { 
                currentIndex++; 
                updateLightboxContent(); 
            } 
        });
        if (lbPrev) lbPrev.addEventListener('click', function() { 
            if (currentIndex > 0) { 
                currentIndex--; 
                updateLightboxContent(); 
            } 
        });
        
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox) closeLightbox();
        });

                document.addEventListener('keydown', function(e) {
            if (!lightbox.classList.contains('etb-lightbox-active')) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowRight' && currentIndex < galleryImages.length - 1) { 
                currentIndex++; 
                updateLightboxContent(); 
            }
            if (e.key === 'ArrowLeft' && currentIndex > 0) { 
                currentIndex--; 
                updateLightboxContent(); 
            }

            // Focus trap : Tab reste cyclé dans la lightbox
            if (e.key === 'Tab') {
                var focusables = Array.from(lightbox.querySelectorAll('button')).filter(function (el) {
                    return el.offsetParent !== null;
                });
                if (focusables.length === 0) return;
                var first = focusables[0];
                var last = focusables[focusables.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
    }
})();
