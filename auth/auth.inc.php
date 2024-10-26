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

    require_once 'client.inc.php';
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
        if( count_chars($password) < 8 ){
            addError("Password too short.");
            return FALSE;
        }

        $dbquery = new Client();
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
}