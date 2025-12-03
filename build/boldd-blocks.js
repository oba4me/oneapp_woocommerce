/**
 * Boldd Payment – WooCommerce Blocks Integration
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';

// Localized data from PHP
const settings = window.wc?.wcSettings?.getSetting('bolddcheckout_data', {});

const Label = createElement(
    'span',
    {},
    settings.title || 'Boldd Payment'
);

const Content = () => {
    const desc = settings.description || 'Pay securely using Boldd.';
    return createElement(
        'div',
        { className: 'wc-boldd-blocks-description' },
        createElement('p', {}, desc)
    );
};

registerPaymentMethod({
    name: 'bolddcheckout',

    label: Label,

    content: Content,
    edit: Content,

    canMakePayment: () => {
        // Optional: add any client-side checks here.
        return true;
    },

    ariaLabel: settings.title || 'Boldd Payment',
    supports: {
        features: ['products']
    }
});
