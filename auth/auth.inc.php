<?php

namespace auth {

    interface AuthenticationMethod {
        /**
         * Checks if the respective authentication method is supported. E.g., checks whether the required libraries are
         * installed, configuration parameters are set, ...
         * @return bool TRUE if the respective authentication method is supported, FALSE otherwise.
         */
        public static function is_supported(): bool;

        /**
         * Tests the login.
         * @param string $username Username to test.
         * @param string $password Respective password for the given username.
         * @return bool TRUE if authentication was successful, FALSE otherwise.
         */
        public static function login(string $username, string $password): bool;
    }
}

namespace {

    require_once __DIR__ . '/../client/Client.inc.php';
    require_once __DIR__ . '/../globals.inc.php';
    require_once __DIR__ . '/../cache/CacheWrapper.inc.php';
    require_once __DIR__ . '/auth.ldap.inc.php';
    require_once __DIR__ . '/auth.local.inc.php';

    /**
     * Authentication.
     * @param string $username Username of user
     * @param string $password Password of User
     * @return bool True if authentication was successful, False otherwise
     */
    function auth(string $username, string $password, string $method = 'ldap') : bool{

        if(\auth\rate_limit()){
            addError("Rate limit exceeded: Please wait 60 seconds until you try again!");
            http_response_code(429); // HTTP/1.1 429 Too Many Requests
            return FALSE;
        }

        if( strlen($password) < 8 ){
            addError("Password too short.");
            return FALSE;
        }

        if( \auth\validate_username($username) !== TRUE ){
            addError("Username invalid.");
            return FALSE;
        }

        // Root login is never permitted!
        if( $username == "root" ){
            addError("Root login is not permitted.");
            return FALSE;
        }

        $dbquery = \client\ClientFactory::newClient();
        $associations = $dbquery->get_user($username);

        if(empty($associations['users'])){
            addError("No such account.");
            return FALSE;
        }

        # Authenticate with LDAP
        if($method == \auth\LDAP::METHOD_NAME && \auth\LDAP::is_supported()) {
            if (\auth\LDAP::login($username, $password)) {
                $_SESSION['USER_OBJ'] = $associations;
                return TRUE;
            }
            else {
                return FALSE;
            }
        }
        elseif ($method == \auth\LDAP::METHOD_NAME && !\auth\LDAP::is_supported()){
            addError("Authentication method not supported. Please try another one.");
        }
        # END Authenticate with LDAP

        # Authentication with SSH
        if($method == \auth\Local::METHOD_NAME && \auth\Local::is_supported()){
            if(\auth\Local::login($username, $password)){
                $_SESSION['USER_OBJ'] = $associations;
                return TRUE;
            }
            else {
                return FALSE;
            }
        }
        elseif ($method == \auth\Local::METHOD_NAME && !\auth\Local::is_supported()){
            addError("Authentication method not supported. Please try another one.");
        }
        # END Authentication with SSH

        return FALSE;
    }

}

namespace auth {
    /**
     * Get a list of login methods.
     * @return array Returns an array where the keys are the names of the authentication methods,
     * and the values are arrays of configuration parameters. such as "supported" (bool) and "default" (bool).
     */
    function get_methods() : array {
        $methods = array();

        if( \auth\LDAP::is_supported() ){
            $methods['ldap'] = array('supported' => TRUE, 'default' => TRUE);
        } else {
            $methods['ldap'] = array('supported' => FALSE);
        }

        $methods['local'] = array('supported' => \auth\Local::is_supported());

        return $methods;
    }

    /**
     * Checks whether $username is a valid posix / linux username.
     * See https://unix.stackexchange.com/a/435120.
     * @param string $username Username to check
     * @return bool TRUE if username is valid, FALSE otherwise.
     */
    function validate_username(string $username) : bool {
        // Pattern: ^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\$)$
        $pattern = '/^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\\$)$/';
        return (bool)preg_match($pattern, $username);
    }

    /**
     * Checks whether an IP address matches an address or a CIDR range.
     * @param string $ip IP address to check.
     * @param string $range IP address or CIDR range, e.g. "10.0.0.1" or "172.16.0.0/12".
     * @return bool TRUE if the address is covered, FALSE otherwise or if an argument is invalid.
     */
    function ip_in_range(string $ip, string $range): bool {
        [$subnet, $prefix] = array_pad(explode('/', $range, 2), 2, NULL);
        if (filter_var($ip, FILTER_VALIDATE_IP) === FALSE || filter_var($subnet, FILTER_VALIDATE_IP) === FALSE) {
            return FALSE;
        }
        $ip_bin     = inet_pton($ip);
        $subnet_bin = inet_pton($subnet);
        if (strlen($ip_bin) !== strlen($subnet_bin)) {
            return FALSE;
        }
        $bits = $prefix === NULL ? strlen($ip_bin) * 8 : (int)$prefix;
        $full_bytes = intdiv($bits, 8);
        if (substr($ip_bin, 0, $full_bytes) !== substr($subnet_bin, 0, $full_bytes)) {
            return FALSE;
        }
        if ($bits % 8 === 0) {
            return TRUE;
        }
        $mask = 0xFF << (8 - $bits % 8) & 0xFF;
        return (ord($ip_bin[$full_bytes]) & $mask) === (ord($subnet_bin[$full_bytes]) & $mask);
    }

    /**
     * Checks whether an IP address belongs to a configured reverse proxy.
     * @param string $ip IP address to check.
     * @return bool TRUE if the address is covered by TRUSTED_PROXIES, FALSE otherwise.
     */
    function is_trusted_proxy(string $ip): bool {
        foreach (config('TRUSTED_PROXIES') as $range) {
            if (ip_in_range($ip, $range)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Determines the address of the client.
     * X-Forwarded-For is only evaluated for requests from a trusted proxy, and from right to left,
     * because clients can forge all entries that are left of the ones added by trusted proxies.
     * @return string Address of the client, REMOTE_ADDR if no other address can be determined.
     */
    function client_ip(): string {
        $remote_addr = $_SERVER['REMOTE_ADDR'];
        if (!is_trusted_proxy($remote_addr)) {
            return $remote_addr;
        }
        $hops = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === FALSE) {
                return $remote_addr;
            }
            if (!is_trusted_proxy($hop)) {
                return $hop;
            }
        }
        return $remote_addr;
    }

    /**
     * Rate limit for user logins.
     * @return bool TRUE if the limit is reached (and thus the login is NOT permitted), FALSE otherwise.
     */
    function rate_limit() : bool {
        if( ! isset($_SERVER['REMOTE_ADDR']) ){
            log_msg(
                "REMOTE_ADDR is not set. Rate limiting does not work.",
                LOG_INFO,
                LOG_MODE_PHP|LOG_MODE_SYSLOG
            );
            return FALSE;
        }
        $client_ip = client_ip();
        $key = 'login_attempts_from_' . md5($client_ip);
        $max_attempts = 3;

        $cache = \cache\CacheWrapper::getInstance();
        // add() only writes if the key does not yet exist, preserving the TTL on subsequent calls
        $cache->add($key, 0, 60);
        $attempts = $cache->increment($key);

        if ($attempts > $max_attempts) {
            log_msg(
                "Client " . $client_ip .
                " has reached the rate limit and has been restricted for 60 seconds.",
                LOG_INFO,
                LOG_MODE_PHP|LOG_MODE_SYSLOG
            );
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Checks if the current user is an admin.
     * @note This function also returns FALSE if the user is not logged in.
     * @return bool TRUE if the currently logged-in user is a SLURM admin, FALSE otherwise.
     */
    function current_user_is_admin() : bool {
        return
            isset($_SESSION['USER']) &&
            isset($_SESSION['USER_OBJ']['users'][0]['administrator_level']) &&
            isset($_SESSION['USER_OBJ']['users'][0]['administrator_level'][0]) &&
            $_SESSION['USER_OBJ']['users'][0]['administrator_level'][0] == "Administrator";
    }

    /**
     * Check if the currently logged-in user has sufficient privileges to view sensitive information.
     * This is the case if he is either a SLURM admin, or in the array $privileged_users.
     * @note This function also returns FALSE if the user is not logged in.
     * @return bool TRUE if the user is privileged, FALSE otherwise.
     */
    function current_user_is_privileged() : bool {
        return isset($_SESSION['USER']) &&
               (current_user_is_admin() || in_array($_SESSION['USER'], config('PRIV_USERS'), TRUE));
    }

    function get_csrf_token(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    function validate_csrf_token(string $token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}