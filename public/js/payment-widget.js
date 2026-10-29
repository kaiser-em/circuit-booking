(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('etb-standalone-payment-form');
        if (!form) return;

        // 1. Debug de sécurité
        if (typeof Stripe === 'undefined') {
            console.error('[ETB Payment] Stripe.js is not loaded.');
            document.getElementById('etb-card-number-mount').innerHTML = '<span style="color:red; font-size:12px;">Error: Stripe.js failed to load.</span>';
            return;
        }
        if (typeof etbPayData === 'undefined' || !etbPayData.stripe_pk) {
            console.error('[ETB Payment] Stripe PK is missing.');
            document.getElementById('etb-card-number-mount').innerHTML = '<span style="color:red; font-size:12px;">Error: Stripe Publishable Key is missing.</span>';
            return;
        }

        // 2. Init Stripe
        const stripe = Stripe(etbPayData.stripe_pk);
        const elements = stripe.elements();

        const elementStyles = {
            base: {
                color: '#1e293b',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                fontSmoothing: 'antialiased',
                fontSize: '15px',
                '::placeholder': { color: '#94a3b8' }
            },
            invalid: { color: '#dc2626', iconColor: '#dc2626' }
        };

        // 3. Montage des champs
        const cardNumber = elements.create('cardNumber', { style: elementStyles, showIcon: true });
        const cardExpiry = elements.create('cardExpiry', { style: elementStyles });
        const cardCvc = elements.create('cardCvc', { style: elementStyles });

        cardNumber.mount('#etb-card-number-mount');
        cardExpiry.mount('#etb-card-expiry-mount');
        cardCvc.mount('#etb-card-cvc-mount');

        // 4. Gestion des erreurs
        const feedback = document.getElementById('etb-standalone-pay-feedback');
        const showError = (msg) => { feedback.textContent = msg; feedback.style.display = 'block'; };
        const clearError = () => { feedback.style.display = 'none'; };

        cardNumber.on('change', (event) => {
            if (event.error) showError(event.error.message);
            else clearError();
        });

        // 5. Clic sur Payer
        const payBtn = document.getElementById('etb-standalone-pay-btn');
        const payText = document.getElementById('etb-standalone-pay-text');
        
        payBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            clearError();
            
            const email = document.getElementById('etb-pay-email').value;
            const name = document.getElementById('etb-pay-cardholder').value;
            if (!email || !name) {
                showError('Please fill in your email and name.');
                return;
            }

            payBtn.disabled = true;
            payText.textContent = 'Processing...';

            try {
                // A. Créer le PaymentIntent côté serveur
                const response = await fetch(etbPayData.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'etb_create_payment_intent',
                        nonce: etbPayData.nonce,
                        booking_id: document.getElementById('etb-pay-booking-id').value,
                        amount: document.getElementById('etb-pay-amount').value,
                        name: name,
                        email: email
                    })
                });
                
                const resData = await response.json();
                if (!resData.success) throw new Error(resData.data.message || 'Error initializing payment.');

                // B. Confirmer avec Stripe (3D Secure)
                const { error, paymentIntent } = await stripe.confirmCardPayment(resData.data.client_secret, {
                    payment_method: {
                        card: cardNumber,
                        billing_details: { name: name, email: email }
                    }
                });

                if (error) throw new Error(error.message);

                // C. Valider la résa côté serveur
                if (paymentIntent.status === 'succeeded' || paymentIntent.status === 'requires_capture') {
                    payText.textContent = 'Finalizing...';
                    
                    const settleRes = await fetch(etbPayData.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'etb_settle_payment',
                            nonce: etbPayData.nonce,
                            booking_id: document.getElementById('etb-pay-booking-id').value,
                            payment_intent_id: paymentIntent.id
                        })
                    });

                    const settleData = await settleRes.json();
                    if (settleData.success) {
                        window.location.href = settleData.data.redirect_url;
                    } else {
                        throw new Error(settleData.data.message || 'Error finalizing booking.');
                    }
                }
            } catch (err) {
                showError(err.message);
                payBtn.disabled = false;
                payText.textContent = 'Pay ' + document.getElementById('etb-pay-amount').value + ' ' + etbPayData.currency;
            }
        });
    });
})();