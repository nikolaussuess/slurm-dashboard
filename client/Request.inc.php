<?php

interface Request {
    function request_json(string $endpoint, string $namespace = "slurm", int $ttl = 5);
    static function socket_exists() : bool;
}

class UnixRequest implements Request {
    // Path to the Unix socket
    const socketPath = '/run/slurmrestd/slurmrestd.socket';
    private mixed $socket;

    function __construct(){
        // Create a Unix socket connection
        $this->socket = stream_socket_client("unix://" . self::socketPath, $errno, $errstr);
        if (!$this->socket) {
            die("Unable to connect to socket: $errstr ($errno)");
        }
    }

    function request_json(string $endpoint, string $namespace = "slurm", int $ttl = 5) : mixed {

        $api_version = REST_API_VERSION;

        if( @apcu_exists($namespace . '/' . $endpoint)){
            return apcu_fetch($namespace . '/' . $endpoint);
        }

        // Prepare the HTTP request
        $request = "GET /{$namespace}/{$api_version}/{$endpoint} HTTP/1.1\r\n" .
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

        @apcu_store($namespace . '/' . $endpoint , $data, $ttl);
        return $data;
    }

    function __destruct(){
        // Close the socket
        fclose($this->socket);
    }

    static function socket_exists(): bool {
        return file_exists(self::socketPath);
    }
}


class RequestFactory {
    public static function newRequest() : Request {
        if(CONNECTION_MODE == 'unix')
            return new UnixRequest();

        throw new Error("Unknown socket type. Wrong configuration in globals.inc.php");
    }

    public static function socket_exists() : bool {
        if(CONNECTION_MODE == 'unix')
            return UnixRequest::socket_exists();
        throw new Error("Unknown socket type. Wrong configuration in globals.inc.php");
    }
}