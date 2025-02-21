<?php

// Proof of concept!

/**
 * API documentation can be found at:
 * https://slurm.schedmd.com/rest_api.html
 */
class Request {
    // Path to the Unix socket
    const socketPath = '/run/slurmrestd/slurmrestd.socket';

    function __construct(){
        // Create a Unix socket connection
        $this->socket = stream_socket_client("unix://" . self::socketPath, $errno, $errstr);
        if (!$this->socket) {
            die("Unable to connect to socket: $errstr ($errno)");
        }
    }

    function request_json(string $endpoint, string $namespace = "slurm", int $ttl = 5) : mixed {

        if( apcu_exists($namespace . '/' . $endpoint)){
            return apcu_fetch($namespace . '/' . $endpoint);
        }

        // Prepare the HTTP request
        $request = "GET /${namespace}/v0.0.40/${endpoint} HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Connection: close\r\n\r\n";
        // Send the request
        fwrite($this->socket, $request);

        // Read the response
        $response = '';
        while (!feof($this->socket)) {
            $response .= fread($this->socket, 8192);
        }

        // Split the response headers and body
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $body = str_replace("Connection: Close", "", $body);
        #print "<pre>";
        #print_r($header);
        #print "\n\n";
        #print_r($body);
        #print "</pre>";

        // Decode the JSON response
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            addError("JSON decode error: " . json_last_error_msg());
            return FALSE;
        }

        apcu_store($namespace . '/' . $endpoint , $data, $ttl);
        return $data;
    }

    function __destruct(){
        // Close the socket
        fclose($this->socket);
    }
}

class Client {

    function getNodeList(): array {
        $request = new Request();
        $json = $request->request_json("nodes", "slurm", 3600 * 24);
        return array_column($json['nodes'], 'name');
    }

    function get_jobs(?array $filter = NULL) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/jobs
        $request = new Request();
        $json = $request->request_json("jobs");

        // Exclude partition p_low if parameter exclude_p_low=1
        if($filter != NULL){
            if(isset($filter['exclude_p_low']) && $filter['exclude_p_low'] == 1){
                $jobs_array = $this->_feature_exclude_p_low($json['jobs']);
                $json['jobs'] = $jobs_array;
            }
        }

        return $json;
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
        $request = new Request();
        $json = $request->request_json("jobs" . $query_string, 'slurmdb', 30);

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
        return $json;
    }

    function get_account_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/accounts
        $request = new Request();
        $json = $request->request_json("accounts", 'slurmdb', 6 * 3600);
        return array_column($json['accounts'], 'name');
    }

    function get_users_list(): array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users
        $request = new Request();
        $json = $request->request_json("users", 'slurmdb', 120);
        return array_column($json['users'], 'name');
    }

    function get_users() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/users?with_assocs&with_deleted
        $request = new Request();
        $json = $request->request_json("users?with_assocs&with_deleted", 'slurmdb', 120);
        return $json['users'];
    }

    function get_job(string $id) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/job/id
        $request = new Request();
        $json = $request->request_json("job/".$id);
        return $json;
    }

    function get_job_from_slurmdb(int|string $id) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.39/job/id
        $request = new Request();
        $json = $request->request_json("job/".$id, 'slurmdb');
        return $json;
    }

    function get_user(string $user_name) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/user/username?with_assocs
        $request = new Request();
        $json = $request->request_json("user/${user_name}?with_assocs", "slurmdb");
        return $json;
    }

    function get_node_info(string $nodename) : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/node/nodename
        $request = new Request();
        $json = $request->request_json("node/$nodename");
        return $json;
    }

    function get_reservations() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.40/reservations
        $request = new Request();
        $json = $request->request_json("reservations");
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
}