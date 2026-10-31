/**
 * Keeps the header of long tables visible below the sticky navbar.
 * position: sticky cannot do this here: the box around a table scrolls horizontally, and sticky elements
 * only stick within their nearest scrolling box. Without JavaScript, the header simply scrolls with the page.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const navbar = document.querySelector('.navbar');
        const boxes = document.querySelectorAll('.tableFixHead');
        let updatePending = false;

        function updateHeaders() {
            updatePending = false;
            const navbarBottom = navbar ? navbar.getBoundingClientRect().bottom : 0;

            boxes.forEach(function (box) {
                const head = box.querySelector('thead');
                const maxShift = box.querySelector('table').offsetHeight - head.offsetHeight;
                const shift = Math.min(navbarBottom - box.getBoundingClientRect().top, maxShift);
                head.style.transform = shift > 0 ? 'translateY(' + shift + 'px)' : '';
            });
        }

        function requestUpdate() {
            if (!updatePending) {
                updatePending = true;
                window.requestAnimationFrame(updateHeaders);
            }
        }

        if (boxes.length > 0) {
            window.addEventListener('scroll', requestUpdate, { passive: true });
            window.addEventListener('resize', requestUpdate);
            updateHeaders();
        }
    });
})();
