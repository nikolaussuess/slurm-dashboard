<?php

namespace client;

require_once __DIR__ . '/../globals.inc.php';
require_once __DIR__ . '/utils/jwt.inc.php';
require_once __DIR__ . '/../exceptions/RequestFailedException.inc.php';
require_once __DIR__ . '/../exceptions/ConfigurationError.inc.php';

use exceptions\ConfigurationError;
use exceptions\RequestFailedException;

interface Request {
    function request_json(string $endpoint, string $namespace, string $api_version, int $ttl = 5) : array;
    static function socket_exists() : bool;

    function request_delete(string $endpoint, string $namespace, string $api_version) : mixed;
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
        $body = str_replace("Connection: Close", "", $body);

        // Debugging ...
        if($_SESSION['USER'] == "suessn98"){
            print "<pre>";
            print_r($header);
            print "\n\n";
            print_r($body);
            print "</pre>";
        }

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