/**
 * Vatly checkout handler.
 *
 * FluentCart's checkout gates the "Place order" button per payment method: when
 * a method is selected it dispatches `fluent_cart_load_payments_<route>` and
 * keeps the button disabled until the method's own script signals readiness via
 * the payment loader handed in on the event. Vatly is a hosted / redirect
 * Merchant-of-Record gateway: there is nothing to prepare on-page (the Vatly
 * checkout session is created server-side at order placement and the customer
 * is redirected to Vatly's hosted checkout), so we simply enable the button,
 * exactly as FluentCart's own offline/redirect gateways do.
 *
 * verified: FluentCart free 2.2.0 — mirrors
 * assets/public/payment-methods/cod-checkout.js.
 */
window.addEventListener('fluent_cart_load_payments_vatly', function (event) {
    var checkoutVars = window.fluentcart_checkout_vars || {};
    var submitButton = checkoutVars.submit_button || {};

    event.detail.paymentLoader.enableCheckoutButton(submitButton.text || 'Place order');
});
