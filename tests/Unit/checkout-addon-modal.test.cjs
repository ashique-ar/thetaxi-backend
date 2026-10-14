const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('resources/views/checkout.blade.php', 'utf8');
const start = source.indexOf("$(document).on('click', '.checkout-toggle-addons', function() {");
const end = source.indexOf("$(document).on('click', '.checkout-addon-load-retry'", start);
assert(start !== -1 && end > start);

for (const theme of ['default', 'theme-02', 'theme-03', 'theme-04']) {
    const body = { theme };
    const modal = { parentNode: { className: `${theme}-site-main` } };
    const document = { body, getElementById: () => modal };
    const loads = [];
    let click;
    let shown = 0;
    let vehicle;
    const button = {
        data: key => ({ 'cart-key': 'cart-a', 'service-type': 'airport', 'vehicle-name': 'Sedan' })[key],
    };

    vm.runInNewContext(source.slice(start, end), {
        document,
        $(target) {
            if (target === document) {
                return { on: (event, selector, handler) => { click = handler; } };
            }
            if (target === button) return button;
            if (target === '.checkout-addon-modal-vehicle') {
                return { text: name => { vehicle = name; } };
            }
            assert.equal(target, '#checkoutAddonModal');
            return { appendTo: parent => { modal.parentNode = parent; } };
        },
        bootstrap: {
            Modal: {
                getOrCreateInstance(element) {
                    assert.equal(element, modal);
                    assert.equal(modal.parentNode, body, `${theme}: modal must escape the theme wrapper before opening`);
                    return { show: () => { shown++; } };
                },
            },
        },
        loadCheckoutAddons: (...args) => loads.push(args),
    });

    click.call(button);
    click.call(button);
    assert.equal(shown, 2);
    assert.equal(vehicle, 'Sedan');
    assert.deepEqual(loads, [['cart-a', 'airport', false], ['cart-a', 'airport', false]]);
}

console.log('Checkout add-ons modal opens outside theme wrappers in all four themes.');
