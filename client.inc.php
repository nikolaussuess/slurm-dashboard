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

    function get_jobs() : array {
        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurm/v0.0.39/jobs
        $request = new Request();
        $json = $request->request_json("jobs");
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
                $query_string .= '&users=' . $filter['users'];
            }
            if(isset($filter['account'])){
                $query_string .= '&account=' . $filter['account'];
            }
            if(isset($filter['node'])){
                $query_string .= '&node=' . $filter['node'];
            }
            if(isset($filter['job_name'])){
                $query_string .= '&job_name=' . $filter['job_name'];
            }
            if(isset($filter['constraints'])){
                $query_string .= '&constraints=' . $filter['constraints'];
            }
            if(isset($filter['state'])){
                $query_string .= '&state=' . $filter['state'];
            }
        }

        # curl --unix-socket /run/slurmrestd/slurmrestd.socket http://slurm/slurmdb/v0.0.40/jobs
        $request = new Request();
        $json = $request->request_json("jobs" . $query_string, 'slurmdb', 30);
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
        return array_filter($raw_array['reservations'], function ($res){
            return isset($res['flags']) && in_array("MAINT", $res['flags']);
        });
    }


}