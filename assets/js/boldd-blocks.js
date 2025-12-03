(function (wc, apiFetch, el) {

    const { registerPaymentMethod } = wc.wcBlocksRegistry;

    const BolddPaymentMethod = {
        name: 'bolddcheckout',
        label: 'Boldd Payment',

        ariaLabel: 'Boldd Payment',
        content: el('div', null, 'Pay securely with Boldd'), // React element
        edit: el('div', null, 'Boldd Payment method'),       // React element for editor

        canMakePayment() {
            return true;
        },

        async payment({ orderId }) {
            const response = await apiFetch({
                path: '/boldd/v1/initiate',
                method: 'POST',
                data: { order_id: orderId }
            });

            if (!response.status) {
                throw new Error('Unable to start payment.');
            }

            window.location.href = response.authorization_url;

            return { redirect: response.authorization_url };
        }
    };

    registerPaymentMethod(BolddPaymentMethod);

})(window.wc, window.wp.apiFetch, window.wp.element.createElement);
