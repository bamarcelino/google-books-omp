(function () {
    'use strict';

    if (window.__googleBooksDashboardScriptRegistered) return;
    window.__googleBooksDashboardScriptRegistered = true;

    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    function randomString(length) {
        if (!window.crypto || !window.crypto.getRandomValues) {
            throw new Error('A cryptographically secure browser random-number generator is unavailable.');
        }
        let result = '';
        while (result.length < length) {
            const bytes = new Uint8Array(Math.max(16, (length - result.length) * 2));
            window.crypto.getRandomValues(bytes);
            for (const value of bytes) {
                if (value >= 248) continue;
                result += alphabet[value % alphabet.length];
                if (result.length === length) break;
            }
        }
        return result;
    }

    function setupTabs() {
        const tabs = Array.from(document.querySelectorAll('[data-gb-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-gb-panel]'));
        const radios = Array.from(document.querySelectorAll('[data-gb-tab-radio]'));
        if (!tabs.length || !panels.length) return;

        function activate(name) {
            if (!panels.some(function (panel) { return panel.dataset.gbPanel === name; })) name = 'overview';
            radios.forEach(function (radio) {
                radio.checked = radio.dataset.gbTabRadio === name;
            });
            tabs.forEach(function (tab) {
                const active = tab.dataset.gbTab === name;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.dataset.gbPanel === name);
            });
            try { window.sessionStorage.setItem('googleBooksDashboardTab', name); } catch (error) {}
        }

        let initial = 'overview';
        try { initial = window.sessionStorage.getItem('googleBooksDashboardTab') || initial; } catch (error) {}
        if (window.location.hash && window.location.hash.indexOf('#gb-') === 0) initial = window.location.hash.substring(4);
        activate(initial);

        tabs.forEach(function (tab) {
            if (tab.dataset.gbBound === '1') return;
            tab.dataset.gbBound = '1';
            function selectTab() {
                const name = tab.dataset.gbTab || 'overview';
                activate(name);
                if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#gb-' + name);
            }
            tab.addEventListener('click', selectTab);
            tab.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectTab();
                }
            });
        });
    }

    function setupCredentials() {
        const button = document.getElementById('gb-generate-credentials');
        const username = document.getElementById('feedUsername');
        const password = document.getElementById('feedPassword');
        if (!button || !username || !password || button.dataset.gbBound === '1') return;
        button.dataset.gbBound = '1';
        button.addEventListener('click', function () {
            try {
                if (!username.value.trim()) username.value = 'googlebooks' + randomString(6);
                password.value = randomString(28);
                password.type = 'text';
                password.focus();
                password.select();
                window.setTimeout(function () { password.type = 'password'; }, 10000);
            } catch (error) {
                window.alert(error.message || 'Unable to generate secure feed credentials in this browser.');
            }
        });
    }

    function setupTransportPanels() {
        const select = document.querySelector('[data-gb-delivery-mode]');
        const panels = Array.from(document.querySelectorAll('[data-gb-transport]'));
        if (!select || !panels.length || select.dataset.gbBound === '1') return;
        select.dataset.gbBound = '1';
        function update() {
            panels.forEach(function (panel) {
                const visible = panel.dataset.gbTransport === select.value;
                panel.classList.toggle('is-active', visible);
                panel.querySelectorAll('input,select,textarea').forEach(function (field) {
                    field.disabled = !visible;
                });
            });
        }
        select.addEventListener('change', update);
        update();
    }

    function initializeDashboard() {
        setupTabs();
        setupCredentials();
        setupTransportPanels();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeDashboard, { once: true });
    } else {
        initializeDashboard();
    }
})();
