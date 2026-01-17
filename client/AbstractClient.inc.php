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

    function is_available() : bool {
        return RequestFactory::socket_exists();
    }

    function getNodeList(): array{
        $request = RequestFactory::newRequest();
        $json = $request->request_json("nodes", "slurm", static::api_version, 3600);
        return array_column($json['nodes'], 'name');
    }

    function get_jobs(?array $filter = NULL): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/jobs
        $json = RequestFactory::newRequest()->request_json("jobs", 'slurm', static::api_version);

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

        return $jobs;
    }

    function get_jobs_from_slurmdb(?array $filter = NULL) : array {
        $query_string = '?';
        if($filter != NULL){
            if(isset($filter['start_time'])){
                $query_string .= '&start_time=uts' . (int)$filter['start_time'];
            }
            if(isset($filter['end_time'])){
                $query_string .= '&end_time=uts' . (int)$filter['end_time'];
            }
            if(isset($filter['users'])){
                $query_string .= '&users=' . urlencode($filter['users']);
            }
            if(isset($filter['account'])){
                $query_string .= '&account=' . urlencode($filter['account']);
            }
            if(isset($filter['node'])){
                $query_string .= '&node=' . urlencode($filter['node']);
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
                if(
                    $filter['state'] != 'COMPLETED' &&
                    $filter['state'] != 'FAILED' &&
                    $filter['state'] != 'TIMEOUT' &&
                    $filter['state'] != 'OUT_OF_MEMORY'
                ){
                    $query_string .= '&state=' . urlencode($filter['state']);
                }
                // ORIGINAL CODE:
                // $query_string .= '&state=' . $filter['state'];
                // END ISSUE 12
            }
        }

        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/jobs
        $json = RequestFactory::newRequest()->request_json("jobs" . $query_string, 'slurmdb', static::api_version);

        /*
         * Issue 12 specific code
         * See https://github.com/nikolaussuess/slurm-dashboard/issues/12
         */
        // RUNNING works on server side
        // COMPLETED does not
        if(isset($filter['state']) && $filter['state'] == 'COMPLETED'){
            $jobs_array = $this->_issue12_bugfix_post_request_filtering($json['jobs'], 'COMPLETED');
            $json['jobs'] = $jobs_array;
        }
        // FAILED does not
        elseif(isset($filter['state']) && $filter['state'] == 'FAILED'){
            $jobs_array = $this->_issue12_bugfix_post_request_filtering($json['jobs'], 'FAILED');
            $json['jobs'] = $jobs_array;
        }
        elseif(isset($filter['state']) && $filter['state'] == 'TIMEOUT'){
            $jobs_array = $this->_issue12_bugfix_post_request_filtering($json['jobs'], 'TIMEOUT');
            $json['jobs'] = $jobs_array;
        }
        elseif(isset($filter['state']) && $filter['state'] == 'OUT_OF_MEMORY'){
            $jobs_array = $this->_issue12_bugfix_post_request_filtering($json['jobs'], 'OUT_OF_MEMORY');
            $json['jobs'] = $jobs_array;
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
                'time_limit' => $this->_get_timelimit_if_defined($json_job['time'], 'limit', 'inf'),
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
        $json = RequestFactory::newRequest()->request_json("accounts", 'slurmdb', static::api_version, 3600);
        return array_column($json['accounts'], 'name');
    }

    function get_users_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users
        $json = RequestFactory::newRequest()->request_json("users", 'slurmdb', static::api_version, 120);
        return array_column($json['users'], 'name');
    }

    function get_job(string $id) : ?array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.40/job/id
        $json = RequestFactory::newRequest()->request_json("job/".$id, 'slurm', static::api_version);

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

    function get_user(string $user_name) : array {
        // TODO: We should not just pass the oroginal array ...
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/user/username?with_assocs
        $json = RequestFactory::newRequest()->request_json("user/{$user_name}?with_assocs", 'slurmdb', static::api_version);
        return $json;
    }

    function get_users() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users?with_assocs&with_deleted
        $json = RequestFactory::newRequest()->request_json("users?with_assocs&with_deleted", 'slurmdb', static::api_version);
        return $json['users'];
    }


    function get_node_info(string $nodename) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/node/nodename
        $json = RequestFactory::newRequest()->request_json("node/{$nodename}", 'slurm', static::api_version);

        // For some reason, $json['nodes'] sometimes does not exist. This yields to a 500 Server Error.
        // We need to debug it ...
        if( ! array_key_exists("nodes", $json) ){
            throw new MissingArrayKeyException(
                "An implementation error occurred. Could not read response array.",
                "Response of GET /node/$nodename does not contain a 'nodes' subarray. Has keys: " . implode(',', array_keys($json))
            );
        }
        elseif( count($json['nodes']) === 0 ){
            throw new MissingArrayKeyException(
                "An implementation error occurred. Could not read response array.",
                "Response of GET /node/$nodename does contain 'nodes' but the array is empty."
            );
        }
        // End debug

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
        if($raw_array == NULL || !isset($raw_array['reservations']))
            return array();
        return array_filter($raw_array['reservations'], function ($res){
            return isset($res['flags']) && in_array("MAINT", $res['flags']);
        });
    }

    function cancel_job(string|int $job_id) : bool {
        $json = RequestFactory::newRequest()->request_delete("job/" . $job_id, 'slurm', static::api_version);
        return !isset($json['errors']) || empty($json['errors']);
    }

    function update_job(array $job_data) : bool{
        if(isset($job_data['time_limit']) && (
            !isset($job_data['time_limit']['infinite']) && !isset($job_data['time_limit']['set']) ||
            isset($job_data['time_liit']['infinite']) && !(intval($job_data['time_limit']['infinite']) == 1 || intval($job_data['time_limit']['infinite']) == 0) ||
            isset($job_data['time_limit']['set']) && intval($job_data['time_limit']['number']) <= 0
            )){
            throw new \exceptions\ValidationException("Wrong format for time_limit.");
        }
        if(isset($job_data['nice_value']) && intval($job_data['nice_value']) != $job_data['nice_value']){
            throw new \exceptions\ValidationException("Wrong format for nice_value.");
        }
        if(!isset($job_data['comment'])){
            throw new \exceptions\ValidationException("Comment was empty.");
        }

        $json = RequestFactory::newRequest()
            ->request_post_json("job/" . $job_data['job_id'], 'slurm', static::api_version, $job_data);
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
        \utils\show_errors($json);
        return !isset($json['errors']) || empty($json['errors']);
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
            if(isset($job_arr['exit_code']['signal']) && isset($job_arr['exit_code']['signal']['name']) && $job_arr['exit_code']['signal']['id']['set'])
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
     */
    protected function _issue12_bugfix_post_request_filtering(array $array, string $value): array {
        return array_filter($array, function ($v, $k) use($value) {
            foreach($v['state']['current'] as $state){
                if( $state == $value )
                    return TRUE;
            }
            return FALSE;
        }, ARRAY_FILTER_USE_BOTH);
    }

}
