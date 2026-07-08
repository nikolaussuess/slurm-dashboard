/**
 * multiselect.js — Searchable multi-select widget
 *
 * Progressively enhances every <select multiple class="multiselect-search">
 * element into a chip-style combobox with live search. Without JavaScript the
 * native <select multiple> remains fully functional (no-JS fallback).
 *
 * The native select is kept in the DOM (hidden) and stays authoritative for
 * form submission — the widget only mirrors its state visually.
 */
(function () {
    'use strict';

    /**
     * Replaces one <select multiple> with the custom widget.
     * @param {HTMLSelectElement} select
     */
    function buildWidget(select) {
        // Wrap the native select so we can position the dropdown relative to it.
        const wrapper = document.createElement('div');
        wrapper.className = 'ms-widget';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        // Hide the native select; keep it in the DOM so the browser submits it.
        select.style.display = 'none';
        select.setAttribute('aria-hidden', 'true');

        // --- Control bar (chips + search input) ---
        const control = document.createElement('div');
        control.className = 'ms-control form-control';

        const chipsContainer = document.createElement('div');
        chipsContainer.className = 'ms-chips';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'ms-search';
        input.placeholder = 'Search…';
        input.setAttribute('autocomplete', 'off');
        // Derive accessible label from the associated <label> element.
        const labelEl = select.id
            ? document.querySelector('label[for="' + select.id + '"]')
            : null;
        input.setAttribute('aria-label', labelEl ? labelEl.textContent.trim() : 'Search');

        control.appendChild(chipsContainer);
        control.appendChild(input);
        wrapper.insertBefore(control, select);

        // --- Dropdown panel ---
        const dropdown = document.createElement('div');
        dropdown.className = 'ms-dropdown';

        const optionsList = document.createElement('ul');
        optionsList.className = 'ms-options';
        optionsList.setAttribute('role', 'listbox');
        optionsList.setAttribute('aria-multiselectable', 'true');

        dropdown.appendChild(optionsList);
        wrapper.appendChild(dropdown);

        // --- Render helpers ---

        /**
         * Rebuilds the dropdown list, optionally filtered by a search string.
         * Uses mousedown (not click) so the event fires before the input loses
         * focus, which would otherwise close the dropdown before the click
         * registers.
         * @param {string} filterText
         */
        function renderOptions(filterText) {
            optionsList.innerHTML = '';
            const lower = filterText.toLowerCase();
            let any = false;

            function appendOption(opt) {
                if (opt.value === '') return; // skip empty placeholder
                if (lower && opt.text.toLowerCase().indexOf(lower) === -1) return;
                any = true;

                const li = document.createElement('li');
                li.className = 'ms-option'
                    + (opt.selected  ? ' ms-selected'  : '')
                    + (opt.disabled  ? ' ms-disabled'  : '')
                    + (opt.className ? ' ' + opt.className : '');
                li.textContent = opt.text;
                li.dataset.value = opt.value;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', opt.selected ? 'true' : 'false');

                if (!opt.disabled) {
                    li.addEventListener('mousedown', function (e) {
                        e.preventDefault(); // prevent input from losing focus
                        opt.selected = !opt.selected;
                        renderChips();
                        renderOptions(input.value);
                    });
                }

                optionsList.appendChild(li);
            }

            Array.from(select.children).forEach(function (child) {
                if (child.tagName === 'OPTGROUP') {
                    // Render matching options first; only add the group header if
                    // at least one option passes the filter.
                    const groupEl = document.createElement('li');
                    groupEl.className = 'ms-optgroup-label';
                    groupEl.textContent = child.label;

                    const before = optionsList.childElementCount;
                    optionsList.appendChild(groupEl);
                    Array.from(child.children).forEach(appendOption);

                    // Remove the header again if no options were added under it.
                    if (optionsList.childElementCount === before + 1) {
                        optionsList.removeChild(groupEl);
                    }
                } else {
                    appendOption(child);
                }
            });

            if (!any) {
                const li = document.createElement('li');
                li.className = 'ms-option ms-no-results';
                li.textContent = 'No results';
                optionsList.appendChild(li);
            }
        }

        /**
         * Rebuilds the chip row from the currently selected native options.
         */
        function renderChips() {
            chipsContainer.innerHTML = '';

            Array.from(select.options).forEach(function (opt) {
                if (!opt.selected || opt.value === '') return;

                const chip = document.createElement('span');
                chip.className = 'ms-chip';

                const label = document.createElement('span');
                label.textContent = opt.text;

                const btn = document.createElement('button');
                btn.type = 'button'; // prevent accidental form submission
                btn.className = 'ms-chip-remove';
                btn.textContent = '×';
                btn.setAttribute('aria-label', 'Remove ' + opt.text);

                btn.addEventListener('click', function (e) {
                    e.stopPropagation(); // don't re-open the dropdown
                    opt.selected = false;
                    renderChips();
                    renderOptions(input.value);
                });

                chip.appendChild(label);
                chip.appendChild(btn);
                chipsContainer.appendChild(chip);
            });
        }

        function openDropdown() {
            dropdown.classList.add('ms-open');
            renderOptions(input.value);
        }

        function closeDropdown() {
            dropdown.classList.remove('ms-open');
            input.value = ''; // clear search when closing
        }

        // --- Event wiring ---

        // Open dropdown when the user clicks the control bar.
        control.addEventListener('click', function () {
            if (!dropdown.classList.contains('ms-open')) {
                openDropdown();
                input.focus();
            }
        });

        // Re-filter options as the user types.
        input.addEventListener('input', function () {
            renderOptions(input.value);
        });

        // Escape closes the dropdown and returns focus to the control.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeDropdown();
                input.blur();
            }
        });

        // Close when the user clicks anywhere outside the widget.
        document.addEventListener('mousedown', function (e) {
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        // Populate chips for any options that are already selected (page reload
        // with preserved filter values).
        renderChips();
    }

    /**
     * Wires up the active-filter chip bar: adds × removal buttons to each
     * .filter-chip element and submits the filter form when one is clicked.
     */
    function initFilterChips() {
        var form = document.querySelector('form[action*="job_history"]');
        if (!form) return;

        document.querySelectorAll('.filter-chip').forEach(function (chip) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'filter-chip-remove';
            btn.textContent = '×';
            btn.setAttribute('aria-label', 'Remove filter');

            btn.addEventListener('click', function () {
                var field = chip.dataset.field;
                var value = chip.dataset.value;
                // querySelector requires escaping brackets in attribute values.
                var el = form.querySelector('[name="' + field + '"]');

                if (el && el.tagName === 'SELECT') {
                    // Deselect the matching option in the (hidden) native select.
                    Array.from(el.options).forEach(function (opt) {
                        if (opt.value === value) opt.selected = false;
                    });
                } else if (el) {
                    el.value = '';
                }
                form.submit();
            });

            chip.appendChild(btn);
        });
    }

    /**
     * Wires up the "Show own jobs only" button: sets the user filter to the
     * current user and submits the form. Clicking it again (it is rendered
     * with the "active" class when the filter already matches) clears the
     * user filter instead.
     */
    function initOwnJobsOnlyButton() {
        var btn = document.getElementById('btn-own-jobs-only');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var form = btn.closest('form');
            var select = form.querySelector('[name="form_user[]"]');
            if (!select) return;

            if (btn.classList.contains('active')) {
                Array.from(select.options).forEach(function (opt) {
                    opt.selected = false;
                });
                form.submit();
                return;
            }

            var currentUser = btn.dataset.currentUser;
            var found = false;
            Array.from(select.options).forEach(function (opt) {
                opt.selected = (opt.value === currentUser);
                if (opt.value === currentUser) found = true;
            });

            // The user list may not include the current user (e.g. not present
            // in the dropdown source); add it so the filter still works.
            if (!found && currentUser) {
                var opt = document.createElement('option');
                opt.value = currentUser;
                opt.text = currentUser;
                opt.selected = true;
                select.appendChild(opt);
            }

            form.submit();
        });
    }

    /**
     * Wires up the state-filter shortcut buttons: sets the state filter to
     * either an explicit list of states (data-states, comma-separated) or all
     * (non-disabled) states of a group (data-state-class), then submits the
     * form. The user filter is left untouched. Clicking an already-active
     * shortcut (rendered with the "active" class) clears the state filter
     * instead of re-applying it.
     */
    function initStateOnlyButtons() {
        document.querySelectorAll('[data-states], [data-state-class]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = btn.closest('form');
                var select = form.querySelector('[name="form_state[]"]');
                if (!select) return;

                if (btn.classList.contains('active')) {
                    Array.from(select.options).forEach(function (opt) {
                        opt.selected = false;
                    });
                    form.submit();
                    return;
                }

                if (btn.dataset.states) {
                    var states = btn.dataset.states.split(',');
                    Array.from(select.options).forEach(function (opt) {
                        opt.selected = !opt.disabled && states.indexOf(opt.value) !== -1;
                    });
                } else {
                    var stateClass = btn.dataset.stateClass;
                    Array.from(select.options).forEach(function (opt) {
                        opt.selected = !opt.disabled && opt.classList.contains(stateClass);
                    });
                }

                form.submit();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select.multiselect-search').forEach(buildWidget);
        initFilterChips();
        initOwnJobsOnlyButton();
        initStateOnlyButtons();
    });
})();
