<?php

namespace view\actions;

/**
 * Renders the Cancel/Edit dropdown menu for a job action button group.
 * @param int $job_id Job ID used in action URLs
 * @param bool $disabled Whether the buttons should be rendered as disabled
 * @param string $tooltip Tooltip shown on disabled buttons; ignored when $disabled is FALSE
 * @return string Rendered HTML for the dropdown menu
 */
function render_job_action_dropdown(int $job_id, bool $disabled, string $tooltip = '') : string {
    if (!$disabled) {
        return '<ul class="dropdown-menu">'
             . '<li><a class="dropdown-item" href="?action=cancel-job&job_id=' . $job_id . '">Cancel job</a></li>'
             . '<li><a class="dropdown-item" href="?action=edit-job&job_id=' . $job_id . '">Edit job</a></li>'
             . '</ul>';
    }
    return '<ul class="dropdown-menu"><li>'
         . '<span class="dropdown-item" data-bs-toggle="tooltip" data-bs-placement="right" title="' . $tooltip . '">'
         . '<a class="dropdown-item disabled" href="?action=cancel-job&job_id=' . $job_id . '" aria-disabled="true">Cancel job</a></span>'
         . '<span class="dropdown-item" data-bs-toggle="tooltip" data-bs-placement="right" title="' . $tooltip . '">'
         . '<a class="dropdown-item disabled" href="?action=edit-job&job_id=' . $job_id . '" aria-disabled="true">Edit job</a></span>'
         . '</li></ul>';
}
