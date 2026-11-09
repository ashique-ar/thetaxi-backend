const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('resources/views/checkout.blade.php', 'utf8');
const start = source.indexOf("$(document).on('click', '.checkout-toggle-extra-km', function() {");
const end = source.indexOf("$(document).on('click', '.checkout-extra-km-load-retry'", start);
assert(start !== -1 && end > start);
let click, hidden, shown = 0, loaded;
const document = { body: {}, getElementById: () => ({}) };
const panel = { data: () => 'cart-a', show() { this.visible = true; return this; }, hide() { this.visible = false; return this; } };
const button = { data: key => ({ 'cart-key': 'cart-a', 'vehicle-name': 'Sedan' })[key] };
let appended, vehicle;
vm.runInNewContext(source.slice(start, end), {
    document,
    $(target) {
        if (target === document) return { on: (event, selector, handler) => { click = handler; } };
        if (target === button) return button;
        if (target === '#checkoutExtraKmModalPanel') return { append: value => { appended = value; } };
        if (target === '.checkout-extra-km-modal-vehicle') return { text: value => { vehicle = value; } };
        if (target === '#checkoutExtraKmModal') return { appendTo() {}, on: (event, handler) => { hidden = handler; } };
        if (target === modal) return { find: () => panel };
        if (target.startsWith('.checkout-extra-km-panel')) return panel;
        if (target.startsWith('.checkout-item-extra-km')) return { append: value => { appended = value; } };
        throw new Error('Unexpected selector ' + target);
    },
    bootstrap: { Modal: { getOrCreateInstance: () => ({ show: () => { shown++; } }) } },
    loadCheckoutExtraKm: (...args) => { loaded = args; },
});
const modal = {};
click.call(button);
assert.equal(shown, 1);
assert.equal(vehicle, 'Sedan');
assert.equal(appended, panel);
assert.equal(panel.visible, true);
assert.deepEqual(loaded, ['cart-a', false]);
hidden.call(modal);
assert.equal(appended, panel);
assert.equal(panel.visible, false);
console.log('Extra-distance dialog opens and returns its panel to the cart.');
