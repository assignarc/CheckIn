import { Controller } from '@hotwired/stimulus';
import intlTelInput from 'intl-tel-input';

export default class extends Controller {
    static values = {
        allowedCountries: Array
    }

    connect() {
        if (this.isInitialized) return;
        this.isInitialized = true;

        // Initialize the international telephone input
        intlTelInput(this.element, {
            loadUtils: () => import("https://cdn.jsdelivr.net/npm/intl-tel-input@29.1.2/dist/js/utils.js"),
            initialCountry: "us",
            onlyCountries: this.hasAllowedCountriesValue ? this.allowedCountriesValue : ["us"],
            separateDialCode: true,
            dropdownContainer: document.body,
        });
    }
}
