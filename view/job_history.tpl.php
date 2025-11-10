<?php

namespace view\actions;

use DateTime;
use Exception;
use TemplateLoader;

/**
 * Get options from the filter form.
 * @return array Array of filter options
 *
 * Filter options:
 * - cluster (CSV list)
 * - account (CSV list)
 * - job_name (CSV list)
 * - constraints (CSV list)
 * - exit_code (numeric)
 * - partition (CSV list)
 * - state (CSV state list)
 * - end_time (UNIX timestamp)
 * - node (node string)
 * - users (CSV user list)
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
                addError("Start time value (" . $filter['start_time_value'] . ") invalid: " .
                    $e->getMessage() . "; Ignoring value");
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
                addError("End time value (" . $filter['end_time_value'] . ") invalid: " . $e->getMessage() .
                    "; Ignoring value");
            }
        }

        $user = $_POST['form_user'] ?? '';
        if($user != ''){
            $filter['users'] = $user;
        }

        $account = $_POST['form_account'] ?? '';
        if($account != ''){
            $filter['account'] = $account;
        }

        $node = $_POST['form_nodename'] ?? '';
        if($node != ''){
            $filter['node'] = $node;
        }

        $job_name = $_POST['form_job_name'] ?? '';
        if($job_name != ''){
            $filter['job_name'] = $job_name;
        }

        $constraints = $_POST['form_constraints'] ?? '';
        if($constraints != ''){
            $filter['constraints'] = $constraints;
        }

        $state = $_POST['form_state'] ?? '';
        if($state != ''){
            $filter['state'] = $state;
        }
    }
    // END evaluate filter form

    return $filter;
}


function get_slurmdb_filter_form(array $filter, array $accounts, array $users, array $nodes) : string {

    // Accounts field
    $account_list = '';
    $selected = FALSE;
    foreach ($accounts as $account){
        if(isset($filter['account']) && $filter['account'] == $account) {
            $account_list .= '<option value="' . $account . '" selected>' . $account . '</option>';
            $selected = TRUE;
        }
        else {
            $account_list .= '<option value="' . $account . '">'. $account . '</option>';
        }
    }
    if(! $selected)
        $account_list = '<option selected></option>' . $account_list;
    else
        $account_list = '<option></option>' . $account_list;

    // Users field
    $users_list = '';
    $selected = FALSE;
    foreach ($users as $user){
        if(isset($filter['users']) && $filter['users'] == $user) {
            $users_list .= '<option value="' . $user . '" selected>'. $user . '</option>';
            $selected = TRUE;
        }
        else {
            $users_list .= '<option value="' . $user . '">'. $user . '</option>';
        }
    }
    if(! $selected)
        $users_list = '<option selected></option>' . $users_list;
    else
        $users_list = '<option></option>' . $users_list;

    // Nodes field
    $node_list = '';
    $selected = FALSE;
    foreach ($nodes as $node){
        if(isset($filter['node']) && $filter['node'] == $node) {
            $node_list .= '<option value="' . $node . '" selected>' . $node . '</option>';
            $selected = TRUE;
        }
        else {
            $node_list .= '<option value="' . $node . '">'. $node . '</option>';
        }
    }
    if(! $selected)
        $node_list = '<option selected></option>' . $node_list;
    else
        $node_list = '<option></option>' . $node_list;

    $templateBuilder = new TemplateLoader("job_filter_form.html");
    $templateBuilder->setParam("CLUSTER", CLUSTER_NAME);
    $templateBuilder->setParam("ACCOUNT_SELECTS", $account_list);
    $templateBuilder->setParam("JOB_NAME", $filter['job_name'] ?? '');
    $templateBuilder->setParam("CONSTRAINTS", $filter['constraints'] ?? '');
    $templateBuilder->setParam("USER_SELECTS", $users_list);
    $templateBuilder->setParam("NODE_SELECTS", $node_list);
    $templateBuilder->setParam("ACTION", 'action=job_history&do=search');
    $templateBuilder->setParam("TIME_MIN_VALUE", $filter['start_time_value'] ?? '');
    $templateBuilder->setParam("TIME_MAX_VALUE", $filter['end_time_value'] ?? '');
    return $templateBuilder->build();
}


function get_filtered_jobs_from_slurmdb(array $jobs) : string {
    $contents = '<div>Found <span style="font-weight: bold">' . count($jobs) . ' jobs</span>.</div>';

    $contents .= <<<EOF
<div class="table-responsive">
    <table class="tableFixHead table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
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

    foreach( $jobs as $job ) {

        $contents .= "<tr>";
        $contents .=    "<td>" . $job['job_id'] . "</td>";
        $contents .=    "<td>" . $job['job_name'] . "</td>";
        $contents .=    "<td>" . $job['account'] . "</td>";
        $contents .=    "<td>" . $job['partition'] . "</td>";
        $contents .=    "<td>" . $job['user_name'] . "</td>";

        $contents .=    "<td>" . \utils\get_job_state_view($job) . "</td>";

        $contents .=    "<td>" . $job['time_start'] . "</td>";
        $contents .=    "<td>" . $job['time_elapsed'] . "</td>";
        $contents .=    "<td>" . $job['time_limit'] . "</td>";

        $contents .=    "<td>" . $job['nodes'] . "</td>";
        $contents .=    '<td><a href="?action=job&job_id=' . $job['job_id'] . '">[Details]</a></td>';

    }
    $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

    return $contents;
}