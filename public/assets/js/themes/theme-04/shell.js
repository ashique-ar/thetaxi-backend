(function () {
    'use strict';

    const desktopQuery = window.matchMedia('(min-width: 1200px)');

    function initTheme04Shell() {
        const header = document.querySelector('[data-t4-header]');
        if (!header || header.dataset.t4Ready === 'true') return;

        const openButton = header.querySelector('[data-t4-menu-open]');
        const closeButton = header.querySelector('[data-t4-menu-close]');
        const navigation = header.querySelector('[data-t4-navigation]');
        const backdrop = header.querySelector('[data-t4-menu-backdrop]');
        if (!openButton || !closeButton || !navigation || !backdrop) return;

        header.dataset.t4Ready = 'true';
        let returnFocusTo = null;
        const focusableSelector = 'a[href],button:not([disabled]),summary,input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

        function isOpen() {
            return document.body.classList.contains('t4-menu-open');
        }

        function sync(open) {
            openButton.setAttribute('aria-expanded', String(open));
            if (desktopQuery.matches) navigation.removeAttribute('aria-hidden');
            else navigation.setAttribute('aria-hidden', String(!open));
        }

        function openMenu() {
            if (desktopQuery.matches) return;
            returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : openButton;
            document.body.classList.add('t4-menu-open');
            sync(true);
            closeButton.focus();
        }

        function closeMenu(restoreFocus) {
            document.body.classList.remove('t4-menu-open');
            sync(false);
            if (restoreFocus && returnFocusTo instanceof HTMLElement) returnFocusTo.focus();
            returnFocusTo = null;
        }

        function handleKeydown(event) {
            if (event.key === 'Escape' && isOpen()) {
                event.preventDefault();
                closeMenu(true);
                return;
            }

            if (event.key !== 'Tab' || !isOpen() || desktopQuery.matches) return;
            const focusable = Array.from(navigation.querySelectorAll(focusableSelector)).filter((element) => element.getClientRects().length > 0);
            if (focusable.length === 0) {
                event.preventDefault();
                navigation.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        function handleViewport(event) {
            if (event.matches) {
                closeMenu(false);
                navigation.removeAttribute('aria-hidden');
            } else sync(false);
        }

        openButton.addEventListener('click', openMenu);
        closeButton.addEventListener('click', function () { closeMenu(true); });
        backdrop.addEventListener('click', function () { closeMenu(true); });
        document.addEventListener('keydown', handleKeydown);
        navigation.addEventListener('click', function (event) {
            if (!desktopQuery.matches && event.target.closest('a')) closeMenu(false);
        });

        if (typeof desktopQuery.addEventListener === 'function') desktopQuery.addEventListener('change', handleViewport);
        else desktopQuery.addListener(handleViewport);
        sync(false);
    }

    function initTheme04JourneyDesk() {
        const desk = document.querySelector('[data-t4-journey-desk]');
        if (!desk || desk.dataset.t4Ready === 'true') return;

        const tabList = desk.querySelector('.filter-item-list');
        const tabs = Array.from(desk.querySelectorAll('.filter-item-list > .single-item'));
        const forms = Array.from(desk.querySelectorAll('.filter-input-wrap > form.filter-input'));
        if (!tabList || tabs.length === 0 || forms.length === 0) return;

        desk.dataset.t4Ready = 'true';
        tabList.setAttribute('role', 'tablist');
        tabList.setAttribute('aria-label', 'Journey service types');

        function formForTab(tab) {
            const serviceCode = tab.dataset.formService || tab.dataset.service;
            return forms.find((form) => form.dataset.service === serviceCode) || null;
        }

        function syncState() {
            tabs.forEach((tab) => {
                const active = tab.classList.contains('active');
                tab.setAttribute('aria-selected', String(active));
                tab.setAttribute('tabindex', active ? '0' : '-1');
            });
            forms.forEach((form) => form.setAttribute('aria-hidden', String(!form.classList.contains('show'))));
        }

        tabs.forEach((tab, index) => {
            const form = formForTab(tab);
            tab.id = tab.id || `t4-journey-tab-${index + 1}`;
            tab.setAttribute('role', 'tab');
            if (form) {
                tab.setAttribute('aria-controls', form.id);
                form.setAttribute('role', 'tabpanel');
                form.setAttribute('aria-labelledby', tab.id);
            }

            tab.addEventListener('click', function () { window.requestAnimationFrame(syncState); });
            tab.addEventListener('keydown', function (event) {
                const keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];
                if (!keys.includes(event.key)) return;
                event.preventDefault();
                let target = index;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') target = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') target = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') target = 0;
                else if (event.key === 'End') target = tabs.length - 1;
                tabs[target].focus();
                tabs[target].click();
            });
        });
        syncState();
    }

    function initTheme04Testimonials() {
        const section = document.querySelector('[data-t4-testimonials]');
        if (!section || section.dataset.t4Ready === 'true' || typeof window.Swiper !== 'function') return;
        section.dataset.t4Ready = 'true';
        new window.Swiper(section.querySelector('.t4-testimonial-slider'), {
            slidesPerView: 1,
            spaceBetween: 18,
            speed: 700,
            navigation: {
                nextEl: section.querySelector('.t4-testimonial-next'),
                prevEl: section.querySelector('.t4-testimonial-prev')
            },
            breakpoints: {
                768: { slidesPerView: 2 },
                1200: { slidesPerView: 3 }
            }
        });
    }

    function initTheme04() {
        initTheme04Shell();
        initTheme04JourneyDesk();
        initTheme04Testimonials();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initTheme04, { once: true });
    else initTheme04();
})();
