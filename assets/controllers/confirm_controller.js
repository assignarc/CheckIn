import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        title: String,
        content: String,
        btnClass: String
    }

    connect() {
        this.element.addEventListener('submit', (e) => {
            e.preventDefault();
            
            // Check if jQuery and jquery-confirm are loaded
            if (typeof $ === 'undefined' || typeof $.confirm === 'undefined') {
                console.error('jQuery or jquery-confirm is not loaded. Falling back to native confirm.');
                if (confirm(this.contentValue || 'Are you sure you want to perform this action?')) {
                    this.element.submit();
                }
                return;
            }

            $.confirm({
                title: this.titleValue || 'Are you sure?',
                content: this.contentValue || 'This action cannot be undone.',
                type: 'red',
                typeAnimated: true,
                buttons: {
                    confirm: {
                        text: 'Yes, I am sure',
                        btnClass: this.btnClassValue || 'btn-danger',
                        action: () => {
                            this.element.submit();
                        }
                    },
                    cancel: function () {
                        // user canceled
                    }
                }
            });
        });
    }
}
