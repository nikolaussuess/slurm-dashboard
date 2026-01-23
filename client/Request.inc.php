<?php

namespace client;

require_once __DIR__ . '/../globals.inc.php';
require_once __DIR__ . '/utils/jwt.inc.php';
require_once __DIR__ . '/../exceptions/RequestFailedException.inc.php';
require_once __DIR__ . '/../exceptions/ConfigurationError.inc.php';

use exceptions\ConfigurationError;
use exceptions\RequestFailedException;

interface Request {
    /**
     * @param string $endpoint Endpoint to call
     * @param string $namespace slurm or slurmdb
     * @param string $api_version API version, e.g. v0.0.40
     * @param int $ttl how long to cache the request
     * @throws RequestFailedException In case of errors
     * @return array associative array
     */
    function request_json(string $endpoint, string $namespace, string $api_version, int $ttl = 5) : array;

    /**
     * @param string $full_endpoint Endpoint to call (incl. namespace and api version)
     * @param int $ttl how long to cache the request
     * @return array associative array
     */
    function request_json2(string $full_endpoint, int $ttl = 5) : array;

    /**
     * @param string $endpoint Endpoint to call
     * @param string $namespace slurm or slurmdb
     * @param string $api_version API version, e.g. v0.0.40
     * @param int $ttl how long to cache the request
     * @throws RequestFailedException In case of errors
     * @return string plain body of the response
     */
    function request_plain(string $endpoint, string $namespace, string $api_version, int $ttl = 5) : string;

    /**
     * @return bool true if the socket exists, false otherwise
     */
    static function socket_exists() : bool;

    /**
     * HTTP delete request, e.g. to cancel a job.
     * @param string $endpoint Endpoint to call
     * @param string $namespace slurm or slurmdb
     * @param string $api_version API version, e.g. v0.0.40
     * @throws RequestFailedException In case of errors
     * @return mixed associative array
     */
    function request_delete(string $endpoint, string $namespace, string $api_version) : mixed;

    /**
     * Post JSON, e.g. submit jobs or something else
     * @param string $endpoint Endpoint to call
     * @param string $namespace slurm or slurmdb
     * @param string $api_version API version, e.g. v0.0.40
     * @throws RequestFailedException In case of errors
     * @return mixed associative array
     */
    function request_post_json(string $endpoint, string $namespace, string $api_version, array $data) : array;
}

class UnixRequest implements Request {
    // Path to the Unix socket
    const socketPath = '/run/slurmrestd/slurmrestd.socket';
    private mixed $socket;

    function __construct(){
        // Create a Unix socket connection
        $this->socket = stream_socket_client("unix://" . self::socketPath, $errno, $errstr);
        if (!$this->socket) {
            throw new RequestFailedException(
                "Unable to connect to socket.",
                "errno=$errno, errstr=$errstr",
                "Unable to connect to socket."
            );
        }
    }

    function request_json(string $endpoint, string $namespace, string $api_version, int $ttl = 5) : array {

        if( @apcu_exists($namespace . '/' . $endpoint)){
            return apcu_fetch($namespace . '/' . $endpoint);
        }

        // Prepare the HTTP request
        $request = "GET /{$namespace}/{$api_version}/{$endpoint} HTTP/1.1\r\n" .
            "Host: localhost\r\n";
        if(\client\utils\jwt\JwtAuthentication::is_supported()){
            $request .= "X-SLURM-USER-NAME: " . ($_SESSION['USER'] ?? config('SLURM_USER')) . "\r\n";
            $request .= "X-SLURM-USER-TOKEN: " . \client\utils\jwt\JwtAuthentication::gen_jwt($_SESSION['USER'] ?? config('SLURM_USER')) . "\r\n";
        }
        $request .= "Connection: close\r\n\r\n";
        // Send the request
        fwrite($this->socket, $request);

        // Read the response
        $response = '';
        while (!feof($this->socket)) {
            $response .= fread($this->socket, 8192);
        }

        // Split the response headers and body
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $this->handle_http_headers(trim($header), array("content-type"=>'application/json')); // may throw an exception
        $body = trim(str_replace("Connection: Close", "", $body));

        #print "<pre>";
        #print_r($header);
        #print "\n\n";
        #print_r($body);
        #print "</pre>";

        // Decode the JSON response
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            #addError("JSON decode error: " . json_last_error_msg());
            throw new RequestFailedException(
                "Server response could not be interpreted.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }

        @apcu_store($namespace . '/' . $endpoint , $data, $ttl);
        return $data;
    }

    function request_json2(string $full_endpoint, int $ttl = 5) : array {

        if( @apcu_exists($full_endpoint)){
            return apcu_fetch($full_endpoint);
        }

        // Prepare the HTTP request
        $request = "GET /{$full_endpoint} HTTP/1.1\r\n" .
            "Host: localhost\r\n";
        if(\client\utils\jwt\JwtAuthentication::is_supported()){
            $request .= "X-SLURM-USER-NAME: " . ($_SESSION['USER'] ?? config('SLURM_USER')) . "\r\n";
            $request .= "X-SLURM-USER-TOKEN: " . \client\utils\jwt\JwtAuthentication::gen_jwt($_SESSION['USER'] ?? config('SLURM_USER')) . "\r\n";
        }
        $request .= "Connection: close\r\n\r\n";
        // Send the request
        fwrite($this->socket, $request);

        // Read the response
        $response = '';
        while (!feof($this->socket)) {
            $response .= fread($this->socket, 8192);
        }

        // Split the response headers and body
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $this->handle_http_headers(trim($header), array("content-type"=>'application/json')); // may throw an exception
        $body = str_replace("Connection: Close", "", $body);
        #print "<pre>";
        #print_r($header);
        #print "\n\n";
        #print_r($body);
        #print "</pre>";

        // Decode the JSON response
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            #addError("JSON decode error: " . json_last_error_msg());
            throw new RequestFailedException(
                "Server response could not be interpreted.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }

        @apcu_store($full_endpoint , $data, $ttl);
        return $data;
    }

    function request_plain(string $endpoint, string $namespace, string $api_version, int $ttl = 5) : string {

        if( @apcu_exists($namespace . '/' . $endpoint)){
            return apcu_fetch($namespace . '/' . $endpoint);
        }

        // Prepare the HTTP request
        $request = "GET /{$namespace}/{$api_version}/{$endpoint} HTTP/1.1\r\n" .
            "Host: localhost\r\n";
        if(\client\utils\jwt\JwtAuthentication::is_supported()){
            $request .= "X-SLURM-USER-NAME: " . ($_SESSION['USER'] ?? config('SLURM_USER')) . "\r\n";
            $request .= "X-SLURM-USER-TOKEN: " . \client\utils\jwt\JwtAuthentication::gen_jwt($_SESSION['USER'] ?? config('SLURM_USER')) . "\r\n";
        }
        $request .= "Connection: close\r\n\r\n";
        // Send the request
        fwrite($this->socket, $request);

        // Read the response
        $response = '';
        while (!feof($this->socket)) {
            $response .= fread($this->socket, 8192);
        }

        // Split the response headers and body
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $this->handle_http_headers(trim($header)); // may throw an exception
        $body = str_replace("Connection: Close", "", $body);

        #print "<pre>";
        #print_r($header);
        #print "\n\n";
        #print_r($body);
        #print "</pre>";

        @apcu_store($namespace . '/' . $endpoint , $body, $ttl);
        return $body;
    }


    function request_delete(string $endpoint, string $namespace, string $api_version) : array {

        // Prepare the HTTP request
        $request = "DELETE /{$namespace}/{$api_version}/{$endpoint} HTTP/1.1\r\n" .
            "Host: localhost\r\n";
        if(\client\utils\jwt\JwtAuthentication::is_supported()){
            $request .= "X-SLURM-USER-NAME: " . $_SESSION['USER'] . "\r\n";
            $request .= "X-SLURM-USER-TOKEN: " . \client\utils\jwt\JwtAuthentication::gen_jwt($_SESSION['USER']) . "\r\n";
        }
        $request .= "Connection: close\r\n\r\n";
        // Send the request
        fwrite($this->socket, $request);

        // Read the response
        $response = '';
        while (!feof($this->socket)) {
            $response .= fread($this->socket, 8192);
        }

        // Split the response headers and body
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $this->handle_http_headers(trim($header)); // may throw an exception
        $body = str_replace("Connection: Close", "", $body);

        // Decode the JSON response
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            #addError("JSON decode error: " . json_last_error_msg());
            throw new RequestFailedException(
                "Server response could not be interpreted.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }

        return $data;
    }

    function request_post_json(string $endpoint, string $namespace, string $api_version, array $data) : array {

        // Encode POST data as JSON
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new RequestFailedException(
                "Failed to encode JSON.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }

        // Prepare the HTTP request
        $request = "POST /{$namespace}/{$api_version}/{$endpoint} HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: " . strlen($jsonData) . "\r\n";
        if(\client\utils\jwt\JwtAuthentication::is_supported()){
            $request .= "X-SLURM-USER-NAME: " . $_SESSION['USER'] . "\r\n";
            $request .= "X-SLURM-USER-TOKEN: " . \client\utils\jwt\JwtAuthentication::gen_jwt($_SESSION['USER']) . "\r\n";
        }
        $request .= "Connection: close\r\n\r\n";

        // JSON Body
        $request .= $jsonData;

        // Send the request
        fwrite($this->socket, $request);

        // Read the response
        $response = '';
        while (!feof($this->socket)) {
            $response .= fread($this->socket, 8192);
        }

        // Split the response headers and body
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $this->handle_http_headers(trim($header)); // may throw an exception
        $body = str_replace("Connection: Close", "", $body);

        // Decode the JSON response
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            #addError("JSON decode error: " . json_last_error_msg());
            throw new RequestFailedException(
                "Server response could not be interpreted.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }

        return $data;
    }

    /**
     * Check if all HTTP headers that we expect are actually here.
     * @throws RequestFailedException if some header is missing.
     */
    function handle_http_headers($header, $expected_headers = array()) {
        $headers = explode("\r\n", $header);
        $statusLine = array_shift($headers);
        preg_match('#HTTP/\d\.\d\s+(\d+)\s+(.*)#', $statusLine, $m);

        $statusCode = (int)$m[1];

        $statusText = $m[2];

        $parsed = [];
        foreach ($headers as $line) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }

        if( $statusCode == 401 ) /* UNAUTHORIZED */ {
            throw new RequestFailedException(
                'UNAUTHORIZED',
                'Server answered 401 UNAUTHORIZED.',
                NULL,
                401
            );
        }

        foreach( $expected_headers as $expected_header => $expected_value ){
            $expected_header = strtolower($expected_header);
            $expected_value = strtolower($expected_value);
            if( ! array_key_exists($expected_header, $parsed) ){
                throw new RequestFailedException(
                    "Server response malformed.",
                    "Expected header '$expected_header' but was missing.",
                    NULL,
                    500
                );
            }

            // Special handling for content type
            if($expected_header == 'content-type' && $parsed[$expected_header] != $expected_value ){
                throw new RequestFailedException(
                    "Server response malformed. We got another content type than expected.",
                    "Expected content-type: $expected_value, but got: $parsed[$expected_header]",
                    NULL,
                    500
                );
            }
            // Others
            elseif( $parsed[$expected_header] != $expected_value ){
                throw new RequestFailedException(
                    "Server response malformed.",
                    "Expected: $expected_value, but got: $parsed[$expected_header]",
                    NULL,
                    500
                );
            }
        }
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
        if(config('CONNECTION_MODE') == 'unix')
            return new UnixRequest();

        throw new ConfigurationError(
            "Unknown socket type.",
            "CONNECTION_MODE has an unknown value, i.e. CONNECTION_MODE=" . config('CONNECTION_MODE'),
            'Wrong configuration for requests. Could not contact server.
            <ul>
                <li>If you are an admin, please set the parameter <kbd>CONNECTION_MODE</kbd> in config.inc.php.</li>
                <li>If you are a user and the error persists, please contact " . ADMIN_EMAIL. "</li>
            </ul>'
        );
    }

    public static function socket_exists() : bool {
        if(config('CONNECTION_MODE') == 'unix')
            return UnixRequest::socket_exists();

        throw new ConfigurationError(
            "Unknown socket type.",
            "CONNECTION_MODE has an unknown value, i.e. CONNECTION_MODE=" . config('CONNECTION_MODE'),
            'Wrong configuration for requests. Could not contact server.
            <ul>
                <li>If you are an admin, please set the parameter <kbd>CONNECTION_MODE</kbd> in config.inc.php.</li>
                <li>If you are a user and the error persists, please contact " . ADMIN_EMAIL. "</li>
            </ul>'
        );
    }
}