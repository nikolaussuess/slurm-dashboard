<?php

namespace auth {
    /**
     * Placeholder and default value in the auth classes for variables that must be overwritten.
     */
    const TO_BE_REPLACED = '<TO BE REPLACED>';

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

    require_once 'client/Client.inc.php';
    require_once 'globals.inc.php';
    require_once "auth.ldap.inc.php";
    require_once "auth.local.inc.php";

    /**
     * Authentication.
     * @param $username string Username of user
     * @param $password string Password of User
     * @return bool True if authentication was successful, False otherwise
     */
    function auth(string $username, string $password, string $method = 'ldap') : bool{

        if(\auth\rate_limit()){
            addError("Rate limit exceeded: Please wait 20 seconds until you try again!");
            http_response_code(429); // HTTP/1.1 429 Too Many Requests
            return FALSE;
        }

        if( count_chars($password) < 8 ){
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
     * @param $username string Username to check
     * @return bool TRUE if username is valid, FALSE otherwise.
     */
    function validate_username(string $username) : bool {
        // Pattern: ^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\$)$
        $pattern = '/^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\\$)$/';
        return (bool)preg_match($pattern, $username);
    }

    /**
     * Rate limit for user logins.
     * @return bool TRUE if the limit is reached (and thus the login is NOT permitted), FALSE otherwise.
     */
    function rate_limit() : bool {
        if( ! isset($_SERVER['REMOTE_ADDR']) ){
            syslog(LOG_WARNING, "slurm-dashboard: REMOTE_ADDR is not set. Rate limiting does not work.");
            return FALSE;
        }
        $userIp = $_SERVER['REMOTE_ADDR'];
        $key = 'login_from_' . md5($userIp);

        // Check if the key exists in APCu
        if (apcu_exists($key)) {
            syslog(LOG_INFO, "slurm-dashboard: REMOTE_ADDR " .
                           $_SERVER['REMOTE_ADDR'] .
                           " has reached the rate limit and has been restricted for 20 seconds.");
            return TRUE;
        }
        // Key does not exist, allow submission and set it with TTL
        apcu_store($key, time(), 20);
        return FALSE;
    }

    /**
     * Checks if the current user is an admin.
     * @return bool TRUE if the currently logged-in user is a SLURM admin, FALSE otherwise.
     */
    function current_user_is_admin() : bool {
        return
            isset($_SESSION['USER_OBJ']['users'][0]['administrator_level']) &&
            isset($_SESSION['USER_OBJ']['users'][0]['administrator_level'][0]) &&
            $_SESSION['USER_OBJ']['users'][0]['administrator_level'][0] == "Administrator";
    }

    /**
     * Check if the currently logged-in user has sufficient privileges to view sensitive information.
     * This is the case if he is either a SLURM admin, or in the array $privileged_users.
     * @return bool TRUE if the user is privileged, FALSE otherwise.
     */
    function current_user_is_privileged() : bool {
        global $privileged_users;
        return current_user_is_admin() || in_array($_SESSION['USER'], $privileged_users, TRUE);
    }
}