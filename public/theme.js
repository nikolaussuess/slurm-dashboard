/**
 * Light/dark theme. Loaded in <head>, so that the page does not flash in the wrong theme.
 * A choice made with the button in the navbar wins, otherwise the setting of the browser applies.
 * This is a separate file because error.php has no CSP nonce for inline scripts.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'theme';

    // Access to localStorage throws if the browser blocks site data.
    function storedTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function storeTheme(theme) {
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {
            // The choice then only lasts until the next page load.
        }
    }

    function systemTheme() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
    }

    applyTheme(storedTheme() || systemTheme());

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (!storedTheme()) {
            applyTheme(systemTheme());
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.getElementById('themeToggle');
        if (!toggle) {
            return;
        }
        toggle.addEventListener('click', function () {
            const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            storeTheme(next);
        });
    });
})();
