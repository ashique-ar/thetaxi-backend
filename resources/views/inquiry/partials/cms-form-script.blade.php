<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#service-inquiry [data-inquiry-form]');
    if (!form) return;

    const conditionalFields = Array.from(form.querySelectorAll('[data-conditional]'));
    const updateConditionalVisibility = (wrapper) => {
        let condition;
        try { condition = JSON.parse(wrapper.dataset.conditional); } catch (_) { return; }
        if (Array.isArray(condition)) condition = condition[0];
        if (!condition || !condition.field) return;

        const controls = Array.from(form.elements).filter((control) => control.name === condition.field);
        const values = controls.map((control) => {
            if (control.type === 'checkbox' || control.type === 'radio') return control.checked ? control.value : null;
            return control.value;
        }).filter((value) => value !== null && value !== '');
        const expected = Array.isArray(condition.value) ? condition.value : [condition.value];
        const matches = values.some((value) => expected.map(String).includes(String(value)));
        const visible = condition.operator === 'not_equals' ? !matches : matches;
        wrapper.hidden = !visible;
        wrapper.style.display = visible ? '' : 'none';
        wrapper.querySelectorAll('input, select, textarea').forEach((control) => {
            control.required = visible && control.dataset.required === 'true';
            if (!visible) {
                if (control.type === 'checkbox' || control.type === 'radio') control.checked = false;
                else if (control.type === 'file') control.value = '';
                else control.value = '';
            }
        });
    };
    conditionalFields.forEach(updateConditionalVisibility);
    form.addEventListener('change', () => conditionalFields.forEach(updateConditionalVisibility));

    form.querySelectorAll('input[type="tel"]').forEach((input) => {
        if (typeof window.intlTelInput !== 'function') return;
        const iti = window.intlTelInput(input, {
            initialCountry: form.dataset.phoneInitialCountry || 'lk',
            preferredCountries: (form.dataset.phonePreferredCountries || 'lk,us,gb,au').split(','),
            separateDialCode: true,
            utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
        });
        form.addEventListener('submit', () => { if (iti.isValidNumber()) input.value = iti.getNumber(); });
    });

    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;
        button.disabled = true;
        setTimeout(() => { button.disabled = false; }, 30000);
    });
});
</script>
