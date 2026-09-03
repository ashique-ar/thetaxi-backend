# Theme 03 Checkout, Payment, and Status

Phase 5 gives the active checkout, success, payment-resume, callback recovery, WebXPay redirect and booking-status surfaces a complete Ceylon Modernist paper-ledger presentation. Controllers, session state, routes, input names, cart calculations, payment choices, quotation handling, WebXPay fields, status lookup and shared JavaScript remain unchanged.

The populated checkout covers cart manifests, add-ons, extra kilometres, discounts/promotions, terms, traveller and flight fields, full/advance/check-in/quotation choices, and responsive summaries. Empty checkout retains its existing Browse Vehicles recovery. Sticky summaries become static below 992px.

Success retains quotation, paid advance, fully paid, pay-on-check-in, pending and fallback branches. Payment resume retains trip/add-on/extra-km/customer/amount/status data plus expired/invalid processing outcomes owned by the controller.

Legacy audit: `/cart` redirects to active `/checkout`; `cart.blade.php` is rollback/orphan markup. `booking/success.blade.php` has no route/controller render owner. `CheckoutController::mockGateway()` is not routed and references an absent view, so it remains a non-public dormant method rather than a supported state.

Theme 03 remains release-gated. Transactional fixture screenshots and a permitted test-gateway booking remain required in later release phases.
