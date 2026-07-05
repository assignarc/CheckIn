import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Global binding for jquery-confirm forms
document.addEventListener('DOMContentLoaded', () => {
    if (typeof $ !== 'undefined') {
        $(document).on('submit', '.confirm-form', function(e) {
            e.preventDefault();
            const form = this;
            const msg = $(form).attr('data-confirm') || 'Are you sure you want to proceed?';
            $.confirm({
                title: 'Confirmation Required',
                content: msg,
                theme: 'modern',
                type: 'blue',
                buttons: {
                    confirm: {
                        text: 'Yes, Proceed',
                        btnClass: 'btn-blue',
                        action: function() {
                            form.submit();
                        }
                    },
                    cancel: {
                        text: 'Cancel'
                    }
                }
            });
        });
    }
});
