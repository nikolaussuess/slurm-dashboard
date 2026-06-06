<?php

namespace view\actions;

/**
 * Renders the full job queue page including filter controls and table.
 * @param array $jobs Job array as returned by Client::get_jobs()
 * @param bool $exclude_p_low Whether to hide p_low partition jobs or not
 * @param string $nonce CSP nonce for inline scripts
 * @return string Rendered HTML
 */
// TODO: $exclude_p_low should be typed as bool or int, but call sites pass 0 (int) which PHP interpolates differently in JS strings ("0" vs ""). Fix call sites first, then add bool type hint.
function get_slurm_queue(array $jobs, $exclude_p_low, string $nonce = '') : string {
    $contents = "<h2>Jobs</h2>";

    // Allow to exclude jobs of partition p_low
    // Every user can use partition p_low. The aim of the partition p_low is to use
    // any (currently) unused resources for experiments that should finish at some point,
    // but it does not matter when. Any normal job will have higher priority than a job in
    // p_low and will hence interrupt (REQUEUE) these jobs.
    //
    // The filtering is done locally on the web server.
    // JavaScript is required.
    $contents .= <<<EOF
<div class="d-flex flex-wrap align-items-center gap-3">

  <div class="form-check form-switch m-0">
    <input 
        class="form-check-input" 
        type="checkbox" 
        role="switch" 
        id="show_p_low"
        >
    <label class="form-check-label" for="show_p_low">
      Hide partition <span class="monospaced">p_low</span>
      <button type="button"
              class="btn p-0"
              data-bs-toggle="tooltip"
              data-bs-trigger="click focus"
              data-bs-placement="top"
              title="Every user can use partition p_low. The aim of the partition p_low is to use any (currently) unused resources for experiments that should finish at some point, but it does not matter when. Any normal job will have higher priority than a job in p_low and will hence interrupt (REQUEUE) these jobs.">
          <i title="Click here for more information">&#9432;</i>
      </button>
      
    </label>
  </div>

  <div class="d-flex align-items-center gap-2 ms-auto">
    <b>Order by:</b>
    <select id="orderBySelect" class="form-select form-select-sm" style="width:auto">
      <option value="job_id">Job ID</option>
      <option value="user_name">Username</option>
      <option value="priority">Priority</option>
      <option value="time_start">Start time</option>
    </select>
  </div>

</div>
EOF;


    $contents .= '<div>Found <span style="font-weight: bold">' . count($jobs) . ' jobs</span>.</div>';

    if(isset($_GET['preferred_view']) && ($_GET['preferred_view'] === "compact" || $_GET['preferred_view'] === "table")){
        $preferred_view = $_GET['preferred_view'];
        $_SESSION['preferred_view'] = $preferred_view;
    } else {
        $preferred_view = $_SESSION['preferred_view'] ?? "compact";
    }

    if($preferred_view === "table")
        $contents .= get_slurm_queue_table($jobs);
    else
        $contents .= get_slurm_queue_compact($jobs);

    $valid_order_fields = ['job_id', 'user_name', 'priority', 'time_start'];
    $orderBy = in_array($_GET['orderby'] ?? '', $valid_order_fields, true) ? $_GET['orderby'] : 'job_id';

    $contents .= <<<EOF
<div class="d-flex align-items-center gap-2">
  <b>View:</b> 
  <span>compact</span>

  <div class="form-check form-switch m-0">
    <input class="form-check-input" type="checkbox" id="viewToggle">
    <label class="form-check-label" for="viewToggle"></label>
  </div>

  <span>old table</span>
</div>



<script nonce="$nonce">
    const toggle = document.getElementById("viewToggle");
    const orderBySelect = document.getElementById("orderBySelect");
    const togglePLow = document.getElementById("show_p_low");
    toggle.checked = ("$preferred_view" === "table");
    togglePLow.checked = ("$exclude_p_low" === "1");
    orderBySelect.value = "$orderBy";

    
    toggle.addEventListener("change", function () {
        // Build new URL
        const newView = this.checked ? "table" : "compact";
        const url = `?action=jobs`+
                    `&exclude_p_low=$exclude_p_low`+
                    `&preferred_view=`+newView+
                    `&orderby=`+orderBySelect.value;

        window.location.href = url;
    });
    
    togglePLow.addEventListener("change", function () {
        // Build new URL
        const newView = this.checked ? "1" : "0";
        const url = `?action=jobs`+
                    `&exclude_p_low=`+newView+
                    `&preferred_view=$preferred_view`+
                    `&orderby=`+orderBySelect.value;

        window.location.href = url;
    });
    
    orderBySelect.addEventListener("change", function () {
        // Build new URL
        const newView = this.value;
        const url = `?action=jobs`+
                    `&exclude_p_low=$exclude_p_low`+
                    `&preferred_view=$preferred_view`+
                    `&orderby=`+newView;

        window.location.href = url;
    });
    
    
    // To allow to click on the table column headers for sorting
    function setOrderBy(value) {
        orderBySelect.value = value;
        orderBySelect.dispatchEvent(new Event("change", { bubbles: true }));
    }
</script>

EOF;


    return $contents;
}


/**
 * Renders the job queue as a HTML table.
 * @param array $jobs Job array as returned by Client::get_jobs()
 * @return string Rendered HTML
 */
function get_slurm_queue_table(array $jobs) : string {
    $active_states = ['PENDING', 'RUNNING', 'COMPLETING', 'CONFIGURING', 'SUSPENDED', 'REQUEUED'];

    $contents = <<<EOF
<div class="table-responsive tableFixHead">
    <table class="table" id="jobtable-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Partition</th>
                <th>User</th>
                <th>State</th>
                <th>Start time</th>
                <th>Time limit</th>
                <th>Nodelist</th>
                <th title="Calculated priority">PR</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
EOF;

    foreach( $jobs as $job ) {

        $job_name_e = htmlspecialchars($job['job_name'], ENT_QUOTES, 'UTF-8');
        $partition_e = htmlspecialchars($job['partition'], ENT_QUOTES, 'UTF-8');
        $user_e     = htmlspecialchars($job['user_name'], ENT_QUOTES, 'UTF-8');
        $user_id_e  = htmlspecialchars((string)$job['user_id'], ENT_QUOTES, 'UTF-8');
        $contents .= "<tr>";
        $contents .=    "<td>" . $job['job_id'] . "</td>";
        $contents .=    "<td class='breakable'>" . $job_name_e . "</td>";
        $contents .=    "<td>" . $partition_e . "</td>";
        $contents .=    '<td title="' . $user_e . " (" . $user_id_e . ') ">' . $user_e . "</td>";
        $contents .=    "<td>" . \utils\get_job_state_view($job) . "</td>";
        $contents .=    "<td>" . $job['time_start'] . "</td>";
        $contents .=    "<td>" . $job['time_limit'] . "</td>";
        $contents .=    "<td>";
        if($job['nodes'] != '?')
            $contents .= htmlspecialchars($job['nodes'], ENT_QUOTES, 'UTF-8');
        else
            $contents .= '<span title="Not yet scheduled. Showing node count instead." class="node-count">' .
                ($job['node_count'] != NULL ? $job['node_count'] : "?") . '</span>';
        $contents .= "</td>";
        $contents .= '<td>' . (int)$job['priority'] . '</td>';
        $contents .= <<<EOF
<td>
    <div class="btn-group">
        <a href="?action=job&job_id={$job['job_id']}" class="btn btn-info" type="button">
            Details
        </a>
        <button type="button" class="btn btn-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
EOF;
        // $job['user_name'] may be empty if the user does not exist on slurmrestd host —
        // in this case the configuration does not allow to determine the user name.
        if (\client\utils\jwt\JwtAuthentication::is_supported() && !empty(array_intersect($job['job_state'], $active_states)) && !empty($job['user_name']) && (\auth\current_user_is_admin() || $job['user_name'] === $_SESSION['USER']))
            $contents .= render_job_action_dropdown($job['job_id'], FALSE);
        elseif (!\client\utils\jwt\JwtAuthentication::is_supported() || empty($job['user_name']))
            $contents .= render_job_action_dropdown($job['job_id'], TRUE, 'This feature is not supported by the current configuration.');
        elseif (empty(array_intersect($job['job_state'], $active_states)))
            $contents .= render_job_action_dropdown($job['job_id'], TRUE, 'Job is no longer active.');
        else
            $contents .= render_job_action_dropdown($job['job_id'], TRUE, 'You are not authorized to cancel or edit this job.');

        $contents .= <<<EOF
    </div>
</td>
EOF;

    }
    $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

    return $contents;
}

/**
 * Renders the job queue in compact view.
 * @param array $jobs Job array as returned by Client::get_jobs()
 * @return string Rendered HTML
 */
function get_slurm_queue_compact(array $jobs) : string {
    $active_states = ['PENDING', 'RUNNING', 'COMPLETING', 'CONFIGURING', 'SUSPENDED', 'REQUEUED'];

    $contents = <<<EOF
<div id="jobtable-compact" class="table-responsive tableFixHead">
    <table class="table">
        <thead>
            <tr>
                <th>Job ID</th>
                <th rowspan="2">Name</th>
                <th>Partition</th>
                <th>Start time</th>
                <th>State</th>
                <th rowspan="2"></th>
            </tr>
            <tr>
                <th>Priority</th>
                <th>User</th>
                <th>Time limit</th>
                <th>Nodelist</th>
            </tr>
        </thead>
        <tbody>
EOF;

    foreach( $jobs as $job ) {

        $job_name_e = htmlspecialchars($job['job_name'], ENT_QUOTES, 'UTF-8');
        $partition_e = htmlspecialchars($job['partition'], ENT_QUOTES, 'UTF-8');
        $user_e     = htmlspecialchars($job['user_name'], ENT_QUOTES, 'UTF-8');
        $user_id_e  = htmlspecialchars((string)$job['user_id'], ENT_QUOTES, 'UTF-8');
        $contents .= "<tr>";
        $contents .=    "<td title='Job-ID'>" . $job['job_id'] . "</td>";
        $contents .=    "<td class='breakable' rowspan='2'>" . $job_name_e . "</td>";
        $contents .=    '<td>' . $partition_e . "</td>";
        $contents .=    "<td>" . $job['time_start'] . "</td>";
        $contents .=    "<td>" . \utils\get_job_state_view($job) . "</td>";

        $contents .= <<<EOF
<td rowspan="2">
    <div class="btn-group">
        <a href="?action=job&job_id={$job['job_id']}" class="btn btn-info" type="button">
            Details
        </a>
        <button type="button" class="btn btn-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
EOF;
        // $job['user_name'] may be empty if the user does not exist on slurmrestd host —
        // in this case the configuration does not allow to determine the user name.
        if (\client\utils\jwt\JwtAuthentication::is_supported() && !empty(array_intersect($job['job_state'], $active_states)) && !empty($job['user_name']) && (\auth\current_user_is_admin() || $job['user_name'] === $_SESSION['USER']))
            $contents .= render_job_action_dropdown($job['job_id'], FALSE);
        elseif (!\client\utils\jwt\JwtAuthentication::is_supported() || empty($job['user_name']))
            $contents .= render_job_action_dropdown($job['job_id'], TRUE, 'This feature is not supported by the current configuration.');
        elseif (empty(array_intersect($job['job_state'], $active_states)))
            $contents .= render_job_action_dropdown($job['job_id'], TRUE, 'Job is no longer active.');
        else
            $contents .= render_job_action_dropdown($job['job_id'], TRUE, 'You are not authorized to cancel or edit this job.');

        $contents .= <<<EOF
    </div>
</td>

    <tr>
EOF;
        $contents .=    '<td title="Calculated job priority">' . (int)$job['priority'] . '</td>';
        $contents .=    '<td title="' . $user_e . " (" . $user_id_e . ') ">' . $user_e . "</td>";
        $contents .=    "<td>" . $job['time_limit'] . "</td>";

        $contents .=    "<td>";
        if($job['nodes'] != '?')
            $contents .= htmlspecialchars($job['nodes'], ENT_QUOTES, 'UTF-8');
        else
            $contents .= '<span title="Not yet scheduled. Showing node count instead." class="node-count">' .
                         ($job['node_count'] != NULL ? $job['node_count'] : "?") . '</span>';
        $contents .= "</td>";

        $contents .= <<<EOF
    </tr>
EOF;

    }
    $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

    return $contents;
}