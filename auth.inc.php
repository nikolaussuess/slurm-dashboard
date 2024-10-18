<?php

namespace {

    require_once 'client.inc.php';
    require_once 'globals.inc.php';

    /**
     * Authentication.
     * @param $username string Username of user
     * @param $password string Password of User
     * @return bool True if authentication was successful, False otherwise
     */
    function auth($username, $password){
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
        if(\auth\ldap_auth($username, $password)){
            $_SESSION['USER_OBJ'] = $associations;
            return TRUE;
        }
        else {
            return FALSE;
        }
        # END Authenticate with LDAP

        # For the Testserver only (where no LDAP is set up)
        # Comment the LDAP section above to use this ...
        if ( $username == "suessn98" && $password == "testtest"){
            $_SESSION['USER_OBJ'] = $associations;
            return TRUE;
        }
        else {
            return FALSE;
        }

    }

}

namespace auth {
    const URI = '<TO BE REPLACED>';
    const BASE = '<TO BE REPLACED>';
    const ADMIN_USER = '<TO BE REPLACED>';
    const ADMIN_PASSWORD = '<TO BE REPLACED>';

    /**
     * @param $username string Username to be checked
     * @param $password string Password
     * @return bool TRUE if authentication was successful, FALSE otherwise
     */
    function ldap_auth($username, $password){
        $ldapConn = ldap_connect(URI);
        if (!$ldapConn) {
            addError("Could not connect to LDAP server.");
            return FALSE;
        }

        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);
        ldap_start_tls($ldapConn); // Start TLS


        $ldap_dn = 'cn=' . ADMIN_USER . ',' . BASE;
        $ldap_password = ADMIN_PASSWORD;

        $login_ok = FALSE;

        // Bind to the LDAP server
        if (@ldap_bind($ldapConn, $ldap_dn, $ldap_password)) {
            // Search for the user
            $filter = "(uid=$username)"; // Change this filter based on your LDAP schema
            $result = ldap_search($ldapConn, BASE, $filter);
            $entries = ldap_get_entries($ldapConn, $result);

            if ($entries['count'] > 0) {
                // User found, try to bind with user's credentials
                $user_dn = $entries[0]['dn'];
                if (ldap_bind($ldapConn, $user_dn, $password)) {
                    addSuccess('Authentication successful!');
                    $login_ok = TRUE;
                } else {
                    addError('LDAP: Invalid credentials.');
                    $login_ok = FALSE;
                }
            } else {
                addError('User not found. Do you have a cs Account?');
                $login_ok = FALSE;
            }
        } else {
            addError('LDAP ERROR: Failed to bind as admin. Please contact ' . ADMIN_EMAIL);
            $login_ok = FALSE;
        }

        ldap_unbind($ldapConn);
        return $login_ok;
    }

}