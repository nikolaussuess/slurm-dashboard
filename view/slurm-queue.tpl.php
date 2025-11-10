<?php

namespace view\actions;

function get_slurm_queue($jobs, $exclude_p_low) : string {
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
<div class="form-check form-switch">
  <input 
        class="form-check-input" 
        type="checkbox" 
        role="switch" 
        id="show_p_low" 
        onclick="if( this.checked ) window.location.href = '?action=jobs&exclude_p_low=1'; else window.location.href = '?action=jobs';"
EOF;

    if($exclude_p_low)
        $contents .= ' checked ';

    $contents .= <<<EOF
        >
  <label 
        class="form-check-label" 
        for="show_p_low">
            Hide partition <span title="Every user can use partition p_low. The aim of the partition p_low is to use any (currently) unused resources for experiments that should finish at some point, but it does not matter when. Any normal job will have higher priority than a job in p_low and will hence interrupt (REQUEUE) these jobs."><span class="monospaced">p_low</span></span>
  </label>
</div>
EOF;


    $contents .= '<div>Found <span style="font-weight: bold">' . count($jobs) . ' jobs</span>.</div>';

    $contents .= <<<EOF
<div class="table-responsive">
    <table class="tableFixHead table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Partition</th>
                <th>User</th>
                <th>State</th>
                <th>Start time</th>
                <th>Time limit</th>
                <th># Nodes</th>
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
        $contents .=    "<td>" . $job['partition'] . "</td>";
        $contents .=    "<td>" . $job['user_name'] . " (" . $job['user_id'] . ")</td>";
        $contents .=    "<td>" . \utils\get_job_state_view($job) . "</td>";
        $contents .=    "<td>" . $job['time_start'] . "</td>";
        $contents .=    "<td>" . $job['time_limit'] . "</td>";
        $contents .=    "<td>" . ($job['node_count'] != NULL ? $job['node_count'] : "?") . "</td>";
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