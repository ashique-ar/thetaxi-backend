const fs = require('node:fs');
const postcss = require('postcss');
const file = 'public/assets/css/checkout-v2.css';
const css = postcss.parse(fs.readFileSync(file, 'utf8'));
// Theme shells use different root font sizes; the supplied checkout designs use a 16px base.
css.walkDecls(declaration => {
    declaration.value = declaration.value.replace(/(-?(?:\d+\.?\d*|\.\d+))rem\b/g, (_, value) => `${Number((Number(value) * 16).toFixed(4))}px`);
});
css.walkRules(rule => {
    if (rule.selector.startsWith(':is(#checkout-v2,#checkout-v2-overlays) ')) {
        rule.walkDecls(declaration => { declaration.important = true; });
    }
});
fs.writeFileSync(file, css.toString());
