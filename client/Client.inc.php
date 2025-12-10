<?php

namespace client;

require_once __DIR__ . '/Request.inc.php';
use Error;
use exceptions\ConfigurationError;

interface Client{
    /**
     * Checks whether the client is available.
     * In case of a local unix socket, e.g., it checks whether the socket file exists.
     * @return bool true if the client is available, false otherwise.
     */
    function is_available() : bool;

    /**
     * Get the compute-node names.
     * @return array Array of strings with the compute node names.
     */
    function getNodeList(): array;

    /**
     * Query the jobs.
     * @param array|null $filter Filter settings.
     * @return array Array of jobs following our format specification:
     * <pre>
     * $job = array(
     *       'job_id'     : int
     *       'job_name'   : string
     *       'partition'  : string
     *       'job_state'  : array of strings
     *       'user_name'  : string
     *       'user_id'    : int or numeric string
     *       'nodes'      : string
     *       'node_count' : int or numeric string
     *       'time_limit' : string
     *       'time_start' : string
     *    );
     * </pre>
     */
    function get_jobs(?array $filter = NULL) : array;

    /**
     * Query the jobs from slurmdb.
     * @param array|null $filter Filter settings.
     * @return array Array of jobs following our format specification:
     * <pre>
     * $job = array(
     *       'job_id'     : int
     *       'job_name'   : string
     *       'partition'  : string
     *       'account'    : string
     *       'job_state'  : array of strings
     *       'user_name'  : string
     *       'nodes'      : string
     *       'time_limit' : string
     *       'time_start' : string
     *       'time_elapsed' : string
     *    );
     * </pre>
     */
    function get_jobs_from_slurmdb(?array $filter = NULL) : array;

    /**
     * Query the account list as a list of strings.
     * @return array List of strings with the account names.
     */
    function get_account_list(): array;

    /**
     * Returns the list of Users as an array of strings with the usernames.
     * @return array List of Users as an array of strings with the usernames.
     */
    function get_users_list(): array;

    /**
     * Returns more detailed information about a specific job.
     * @param string $id ID of the job to query for.
     * @return array|null NULL if the job was not found, an associative array otherwise:
     * <pre>
     * array(
     * 'job_id'     => $json_job['job_id'],    // Int
     * 'job_name'   => $json_job['name'],      // String
     * 'job_state'  => $json_job['job_state'], // Array of strings
     * 'user_name'  => $json_job['user_name'],
     * 'user_id'    => $json_job['user_id'],
     * 'group_name' => $json_job['group_name'],
     * 'group_id'   => $json_job['group_id'],
     * 'account'    => $json_job['account'],
     * 'partition'  => $json_job['partition'],
     * 'priority'   => $json_job['priority']['set'] ? $json_job['priority']['number'] : NULL,
     * 'submit_line'=> $json_job['command'] ?? NULL,
     * 'working_directory' => $json_job['current_working_directory'] ?? NULL,
     * 'comment'    => $json_job['comment'],
     * 'exit_code'  => $this->_read_exit_code($json_job),
     * 'scheduled_nodes' => $json_job['scheduled_nodes'] ?? NULL,
     * 'required_nodes' => $json_job['required_nodes'] ?? NULL,
     * 'nodes'      => $this->get_nodes($json_job),
     * 'qos'        => $json_job['qos'],
     * 'container'  => $json_job['container'],
     * 'container_id' => $json_job['container_id'] ?? NULL,
     * 'allocating_node' => $json_job['allocating_node'] ?? NULL,
     * 'flags'      => $json_job['flags'] ?? array(),
     * 'cores_per_socket' => $json_job['cores_per_socket']['set'] ? $json_job['cores_per_socket']['number'] : NULL,
     * 'cpus_per_task' => $json_job['cpus_per_task']['set'] ? $json_job['cpus_per_task']['number'] : NULL,
     * 'deadline'   => $json_job['deadline']['set'] ? $json_job['deadline']['number'] : NULL, // FIXME
     * 'dependency' => $json_job['dependency'] ?? NULL,
     * 'features'   => $json_job['features'] ?? NULL,
     * 'gres'       => $json_job['gres_detail'] ?? NULL,
     * 'cpus'       => $json_job['cpus']['set'] ? $json_job['cpus']['number'] : NULL,
     * 'node_count' => $json_job['node_count']['set'] ? $json_job['node_count']['number'] : NULL,
     * 'tasks'      => $json_job['tasks']['set'] ? $json_job['tasks']['number'] : NULL,
     * 'memory_per_cpu' => $json_job['memory_per_cpu']['set'] ? $json_job['memory_per_cpu']['number'] : NULL,
     * 'memory_per_node' => $json_job['memory_per_node']['set'] ? $json_job['memory_per_node']['number'] : NULL,
     * 'requeue'    => $json_job['requeue'],
     * 'submit_time'=> $this->_get_date_from_unix_if_defined($json_job, 'submit_time'),
     * 'time_limit' => $this->_get_timelimit_if_defined($json_job, 'time_limit')
     * );
     * </pre>
     */
    function get_job(string $id) : ?array;

    /**
     * Returns more detailed information about a specific job from slurmdb.
     * @param int|string $id ID of the job to query for.
     * @return array|null NULL if the job was not found, an associative array otherwise:
     * <pre>
     * array(
     * 'job_id'     => $json_job['job_id'],    // Int
     * 'job_name'   => $json_job['name'],      // String
     * 'job_state'  => $json_job['state']['current'], // Array of strings
     * 'user_name'  => $json_job['user'],
     * 'group_name' => $json_job['group'],
     * 'account'    => $json_job['account'],
     * 'partition'  => $json_job['partition'],
     * 'priority'   => $this->_get_number_if_defined($json_job['priority']),
     * 'submit_line'=> $json_job['submit_line'] ?? NULL,
     * 'working_directory' => $json_job['current_working_directory'] ?? NULL,
     * 'comment'    => $json_job['comment'],
     * 'exit_code'  => $this->_read_exit_code($json_job),
     * 'nodes'      => $json_job['nodes'],
     * 'qos'        => $json_job['qos'],
     * 'container'  => $json_job['container'],
     * 'flags'      => $json_job['flags'] ?? array(),
     * 'gres'       => $json_job['jobs'][0]['used_gres'] ?? NULL,
     * 'tres'       => $json_job['tres'],
     * 'time_submit'=> $this->_get_date_from_unix($json_job['time'], 'submission'),
     * 'time_limit' => $this->_get_timelimit_if_defined($json_job['time'], 'limit'),
     * 'time_elapsed' => $this->_get_elapsed_time($json_job['time']),
     * 'time_start' => $this->_get_date_from_unix($json_job['time'], 'start'),
     * 'time_end'   => $this->_get_date_from_unix($json_job['time'], 'end'),
     * 'time_eligible' => $this->_get_date_from_unix($json_job['time'], 'eligible'),
     * );
     * </pre>
     */
    function get_job_from_slurmdb(int|string $id) : ?array;

    /**
     * Queries information about user $user_name from slurmdb.
     * @param string $user_name The user to search for.
     * @return array Infos about the user.
     * @unstable
     */
    function get_user(string $user_name) : array;

    /**
     * Get a list of all slurm users from slurmdb.
     * @return array of slurm users.
     * @unstable
     */
    function get_users() : array;

    /**
     * Get information about a compute-node.
     * @param string $nodename Name of the node to query the information about.
     * @return array Associative array of the node infos, of the form:
     * <pre>
     * array(
     * 'node_name'  => $nodename,
     * 'alloc_cpus' => $json["nodes"][0]["alloc_cpus"],
     * 'cpus'       => $json["nodes"][0]["cpus"],
     * 'mem_total'  => $json["nodes"][0]["real_memory"],
     * 'mem_free'   => $json["nodes"][0]["free_mem"]['number'],
     * 'mem_alloc'  => $json["nodes"][0]["alloc_memory"],
     * 'gres'       => $json["nodes"][0]["gres"],
     * 'gres_used'  => $json["nodes"][0]["gres_used"],
     * 'state'      => $json["nodes"][0]["state"],
     * 'architecture' => $json["nodes"][0]["architecture"],
     * 'boards' => $json["nodes"][0]["boards"],
     * 'features' => $json["nodes"][0]["features"],
     * 'active_features' => $json["nodes"][0]["active_features"],
     * 'address' => $json["nodes"][0]["address"],
     * 'hostname' => $json["nodes"][0]["hostname"],
     * 'operating_system' => $json["nodes"][0]["operating_system"],
     * 'owner' => $json["nodes"][0]["owner"],
     * 'tres' => $json["nodes"][0]["tres"],
     * 'tres_used' => $json["nodes"][0]["tres_used"],
     * 'boot_time' => $this->_get_date_from_unix_if_defined($json["nodes"][0], "boot_time"),
     * 'last_busy' => $this->_get_date_from_unix_if_defined($json["nodes"][0], "last_busy"),
     * 'partitions' => $json["nodes"][0]["partitions"] ?? array(),
     * 'reservation' => $json["nodes"][0]["reservation"] ??'',
     * 'slurm_version' => $json["nodes"][0]["version"] ?? '',
     *  );
     * </pre>
     */
    function get_node_info(string $nodename) : array;

    /**
     * List of scheduled maintenances.
     * @return array Array of maintenances.
     * @unstable
     */
    function get_maintenances() : array;

    function cancel_job(string|int $job_id) : bool;
    function update_job(array $job_data) : bool;

    function set_node_state(string $nodename, string $new_state) : bool;
}

class ClientFactory {
    public static function newClient($version = NULL) : Client {
        if( $version === NULL )
            $version = config('REST_API_VERSION');

        if( $version == 'auto' ){
            $response = RequestFactory::newRequest()->request_json2("openapi/v3", 0);
            if(! isset($response['info']['x-slurm']['data_parsers']) ){
                throw new ConfigurationError(
                    "Could not autodetect supported SLURM REST API versions",
                    '$response["info"]["x-slurm"]["data_parsers"] is not set',
                    "Could not autodetect supported SLURM REST API versions. This is likely, because your SLURM version does not support it, yet.
                    <ul>
                        <li>If you are an admin, please set the parameter <kbd>REST_API_VERSION</kbd> in config.inc.php.</li>
                        <li>If you are a user and the error persists, please contact " . config('ADMIN_EMAIL') . "</li>
                    </ul>",
                );
            }
            $parsers = array_column($response['info']['x-slurm']['data_parsers'], 'plugin');
            rsort($parsers, SORT_NATURAL | SORT_FLAG_CASE);

            log_msg("Detected the following data parsers: " . implode(', ', $parsers));
        }

        if( ! isset($parsers) )
            $parsers = [$version];

        // Use the first matching version.
        foreach ($parsers as $version){
            $classname = strtoupper(str_replace('.', '', $version)) . "Client";
            if(file_exists(__DIR__ . "/{$classname}.inc.php")){
                require_once __DIR__ . "/{$classname}.inc.php";
                $classname = '\\client\\' . $classname;
                return new $classname();
            }
        }
        throw new Error("API version currently unsupported. No client found.");
    }

}
