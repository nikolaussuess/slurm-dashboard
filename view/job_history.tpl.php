<?php

namespace view\actions;

use DateTime;
use Exception;
use TemplateLoader;

/**
 * Get options from the filter form.
 * @return array Array of filter options
 *
 * Filter options (passed to the API):
 * - account        (array of strings)
 * - users          (array of strings)
 * - partition      (array of strings)
 * - node           (array of strings)
 * - state          (array of strings, validated against known Slurm states)
 * - job_name       (string)
 * - constraints    (string)
 * - start_time     (int, UNIX timestamp)
 * - end_time       (int, UNIX timestamp)
 *
 * Other indices:
 * - start_time_value (string, raw datetime string for form repopulation)
 * - end_time_value   (string, raw datetime string for form repopulation)
 */
function get_slurmdb_filter_form_evaluation() : array {
    // BEGIN evaluate filter form
    $filter = array();

    if( isset($_GET['do']) && $_GET['do'] == 'search' ){

        $start_time = $_POST['form_time_min'] ?? '';
        if($start_time != ''){
            $filter['start_time_value'] = $start_time;
            try {
                $dateTimeObject = new DateTime($start_time);
                $start_time = $dateTimeObject->getTimestamp();
                $filter['start_time'] = $start_time;
            } catch (Exception $e) {
                addError("Start time value (" . htmlspecialchars($filter['start_time_value'], ENT_QUOTES, 'UTF-8') . ") invalid: " .
                    htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "; Ignoring value");
            }
        }

        $end_time = $_POST['form_time_max'] ?? '';
        if($end_time != ''){
            $filter['end_time_value'] = $end_time;
            try {
                $dateTimeObject = new DateTime($end_time);
                $end_time = $dateTimeObject->getTimestamp();
                $filter['end_time'] = $end_time;
            } catch (Exception $e) {
                addError("End time value (" . htmlspecialchars($filter['end_time_value'], ENT_QUOTES, 'UTF-8') . ") invalid: " .
                    htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "; Ignoring value");
            }
        }

        $user = array_filter(array_map('trim', $_POST['form_user'] ?? []));
        if (!empty($user)) {
            $filter['users'] = array_values($user);
        }

        $account = array_filter(array_map('trim', $_POST['form_account'] ?? []));
        if (!empty($account)) {
            $filter['account'] = array_values($account);
        }

        $partition = array_filter(array_map('trim', $_POST['form_partition'] ?? []));
        if (!empty($partition)) {
            $filter['partition'] = array_values($partition);
        }

        $node = array_filter(array_map('trim', $_POST['form_nodename'] ?? []));
        if (!empty($node)) {
            $filter['node'] = array_values($node);
        }

        $job_name = $_POST['form_job_name'] ?? '';
        if($job_name != ''){
            $filter['job_name'] = $job_name;
        }

        $constraints = $_POST['form_constraints'] ?? '';
        if($constraints != ''){
            $filter['constraints'] = $constraints;
        }

        $state = array_values(array_filter($_POST['form_state'] ?? [], fn($s) => isset(\utils\SLURM_JOB_STATES[$s])));
        if (!empty($state)) {
            $filter['state'] = $state;
        }
    }
    // END evaluate filter form

    return $filter;
}


function get_slurmdb_filter_form(array $filter, array $accounts, array $users, array $nodes, array $partitions) : string {

    // Accounts field
    $selected_accounts = array_flip($filter['account'] ?? []);
    $account_list = '';
    foreach ($accounts as $account) {
        if ($account === 'root') continue;
        $account_e = htmlspecialchars($account, ENT_QUOTES, 'UTF-8');
        $sel = isset($selected_accounts[$account]) ? ' selected' : '';
        $account_list .= '<option value="' . $account_e . '"' . $sel . '>' . $account_e . '</option>';
    }

    // Users field
    $selected_users = array_flip($filter['users'] ?? []);
    $users_list = '';
    foreach ($users as $user) {
        if ($user === 'root') continue;
        $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
        $sel = isset($selected_users[$user]) ? ' selected' : '';
        $users_list .= '<option value="' . $user_e . '"' . $sel . '>' . $user_e . '</option>';
    }

    // Partitions field
    $selected_partitions = array_flip($filter['partition'] ?? []);
    $partition_list = '';
    foreach ($partitions as $partition) {
        $partition_e = htmlspecialchars($partition, ENT_QUOTES, 'UTF-8');
        $sel = isset($selected_partitions[$partition]) ? ' selected' : '';
        $partition_list .= '<option value="' . $partition_e . '"' . $sel . '>' . $partition_e . '</option>';
    }

    // Nodes field
    $selected_nodes = array_flip($filter['node'] ?? []);
    $node_list = '';
    foreach ($nodes as $node) {
        $node_e = htmlspecialchars($node, ENT_QUOTES, 'UTF-8');
        $sel = isset($selected_nodes[$node]) ? ' selected' : '';
        $node_list .= '<option value="' . $node_e . '"' . $sel . '>' . $node_e . '</option>';
    }

    // State field — built from the authoritative SLURM_JOB_STATES constant.
    $selected_states = array_flip($filter['state'] ?? []);
    $state_list = '';
    foreach (\utils\SLURM_JOB_STATE_GROUP_META as $group_label => $group_meta) {
        $group_e = htmlspecialchars($group_label, ENT_QUOTES, 'UTF-8');
        $state_list .= '<optgroup label="' . $group_e . '">';
        foreach (\utils\SLURM_JOB_STATES as $val => $attrs) {
            if ($attrs['group'] !== $group_label) continue;
            $val_e     = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $sel       = isset($selected_states[$val]) ? ' selected' : '';
            $dis       = $attrs['disabled'] ? ' disabled' : '';
            $cls_attr  = ' class="' . $group_meta['css_class'] . '"';
            $state_list .= '<option value="' . $val_e . '"' . $sel . $dis . $cls_attr . '>' . $val_e . '</option>';
        }
        $state_list .= '</optgroup>';
    }

    $templateBuilder = new TemplateLoader("job_filter_form.html");
    $templateBuilder->setParam("ACCOUNT_SELECTS", $account_list);
    $templateBuilder->setParam("PARTITION_SELECTS", $partition_list);
    $templateBuilder->setParam("STATE_SELECTS", $state_list);
    $templateBuilder->setParam("JOB_NAME", htmlspecialchars($filter['job_name'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("CONSTRAINTS", htmlspecialchars($filter['constraints'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("USER_SELECTS", $users_list);
    $templateBuilder->setParam("NODE_SELECTS", $node_list);
    $templateBuilder->setParam("ACTION", 'action=job_history&do=search');
    $templateBuilder->setParam("TIME_MIN_VALUE", htmlspecialchars($filter['start_time_value'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("TIME_MAX_VALUE", htmlspecialchars($filter['end_time_value'] ?? '', ENT_QUOTES, 'UTF-8'));
    return $templateBuilder->build();
}


function get_filtered_jobs_from_slurmdb(array $jobs, array $filter = []) : string {
    // Active filter chips — JS adds the × removal buttons.
    $chip_configs = [
        // filter key => [display label, form field name, is multi-select]
        'account'     => ['Account',      'form_account',    TRUE],
        'users'       => ['User',         'form_user',       TRUE],
        'partition'   => ['Partition',    'form_partition',  TRUE],
        'node'        => ['Node',         'form_nodename',   TRUE],
        'state'       => ['State',        'form_state',      TRUE],
        'job_name'    => ['Job name',     'form_job_name',   FALSE],
        'constraints' => ['Constraints',  'form_constraints',FALSE],
    ];
    $chips = '';
    foreach ($chip_configs as $key => [$label, $field, $multi]) {
        if (!isset($filter[$key])) continue;
        foreach (($multi ? $filter[$key] : [$filter[$key]]) as $val) {
            $val_e      = htmlspecialchars($val,   ENT_QUOTES, 'UTF-8');
            $field_e    = htmlspecialchars($multi ? $field . '[]' : $field, ENT_QUOTES, 'UTF-8');
            $label_e    = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $extra_class = '';
            if ($key === 'state' && isset(\utils\SLURM_JOB_STATES[$val])) {
                $extra_class = ' ' . \utils\SLURM_JOB_STATE_GROUP_META[\utils\SLURM_JOB_STATES[$val]['group']]['css_class'];
            }
            $chips .= '<span class="filter-chip' . $extra_class . '" data-field="' . $field_e . '" data-value="' . $val_e . '">'
                    . $label_e . ': <strong>' . $val_e . '</strong></span>';
        }
    }
    if (isset($filter['start_time_value'])) {
        $val_e  = htmlspecialchars($filter['start_time_value'], ENT_QUOTES, 'UTF-8');
        $chips .= '<span class="filter-chip" data-field="form_time_min" data-value="">From: <strong>' . $val_e . '</strong></span>';
    }
    if (isset($filter['end_time_value'])) {
        $val_e  = htmlspecialchars($filter['end_time_value'], ENT_QUOTES, 'UTF-8');
        $chips .= '<span class="filter-chip" data-field="form_time_max" data-value="">To: <strong>' . $val_e . '</strong></span>';
    }

    $contents = $chips !== ''
        ? '<div class="active-filter-chips"><span class="filter-chips-label">Active filters:</span> ' . $chips . '</div>'
        : '';

    $contents .= '<div>Found <span style="font-weight: bold">' . count($jobs) . ' jobs</span>.</div>';

    $contents .= <<<EOF
<div id="jobtable-table" class="table-responsive tableFixHead">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th class="breakable">Name</th>
                <th>Account</th>
                <th>Partition</th>
                <th>User</th>
                <th>State</th>
                <th>Start time</th>
                <th>Time elapsed</th>
                <th>Time limit</th>
                <th>Nodelist</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
EOF;

    $active_states = ['PENDING', 'RUNNING', 'COMPLETING', 'CONFIGURING', 'SUSPENDED', 'REQUEUED'];

    foreach( $jobs as $job ) {

        $contents .= "<tr>";
        $contents .=    "<td>" . $job['job_id'] . "</td>";
        $contents .=    "<td class='breakable'>" . htmlspecialchars($job['job_name'], ENT_QUOTES, 'UTF-8') . "</td>";
        $contents .=    "<td>" . \utils\auto_link_account(htmlspecialchars($job['account'], ENT_QUOTES, 'UTF-8'), $job['account']) . "</td>";
        $contents .=    "<td>" . \utils\auto_link_csv($job['partition'], '\utils\auto_link_partition') . "</td>";
        $contents .=    "<td>" . htmlspecialchars($job['user_name'], ENT_QUOTES, 'UTF-8') . "</td>";

        $contents .=    "<td>" . \utils\get_job_state_view($job) . "</td>";

        $contents .=    "<td>" . $job['time_start'] . "</td>";
        $contents .=    "<td>" . $job['time_elapsed'] . "</td>";
        $contents .=    "<td>" . $job['time_limit'] . "</td>";

        $nodes_e = htmlspecialchars($job['nodes'], ENT_QUOTES, 'UTF-8');
        if (strpbrk($job['nodes'], '[,') === FALSE) {
            // Single node: link it via auto_link_node.
            $nodes_display = \utils\auto_link_node($nodes_e, $job['nodes']);
        } else {
            // Multiple nodes: zero-width spaces after commas allow wrapping
            // without adding visible characters.
            $nodes_display = str_replace(',', ',&#8203;', $nodes_e);
        }
        $contents .=    "<td class='nodes-cell'>" . $nodes_display . "</td>";

        $contents .= '<td><div class="btn-group">';
        $contents .= '<a href="?action=job&job_id=' . $job['job_id'] . '" class="btn btn-info" type="button">Details</a>';
        $contents .= '<button type="button" class="btn btn-danger dropdown-toggle dropdown-toggle-split"'
                   . ' data-bs-toggle="dropdown" aria-expanded="false">'
                   . '<span class="visually-hidden">Toggle Dropdown</span></button>';

        if (\client\utils\jwt\JwtAuthentication::is_supported() && !empty(array_intersect($job['job_state'], $active_states))) {
            $contents .= '<ul class="dropdown-menu">'
                       . '<li><a class="dropdown-item" href="?action=cancel-job&job_id=' . $job['job_id'] . '">Cancel job</a></li>'
                       . '<li><a class="dropdown-item" href="?action=edit-job&job_id=' . $job['job_id'] . '">Edit job</a></li>'
                       . '</ul>';
        } elseif (!\client\utils\jwt\JwtAuthentication::is_supported()) {
            $tooltip = 'This feature is not supported by the current configuration.';
            $contents .= '<ul class="dropdown-menu"><li>'
                       . '<span class="dropdown-item" data-bs-toggle="tooltip" data-bs-placement="right" title="' . $tooltip . '">'
                       . '<a class="dropdown-item disabled" href="?action=cancel-job&job_id=' . $job['job_id'] . '" aria-disabled="true">Cancel job</a></span>'
                       . '<span class="dropdown-item" data-bs-toggle="tooltip" data-bs-placement="right" title="' . $tooltip . '">'
                       . '<a class="dropdown-item disabled" href="?action=edit-job&job_id=' . $job['job_id'] . '" aria-disabled="true">Edit job</a></span>'
                       . '</li></ul>';
        } else {
            $tooltip = 'Job is no longer active.';
            $contents .= '<ul class="dropdown-menu"><li>'
                       . '<span class="dropdown-item" data-bs-toggle="tooltip" data-bs-placement="right" title="' . $tooltip . '">'
                       . '<a class="dropdown-item disabled" href="?action=cancel-job&job_id=' . $job['job_id'] . '" aria-disabled="true">Cancel job</a></span>'
                       . '<span class="dropdown-item" data-bs-toggle="tooltip" data-bs-placement="right" title="' . $tooltip . '">'
                       . '<a class="dropdown-item disabled" href="?action=edit-job&job_id=' . $job['job_id'] . '" aria-disabled="true">Edit job</a></span>'
                       . '</li></ul>';
        }

        $contents .= '</div></td>';
        $contents .= '</tr>';
    }
    $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

    return $contents;
}