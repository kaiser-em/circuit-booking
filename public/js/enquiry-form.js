(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('etb-enquiry-form');
        if (!form) return;

        const btn = document.getElementById('etb-enq-submit-btn');
        const feedback = document.getElementById('etb-enq-feedback');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = 'Sending...';
            feedback.style.display = 'none';

            const formData = new FormData(form);
            formData.append('action', 'etb_submit_enquiry');

            // Vérification de sécurité : est-ce que etbAjax est bien chargé ?
            if (typeof etbAjax === 'undefined') {
                feedback.className = 'etb-enq-feedback etb-error';
                feedback.textContent = 'System error: AJAX URL not found.';
                feedback.style.display = 'block';
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }

            fetch(etbAjax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    feedback.className = 'etb-enq-feedback etb-success';
                    feedback.innerHTML = '<div class="err-ms"style="text-align:center;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="4" style="margin-bottom:10px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' + data.data.message + '</div>';
                    feedback.style.display = 'block';
                    form.reset();
                } else {
                    feedback.className = 'etb-enq-feedback etb-error';
                    feedback.textContent = data.data.message || 'Error sending enquiry.';
                    feedback.style.display = 'block';
                }
            })
            .catch((error) => {
                console.error('ETB AJAX Error:', error);
                feedback.className = 'etb-enq-feedback etb-error';
                feedback.textContent = 'Connection error. Please try again.';
                feedback.style.display = 'block';
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = originalText;
            });
        });
    });
})();