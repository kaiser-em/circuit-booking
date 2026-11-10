(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('etb-enquiry-form');
        if (!form) return;

        var btn = document.getElementById('etb-enq-submit-btn');
        var feedback = document.getElementById('etb-enq-feedback');
        var btnDefaultHTML = btn ? btn.innerHTML : '';

        function showFeedback(type, iconClass, message) {
            feedback.className = 'etb-enq-feedback is-visible etb-' + type;
            feedback.innerHTML = '';

            var icon = document.createElement('i');
            icon.className = iconClass;
            icon.setAttribute('aria-hidden', 'true');

            var span = document.createElement('span');
            span.textContent = message;

            feedback.appendChild(icon);
            feedback.appendChild(span);
            feedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function hideFeedback() {
            feedback.className = 'etb-enq-feedback';
            feedback.innerHTML = '';
        }

        // Téléphone international (intl-tel-input, mêmes réglages que le booking widget)
        var phoneInput = document.getElementById('etb-enq-phone');
        var phoneIti = null;

        if (phoneInput && typeof window.intlTelInput !== 'undefined') {
            // Chrome/Edge remplissent le champ sans déclencher le formatage : forcer setNumber
            var formatAutofilledPhone = function () {
                if (!phoneIti) return;
                var value = phoneInput.value.trim();
                if (!value) return;
                phoneIti.setNumber(value);
            };

            phoneInput.addEventListener('change', formatAutofilledPhone);
            phoneInput.addEventListener('blur', formatAutofilledPhone);

            var getCountry = function () {
                return fetch('https://ipapi.co/json/')
                    .then(function (res) {
                        if (!res.ok) throw new Error('IP lookup failed');
                        return res.json();
                    })
                    .then(function (data) {
                        return data.country_code ? String(data.country_code).toLowerCase() : 'fr';
                    })
                    .catch(function () { return 'fr'; });
            };

            getCountry().then(function (countryCode) {
                phoneIti = window.intlTelInput(phoneInput, {
                    initialCountry: countryCode,
                    countryOrder: ['fr', 'mc', 'gb', 'us', 'ch', 'ae', 'de', 'it'],
                    excludeCountries: ['gg', 'je', 'im'],
                    separateDialCode: true,
                    formatAsYouType: true,
                    placeholderNumberType: 'MOBILE'
                });
                if (phoneIti.promise) phoneIti.promise.catch(function () {});
                formatAutofilledPhone();
                setTimeout(formatAutofilledPhone, 300);
            });
        }

        // Date (Flatpickr) — affichage au format anglais, valeur serveur Y-m-d
        var dateInput = document.getElementById('etb-enq-date');

        if (dateInput && typeof window.flatpickr !== 'undefined') {
            var fp = window.flatpickr(dateInput, {
                minDate: 'today',
                dateFormat: 'Y-m-d',
                altInput: true,
                altInputClass: '',
                altFormat: 'm/d/Y',
                allowInput: true,
                disableMobile: true
            });

            if (fp.altInput) {
                dateInput.removeAttribute('id');
                fp.altInput.id = 'etb-enq-date';
                fp.altInput.placeholder = 'MM/DD/YYYY';
            }
        }

        // Steppers Adults / Kids
        form.addEventListener('click', function (e) {
            var stepBtn = e.target.closest('.etb-enq-step-btn');
            if (!stepBtn) return;
            e.preventDefault();

            var wrap = stepBtn.closest('.etb-enq-input-wrap');
            var input = wrap ? wrap.querySelector('input[type="number"]') : null;
            if (!input) return;

            var min = parseInt(input.min || '0', 10);
            var max = parseInt(input.max || '99', 10);
            var val = parseInt(input.value, 10);
            if (isNaN(val)) val = min;

            val += (stepBtn.dataset.step === 'up') ? 1 : -1;
            input.value = Math.min(max, Math.max(min, val));
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            btn.disabled = true;
            btn.innerHTML = '<span class="etb-enq-btn-label">Sending...</span>' +
                '<span class="etb-enq-btn-arrow"><i class="fas fa-circle-notch fa-spin"></i></span>';
            hideFeedback();

            // Normalise le téléphone au format international avant envoi
            if (phoneIti && phoneInput.value.trim() !== '' && typeof phoneIti.getNumber === 'function') {
                var fullPhone = phoneIti.getNumber();
                if (fullPhone) phoneInput.value = fullPhone;
            }

            var formData = new FormData(form);
            formData.append('action', 'etb_submit_enquiry');

            // Vérification de sécurité : est-ce que etbAjax est bien chargé ?
            if (typeof etbAjax === 'undefined') {
                showFeedback('error', 'fas fa-triangle-exclamation', 'System error: AJAX URL not found.');
                btn.disabled = false;
                btn.innerHTML = btnDefaultHTML;
                return;
            }

            fetch(etbAjax.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        showFeedback('success', 'fas fa-circle-check', (data.data && data.data.message) || 'Your enquiry has been sent successfully.');
                        form.reset();
                    } else {
                        showFeedback('error', 'fas fa-circle-exclamation', (data.data && data.data.message) || 'Error sending enquiry.');
                    }
                })
                .catch(function (error) {
                    console.error('ETB AJAX Error:', error);
                    showFeedback('error', 'fas fa-circle-exclamation', 'Connection error. Please try again.');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.innerHTML = btnDefaultHTML;
                });
        });
    });
})();
