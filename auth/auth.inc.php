<?php

namespace auth {
    const TO_BE_REPLACED = '<TO BE REPLACED>';

    interface AuthenticationMethod {
        public static function is_supported(): bool;

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

        return FALSE;
    }

}

namespace auth {
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