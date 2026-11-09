const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/checkout/v2/behavior.blade.php', 'utf8');
const start = source.indexOf('function renderCheckoutExtraKm(cartKey, data) {');
const end = source.indexOf('function mutateCheckoutExtraKm(', start);
assert(start >= 0 && end > start);
let html = '', savedRate;
const panel = { data(key, value) { if (value !== undefined) savedRate = value; return this; }, html(value) { html = value; return this; } };
vm.runInNewContext(source.slice(start, end) + '\nrenderCheckoutExtraKm("cart-a", {rate:{rate:100},current_extra_km:{km:150}});', {
    $(selector) { if (selector.startsWith('.checkout-extra-km-panel')) return panel; return { text() {} }; },
    formatCheckoutAmount: amount => 'LKR ' + amount,
    checkoutEscapeHtml: value => value,
});
assert.equal(savedRate, 100);
assert.match(html, /\+150 km/);
assert.match(html, /LKR 15000/);
assert.match(html, /data-km="150" aria-pressed="true"/);
assert.match(html, /class="v2-km-slider"/);

const applyStart = source.indexOf("$(document).on('click', '.checkout-extra-km-apply', function() {");
const applyEnd = source.indexOf("$(document).on('click', '.checkout-extra-km-remove'", applyStart);
assert(applyStart >= 0 && applyEnd > applyStart);
const document = {}, button = { data: () => 'cart-a' }, input = { value: '10001', setCustomValidity(value) { this.message = value; }, reportValidity() { this.reported = true; } };
let click, calls = 0, request;
vm.runInNewContext(source.slice(applyStart, applyEnd).replace("{{ route('cart.extra-km.add') }}", '/cart/extra-km/add'), {
    document,
    $(target) { if (target === document) return { on(event, selector, handler) { click = handler; } }; if (target === button) return button; return [input]; },
    mutateCheckoutExtraKm(url, data) { calls++; request = data; },
});
click.call(button);
assert.equal(calls, 0);
assert.equal(input.reported, true);
input.value = '150';
click.call(button);
assert.equal(calls, 1);
assert.equal(request.extra_km, 150);
console.log('Extra-distance presets calculate from the rate and reject invalid values.');
