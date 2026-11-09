module.exports = {
    content: [
        './public/theme1and2-checkout-theme.html',
        './public/theme1and2-checkout-popups.html',
        './public/theme4-checkout.html',
        './public/theme4-checkout-popup.html',
        './resources/views/checkout/v2/**/*.blade.php',
    ],
    important: ':is(#checkout-v2, #checkout-v2-overlays)',
    corePlugins: { preflight: false },
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
            colors: {
                'checkout-accent': 'var(--checkout-accent)',
                'checkout-accent-hover': 'var(--checkout-accent-hover)',
                'checkout-accent-soft': 'var(--checkout-accent-soft)',
                'checkout-accent-border': 'var(--checkout-accent-border)',
                brand: { red: '#C5221F', redHover: '#AB1B19', redLight: '#FDF2F2', dark: '#121316', charcoal: '#1F242D', surface: '#F8FAFC', 50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 500: '#ef4444', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d' },
                crimson: { 50: '#fef2f2', 100: '#fee2e2', 500: '#ef4444', 600: '#dc2626', 700: '#c5221f', 800: '#991b1b', 900: '#7f1d1d' },
                brandDark: { 800: '#111827', 900: '#0b0f19', 950: '#070a11' },
            },
            boxShadow: { card: '0 1px 3px rgba(15,23,42,.08)', modal: '0 25px 50px -12px rgba(15,23,42,.25)' },
        },
    },
};
