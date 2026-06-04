<?php

namespace client;

require_once __DIR__ . '/../exceptions/MissingArrayKeyException.php';
require_once __DIR__ . '/../exceptions/RequestFailedException.inc.php';

use exceptions\MissingArrayKeyException;
use exceptions\RequestFailedException;

/**
 * Abstract implementation of common functions of versions
 * - v0.0.40
 * - v0.0.43
 *
 * NOTE: This class might be renamed in the future, e.g. to Common_V0040_V0043_AbstractClient.
 */
abstract class AbstractClient implements Client {

    abstract protected function get_nodes(array $job_arr) : string;

    // There is too much difference and a bug in v0.0.40 for /shares
    // so that a general implementation does not really make sense ...
    abstract function get_fairshare(?string $user_name) : array;

    function is_available() : bool {
        return RequestFactory::socket_exists();
    }

    function getNodeList(): array{
        $json = RequestFactory::newRequest()->request_json("nodes", "slurm", static::api_version, 300);
        if (!array_key_exists('nodes', $json) || empty($json['nodes'])) {
            throw new RequestFailedException(
                "Could not retrieve node list. slurmctld may be down.",
                "Response of GET /nodes does not contain a 'nodes' key or array is empty. " . $this->_response_debug_info($json)
            );
        }
        return array_column($json['nodes'], 'name');
    }

    function get_jobs(?array $filter = NULL): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/jobs
        $json = RequestFactory::newRequest()->request_json("jobs", 'slurm', static::api_version);
        if (!array_key_exists('jobs', $json)) {
            throw new RequestFailedException(
                "Could not retrieve job list. slurmctld may be down.",
                "Response of GET /jobs does not contain a 'jobs' key. " . $this->_response_debug_info($json)
            );
        }

        // Exclude partition p_low if parameter exclude_p_low=1
        if($filter != NULL){
            if(isset($filter['exclude_p_low']) && $filter['exclude_p_low'] == 1){
                $jobs_array = $this->_feature_exclude_p_low($json['jobs']);
                $json['jobs'] = $jobs_array;
            }
        }

        $jobs = [];
        foreach ($json['jobs'] as $json_job){

            $job = array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'partition'  => $json_job['partition'],
                'job_state'  => $json_job['job_state'], // Array of strings
                'user_name'  => $json_job['user_name'],
                'user_id'    => $json_job['user_id'],
                'nodes'      => $this->get_nodes($json_job),
                'node_count' => $json_job['node_count']['set'] ? $json_job['node_count']['number'] : NULL,
                'time_limit' => $this->_get_timelimit_if_defined($json_job, 'time_limit'),
                'time_start' => $this->_get_date_from_unix_if_defined($json_job, 'start_time'),
                'priority'   => $this->_get_number_if_defined($json_job['priority'], ''),
            );
            $jobs[] = $job;
        }

        // Order by some parameter
        if($filter != NULL){
            if(isset($filter['orderby']) && in_array($filter['orderby'], array('job_id', 'user_name', 'priority', 'time_start'))){
                $jobs = $this->_slurm_queue_order_by($jobs, $filter['orderby']);
            }
        }

        return $jobs;
    }

    function get_jobs_from_slurmdb(?array $filter = NULL) : array {
        $query_string = '?skip_steps=true';
        if($filter != NULL){
            if(isset($filter['start_time'])){
                $query_string .= '&start_time=uts' . (int)$filter['start_time'];
            }
            if(isset($filter['end_time'])){
                $query_string .= '&end_time=uts' . (int)$filter['end_time'];
            }
            if(isset($filter['users'])){
                // Repeated parameters (users=a&users=b): slurmrestd uses array
                // serialization here despite documenting a "CSV list".
                foreach($filter['users'] as $u){
                    $query_string .= '&users=' . urlencode($u);
                }
            }
            if(isset($filter['account'])){
                foreach($filter['account'] as $a){
                    $query_string .= '&account=' . urlencode($a);
                }
            }
            if(isset($filter['partition'])){
                foreach($filter['partition'] as $p){
                    $query_string .= '&partition=' . urlencode($p);
                }
            }
            if(isset($filter['node'])){
                foreach($filter['node'] as $n){
                    $query_string .= '&node=' . urlencode($n);
                }
            }
            if(isset($filter['job_name'])){
                $query_string .= '&job_name=' . urlencode($filter['job_name']);
            }
            if(isset($filter['constraints'])){
                $query_string .= '&constraints=' . urlencode($filter['constraints']);
            }
            if(isset($filter['state'])){
                /*
                 * Issue 12 specific code
                 * See https://github.com/nikolaussuess/slurm-dashboard/issues/12
                 */
                $broken = ['COMPLETED', 'FAILED', 'TIMEOUT', 'OUT_OF_MEMORY'];
                if(count($filter['state']) === 1 && !in_array($filter['state'][0], $broken, TRUE)){
                    $query_string .= '&state=' . urlencode($filter['state'][0]);
                }
                // ORIGINAL CODE:
                // $query_string .= '&state=' . $filter['state'];
                // END ISSUE 12
            }
        }

        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/jobs
        // FALSE: slurmdb/jobs responses can be very large, causing cache store to trigger an OOM fatal error.
        $json = RequestFactory::newRequest()->request_json("jobs" . $query_string, 'slurmdb', static::api_version, FALSE);
        if (!array_key_exists('jobs', $json)) {
            throw new RequestFailedException(
                "Could not retrieve job list. slurmdbd may be down.",
                "Response of GET /jobs does not contain a 'jobs' key. " . $this->_response_debug_info($json)
            );
        }

        /*
         * Issue 12 specific code
         * See https://github.com/nikolaussuess/slurm-dashboard/issues/12
         * RUNNING works on server side; COMPLETED/FAILED/TIMEOUT/OUT_OF_MEMORY do not.
         * Multiple selected states are also always post-filtered here.
         */
        if(isset($filter['state'])){
            $broken = ['COMPLETED', 'FAILED', 'TIMEOUT', 'OUT_OF_MEMORY'];
            if(count($filter['state']) > 1 || in_array($filter['state'][0], $broken, TRUE)){
                $json['jobs'] = $this->_issue12_bugfix_post_request_filtering($json['jobs'], $filter['state']);
            }
        }
        // END Issue 12



        $jobs = [];
        foreach ($json['jobs'] as $json_job){

            $job = array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'job_state'  => $json_job['state']['current'], // Array of strings
                'user_name'  => $json_job['user'],
                'account'    => $json_job['account'],
                'partition'  => $json_job['partition'],
                'time_limit' => $this->_get_timelimit_if_defined($json_job['time'], 'limit', 'infinite'),
                'time_start' => $this->_get_date_from_unix($json_job['time'], 'start'),
                'time_elapsed' => $this->_get_elapsed_time($json_job['time']),
                'nodes'      => $json_job['nodes']
            );
            $jobs[] = $job;
        }

        return array_reverse($jobs); // newest entry first
    }

    function get_account_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/accounts
        $json = RequestFactory::newRequest()->request_json("accounts", 'slurmdb', static::api_version, 900);
        // Accounts are not technically required by Slurm, so we silently ignore this possible error.
        if (!array_key_exists('accounts', $json) || empty($json['accounts'])) {
            log_msg("GET /accounts: 'accounts' key missing or empty (slurmdbd may be down). " . $this->_response_debug_info($json));
            return [];
        }
        return array_column($json['accounts'], 'name');
    }

    function get_partition_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.43/partitions
        $json = RequestFactory::newRequest()->request_json("partitions", 'slurm', static::api_version, 900);
        if (!array_key_exists('partitions', $json) || empty($json['partitions'])) {
            throw new RequestFailedException(
                "Could not retrieve partition list. slurmctld may be down.",
                "Response of GET /partitions does not contain a 'partitions' key or array is empty. " . $this->_response_debug_info($json)
            );
        }
        return array_column($json['partitions'], 'name');
    }

    function get_users_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users
        $json = RequestFactory::newRequest()->request_json("users", 'slurmdb', static::api_version, 120);
        if (!array_key_exists('users', $json) || empty($json['users'])) {
            throw new RequestFailedException(
                "Could not retrieve user list. slurmdbd may be down.",
                "Response of GET /users does not contain a 'users' key or array is empty. " . $this->_response_debug_info($json)
            );
        }
        return array_column($json['users'], 'name');
    }

    function get_job(string $id) : ?array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.40/job/id
        $json = RequestFactory::newRequest()->request_json("job/".$id, 'slurm', static::api_version);
        if (!array_key_exists('jobs', $json)) {
            throw new RequestFailedException(
                "Could not retrieve job $id. slurmctld may be down.",
                "Response of GET /job/$id does not contain a 'jobs' key. " . $this->_response_debug_info($json)
            );
        }

        foreach ($json['jobs'] as $json_job){

            return array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'job_state'  => $json_job['job_state'], // Array of strings
                'user_name'  => $json_job['user_name'],
                'user_id'    => $json_job['user_id'],
                'group_name' => $json_job['group_name'],
                'group_id'   => $json_job['group_id'],
                'account'    => $json_job['account'],
                'partition'  => $json_job['partition'],
                'priority'   => $json_job['priority']['set'] ? $json_job['priority']['number'] : NULL,
                'submit_line'=> $json_job['command'] ?? NULL,
                'working_directory' => $json_job['current_working_directory'] ?? NULL,
                'comment'    => $json_job['comment'],
                'exit_code'  => $this->_read_exit_code($json_job),
                'scheduled_nodes' => $json_job['scheduled_nodes'] ?? NULL,
                'required_nodes' => $json_job['required_nodes'] ?? NULL,
                'nodes'      => $this->get_nodes($json_job),
                'qos'        => $json_job['qos'],
                'nice'       => $json_job['nice'] ?? NULL,
                'container'  => $json_job['container'],
                'container_id' => $json_job['container_id'] ?? NULL,
                'allocating_node' => $json_job['allocating_node'] ?? NULL,
                'flags'      => $json_job['flags'] ?? array(),
                'cores_per_socket' => $json_job['cores_per_socket']['set'] ? $json_job['cores_per_socket']['number'] : NULL,
                'cpus_per_task' => $json_job['cpus_per_task']['set'] ? $json_job['cpus_per_task']['number'] : NULL,
                'deadline'   => $this->_get_date_from_unix_if_defined( $json_job , 'deadline', NULL) ,
                'dependency' => $json_job['dependency'] ?? NULL,
                'features'   => $json_job['features'] ?? NULL,
                'gres'       => $json_job['gres_detail'] ?? NULL,
                'cpus'       => $json_job['cpus']['set'] ? $json_job['cpus']['number'] : NULL,
                'node_count' => $json_job['node_count']['set'] ? $json_job['node_count']['number'] : NULL,
                'tasks'      => $json_job['tasks']['set'] ? $json_job['tasks']['number'] : NULL,
                'memory_per_cpu' => $json_job['memory_per_cpu']['set'] ? $json_job['memory_per_cpu']['number'] : NULL,
                'memory_per_node' => $json_job['memory_per_node']['set'] ? $json_job['memory_per_node']['number'] : NULL,
                'requeue'    => $json_job['requeue'],
                'submit_time'=> $this->_get_date_from_unix_if_defined($json_job, 'submit_time'),
                'time_limit' => $this->_get_timelimit_if_defined($json_job, 'time_limit')
            );
        }
        return NULL;
    }

    function get_job_from_slurmdb(int|string $id) : ?array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.39/job/id
        $json = RequestFactory::newRequest()->request_json("job/".$id, 'slurmdb', static::api_version);
        if (!array_key_exists('jobs', $json)) {
            throw new RequestFailedException(
                "Could not retrieve job $id. slurmdbd may be down.",
                "Response of GET /job/$id does not contain a 'jobs' key. " . $this->_response_debug_info($json)
            );
        }

        foreach ($json['jobs'] as $json_job){

            return array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'job_state'  => $json_job['state']['current'], // Array of strings
                'user_name'  => $json_job['user'],
                'group_name' => $json_job['group'],
                'account'    => $json_job['account'],
                'partition'  => $json_job['partition'],
                'priority'   => $this->_get_number_if_defined($json_job['priority']),
                'submit_line'=> $json_job['submit_line'] ?? NULL,
                'working_directory' => $json_job['current_working_directory'] ?? NULL,
                'comment'    => $json_job['comment'],
                'exit_code'  => $this->_read_exit_code($json_job),
                'nodes'      => $json_job['nodes'],
                'qos'        => $json_job['qos'],
                'container'  => $json_job['container'],
                'flags'      => $json_job['flags'] ?? array(),

                'gres'       => $json_job['jobs'][0]['used_gres'] ?? NULL,
                'tres'       => $json_job['tres'],

                'time_submit'=> $this->_get_date_from_unix($json_job['time'], 'submission'),
                'time_limit' => $this->_get_timelimit_if_defined($json_job['time'], 'limit'),
                'time_elapsed' => $this->_get_elapsed_time($json_job['time']),
                'time_start' => $this->_get_date_from_unix($json_job['time'], 'start'),
                'time_end'   => $this->_get_date_from_unix($json_job['time'], 'end'),
                'time_eligible' => $this->_get_date_from_unix($json_job['time'], 'eligible'),
            );
        }
        return NULL;
    }

    function get_user(string $user_name, bool $with_deleted = FALSE) : array {
        $parameters = '?with_assocs';
        if($with_deleted)
            $parameters .= '&with_deleted';
        // TODO: We should not just pass the oroginal array ...
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/user/username?with_assocs
        $json = RequestFactory::newRequest()->request_json("user/{$user_name}{$parameters}", 'slurmdb', static::api_version);
        return $json;
    }

    function get_users() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users?with_assocs&with_deleted
        $json = RequestFactory::newRequest()->request_json("users?with_assocs&with_deleted", 'slurmdb', static::api_version);
        if (!array_key_exists('users', $json) || empty($json['users'])) {
            throw new RequestFailedException(
                "Could not retrieve user list. slurmdbd may be down.",
                "Response of GET /users does not contain a 'users' key or array is empty. " . $this->_response_debug_info($json)
            );
        }
        return $json['users'];
    }


    function get_node_info(string $nodename) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/node/nodename
        $json = RequestFactory::newRequest()->request_json("node/{$nodename}", 'slurm', static::api_version);

        if( ! array_key_exists("nodes", $json) ){
            throw new MissingArrayKeyException(
                "Could not retrieve node information. slurmctld may be down.",
                "Response of GET /node/$nodename does not contain a 'nodes' key. " . $this->_response_debug_info($json)
            );
        }
        elseif( empty($json['nodes']) ){
            throw new MissingArrayKeyException(
                "Node '$nodename' not found in slurmctld response.",
                "Response of GET /node/$nodename contains an empty 'nodes' array."
            );
        }
        return array(
            'node_name'  => $nodename,
            'alloc_cpus' => $json["nodes"][0]["alloc_cpus"],
            'cpus'       => $json["nodes"][0]["cpus"],
            'mem_total'  => $json["nodes"][0]["real_memory"],
            'mem_free'   => $json["nodes"][0]["free_mem"]['number'],
            'mem_alloc'  => $json["nodes"][0]["alloc_memory"],
            'gres'       => $json["nodes"][0]["gres"],
            'gres_used'  => $json["nodes"][0]["gres_used"],
            'state'      => $json["nodes"][0]["state"],
            'architecture' => $json["nodes"][0]["architecture"],
            'boards' => $json["nodes"][0]["boards"],
            'features' => $json["nodes"][0]["features"],
            'active_features' => $json["nodes"][0]["active_features"],
            'address' => $json["nodes"][0]["address"],
            'hostname' => $json["nodes"][0]["hostname"],
            'operating_system' => $json["nodes"][0]["operating_system"],
            'owner' => $json["nodes"][0]["owner"],
            'tres' => $json["nodes"][0]["tres"],
            'tres_used' => $json["nodes"][0]["tres_used"],
            'boot_time' => $this->_get_date_from_unix_if_defined($json["nodes"][0], "boot_time"),
            'last_busy' => $this->_get_date_from_unix_if_defined($json["nodes"][0], "last_busy"),
            'partitions' => $json["nodes"][0]["partitions"] ?? array(),
            'reservation' => $json["nodes"][0]["reservation"] ??'',
            'slurm_version' => $json["nodes"][0]["version"] ?? '',
        );
    }

    private function get_reservations() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.40/reservations
        $json = RequestFactory::newRequest()->request_json("reservations", 'slurm', static::api_version);
        return $json;
    }

    function get_maintenances() : array {
        $raw_array = $this->get_reservations();
        if($raw_array == NULL || !isset($raw_array['reservations'])) {
            log_msg("GET /reservations: 'reservations' key missing. " . $this->_response_debug_info($raw_array ?? []));
            return array();
        }
        return array_filter($raw_array['reservations'], function ($res){
            return isset($res['flags']) && in_array("MAINT", $res['flags']);
        });
    }

    function cancel_job(string|int $job_id) : bool {
        $json = RequestFactory::newRequest()->request_delete("job/" . $job_id, 'slurm', static::api_version);
        if (!empty($json['errors']))
            log_msg("DELETE /job/$job_id: errors: " . json_encode($json['errors']));
        if (!empty($json['warnings']))
            log_msg("DELETE /job/$job_id: warnings: " . json_encode($json['warnings']));
        return !isset($json['errors']) || empty($json['errors']);
    }

    function update_job(array $job_data) : bool{
        if(isset($job_data['time_limit']) && (
            !isset($job_data['time_limit']['infinite']) && !isset($job_data['time_limit']['set']) ||
            isset($job_data['time_limit']['infinite']) && !(intval($job_data['time_limit']['infinite']) == 1 || intval($job_data['time_limit']['infinite']) == 0) ||
            isset($job_data['time_limit']['set']) && intval($job_data['time_limit']['number']) <= 0
            )){
            throw new \exceptions\ValidationException("Wrong format for time_limit.");
        }
        if(isset($job_data['nice']) && filter_var($job_data['nice'], FILTER_VALIDATE_INT) === false){
            throw new \exceptions\ValidationException("Wrong format for nice_value.");
        }
        if(!isset($job_data['comment'])){
            throw new \exceptions\ValidationException("Comment was not set.");
        }

        $json = RequestFactory::newRequest()
            ->request_post_json("job/" . $job_data['job_id'], 'slurm', static::api_version, $job_data);
        if (!empty($json['errors']))
            log_msg("POST /job/{$job_data['job_id']}: errors: " . json_encode($json['errors']));
        if (!empty($json['warnings']))
            log_msg("POST /job/{$job_data['job_id']}: warnings: " . json_encode($json['warnings']));
        \utils\show_errors($json);
        return !isset($json['errors']) || empty($json['errors']);
    }

    function set_node_state(string $nodename, string $new_state) : bool {
        if( !in_array($nodename, $this->getNodeList()) ){
            throw new RequestFailedException(
                "Node name unknown: " . $nodename,
                "nodename=$nodename, new_state=$new_state"
            );
        }

        $data = array(
            'state'=>$new_state
        );

        $json = RequestFactory::newRequest()
            ->request_post_json("node/" . $nodename, 'slurm', static::api_version, $data);
        if (!empty($json['errors']))
            log_msg("POST /node/$nodename: errors: " . json_encode($json['errors']));
        if (!empty($json['warnings']))
            log_msg("POST /node/$nodename: warnings: " . json_encode($json['warnings']));
        \utils\show_errors($json);
        return !isset($json['errors']) || empty($json['errors']);
    }


    protected function _response_debug_info(array $json): string {
        $parts = [];
        if (!empty($json['errors']))
            $parts[] = "errors: " . json_encode($json['errors']);
        if (!empty($json['warnings']))
            $parts[] = "warnings: " . json_encode($json['warnings']);
        return empty($parts) ? "Has keys: " . implode(',', array_keys($json)) : implode('; ', $parts);
    }

    protected function _get_number_if_defined(array $arr, string $default = 'undefined') : string {
        if($arr['set'])
            return $arr['number'];
        else
            return $default;
    }

    protected function _get_date_from_unix(array $job_arr, string $param): string {
        if(! isset($job_arr[$param]) || $job_arr[$param] == 0){
            return "?";
        }

        return date('Y-m-d H:i:s', $job_arr[$param]);
    }

    protected function _get_date_from_unix_if_defined(array $job_arr, string $param, ?string $default = 'undefined') : ?string {
        if(! isset($job_arr[$param])){
            return $default;
        }

        if($job_arr[$param]['set']){
            if($job_arr[$param]['number'] == 0){
                // When a dependency can never be satisfied, a depending job may never be started.
                // In this case, 0 is reported as start_time (which is 1970/1/1).
                // However, 0 is also returned if start_time cannot yet be determined.
                //
                // This is a fix for \utils\get_date_from_unix_if_defined to handle this,
                // i.e. it prints "undefined" (or $default) instead of 1970/1/1.
                return $default;
            }
            else {
                return date('Y-m-d H:i:s', $job_arr[$param]['number']);
            }
        }
        else {
            return $default;
        }
    }

    /**
     * Elapsed time is in seconds. Display it accordingly.
     * @param $job_arr array Job array as from JSON
     * @param $param string Array index (e.g. elapsed)
     * @return string The time in D-HH:MM:SS
     */
    protected function _get_elapsed_time(array $job_arr, string $param = 'elapsed'): string {
        if(! isset($job_arr[$param]) || $job_arr[$param] == 0 ){
            return "?";
        }

        $totalSeconds = $job_arr[$param]; // Assuming $job_arr[$param] is in seconds
        $days = floor($totalSeconds / 86400); // 86400 seconds in a day
        $hours = floor(($totalSeconds % 86400) / 3600); // 3600 seconds in an hour
        $remainingMinutes = floor(($totalSeconds % 3600) / 60); // Remaining minutes
        $remainingSeconds = $totalSeconds % 60; // Remaining seconds

        return sprintf('%d-%02d:%02d:%02d', $days, $hours, $remainingMinutes, $remainingSeconds);
    }

    /**
     * Time limit is in minutes, so the input is minutes.
     * @param $job_arr array Job array as from JSON
     * @param $param string Array index (e.g. time_limit)
     * @param $default string what to return if not set.
     * @return string The time limit in D-HH:MM:SS
     */
    protected function _get_timelimit_if_defined(array $job_arr, string $param, string $default = 'undefined'): string {
        if(! isset($job_arr[$param])){
            return "?";
        }

        if(isset($job_arr[$param]['infinite']) && $job_arr[$param]['infinite'] == 1){
            return "infinite";
        }
        elseif(isset($job_arr[$param]['set']) && $job_arr[$param]['set']){
            $days = floor($job_arr[$param]['number'] / 1440); // 1440 minutes in a day
            $hours = floor(($job_arr[$param]['number'] % 1440) / 60); // 60 minutes in an hour
            $remainingMinutes = $job_arr[$param]['number'] % 60; // Remaining minutes
            return sprintf('%d-%02d:%02d:00', $days, $hours, $remainingMinutes);
        }
        else {
            return $default;
        }
    }

    /**
     * Exclude partition p_low in /slurm/jobs queue.
     * @param array $jobs Job array
     * @return array Job array without jobs in partition p_low
     */
    protected function _feature_exclude_p_low(array $jobs): array {
        return array_filter($jobs, function ($v, $k) {
            if( $v['partition'] != 'p_low' ){
                return TRUE;
            }
            return FALSE;
        }, ARRAY_FILTER_USE_BOTH);
    }

    protected function _read_exit_code(array $job_arr): string {

        if(! isset($job_arr['exit_code'])){
            return "?";
        }

        if(isset($job_arr['exit_code']['return_code'])){
            if(isset($job_arr['exit_code']['signal']) && isset($job_arr['exit_code']['signal']['name']) && isset($job_arr['exit_code']['signal']['id']['set']) && $job_arr['exit_code']['signal']['id']['set'])
                return $this->_get_number_if_defined($job_arr['exit_code']['return_code']) . " with Signal " . $job_arr['exit_code']['signal']['name'] . ' (' . $job_arr['exit_code']['signal']['id']['number'] . ')';
            else
                return $this->_get_number_if_defined($job_arr['exit_code']['return_code']);
        }
        else {
            return $this->_get_number_if_defined($job_arr['exit_code']);
        }
    }

    /**
     * [ISSUE 12]
     * This is a bugfix for the slurmrestd upstream bug https://support.schedmd.com/show_bug.cgi?id=21853
     * Filtering for completed jobs does not work in slurmdb. Thus, we do not filter for them at the
     * request, but we filter the response.
     * See https://github.com/nikolaussuess/slurm-dashboard/issues/12
     * @param array $jobs Raw job array from slurmdb response.
     * @param array $states List of state strings; a job matches if it has any of them.
     * @return array Filtered job array (re-indexed).
     */
    protected function _issue12_bugfix_post_request_filtering(array $jobs, array $states): array {
        return array_values(array_filter($jobs, function($job) use ($states) {
            foreach($job['state']['current'] as $s){
                if(in_array($s, $states, TRUE)) return TRUE;
            }
            return FALSE;
        }));
    }

    function get_running_jobs_summary(): array {
        $json = RequestFactory::newRequest()->request_json("jobs", 'slurm', static::api_version);
        if (!array_key_exists('jobs', $json)) {
            throw new RequestFailedException(
                "Could not retrieve job list. slurmctld may be down.",
                "Response of GET /jobs does not contain a 'jobs' key. " . $this->_response_debug_info($json)
            );
        }

        $result = [];
        foreach ($json['jobs'] as $json_job) {
            if ( !in_array('RUNNING', $json_job['job_state']) )
                continue;

            $nodes_str = $this->get_nodes($json_job);
            if ($nodes_str === '?')
                continue;

            $cpus_total = isset($json_job['cpus']['set']) && $json_job['cpus']['set']
                ? (int)$json_job['cpus']['number'] : 0;
            $node_count = isset($json_job['node_count']['set']) && $json_job['node_count']['set']
                ? max(1, (int)$json_job['node_count']['number']) : 1;
            $cpus_per_node = $node_count > 0 ? (int)round($cpus_total / $node_count) : $cpus_total;

            if (isset($json_job['memory_per_node']['set']) && $json_job['memory_per_node']['set']) {
                $mem_per_node = (int)$json_job['memory_per_node']['number'];
            }
            elseif (isset($json_job['memory_per_cpu']['set']) && $json_job['memory_per_cpu']['set']) {
                $mem_per_node = (int)$json_job['memory_per_cpu']['number'] * max(1, $cpus_per_node);
            }
            else {
                $mem_per_node = 0;
            }

            $gpus_per_node = 0;
            // gres_detail is an array with one entry per allocated node; take the first.
            // Handles both "gpu:2(IDX:0,1)" and "gpu:V100:2(IDX:0,1)" formats.
            if (isset($json_job['gres_detail']) && is_array($json_job['gres_detail']) && !empty($json_job['gres_detail'])) {
                $gres_str = $json_job['gres_detail'][0];
                if (is_string($gres_str) && preg_match('/gpu.*?:(\d+)(?:\(|,|$)/i', $gres_str, $matches)) {
                    $gpus_per_node = (int)$matches[1];
                }
            }
            // Fallback: parse total from tres_alloc_str ("...gres/gpu=2...") and split evenly across nodes.
            if ($gpus_per_node === 0 && isset($json_job['tres_alloc_str']) && is_string($json_job['tres_alloc_str'])) {
                if (preg_match('/gres\/gpu=(\d+)/', $json_job['tres_alloc_str'], $matches)) {
                    $gpus_total = (int)$matches[1];
                    $gpus_per_node = $node_count > 0 ? (int)round($gpus_total / $node_count) : $gpus_total;
                }
            }

            $result[] = [
                'user_name'     => $json_job['user_name'],
                'partition'     => $json_job['partition'] ?? '',
                'nodes_str'     => $nodes_str,
                'cpus_per_node' => $cpus_per_node,
                'mem_per_node'  => $mem_per_node,
                'gpus_per_node' => $gpus_per_node,
            ];
        }
        return $result;
    }

    protected function _slurm_queue_order_by(array $jobs, string $orderby) : array {
        if( !in_array($orderby, array('job_id', 'user_name', 'priority', 'time_start')))
            return $jobs;

        // 'job_id'     ASC,
        // 'user_name'  ASC,
        // 'priority'   DESC,
        // 'time_start' ASC
        if($orderby == 'priority') {
            // DESC
            usort($jobs, function ($a, $b) use ($orderby) {
                return $b[$orderby] <=> $a[$orderby];
            });
        }
        else {
            // ASC
            usort($jobs, function ($a, $b) use ($orderby) {
                return $a[$orderby] <=> $b[$orderby];
            });
        }

        return $jobs;
    }

}
