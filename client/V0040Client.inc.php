<?php

namespace client;

use function utils\get_number_if_defined;

class V0040Client extends AbstractClient {

    function is_available() : bool {
        return \RequestFactory::socket_exists();
    }

    function getNodeList(): array{
        $request = \RequestFactory::newRequest();
        $json = $request->request_json("nodes", "slurm", 3600);
        return array_column($json['nodes'], 'name');
    }

    function get_jobs(?array $filter = NULL): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/jobs
        $json = \RequestFactory::newRequest()->request_json("jobs");

        // Exclude partition p_low if parameter exclude_p_low=1
        if($filter != NULL){
            if(isset($filter['exclude_p_low']) && $filter['exclude_p_low'] == 1){
                $jobs_array = $this->_feature_exclude_p_low($json['jobs']);
                $json['jobs'] = $jobs_array;
            }
        }

        $jobs = [];
        foreach ($json['jobs'] as $json_job){

            /**
            $contents .=    "<td>" . $job['start_time'] . "</td>";
            $contents .=    '<td><a href="?action=job&job_id=' . $job['job_id'] . '">[Details]</a></td>';
             */
            $job = array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'partition'  => $json_job['partition'],
                'job_state'  => $json_job['job_state'], // Array of strings
                'user_name'  => $json_job['user_name'],
                'user_id'    => $json_job['user_id'],
                'nodes'      => $this->get_nodes($json_job),
                'node_count' => $json_job['node_count']['set'] ? $json_job['node_count']['number'] : NULL,
                'time_limit' => $this->__get_timelimit_if_defined($json_job, 'time_limit'),
                'time_start' => $this->__get_date_from_unix_if_defined($json_job, 'start_time')
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
        $json = \RequestFactory::newRequest()->request_json("jobs" . $query_string, 'slurmdb');

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
                'state'      => $json_job['state']['current'], // Array of strings
                'user_name'  => $json_job['user'],
                'account'    => $json_job['account'],
                'partitions' => $json_job['partition'],
                'time_limit' => $this->__get_timelimit_if_defined($json_job['time'], 'limit', 'inf'),
                'start_time' => $this->__get_date_from_unix($json_job['time'], 'start'),
                'elapsed_time' => $this->__get_elapsed_time($json_job['time'], 'elapsed'),
                'nodes'      => $json_job['nodes']
            );
            $jobs[] = $job;
        }

        return $jobs;
    }

    function get_account_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/accounts
        $json = \RequestFactory::newRequest()->request_json("accounts", 'slurmdb', 3600);
        return array_column($json['accounts'], 'name');
    }

    function get_users_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users
        $json = \RequestFactory::newRequest()->request_json("users", 'slurmdb', 120);
        return array_column($json['users'], 'name');
    }

    function get_job(string $id) : ?array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.40/job/id
        $json = \RequestFactory::newRequest()->request_json("job/".$id);

        foreach ($json['jobs'] as $json_job){

            $job = array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'state'      => $json_job['job_state'], // Array of strings
                'user_name'  => $json_job['user_name'],
                'user_id'    => $json_job['user_id'],
                'group_name' => $json_job['group_name'],
                'group_id'   => $json_job['group_id'],
                'account'    => $json_job['account'],
                'partitions' => $json_job['partition'],
                'priority'   => $json_job['priority']['set'] ? $json_job['priority']['number'] : NULL,
                'submit_line'=> $json_job['command'] ?? NULL,
                'working_directory' => $json_job['current_working_directory'] ?? NULL,
                'comment'    => $json_job['comment'],
                'exit_code'  => $this->__read_exit_code($json_job),
                'scheduled_nodes' => $json_job['scheduled_nodes'] ?? NULL,
                'required_nodes' => $json_job['required_nodes'] ?? NULL,
                'nodes'      => $this->get_nodes($json_job),
                'qos'        => $json_job['qos'],
                'container'  => $json_job['container'],
                'container_id' => $json_job['container_id'] ?? NULL,
                'allocating_node' => $json_job['allocating_node'] ?? NULL,
                'flags'      => $json_job['flags'] ?? array(),
                'cores_per_socket' => $json_job['cores_per_socket']['set'] ? $json_job['cores_per_socket']['number'] : NULL,
                'cpus_per_task' => $json_job['cpus_per_task']['set'] ? $json_job['cpus_per_task']['number'] : NULL,
                'deadline'   => $json_job['deadline']['set'] ? $json_job['deadline']['number'] : NULL, // FIXME
                'dependency' => $json_job['dependency'] ?? NULL,
                'features'   => $json_job['features'] ?? NULL,
                'gres'       => $json_job['jobs'][0]['gres_detail'] ?? NULL,
                'cpus'       => $json_job['cpus']['set'] ? $json_job['cpus']['number'] : NULL,
                'node_count' => $json_job['node_count']['set'] ? $json_job['node_count']['number'] : NULL,
                'tasks'      => $json_job['tasks']['set'] ? $json_job['tasks']['number'] : NULL,
                'memory_per_cpu' => $json_job['memory_per_cpu']['set'] ? $json_job['memory_per_cpu']['number'] : NULL,
                'memory_per_node' => $json_job['memory_per_node']['set'] ? $json_job['memory_per_node']['number'] : NULL,
                'requeue'    => $json_job['requeue'],
                'submit_time'=> $this->__get_date_from_unix_if_defined($json_job, 'submit_time'),
                'time_limit' => $this->__get_timelimit_if_defined($json_job, 'time_limit')
            );

            return $job;
        }
        return NULL;
    }

    function get_job_from_slurmdb(int|string $id) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.39/job/id
        $json = \RequestFactory::newRequest()->request_json("job/".$id, 'slurmdb');

        foreach ($json['jobs'] as $json_job){

            $job = array(
                'job_id'     => $json_job['job_id'],    // Int
                'job_name'   => $json_job['name'],      // String
                'state'      => $json_job['state']['current'], // Array of strings
                'user_name'  => $json_job['user'],
                'group_name' => $json_job['group'],
                'account'    => $json_job['account'],
                'partitions' => $json_job['partition'],
                'priority'   => $this->__get_number_if_defined($json_job['priority']),
                'submit_line'=> $json_job['command'] ?? NULL,
                'working_directory' => $json_job['current_working_directory'] ?? NULL,
                'comment'    => $json_job['comment'],
                'exit_code'  => $this->__read_exit_code($json_job),
                'nodes'      => $json_job['nodes'],
                'qos'        => $json_job['qos'],
                'container'  => $json_job['container'],
                'flags'      => $json_job['flags'] ?? array(),
                
                'gres'       => $json_job['jobs'][0]['used_gres'] ?? NULL,
                'tres'       => $json_job['tres'],
                
                'time_submit'=> $this->__get_date_from_unix($json_job['time'], 'submission'),
                'time_limit' => $this->__get_timelimit_if_defined($json_job['time'], 'limit'),
                'time_elapsed' => $this->__get_elapsed_time($json_job['time'], 'elapsed'),
                'time_start' => $this->__get_date_from_unix($json_job['time'], 'start'),
                'time_end'   => $this->__get_date_from_unix($json_job['time'], 'end'),
                'time_eligible' => $this->__get_date_from_unix($json_job['time'], 'eligible'),
            );

            return $job;
        }
        throw new \Exception("Job does not exist.");
    }

    function get_user(string $user_name) : array {
        // TODO: We should not just pass the oroginal array ...
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/user/username?with_assocs
        $json = \RequestFactory::newRequest()->request_json("user/{$user_name}?with_assocs", 'slurmdb');
        return $json;
    }

    function get_node_info(string $nodename) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/node/nodename
        $json = \RequestFactory::newRequest()->request_json("node/{$nodename}");

        $node = array(
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
            'boot_time' => $this->__get_date_from_unix_if_defined($json["nodes"][0], "boot_time"),
            'last_busy' => $this->__get_date_from_unix_if_defined($json["nodes"][0], "last_busy"),
            'partitions' => $json["nodes"][0]["partitions"] ?? array(),
            'reservation' => $json["nodes"][0]["reservation"] ??'',
            'slurm_version' => $json["nodes"][0]["version"] ?? '',
        );

        return $node;

    }

    private function get_reservations() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.40/reservations
        $json = \RequestFactory::newRequest()->request_json("reservations");
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


    /**
     * Exclude partition p_low in /slurm/jobs queue.
     * @param array $jobs Job array
     * @return array Job array without jobs in partition p_low
     */
    private function _feature_exclude_p_low(array $jobs) {
        return array_filter($jobs, function ($v, $k) {
            if( $v['partition'] != 'p_low' ){
                return TRUE;
            }
            return FALSE;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function __read_exit_code(array $job_arr): string {

        if(! isset($job_arr['exit_code'])){
            return "?";
        }

        if(isset($job_arr['exit_code']['return_code'])){
            if(isset($job_arr['exit_code']['signal']) && isset($job_arr['exit_code']['signal']['name']) && $job_arr['exit_code']['signal']['id']['set'])
                return get_number_if_defined($job_arr['exit_code']['return_code']) . " with Signal " . $job_arr['exit_code']['signal']['name'] . ' (' . $job_arr['exit_code']['signal']['id']['number'] . ')';
            else
                return get_number_if_defined($job_arr['exit_code']['return_code']);
        }
        else {
            return get_number_if_defined($job_arr['exit_code']);
        }
    }

    private function get_nodes(array $job_arr) : string {
        if(isset($job_arr['job_resources']) && isset($job_arr['job_resources']['nodes']))
            return $job_arr['job_resources']['nodes'];
        else
            return "?";
    }

    /**
     * [ISSUE 12]
     * This is a bugfix for the slurmrestd upstream bug https://support.schedmd.com/show_bug.cgi?id=21853
     * Filtering for completed jobs does not work in slurmdb. Thus, we do not filter for them at the
     * request but we filter the response.
     * See https://github.com/nikolaussuess/slurm-dashboard/issues/12
     */
    private function _issue12_bugfix_post_request_filtering(array $array, string $value){
        return array_filter($array, function ($v, $k) use($value) {
            foreach($v['state']['current'] as $state){
                if( $state == $value )
                    return TRUE;
            }
            return FALSE;
        }, ARRAY_FILTER_USE_BOTH);
    }

}