const fs = require('node:fs');
const postcss = require('postcss');
const file = 'public/assets/css/checkout-v2.css';
const css = postcss.parse(fs.readFileSync(file, 'utf8'));
css.walkRules(rule => {
    if (rule.selector.startsWith(':is(#checkout-v2,#checkout-v2-overlays) ')) {
        rule.walkDecls(declaration => { declaration.important = true; });
    }
});
fs.writeFileSync(file, css.toString());
