(function () {
    'use strict';

    const desktopQuery = window.matchMedia('(min-width: 1200px)');

    function initTheme03Shell() {
        const header = document.querySelector('[data-t3-header]');

        if (!header || header.dataset.t3Ready === 'true') {
            return;
        }

        const openButton = header.querySelector('[data-t3-menu-open]');
        const closeButton = header.querySelector('[data-t3-menu-close]');
        const navigation = header.querySelector('[data-t3-navigation]');
        const backdrop = header.querySelector('[data-t3-menu-backdrop]');

        if (!openButton || !closeButton || !navigation || !backdrop) {
            return;
        }

        header.dataset.t3Ready = 'true';
        let returnFocusTo = null;

        const focusableSelector = [
            'a[href]',
            'button:not([disabled])',
            'summary',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])'
        ].join(',');

        function isMenuOpen() {
            return document.body.classList.contains('t3-menu-open');
        }

        function setAccessibilityState(open) {
            openButton.setAttribute('aria-expanded', String(open));

            if (desktopQuery.matches) {
                navigation.removeAttribute('aria-hidden');
            } else {
                navigation.setAttribute('aria-hidden', String(!open));
            }
        }

        function openMenu() {
            if (desktopQuery.matches) {
                return;
            }

            returnFocusTo = document.activeElement instanceof HTMLElement
                ? document.activeElement
                : openButton;
            document.body.classList.add('t3-menu-open');
            setAccessibilityState(true);
            closeButton.focus();
        }

        function closeMenu(restoreFocus) {
            document.body.classList.remove('t3-menu-open');
            setAccessibilityState(false);

            if (restoreFocus && returnFocusTo instanceof HTMLElement) {
                returnFocusTo.focus();
            }

            returnFocusTo = null;
        }

        function trapFocus(event) {
            if (event.key !== 'Tab' || !isMenuOpen() || desktopQuery.matches) {
                return;
            }

            const focusable = Array.from(navigation.querySelectorAll(focusableSelector))
                .filter((element) => element.getClientRects().length > 0);

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

        function handleKeydown(event) {
            if (event.key === 'Escape' && isMenuOpen()) {
                event.preventDefault();
                closeMenu(true);
                return;
            }

            trapFocus(event);
        }

        function handleViewportChange(event) {
            if (event.matches) {
                closeMenu(false);
                navigation.removeAttribute('aria-hidden');
            } else {
                setAccessibilityState(false);
            }
        }

        openButton.addEventListener('click', openMenu);
        closeButton.addEventListener('click', function () {
            closeMenu(true);
        });
        backdrop.addEventListener('click', function () {
            closeMenu(true);
        });
        document.addEventListener('keydown', handleKeydown);

        navigation.addEventListener('click', function (event) {
            if (!desktopQuery.matches && event.target.closest('a')) {
                closeMenu(false);
            }
        });

        if (typeof desktopQuery.addEventListener === 'function') {
            desktopQuery.addEventListener('change', handleViewportChange);
        } else {
            desktopQuery.addListener(handleViewportChange);
        }

        setAccessibilityState(false);
    }

    function initTheme03JourneyDesk() {
        const desk = document.querySelector('[data-t3-journey-desk]');

        if (!desk || desk.dataset.t3Ready === 'true') {
            return;
        }

        const tabList = desk.querySelector('.filter-item-list');
        const tabs = Array.from(desk.querySelectorAll('.filter-item-list > .single-item'));
        const forms = Array.from(desk.querySelectorAll('.filter-input-wrap > form.filter-input'));

        if (!tabList || tabs.length === 0 || forms.length === 0) {
            return;
        }

        desk.dataset.t3Ready = 'true';
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

            forms.forEach((form) => {
                const active = form.classList.contains('show');
                form.setAttribute('aria-hidden', String(!active));
            });
        }

        tabs.forEach((tab, index) => {
            const controlledForm = formForTab(tab);
            tab.id = tab.id || `t3-journey-tab-${index + 1}`;
            tab.setAttribute('role', 'tab');

            if (controlledForm) {
                tab.setAttribute('aria-controls', controlledForm.id);
                controlledForm.setAttribute('role', 'tabpanel');
                controlledForm.setAttribute('aria-labelledby', tab.id);
            }

            tab.addEventListener('click', function () {
                window.requestAnimationFrame(syncState);
            });

            tab.addEventListener('keydown', function (event) {
                const keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];
                if (!keys.includes(event.key)) {
                    return;
                }

                event.preventDefault();
                let targetIndex = index;

                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    targetIndex = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    targetIndex = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    targetIndex = 0;
                } else if (event.key === 'End') {
                    targetIndex = tabs.length - 1;
                }

                tabs[targetIndex].focus();
                tabs[targetIndex].click();
            });
        });

        syncState();
    }

    function initTheme03() {
        initTheme03Shell();
        initTheme03JourneyDesk();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTheme03, { once: true });
    } else {
        initTheme03();
    }
})();
