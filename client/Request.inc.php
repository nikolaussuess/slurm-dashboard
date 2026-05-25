<?php

namespace client;

require_once __DIR__ . '/../globals.inc.php';
require_once __DIR__ . '/utils/jwt.inc.php';
require_once __DIR__ . '/../exceptions/RequestFailedException.inc.php';
require_once __DIR__ . '/../exceptions/ConfigurationError.inc.php';
require_once __DIR__ . '/../cache/CacheWrapper.inc.php';

use exceptions\ConfigurationError;
use exceptions\RequestFailedException;

interface Request {
    /**
     * Send a GET request to slurmrestd (and decode the response as JSON).
     * @param string $endpoint Endpoint to call
     * @param string $namespace slurm or slurmdb
     * @param string $api_version API version, e.g. v0.0.40
     * @param int|bool $ttl how long to cache the request, FALSE to disable caching
     * @throws RequestFailedException In case of errors
     * @return array associative array
     */
    function request_json(string $endpoint, string $namespace, string $api_version, int|bool $ttl = 5) : array;

    /**
     * Send a GET request to slurmrestd (and decode the response as JSON).
     * Here, $full_endpoint must contain the namespace.
     * @param string $full_endpoint Endpoint to call (incl. namespace and api version)
     * @param int|bool $ttl how long to cache the request, FALSE to disable caching
     * @return array associative array
     */
    function request_json2(string $full_endpoint, int|bool $ttl = 5) : array;

    /**
     * Send a GET request to slurmrestd and return the plain response.
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

abstract class AbstractRequest implements Request {

    abstract protected function get_base_url(): string;
    abstract protected function apply_transport(\CurlHandle $ch): void;

    /**
     * Adds JWT headers if we are using JWT for authentication.
     * If $as_slurm_user == TRUE, then the request may be performed as SLURM_USER if $SESSION['USER'] is not set.
     * This is required to request data before login.
     * @note $as_slurm_user should not be TRUE except for GET requests.
     * @param bool $as_slurm_user if true, allow use of SLURM_USER instead of the current user, otherwise require $_SESSION['USER'] to be set.
     * @return array|string[] authentication headers as an array or an empty array (if we are not using JWT)
     */
    private function build_auth_headers(bool $as_slurm_user = FALSE): array {
        if (!\client\utils\jwt\JwtAuthentication::is_supported())
            return [];

        $user = $as_slurm_user ? ($_SESSION['USER'] ?? config('SLURM_USER')) : $_SESSION['USER'];
        $user = str_replace(["\r", "\n"], '', $user);
        return [
            "X-SLURM-USER-NAME: " . $user,
            "X-SLURM-USER-TOKEN: " . \client\utils\jwt\JwtAuthentication::gen_jwt($user),
        ];
    }

    /**
     * Set up connection and return curl handle
     * @param string $url The full URL to connect to (incl. endpoint and parameters)
     * @param string $method GET/POST/DELETE
     * @param array $headers e.g. authentication headers as a PHP array/dictionary
     * @return \CurlHandle curl handle
     */
    private function new_handle(string $url, string $method, array $headers = []): \CurlHandle {
        $ch = curl_init($url);
        $this->apply_transport($ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => TRUE,
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CONNECTTIMEOUT  => 10,   // abort if connection cannot be established within 10s
            CURLOPT_TIMEOUT         => 300,  // hard cap for slow responses (e.g. large job lists)
        ]);
        return $ch;
    }

    /**
     * Executes the cURL handle and returns [http_code, content_type, body].
     * @param \CurlHandle $ch Curl handle
     * @return array [http_code, content_type, body]
     *@throws RequestFailedException on cURL-level error (e.g. connection refused, timeout)
     */
    private function execute(\CurlHandle $ch): array {
        $body = curl_exec($ch);
        if ($body === FALSE) {
            $errno  = curl_errno($ch);
            $errstr = curl_error($ch);
            curl_close($ch);
            throw new RequestFailedException(
                "Request failed.",
                "curl_errno=$errno, curl_error=$errstr",
                "Unable to connect to server."
            );
        }
        $http_code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
        curl_close($ch);
        return [$http_code, $content_type, $body];
    }

    /**
     * Validate response (e.g. against UNAUTHORIZED or content type).
     * @param int $http_code HTTP response code
     * @param string $content_type Content type of response
     * @param string|null $expected_ct Expected content type of response (or NULL to skip content type check)
     * @throws RequestFailedException on HTTP 401 or unexpected content-type
     */
    private function check_response(int $http_code, string $content_type, ?string $expected_ct = NULL): void {
        if ($http_code === 401) {
            throw new RequestFailedException('UNAUTHORIZED', 'Server answered 401 UNAUTHORIZED.', NULL, 401);
        }
        if ($expected_ct !== NULL && !str_starts_with(strtolower($content_type), strtolower($expected_ct))) {
            throw new RequestFailedException(
                "Server response malformed. We got another content type than expected.",
                "Expected content-type: $expected_ct, but got: $content_type",
                NULL,
                500
            );
        }
    }

    private function decode_json(string $body): array {
        $data = json_decode($body, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestFailedException(
                "Server response could not be interpreted.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }
        return $data;
    }

    /** @inheritDoc */
    function request_json(string $endpoint, string $namespace, string $api_version, int|bool $ttl = 5): array {
        $cache = \cache\CacheWrapper::getInstance();
        $cache_key = $namespace . '/' . $endpoint;
        if ($cache->exists($cache_key))
            return $cache->get($cache_key);

        $ch = $this->new_handle(
            $this->get_base_url() . "/{$namespace}/{$api_version}/{$endpoint}",
            'GET',
            $this->build_auth_headers(TRUE)
        );
        [$http_code, $content_type, $body] = $this->execute($ch);
        $this->check_response($http_code, $content_type, 'application/json');

        $data = $this->decode_json($body);
        $cache->set($cache_key, $data, $ttl);
        return $data;
    }

    /** @inheritDoc */
    function request_json2(string $full_endpoint, int|bool $ttl = 5): array {
        $cache = \cache\CacheWrapper::getInstance();
        if ($cache->exists($full_endpoint))
            return $cache->get($full_endpoint);

        $ch = $this->new_handle(
            $this->get_base_url() . "/{$full_endpoint}",
            'GET',
            $this->build_auth_headers(TRUE)
        );
        [$http_code, $content_type, $body] = $this->execute($ch);
        $this->check_response($http_code, $content_type, 'application/json');

        $data = $this->decode_json($body);
        $cache->set($full_endpoint, $data, $ttl);
        return $data;
    }

    /** @inheritDoc */
    function request_plain(string $endpoint, string $namespace, string $api_version, int $ttl = 5): string {
        $cache = \cache\CacheWrapper::getInstance();
        $cache_key = $namespace . '/' . $endpoint;
        if ($cache->exists($cache_key))
            return $cache->get($cache_key);

        $ch = $this->new_handle(
            $this->get_base_url() . "/{$namespace}/{$api_version}/{$endpoint}",
            'GET',
            $this->build_auth_headers(TRUE)
        );
        [$http_code, $content_type, $body] = $this->execute($ch);
        $this->check_response($http_code, $content_type);

        $cache->set($cache_key, $body, $ttl);
        return $body;
    }

    /** @inheritDoc */
    function request_delete(string $endpoint, string $namespace, string $api_version): array {
        $ch = $this->new_handle(
            $this->get_base_url() . "/{$namespace}/{$api_version}/{$endpoint}",
            'DELETE',
            $this->build_auth_headers()
        );
        [$http_code, $content_type, $body] = $this->execute($ch);
        $this->check_response($http_code, $content_type);
        return $this->decode_json($body);
    }

    /** @inheritDoc */
    function request_post_json(string $endpoint, string $namespace, string $api_version, array $data): array {
        $jsonData = json_encode($data);
        if ($jsonData === FALSE) {
            throw new RequestFailedException(
                "Failed to encode JSON.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }

        $headers = array_merge(['Content-Type: application/json'], $this->build_auth_headers());
        $ch = $this->new_handle(
            $this->get_base_url() . "/{$namespace}/{$api_version}/{$endpoint}",
            'POST',
            $headers
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        [$http_code, $content_type, $body] = $this->execute($ch);
        $this->check_response($http_code, $content_type);
        return $this->decode_json($body);
    }
}


class UnixRequest extends AbstractRequest {

    protected function get_base_url(): string {
        return 'http://localhost';
    }

    protected function apply_transport(\CurlHandle $ch): void {
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, config('UNIX_SOCKET_PATH'));
    }

    static function socket_exists(): bool {
        return file_exists(config('UNIX_SOCKET_PATH'));
    }
}


class TcpRequest extends AbstractRequest {

    protected function get_base_url(): string {
        $port = config('SLURM_TCP_PORT');
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
            throw new \exceptions\ConfigurationError(
                "Invalid TCP port.",
                "SLURM_TCP_PORT must be a number between 1 and 65535, got: " . $port,
                "Wrong configuration: SLURM_TCP_PORT is invalid."
            );
        }
        return 'https://' . config('SLURM_TCP_HOST') . ':' . $port;
    }

    protected function apply_transport(\CurlHandle $ch): void {
        $ca_cert = config('SLURM_TCP_CA_CERT');
        if ($ca_cert !== TO_BE_REPLACED) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca_cert);
        }
    }

    static function socket_exists(): bool {
        $host = config('SLURM_TCP_HOST');
        $port = config('SLURM_TCP_PORT');
        if (!$host || $host === TO_BE_REPLACED) return FALSE;
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return TRUE;
        }
        return FALSE;
    }
}


class RequestFactory {
    public static function newRequest(): Request {
        if (config('CONNECTION_MODE') == 'unix')
            return new UnixRequest();

        if (config('CONNECTION_MODE') == 'tcp')
            return new TcpRequest();

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

    public static function socket_exists(): bool {
        if (config('CONNECTION_MODE') == 'unix')
            return UnixRequest::socket_exists();

        if (config('CONNECTION_MODE') == 'tcp')
            return TcpRequest::socket_exists();

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