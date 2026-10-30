(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('etb-standalone-payment-form');
        if (!form) return;

        if (typeof Stripe === 'undefined' || typeof etbPayData === 'undefined' || !etbPayData.stripe_pk) {
            document.getElementById('etb-card-number-mount').innerHTML = '<span style="color:red; font-size:12px;">Error: Stripe is missing.</span>';
            return;
        }

        const stripe = Stripe(etbPayData.stripe_pk);
        const elements = stripe.elements();
        const amount = parseFloat(document.getElementById('etb-pay-amount').value);
        const bookingId = document.getElementById('etb-pay-booking-id').value;

        // Déclarations globales (une seule fois)
        const feedback = document.getElementById('etb-standalone-pay-feedback');
        const payBtn = document.getElementById('etb-standalone-pay-btn');
        const payText = document.getElementById('etb-standalone-pay-text');
        
        // Désactiver le bouton au départ (sécurité)
        if(payBtn) {
            payBtn.disabled = true;
            payBtn.style.opacity = '0.5';
            payBtn.style.cursor = 'not-allowed';
        }

        const showError = (msg) => { feedback.textContent = msg; feedback.style.display = 'block'; };
        const clearError = () => { feedback.style.display = 'none'; };

        // ══════════════════════════════════════════════════════════════
        // 1. INITIALISATION DE STRIPE ELEMENTS (CARTE BANCAIRE)
        // ══════════════════════════════════════════════════════════════
        const elementStyles = {
            base: { color: '#1e293b', fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', fontSmoothing: 'antialiased', fontSize: '15px', '::placeholder': { color: '#94a3b8' } },
            invalid: { color: '#dc2626', iconColor: '#dc2626' }
        };

        const cardNumber = elements.create('cardNumber', { style: elementStyles, showIcon: true });
        const cardExpiry = elements.create('cardExpiry', { style: elementStyles });
        const cardCvc = elements.create('cardCvc', { style: elementStyles });

        cardNumber.mount('#etb-card-number-mount');
        cardExpiry.mount('#etb-card-expiry-mount');
        cardCvc.mount('#etb-card-cvc-mount');

        // Écouter les changements pour activer/désactiver le bouton
        cardNumber.on('change', (event) => {
            if (event.error) {
                showError(event.error.message);
                if (payBtn) { payBtn.disabled = true; payBtn.style.opacity = '0.5'; payBtn.style.cursor = 'not-allowed'; }
            } else if (event.complete) {
                clearError();
                if (payBtn) { payBtn.disabled = false; payBtn.style.opacity = '1'; payBtn.style.cursor = 'pointer'; }
            } else {
                clearError();
                if (payBtn) { payBtn.disabled = true; payBtn.style.opacity = '0.5'; payBtn.style.cursor = 'not-allowed'; }
            }
        });

        // ══════════════════════════════════════════════════════════════
        // 2. INITIALISATION DE APPLE PAY / GOOGLE PAY (PAYMENT REQUEST)
        // ══════════════════════════════════════════════════════════════
        const paymentRequest = stripe.paymentRequest({
            country: 'FR',
            currency: 'eur',
            total: {
                label: 'VIP Booking Payment',
                amount: Math.round(amount * 100),
            },
            requestPayerName: true,
            requestPayerEmail: true,
        });

        const prButton = elements.create('paymentRequestButton', {
            paymentRequest: paymentRequest,
            style: { paymentRequestButton: { theme: 'dark', height: '48px' } }
        });

        paymentRequest.canMakePayment().then(function(result) {
            if (result) {
                document.getElementById('etb-wallet-container').style.display = 'block';
                document.getElementById('etb-wallet-divider').style.display = 'flex';
                prButton.mount('#etb-wallet-mount');
            }
        });

        // ══════════════════════════════════════════════════════════════
        // 3. FONCTIONS DE PAIEMENT
        // ══════════════════════════════════════════════════════════════
        const settleBooking = async function(paymentIntentId) {
            try {
                const settleRes = await fetch(etbPayData.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'etb_settle_payment', // <-- L'ACTION CORRECTE POUR CIRCUIT
                        nonce: etbPayData.nonce,
                        booking_id: bookingId,
                        payment_intent_id: paymentIntentId
                    })
                });

                const settleData = await settleRes.json();
                if (settleData.success) {
                    window.location.href = settleData.data.redirect_url;
                } else {
                    throw new Error(settleData.data.message || 'Error finalizing booking.');
                }
            } catch (err) {
                showError(err.message);
                if (payBtn) { 
                    payBtn.disabled = false; 
                    payBtn.style.opacity = '1';
                    payBtn.style.cursor = 'pointer';
                    payText.textContent = 'Pay ' + amount.toFixed(2) + ' ' + etbPayData.currency; 
                }
            }
        };

        const createIntent = async function(name, email) {
            const response = await fetch(etbPayData.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'etb_create_payment_intent',
                    nonce: etbPayData.nonce,
                    booking_id: bookingId,
                    amount: amount,
                    name: name,
                    email: email
                })
            });
            const resData = await response.json();
            if (!resData.success) throw new Error(resData.data.message || 'Error initializing payment.');
            return resData.data.client_secret;
        };

        // ══════════════════════════════════════════════════════════════
        // 4. ÉVÉNEMENT : PAIEMENT VIA APPLE PAY / GOOGLE PAY
        // ══════════════════════════════════════════════════════════════
        paymentRequest.on('paymentmethod', async (ev) => {
            try {
                clearError();
                const clientSecret = await createIntent(ev.payerName || 'Wallet User', ev.payerEmail || '');
                
                const { paymentIntent, error: confirmError } = await stripe.confirmCardPayment(
                    clientSecret,
                    { payment_method: ev.paymentMethod.id },
                    { handleActions: false }
                );

                if (confirmError) {
                    ev.complete('fail');
                    showError(confirmError.message);
                } else {
                    ev.complete('success');
                    if (paymentIntent.status === 'requires_action') {
                        const { error: actionError, paymentIntent: actionIntent } = await stripe.confirmCardPayment(clientSecret);
                        if (actionError) { showError(actionError.message); return; }
                        if (actionIntent.status === 'succeeded' || actionIntent.status === 'requires_capture') {
                            settleBooking(actionIntent.id);
                        }
                    } else if (paymentIntent.status === 'succeeded' || paymentIntent.status === 'requires_capture') {
                        settleBooking(paymentIntent.id);
                    }
                }
            } catch (err) {
                ev.complete('fail');
                showError(err.message);
            }
        });

        // ══════════════════════════════════════════════════════════════
        // 5. ÉVÉNEMENT : PAIEMENT VIA CARTE BANCAIRE
        // ══════════════════════════════════════════════════════════════
        payBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            clearError();
            
            const email = document.getElementById('etb-pay-email').value;
            const name = document.getElementById('etb-pay-cardholder').value;
            if (!email || !name) { showError('Please fill in your email and name.'); return; }

            payBtn.disabled = true;
            payText.innerHTML = '<span class="etb-processing-spinner"></span> Processing...';

            try {
                const clientSecret = await createIntent(name, email);
                
                const { error, paymentIntent } = await stripe.confirmCardPayment(clientSecret, {
                    payment_method: { card: cardNumber, billing_details: { name: name, email: email } }
                });

                if (error) throw new Error(error.message);

                if (paymentIntent.status === 'succeeded' || paymentIntent.status === 'requires_capture') {
                    payText.innerHTML = 'Finalizing...';
                    settleBooking(paymentIntent.id);
                }
            } catch (err) {
                showError(err.message);
                payBtn.disabled = false;
                payBtn.style.opacity = '1';
                payBtn.style.cursor = 'pointer';
                payText.textContent = 'Pay ' + amount.toFixed(2) + ' ' + etbPayData.currency;
            }
        });
    });
})();