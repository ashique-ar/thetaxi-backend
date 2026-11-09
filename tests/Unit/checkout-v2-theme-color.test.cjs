const assert = require('node:assert/strict');
const fs = require('node:fs');
const css = fs.readFileSync('resources/css/checkout-v2.css', 'utf8');
const generated = fs.readFileSync('public/assets/css/checkout-v2.css', 'utf8');
const config = fs.readFileSync('resources/checkout-v2.tailwind.config.cjs', 'utf8');
assert.match(css, /#checkout-v2, #checkout-v2-overlays\s*\{[^}]*--checkout-accent:\s*var\(--primary-color1,\s*#BF2629\)/);
assert.match(css, /checkout-addon-apply[^}]*background:\s*var\(--checkout-accent\)/);
assert.match(css, /v2-km-slider[^}]*accent-color:\s*var\(--checkout-accent\)/);
assert.match(config, /'checkout-accent':\s*'var\(--checkout-accent\)'/);
assert.match(generated, /\.bg-checkout-accent\{background-color:var\(--checkout-accent\)\s*!important\}/);
for (const view of ['header-01', 'header-04', 'customer', 'payment-options', 'actions', 'popups']) {
    assert.match(fs.readFileSync(`resources/views/checkout/v2/${view}.blade.php`, 'utf8'), /checkout-accent/);
}
console.log('Checkout and popup accents follow the active theme primary color.');
