const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/checkout/v2/behavior.blade.php', 'utf8');
const start = source.indexOf("$(document).on('click', '.checkout-toggle-addons', function() {");
const end = source.indexOf("$(document).on('click', '.checkout-addon-load-retry'", start);
assert(start >= 0 && end > start);
const overlay = { parentNode: null };
const modal = { parentNode: overlay };
const document = { body: {}, getElementById: () => modal };
const button = { data: key => ({ 'cart-key': 'cart-a', 'service-type': 'airport', 'vehicle-name': 'Sedan' })[key] };
let click, shown = 0, vehicle, loaded;
vm.runInNewContext(source.slice(start, end), {
    document,
    $(target) {
        if (target === document) return { on: (event, selector, handler) => { click = handler; } };
        if (target === button) return button;
        if (target === '.checkout-addon-modal-vehicle') return { text: value => { vehicle = value; } };
        if (target === '#checkoutAddonModalStatus') return { prop() { return this; }, text() { return this; } };
        if (target === '#checkout-v2-overlays') return { appendTo: parent => { overlay.parentNode = parent; } };
        throw new Error('Unexpected selector: ' + target);
    },
    bootstrap: { Modal: { getOrCreateInstance(element) { assert.equal(element, modal); assert.equal(overlay.parentNode, document.body); assert.equal(modal.parentNode, overlay); return { show: () => { shown++; } }; } } },
    loadCheckoutAddons: (...args) => { loaded = args; },
});
click.call(button);
assert.equal(vehicle, 'Sedan');
assert.equal(shown, 1);
assert.deepEqual(loaded, ['cart-a', 'airport', false]);
console.log('Version 2 modal keeps scoped styles when opened outside the theme wrapper.');
const extraStart = source.indexOf("$(document).on('click', '.checkout-toggle-extra-km', function() {");
const extraEnd = source.indexOf("$(document).on('click', '.checkout-extra-km-load-retry'", extraStart);
assert(extraStart >= 0 && extraEnd > extraStart);
let extraClick, hidden, extraLoaded;
const panel = { visible: false, data: () => 'cart-a', show() { this.visible = true; return this; }, hide() { this.visible = false; return this; } };
const extraModal = {};
const extraDocument = { body: {}, getElementById: () => extraModal };
const extraOverlay = { parentNode: null };
let lastPanel;
vm.runInNewContext(source.slice(extraStart, extraEnd), {
    document: extraDocument,
    $(target) {
        if (target === extraDocument) return { on: (event, selector, handler) => { extraClick = handler; } };
        if (target === button) return button;
        if (target === '#checkoutExtraKmModalPanel') return { append: value => { lastPanel = value; } };
        if (target === '.checkout-extra-km-modal-vehicle') return { text() {} };
        if (target === '#checkout-v2-overlays') return { appendTo: parent => { extraOverlay.parentNode = parent; } };
        if (target === '#checkoutExtraKmModal') return { on: (event, handler) => { hidden = handler; } };
        if (target === extraModal) return { find: () => panel };
        if (target.startsWith('.checkout-extra-km-panel')) return panel;
        if (target.startsWith('.checkout-item-extra-km')) return { append: value => { lastPanel = value; } };
        throw new Error('Unexpected selector: ' + target);
    },
    bootstrap: { Modal: { getOrCreateInstance(element) { assert.equal(element, extraModal); assert.equal(extraOverlay.parentNode, extraDocument.body); return { show() {} }; } } },
    loadCheckoutExtraKm: (...args) => { extraLoaded = args; },
});
extraClick.call(button);
assert.equal(lastPanel, panel);
assert.equal(panel.visible, true);
assert.deepEqual(extraLoaded, ['cart-a', false]);
hidden.call(extraModal);
assert.equal(lastPanel, panel);
assert.equal(panel.visible, false);
console.log('Version 2 distance modal preserves and restores the selected cart panel.');
