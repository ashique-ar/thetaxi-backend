const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/checkout/v2/behavior.blade.php', 'utf8');
const renderStart = source.indexOf('function renderCheckoutAddons(cartKey, addons, selectedAddons) {');
const renderEnd = source.indexOf('function checkoutAddonQuantity(input)', renderStart);
assert(renderStart >= 0 && renderEnd > renderStart);
let html = '';
const panel = { html(value) { html = value; } };
vm.runInNewContext(source.slice(renderStart, renderEnd) + '\nrenderCheckoutAddons("cart-a", addons, selected);', {
    $: () => panel,
    addons: [{ id: 'one', name: 'Seat', max_qty: 2 }, { id: 'two', name: 'Wi-Fi', max_qty: 3 }],
    selected: [{ addon_id: 'one', qty: 1 }],
    selectedAddonMap: entries => Object.fromEntries(entries.map(entry => [entry.addon_id, entry.qty])),
    checkoutEscapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
    checkoutAddonAmount: () => 'LKR 500',
    renderSelectedAddonSummary() {},
});
assert.match(html, /checkout-addon-card is-selected/);
assert.match(html, /v2-addon-active/);
assert.match(html, /aria-label="Increase Seat quantity"/);
assert.match(html, /checkout-addon-qty" hidden/);
assert.match(html, /\+ Add/);

const qtyStart = source.indexOf('function checkoutAddonQuantity(input) {');
const qtyEnd = source.indexOf("$(document).on('input', '.checkout-addon-qty input'", qtyStart);
const check = vm.runInNewContext(source.slice(qtyStart, qtyEnd) + '\ncheckoutAddonQuantity;');
const input = { value: '4', max: '3', setCustomValidity(value) { this.message = value; }, reportValidity() { this.reported = true; } };
assert.equal(check(input), null);
assert.equal(input.reported, true);
input.value = '2';
assert.equal(check(input), 2);
assert.equal(input.message, '');

const applyStart = source.indexOf("$(document).on('click', '.checkout-addon-apply', function() {");
const applyEnd = source.indexOf("$(document).on('click', '.checkout-addon-remove'", applyStart);
assert(applyStart >= 0 && applyEnd > applyStart);
const document = {}, button = { data: key => ({ 'cart-key': 'cart-a', 'addon-id': 'two' })[key] };
const raw = { value: '0', max: '3', setCustomValidity() {}, reportValidity() {} };
const field = { 0: raw, val(value) { if (value !== undefined) { raw.value = String(value); return this; } return raw.value; }, data() { return 0; } };
let click, request;
const script = source.slice(applyStart, applyEnd).replace(/\{\{ route\('cart\.addon\.[^']+'\) \}\}/g, '/addon');
vm.runInNewContext(script, {
    document, checkoutAddonQuantity: check,
    $(target) { if (target === document) return { on(event, selector, handler) { click = handler; } }; if (target === button) return button; return field; },
    mutateCheckoutAddon(url, data) { request = data; },
});
click.call(button);
assert.equal(request.qty, 1, 'Add must add one item when the initial quantity is zero');
console.log('Add-on cards show selected quantities and add one item from the initial state.');
