const assert = require('node:assert/strict');
const fs = require('node:fs');

const source = fs.readFileSync('public/assets/js/booking-form.js', 'utf8');
const start = source.indexOf('function getFirstValue(');
const end = source.indexOf('function ensureHidden(', start);
assert(start !== -1 && end > start);

const form = {
    querySelectorAll: () => [
        { type: 'radio', checked: false, value: '100', disabled: false, dataset: {} },
        { type: 'radio', checked: true, value: '200', disabled: false, dataset: {} },
    ],
};

eval(source.slice(start, end));
assert.equal(getFirstValue(['package_id']), '200');
