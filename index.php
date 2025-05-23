<?php
#error_reporting(E_ALL);
#ini_set('display_errors', '1');
session_start();
date_default_timezone_set('Europe/Vienna');

require_once 'TemplateLoader.inc.php';
require_once 'client.inc.php';
require_once 'globals.inc.php';
require_once 'auth/auth.inc.php';
require_once 'utils.inc.php';

$dao = new Client();
$title = "Clusterinfo " . CLUSTER_NAME;
$contents = "";

if( isset($_GET['action']) && $_GET['action'] == "logout"){
    session_destroy();
    unset($_SESSION['USER']);
}

// Check if the socket exists and add a warning otherwise
if( ! Client::socketExists() ){
    addError("Cannot create socket. Is <kbd>slurmrestd</kbd> running? Please report this issue to " . ADMIN_EMAIL);
}

if(!isset($_SESSION['USER'])) {

    if(isset($_GET['action']) && $_GET['action'] == "login"){
        if( !isset($_POST['username']) || !isset($_POST['password'])){
            addError("Login failed.");
        }
        else {
            $username = $_POST['username'];
            $password = $_POST['password'];
            $method = $_POST['method'];
            if(auth($username, $password, $method)){
                $_SESSION['USER'] = $username;
                addSuccess("Login successful!");
            }
            else {
                addError("Login failed.");
            }
        }
    }
    // Is set above if the login was successful.
    // Otherwise, the login form is displayed again.
    if( ! isset($_SESSION['USER']) && (!isset($_GET['action']) || $_GET['action'] != "about")) {

        $methods_string = '';
        foreach(\auth\get_methods() as $method => $settings ){
            $methods_string .= '<option value="' . $method . '"';
            if(isset($settings['default']) &&  $settings['default'] === TRUE){
                 $methods_string .= ' selected ';
            }

            if( ! isset($settings['supported']) ||  $settings['supported'] !== TRUE){
                $methods_string .= ' disabled ';
            }

            $methods_string .= '>' . $method . '</option>';
        }

        $templateBuilder = new TemplateLoader("loginForm.html");
        $templateBuilder->setParam("action", "login");
        $templateBuilder->setParam("buttontext", "Login");
        $templateBuilder->setParam("methods", $methods_string);
        $contents .= $templateBuilder->build();
    }
}

# About page
if(isset($_GET['action']) && $_GET['action'] == "about"){
    $title = "About the cluster " . CLUSTER_NAME;

    $templateBuilder = new TemplateLoader("about.html");
    $templateBuilder->setParam("CLUSTER_NAME", CLUSTER_NAME);
    $templateBuilder->setParam("ADMIN_NAMES", ADMIN_NAMES);
    $templateBuilder->setParam("ADMIN_EMAIL", ADMIN_EMAIL);
    $templateBuilder->setParam("SLURM_LOGIN_NODE", SLURM_LOGIN_NODE);
    $templateBuilder->setParam("WIKI_LINK", WIKI_LINK);
    $contents .= $templateBuilder->build();
}

# User is logged in
if( isset($_SESSION['USER']) ){

    $action = $_GET['action'] ?? "usage";
    if( $action == "login") $action = "usage";

    // Show maintenance dates if there are some
    $maintenances = $dao->get_maintenances();
    if(! empty($maintenances)){
        $contents .= '<div class="alert alert-info" role="alert"><strong>Scheduled maintenances:</strong><ul>';
    }
    foreach( $maintenances as $maintenance ){
        $contents .= '<li>Node(s) ';
        if(isset($maintenance['node_list']))
            $contents .= '<span class="monospaced">' . $maintenance['node_list'] . '</span>';
        else
            $contents .= '(any)';
        $contents .= " will be unavailable from " . \utils\get_date_from_unix_if_defined($maintenance, 'start_time')
            . " until " . \utils\get_date_from_unix_if_defined($maintenance, 'end_time') . ".";
        $contents .= '</li>';
    }
    if(! empty($maintenances)){
        $contents .= '</ul><p>All jobs that are guaranteed to end before the maintenance window due to the time limit are scheduled normally. Jobs that are not guaranteed to end before the start of the maintenance window can only start after the maintenance window on affected nodes. Tip: You could run shorter jobs for the time being, use breakpoints to interrupt your job for maintenance or use other nodes that are not affected from maintenance.</p></div>';
    }
    // END of maintenance

    switch($action){

        case "usage":
            $contents .= "<h2>Current cluster usage</h2>";

            foreach ($dao->getNodeList() as $node) {
                $data = $dao->get_node_info($node);

                $templateBuilder = new TemplateLoader("nodeinfo.html");
                $templateBuilder->setParam("NODENAME", $node);

                $templateBuilder->setParam("CPU_PERCENTAGE", $data["nodes"][0]["alloc_cpus"]/$data["nodes"][0]["cpus"]*100);
                $templateBuilder->setParam("CPU_USED", $data["nodes"][0]["alloc_cpus"]);
                $templateBuilder->setParam("CPU_TOTAL", $data["nodes"][0]["cpus"]);

                $templateBuilder->setParam("MEM_PERCENTAGE", ($data["nodes"][0]["real_memory"]-$data["nodes"][0]["free_mem"]["number"])/$data["nodes"][0]["real_memory"]*100);
                $templateBuilder->setParam("MEM_USED", $data["nodes"][0]["real_memory"] - $data["nodes"][0]["free_mem"]["number"]);
                $templateBuilder->setParam("MEM_TOTAL", $data["nodes"][0]["real_memory"]);
                $templateBuilder->setParam("ALLOC_MEM_PERCENTAGE", ($data["nodes"][0]["alloc_memory"])/$data["nodes"][0]["real_memory"]*100);
                $templateBuilder->setParam("ALLOC_MEM", $data["nodes"][0]["alloc_memory"]);

                $gres = $data["nodes"][0]["gres"];
                $gres_used = $data["nodes"][0]["gres_used"];
                if($gres == ""){
                    $gpus = 0;
                    $gpus_used = 0;
                    $gpus_percentage = 0;
                }
                else {

                    $gpus = preg_replace('/.*:(\d+)(?:\(.*\))?$/', '$1', $gres);
                    $gpus_used = preg_replace('/.*:(\d+)(?:\(.*\))?$/', '$1', $gres_used);
                    //$gpus = preg_replace('/.*gpu:(\d+).*|.*gpu:\(null\):(\d+).*/', '$1$2', $gres);
                    //$gpus_used = preg_replace('/.*gpu:(\d+).*|.*gpu:\(null\):(\d+).*/', '$1$2', $gres_used);
                    // For debugging
                    //echo "GPUs='$gpus', gpus_used='$gpus_used', gres='$gres', gres_used='$gres_used'";
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
                $templateBuilder->setParam("BOOT_TIME", \utils\get_date_from_unix_if_defined($data["nodes"][0], "boot_time"));
                $templateBuilder->setParam("LAST_BUSY", \utils\get_date_from_unix_if_defined($data["nodes"][0], "last_busy"));
                $templateBuilder->setParam("PARTITIONS", count($data["nodes"][0]["partitions"]) > 0 ? '<li><span class="monospaced">' . implode('</li><li><span class="monospaced">', $data["nodes"][0]["partitions"]) . '</span></li>' : '');
                $templateBuilder->setParam("RESERVATION", $data["nodes"][0]["reservation"] ?? '');
                $templateBuilder->setParam("SLURM_VERSION", $data["nodes"][0]["version"] ?? '');

                $contents .= $templateBuilder->build();
            }


            break;

        case "job":
            if( ! isset($_GET['job_id'])){
                addError("No job ID given.");
                break;
            }

            $contents .= "<h2>Job " . $_GET['job_id'] . "</h2>";
            $query = $dao->get_job($_GET['job_id']);
            if(count($query['jobs']) == 0){
                $contents .= "<p>Job " . $_GET['job_id'] . " not in active queue any more.</p>";
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

                $templateBuilder = new TemplateLoader("jobinfo.html");
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


            # SLURMDB information
            $query = $dao->get_job_from_slurmdb($_GET['job_id']);
            if(count($query['jobs']) == 0){
                $contents .= "<p>Job " . $_GET['job_id'] . " not found in <span class='monospaced'>slurmdb</span>.</p>";
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

                $templateBuilder = new TemplateLoader("jobinfo_slurmdb.html");
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

            break;

        case "jobs":
            $contents .= "<h2>Jobs</h2>";

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
            if(isset($_GET['exclude_p_low']) && $_GET['exclude_p_low'] == 1)
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

            // Filter
            // Exclude partition p_low if parameter exclude_p_low=1
            $filter = array();
            if(isset($_GET['exclude_p_low']) && $_GET['exclude_p_low'] == 1)
                $filter['exclude_p_low'] = 1;

            $jobs = $dao->get_jobs($filter);

            $contents .= '<div>Found <span style="font-weight: bold">' . count($jobs['jobs']) . ' jobs</span>.</div>';

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
                $contents .=    '<td><a href="?action=job&job_id=' . $job['job_id'] . '">[Details]</a></td>';

            }
            $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

            break;

        case 'job_history':

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
            $contents .= $templateBuilder->build();

            $jobs = $dao->get_jobs_from_slurmdb($filter);
            $jobs = array_reverse($jobs['jobs']); // newest entry first

            $contents .= '<div>Found <span style="font-weight: bold">' . count($jobs) . ' jobs</span>.</div>';

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
                $contents .=    "<td>" . $job['name'] . "</td>";
                $contents .=    "<td>" . $job['account'] . "</td>";
                $contents .=    "<td>" . $job['partition'] . "</td>";
                $contents .=    "<td>" . $job['user'] . "</td>";

                $contents .=    "<td>" . \utils\get_job_state_view($job, 'state', 'current') . "</td>";


                $contents .=    "<td>" . \utils\get_date_from_unix($job['time'], 'start') . "</td>";
                $contents .=    "<td>" . \utils\get_elapsed_time($job['time'], 'elapsed') . "</td>";
                $contents .=    "<td>" . \utils\get_timelimit_if_defined($job['time'], 'limit', 'inf') . "</td>";

                $contents .=    "<td>" . $job['nodes'] . "</td>";
                $contents .=    '<td><a href="?action=job&job_id=' . $job['job_id'] . '">[Details]</a></td>';

            }
            $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

            break;

        case 'users':
            $title = "List of users";

            // Check if user is administrator, otherwise show 403.
            if( ! \auth\current_user_is_privileged() ){
                http_response_code(403);
                $contents .= "403 Forbidden.<br>";
                $contents .= "Only admins are allowed to list all users.";
                break;
            }

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
                } catch (Exception $e){
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

            break;

        default:
            http_response_code(404);
            $contents .= "404 Not Found.";
    }
} // endif user_is_logged_in()
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <title><?php print (!empty($title) ? $title . " | " : ""); ?>Slurm Dashboard</title>

    <link rel="stylesheet" href="/lib/bootstrap/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="/lib/jquery/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="/lib/popper.js/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="/lib/bootstrap/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>

    <link rel="stylesheet" href="/style.css" crossorigin="anonymous">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>

<?php if(isset($_SESSION['USER'])): ?>
    <nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavDropdown">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 justify-content-center">
                    <li class="nav-item">
                        <a class="nav-link" href="?action=usage">Cluster Usage</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=jobs">Queue</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=job_history">Job history</a>
                    </li>
<?php
    if( \auth\current_user_is_privileged() ):
?>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=users">Users</a>
                    </li>
<?php
    endif;
?>
                </ul>
                <div class="text-end">
                    <div class="small float-lg-start" style="margin-right: 5px">Angemeldet als<br> <i><?php print $_SESSION['USER']; ?></i></div>
                    <a href="?action=logout"><button type="button" class="btn btn-warning">Logout</button></a>
                </div>
            </div>
        </div>
    </nav>
<?php endif; ?>

<div id="content">
    <div class="alert alert-danger" role="alert" style="display: <?php echo !empty($errormsg) ? "block" : "none"; ?>">
        <strong>Error:</strong>
        <ul>
            <?php print $errormsg; ?>
        </ul>
    </div>
    <div class="alert alert-success" role="alert" style="display: <?php echo !empty($successmsg) ? "block" : "none"; ?>">
        <strong>Success:</strong>
        <ul>
            <?php print $successmsg; ?>
        </ul>
    </div>

    <h1><?php print (!empty($title) ? $title : "Slurm Dashboard"); ?></h1>

    <?php
    print $contents;
    ?>
</div>

    <hr>
    <footer>
        &copy; 2024-2025 by <a href="https://suess.dev/" target="_blank">Nikolaus Süß</a>, University of Vienna<br>
        Source code available on <a href="https://github.com/nikolaussuess/slurm-dashboard" target="_blank">GitHub</a>.
    </footer>

</body>
</html>

