const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/checkout/v2/behavior.blade.php', 'utf8');
const start = source.indexOf('function restoreCheckoutInteractiveState() {');
const end = source.indexOf('// Apply the selected payment type', start);
assert(start >= 0 && end > start);
assert.ok(source.includes("window.addEventListener('checkout:restored', syncPaymentContactRequirement);"));
let disabled = true, changed = false, events = 0;
const window = { dispatchEvent(event) { assert.equal(event.type, 'checkout:restored'); events++; disabled = true; } };
vm.runInNewContext(source.slice(start, end) + '\nrestoreCheckoutInteractiveState();', {
    window, Event: class { constructor(type) { this.type = type; } },
    $(selector) {
        if (selector === '#checkout-submit-btn') return { prop(name, value) { assert.equal(name, 'disabled'); disabled = value; } };
        return { trigger(name) { assert.equal(name, 'change'); changed = true; } };
    },
});
assert.equal(changed, true);
assert.equal(events, 1);
assert.equal(disabled, true, 'Contact validation can re-disable the button after browser restoration');
console.log('Checkout restoration re-applies payment contact validation.');
