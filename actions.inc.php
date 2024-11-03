<?php

namespace action;

function get_cluster_usage(\Client $dao) : string {
    $contents = '';

    $contents .= "<h2>Current cluster usage</h2>";

    foreach ($dao->getNodeList() as $node) {
        $data = $dao->get_node_info($node);

        $templateBuilder = new \TemplateLoader("nodeinfo.html");
        $templateBuilder->setParam("NODENAME", $node);

        $templateBuilder->setParam("CPU_PERCENTAGE", $data["nodes"][0]["alloc_cpus"]/$data["nodes"][0]["cpus"]*100);
        $templateBuilder->setParam("CPU_USED", $data["nodes"][0]["alloc_cpus"]);
        $templateBuilder->setParam("CPU_TOTAL", $data["nodes"][0]["cpus"]);

        $templateBuilder->setParam("MEM_PERCENTAGE", ($data["nodes"][0]["real_memory"]-$data["nodes"][0]["free_mem"]["number"])/$data["nodes"][0]["real_memory"]*100);
        $templateBuilder->setParam("MEM_USED", $data["nodes"][0]["real_memory"] - $data["nodes"][0]["free_mem"]["number"]);
        $templateBuilder->setParam("MEM_TOTAL", $data["nodes"][0]["real_memory"]);

        $gres = $data["nodes"][0]["gres"];
        $gres_used = $data["nodes"][0]["gres_used"];
        if($gres == ""){
            $gpus = 0;
            $gpus_used = 0;
            $gpus_percentage = 0;
        }
        else {
            $gpus = preg_replace('/.*gpu:(\d+).*|.*gpu:\(null\):(\d+).*/', '$1$2', $gres);
            $gpus_used = preg_replace('/.*gpu:(\d+).*|.*gpu:\(null\):(\d+).*/', '$1$2', $gres_used);
            $gpus_percentage = (int)$gpus_used / (int)$gpus * 100;
        }
        $templateBuilder->setParam("GPU_PERCENTAGE", $gpus_percentage);
        $templateBuilder->setParam("GPU_USED", $gpus_used);
        $templateBuilder->setParam("GPU_TOTAL", $gpus);

        $templateBuilder->setParam("STATE", implode(", ", $data["nodes"][0]["state"]));
        $state_color = "#f9c98f"; # orange
        if(
            in_array("IDLE", $data["nodes"][0]["state"]) ||
            in_array("MIX", $data["nodes"][0]["state"]) ||
            in_array("MIXED", $data["nodes"][0]["state"]) ||
            in_array("ALLOC", $data["nodes"][0]["state"]) ||
            in_array("ALLOCATED", $data["nodes"][0]["state"])
        ){
            $state_color = "#c1dead"; # green
        }
        elseif (
            in_array("DOWN", $data["nodes"][0]["state"]) ||
            in_array("DRAIN", $data["nodes"][0]["state"]) ||
            in_array("DRAINED", $data["nodes"][0]["state"]) ||
            in_array("DRAINING", $data["nodes"][0]["state"]) ||
            in_array("FAIL", $data["nodes"][0]["state"])
        ) {
            $state_color = "#deadae"; # Red
        }
        $templateBuilder->setParam("STATE_COLOR", $state_color);

        $templateBuilder->setParam("ARCHITECTURE", $data["nodes"][0]["architecture"]);
        $templateBuilder->setParam("BOARDS", $data["nodes"][0]["boards"]);

        $feature_str = "";
        foreach ($data["nodes"][0]["features"] as $feature){
            $feature_str .= '<span class="feature">' . $feature . '</span> ';
        }
        $templateBuilder->setParam("FEATURES", $feature_str);

        $feature_str = "";
        foreach ($data["nodes"][0]["active_features"] as $feature){
            $feature_str .= '<span class="feature">' . $feature . '</span> ';
        }
        $templateBuilder->setParam("ACTIVE_FEATURES", $feature_str);

        $templateBuilder->setParam("ADDRESS", $data["nodes"][0]["address"]);
        $templateBuilder->setParam("HOSTNAME", $data["nodes"][0]["hostname"]);
        $templateBuilder->setParam("OPERATING_SYSTEM", $data["nodes"][0]["operating_system"]);
        $templateBuilder->setParam("OWNER", $data["nodes"][0]["owner"]);
        $templateBuilder->setParam("TRES", $data["nodes"][0]["tres"]);
        $templateBuilder->setParam("TRES_USED", $data["nodes"][0]["tres_used"]);

        $contents .= $templateBuilder->build();
    }

    return $contents;
}



function get_slurm_job_info(\Client $dao, string|int $job_id) : string {
    $contents = '';

    $contents .= "<h2>Job " . $job_id . "</h2>";
    $query = $dao->get_job($job_id);
    if(count($query['jobs']) == 0){
        $contents .= "<p>Job " . $job_id . " not in active queue any more.</p>";
    }
    else {
        $contents .= '<h3>Job queue information</h3>';

        $job_id = $query['jobs'][0]['job_id'];
        $job_name = $query['jobs'][0]['name'];
        $job_state_text = \utils\get_job_state_view($query['jobs'][0]);

        $user = $query['jobs'][0]['user_name'] . " (" . $query['jobs'][0]['user_id'] . ')';
        $group = $query['jobs'][0]['group_name'] . " (" . $query['jobs'][0]['group_id'] . ')';
        $account = $query['jobs'][0]['account'];
        $partitions = $query['jobs'][0]['partition'];
        $priority = \utils\get_number_if_defined($query['jobs'][0]['priority']);
        $submit_line = $query['jobs'][0]['command'] ?? "";
        $working_directory = $query['jobs'][0]['current_working_directory'] ?? "";
        $comment = $query['jobs'][0]['comment'];
        $exit_code = \utils\read_exit_code($query['jobs'][0]);
        $schednodes = $query['jobs'][0]['scheduled_nodes'] ?? '';
        $reqnodes = $query['jobs'][0]['required_nodes'] ?? '';
        $nodes = \utils\get_nodes($query['jobs'][0]);
        $qos = $query['jobs'][0]['qos'];
        $container = $query['jobs'][0]['container'];
        $container_id = $query['jobs'][0]['container_id'] ?? "undefined";
        $allocating_node = $query['jobs'][0]['allocating_node'] ?? "undefined";
        $flags = $query['jobs'][0]['flags'] ?? "undefined";
        $cores_per_socket = \utils\get_number_if_defined($query['jobs'][0]['cores_per_socket']);
        $cpus_per_task = \utils\get_number_if_defined($query['jobs'][0]['cpus_per_task']);
        $deadline = \utils\get_number_if_defined($query['jobs'][0]['deadline']);
        $dependency = $query['jobs'][0]['dependency'] ?? "undefined";
        $features = $query['jobs'][0]['features'] ?? "-";
        $gres_detail = isset($query['jobs'][0]['gres_detail']) ? implode(",", $query['jobs'][0]['gres_detail']) : "none";
        $cpus = \utils\get_number_if_defined($query['jobs'][0]['cpus']);
        $node_count = \utils\get_number_if_defined($query['jobs'][0]['node_count']);
        $tasks = \utils\get_number_if_defined($query['jobs'][0]['tasks']);
        $memory_per_cpu = \utils\get_number_if_defined($query['jobs'][0]['memory_per_cpu']);
        $memory_per_node = \utils\get_number_if_defined($query['jobs'][0]['memory_per_node']);
        $requeue = $query['jobs'][0]['requeue'] ? 'yes' : 'no';
        $submit_time = \utils\get_date_from_unix_if_defined($query['jobs'][0], 'submit_time');
        $time_limit = \utils\get_timelimit_if_defined($query['jobs'][0], 'time_limit');

        $templateBuilder = new \TemplateLoader("jobinfo.html");
        $templateBuilder->setParam("JOBID", $job_id);
        $templateBuilder->setParam("JOBNAME", $job_name);
        $templateBuilder->setParam("USER", $user);
        $templateBuilder->setParam("GROUP", $group);
        $templateBuilder->setParam("ACCOUNT", $account);
        $templateBuilder->setParam("PARTITIONS", $partitions);
        $templateBuilder->setParam("PRIORITY", $priority);
        $templateBuilder->setParam("SUBMIT_LINE", $submit_line);
        $templateBuilder->setParam("WORKING_DIRECTORY", $working_directory);
        $templateBuilder->setParam("COMMENT", $comment);
        $templateBuilder->setParam("EXIT_CODE", $exit_code);
        $templateBuilder->setParam("SCHEDNODES", $schednodes);
        $templateBuilder->setParam("REQNODES", $reqnodes);
        $templateBuilder->setParam("NODES", $nodes);
        $templateBuilder->setParam("QOS", $qos);
        $templateBuilder->setParam("CONTAINER", $container);
        $templateBuilder->setParam("CONTAINER_ID", $container_id);
        $templateBuilder->setParam("ALLOCATING_NODE", $allocating_node);
        $templateBuilder->setParam("FLAGS", implode('<br>', $flags));
        $templateBuilder->setParam("CORES_PER_SOCKET", $cores_per_socket);
        $templateBuilder->setParam("CPUS_PER_TASK", $cpus_per_task);
        $templateBuilder->setParam("DEADLINE", $deadline);
        $templateBuilder->setParam("DEPENDENCY", $dependency);
        $templateBuilder->setParam("FEATURES", $features);
        $templateBuilder->setParam("GRES_DETAIL", $gres_detail);
        $templateBuilder->setParam("CPUS", $cpus);
        $templateBuilder->setParam("NODE_COUNT", $node_count);
        $templateBuilder->setParam("TASKS", $tasks);
        $templateBuilder->setParam("MEMORY_PER_CPU", $memory_per_cpu);
        $templateBuilder->setParam("MEMORY_PER_NODE", $memory_per_node);
        $templateBuilder->setParam("REQUEUE", $requeue);
        $templateBuilder->setParam("SUBMIT_TIME", $submit_time);
        $templateBuilder->setParam("TIME_LIMIT", $time_limit);
        $templateBuilder->setParam("JOB_STATE", $job_state_text);

        $contents .= $templateBuilder->build();
    }

    return $contents;
}

function get_slurmdb_job_info(\Client $dao, string|int $job_id) : string {
    $contents = '';

    # SLURMDB information
    $query = $dao->get_job_from_slurmdb($job_id);
    if(count($query['jobs']) == 0){
        $contents .= "<p>Job " . $job_id . " not found in <span class='monospaced'>slurmdb</span>.</p>";
    }
    else {
        $contents .= '<h3>Slurmdb information</h3>';

        $job_id = $query['jobs'][0]['job_id'];
        $job_name = $query['jobs'][0]['name'];
        $job_state_text = \utils\get_job_state_view($query['jobs'][0], 'state', 'current');

        $user = $query['jobs'][0]['user'];
        $group = $query['jobs'][0]['group'];
        $account = $query['jobs'][0]['account'];
        $partitions = $query['jobs'][0]['partition'];
        $priority = \utils\get_number_if_defined($query['jobs'][0]['priority']);
        $submit_line = $query['jobs'][0]['submit_line'];
        $working_directory = $query['jobs'][0]['current_working_directory'] ?? "";

        $comment = '<ul>';
        if($query['jobs'][0]['comment']['administrator'] != '')
            $comment .= '<li><b>Admin comment:</b> ' .$query['jobs'][0]['comment']['administrator'] . '</li>';
        if($query['jobs'][0]['comment']['job'] != '')
            $comment .= '<li><b>Job comment:</b> ' .$query['jobs'][0]['comment']['job'] . '</li>';
        if($query['jobs'][0]['comment']['system'] != '')
            $comment .= '<li><b>System comment:</b> ' .$query['jobs'][0]['comment']['system'] . '</li>';
        $comment .= '</ul>';

        $exit_code = \utils\read_exit_code($query['jobs'][0]);
        $nodes = $query['jobs'][0]['nodes'];
        $qos = $query['jobs'][0]['qos'];
        $container = $query['jobs'][0]['container'];
        $flags = $query['jobs'][0]['flags'] ?? array();

        $gres_detail = isset($query['jobs'][0]['used_gres']) ? $query['jobs'][0]['used_gres'] : "none";
        $tres_detail = '';
        if(isset($query['jobs'][0]['tres']) && isset($query['jobs'][0]['tres']['allocated'])){
            $tres_detail .= '<b>Allocated:</b><ul>';
            foreach($query['jobs'][0]['tres']['allocated'] as $tres){
                $tres_detail .= '<li>Name: ' . $tres['name'] . ', type: ' . $tres['type'] . ', count: ' . $tres['count'] . '</li>';
            }
            $tres_detail .= '</ul>';
        }
        if(isset($query['jobs'][0]['tres']) && isset($query['jobs'][0]['tres']['requested'])){
            $tres_detail .= '<b>Requested:</b><ul>';
            foreach($query['jobs'][0]['tres']['requested'] as $tres){
                $tres_detail .= '<li>Name: ' . $tres['name'] . ', type: ' . $tres['type'] . ', count: ' . $tres['count'] . '</li>';
            }
            $tres_detail .= '</ul>';
        }

        $submit_time = \utils\get_date_from_unix($query['jobs'][0]['time'], 'submission');
        $time_limit = \utils\get_timelimit_if_defined($query['jobs'][0]['time'], 'limit');
        $time_elapsed = \utils\get_elapsed_time($query['jobs'][0]['time'], 'elapsed');
        $time_start = \utils\get_date_from_unix($query['jobs'][0]['time'], 'start');
        $time_end = \utils\get_date_from_unix($query['jobs'][0]['time'], 'end');
        $time_eligible = \utils\get_date_from_unix($query['jobs'][0]['time'], 'eligible');

        $templateBuilder = new \TemplateLoader("jobinfo_slurmdb.html");
        $templateBuilder->setParam("JOBID", $job_id);
        $templateBuilder->setParam("JOBNAME", $job_name);
        $templateBuilder->setParam("USER", $user);
        $templateBuilder->setParam("GROUP", $group);
        $templateBuilder->setParam("ACCOUNT", $account);
        $templateBuilder->setParam("PARTITIONS", $partitions);
        $templateBuilder->setParam("PRIORITY", $priority);
        $templateBuilder->setParam("SUBMIT_LINE", $submit_line);
        $templateBuilder->setParam("WORKING_DIRECTORY", $working_directory);
        $templateBuilder->setParam("COMMENT", $comment);
        $templateBuilder->setParam("EXIT_CODE", $exit_code);
        $templateBuilder->setParam("NODES", $nodes);
        $templateBuilder->setParam("QOS", $qos);
        $templateBuilder->setParam("CONTAINER", $container);
        $templateBuilder->setParam("FLAGS", count($flags) > 0 ? '<li><span class="monospaced">' . implode('</li><li><span class="monospaced">', $flags) . '</span></li>' : '');
        $templateBuilder->setParam("GRES_DETAIL", $gres_detail);
        $templateBuilder->setParam("TRES_DETAIL", $tres_detail);

        $templateBuilder->setParam("SUBMIT_TIME", $submit_time);
        $templateBuilder->setParam("TIME_LIMIT", $time_limit);
        $templateBuilder->setParam("TIME_ELAPSED", $time_elapsed);
        $templateBuilder->setParam("START_TIME", $time_start);
        $templateBuilder->setParam("END_TIME", $time_end);
        $templateBuilder->setParam("TIME_ELIGIBLE", $time_eligible);

        $templateBuilder->setParam("JOB_STATE", $job_state_text);

        $contents .= $templateBuilder->build();
    }

    return $contents;
}

function get_job_queue(\Client $dao): string {
    $contents = '';

    $contents .= "<h2>Jobs</h2>";

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
    $jobs = $dao->get_jobs();
    foreach( $jobs['jobs'] as $job ) {

        $contents .= "<tr>";
        $contents .=    "<td>" . $job['job_id'] . "</td>";
        $contents .=    "<td>" . $job['name'] . "</td>";
        $contents .=    "<td>" . $job['partition'] . "</td>";
        $contents .=    "<td>" . $job['user_name'] . " (" . $job['user_id'] . ")</td>";
        $contents .=    "<td>" . \utils\get_job_state_view($job) . "</td>";
        $contents .=    "<td>" . \utils\get_date_from_unix_if_defined($job, 'start_time') . "</td>";
        $contents .=    "<td>" . \utils\get_timelimit_if_defined($job, 'time_limit', "inf") . "</td>";
        $contents .=    "<td>" . \utils\get_number_if_defined($job['node_count'], "?") . "</td>";
        $contents .=    "<td>" . \utils\get_nodes($job) . "</td>";
        $contents .= <<<EOF
<td>
    <div class="btn-group">
        <a href="?action=job&job_id={$job['job_id']}" class="btn btn-info" type="button">
            Details
        </a>
        <button type="button" class="btn btn-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="?action=cancel-job&job_id={$job['job_id']}">Cancel job</a></li>
        </ul>
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

function get_job_history(\Client $dao, array $filter) : string {
    $contents = '';

    $contents .= "<h2>Jobs</h2>";

    # Filter options:
    # - cluster (CSV list)
    # - account (CSV list)
    # - job_name (CSV list)
    # - constraints (CSV list)
    # - exit_code (numeric)
    # - partition (CSV list)
    # - state (CSV state list)
    # - start_time (UNIX timestamp)
    # - end_time (UNIX timestamp)
    # - node (node string)
    # - users (CSV user list)

    $accounts = $dao->get_account_list();
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

    $users = $dao->get_users_list();
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

    $nodes = $dao->getNodeList();
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

    $templateBuilder = new \TemplateLoader("job_filter_form.html");
    $templateBuilder->setParam("CLUSTER", CLUSTER_NAME);
    $templateBuilder->setParam("ACCOUNT_SELECTS", $account_list);
    $templateBuilder->setParam("JOB_NAME", $filter['job_name'] ?? '');
    $templateBuilder->setParam("CONSTRAINTS", $filter['constraints'] ?? '');
    $templateBuilder->setParam("USER_SELECTS", $users_list);
    $templateBuilder->setParam("NODE_SELECTS", $node_list);
    $templateBuilder->setParam("ACTION", 'action=job_history&do=search');
    $templateBuilder->setParam("TIME_MIN_VALUE", $filter['start_time_value'] ?? '');
    $templateBuilder->setParam("TIME_MAX_VALUE", $filter['end_time_value'] ?? '');
    $contents .= $templateBuilder->build();

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
    $jobs = $dao->get_jobs_from_slurmdb($filter);
    $jobs = array_reverse($jobs['jobs']); // newest entry first
    foreach( $jobs as $job ) {

        $contents .= "<tr>";
        $contents .=    "<td>" . $job['job_id'] . "</td>";
        $contents .=    "<td>" . $job['name'] . "</td>";
        $contents .=    "<td>" . $job['account'] . "</td>";
        $contents .=    "<td>" . $job['partition'] . "</td>";
        $contents .=    "<td>" . $job['user'] . "</td>";

        $contents .=    "<td>" . \utils\get_job_state_view($job, 'state', 'current') . "</td>";


        $contents .=    "<td>" . \utils\get_date_from_unix($job['time'], 'start') . "</td>";
        $contents .=    "<td>" . \utils\get_elapsed_time($job['time'], 'elapsed') . "</td>";
        $contents .=    "<td>" . \utils\get_timelimit_if_defined($job['time'], 'limit', 'inf') . "</td>";

        $contents .=    "<td>" . $job['nodes'] . "</td>";
        // TODO: Provide the cancel option only if job is still active
        $contents .= <<<EOF
<td>
    <div class="btn-group">
        <a href="?action=job&job_id={$job['job_id']}" class="btn btn-info" type="button">
            Details
        </a>
        <button type="button" class="btn btn-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#">Cancel job</a></li>
        </ul>
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


function get_user_list(\Client $dao) : string {
    $contents = '';

    $contents .= <<<EOF
<div class="table-responsive">
    <table class="tableFixHead table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Accounts</th>
                <th>Default account</th>
                <th>Admin level</th>
                <th>Full name</th>
                <th>Department</th>
                <th>E-Mail</th>
            </tr>
        </thead>
        <tbody>
EOF;


    // User is administrator and therefore allowed to visit this page.
    $users = $dao->get_users();

    $ldap_client = NULL;
    if(\auth\LDAP::is_supported()){
        try {
            $ldap_client = new \auth\LDAP();
        } catch (\Exception $e){
            addError($e->getMessage());
        }
    }

    foreach($users as $user_arr) {
        $contents .= "<tr>";
        $contents .=    "<td>" . $user_arr['name'] . "</td>";
        $contents .=    "<td><ul>";
        foreach($user_arr['associations'] as $assoc){
            if($assoc['account'] == $user_arr['default']['account'])
                $contents .= '<li><b>' . $assoc['account'] . '</b></li>';
            else
                $contents .= '<li>' . $assoc['account'] . '</li>';
        }
        $contents .=           "</ul></td>";
        $contents .=    "<td>" . $user_arr['default']['account'] . "</td>";
        global $privileged_users;
        if( implode(", ", $user_arr['administrator_level']) == 'None' && in_array($user_arr['name'], $privileged_users))
            $contents .=    "<td>Web</td>";
        else
            $contents .=    "<td>" . implode(", ", $user_arr['administrator_level']) . "</td>";

        // LDAP
        if( ! \auth\LDAP::is_supported() || $user_arr['name'] == "root" || $ldap_client === NULL ){
            $contents .= '<td colspan="4"><i>No LDAP server available</i></td>';
        }
        else {
            $ldap_data = $ldap_client->get_data_for_user($user_arr['name']);
            if($ldap_data["count"] == 0){
                $contents .= '<td colspan="4"><i>No LDAP data available</i></td>';
            }
            else {
                for($i = 0; $i < $ldap_data["count"]; $i++){
                    $contents .=    "<td>" . $ldap_data[$i]["displayname"][0] . "</td>";
                    $contents .=    "<td>" . $ldap_data[$i]["department"][0];
                    if(isset($ldap_data[$i]["departmentnumber"]) && isset($ldap_data[$i]["departmentnumber"][0])){
                        $contents .= " (" . $ldap_data[$i]["departmentnumber"][0] . ")";
                    }
                    $contents .= "</td>";
                    $contents .=    "<td>" . $ldap_data[$i]["mail"][0] . "</td>";

                    break;
                }
            }
        }
        $contents .= '</tr>';
    }

    if(isset($ldap_client))
        unset($ldap_client);

    $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

    return $contents;
}