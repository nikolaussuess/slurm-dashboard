<?php

namespace auth {
    require_once __DIR__ . "/auth.inc.php";

    /**
     * Authentication via LDAP.
     */
    class LDAP implements AuthenticationMethod {
        public const METHOD_NAME = 'ldap';

        private mixed $ldapConn;

        public static function is_supported(): bool {
            // LDAP functions must be available
            if (!function_exists("ldap_connect") || !function_exists("ldap_bind")) {
                return FALSE;
            }

            if (config('LDAP_URI') == TO_BE_REPLACED ||
                config('LDAP_BASE') == TO_BE_REPLACED ||
                config('LDAP_ADMIN_USER') == TO_BE_REPLACED ||
                config('LDAP_ADMIN_PASSWORD') == TO_BE_REPLACED) {
                return FALSE;
            }

            return TRUE;
        }

        /**
         * @param $username string Username to be checked
         * @param $password string Password
         * @return bool TRUE if authentication was successful, FALSE otherwise
         */
        public static function login(string $username, string $password): bool {
            $ldapConn = ldap_connect(config('LDAP_URI'));
            if (!$ldapConn) {
                addError("Could not connect to LDAP server.");
                return FALSE;
            }

            // Prevent LDAP injection
            if( \auth\validate_username($username) !== TRUE ){
                addError("Invalid username.");
                return FALSE;
            }

            ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);
            ldap_start_tls($ldapConn); // Start TLS


            $ldap_dn = 'cn=' . config('LDAP_ADMIN_USER') . ',' . config('LDAP_BASE');
            $ldap_password = config('LDAP_ADMIN_PASSWORD');

            $login_ok = FALSE;

            // Bind to the LDAP server
            if (@ldap_bind($ldapConn, $ldap_dn, $ldap_password)) {
                // Search for the user
                $escaped_username = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
                $filter = "(uid=$escaped_username)";
                $result = ldap_search($ldapConn, config('LDAP_BASE'), $filter);
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
                addError('LDAP ERROR: Failed to bind as admin. Please contact ' . config('ADMIN_EMAIL'));
                $login_ok = FALSE;
            }

            ldap_unbind($ldapConn);
            return $login_ok;
        }

        function __construct() {

            if(! self::is_supported() ){
                throw new \Exception("LDAP not supported on this server!");
            }

            $this->ldapConn = ldap_connect(config('LDAP_URI'));
            if (!$this->ldapConn) {
                throw new \Exception("Could not connect to LDAP server.", 403);
            }

            ldap_set_option($this->ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->ldapConn, LDAP_OPT_REFERRALS, 0);
            ldap_start_tls($this->ldapConn); // Start TLS


            $ldap_dn = 'cn=' . config('LDAP_ADMIN_USER') . ',' . config('LDAP_BASE');
            $ldap_password = config('LDAP_ADMIN_PASSWORD');

            // Bind to the LDAP server
            if (! @ldap_bind($this->ldapConn, $ldap_dn, $ldap_password)) {
                throw new \Exception('LDAP ERROR: Failed to bind as admin. Please contact ' . config('ADMIN_EMAIL'));
            }
        }

        function get_data_for_user(string $uid): array {

            // Prevent LDAP injection
            if( \auth\validate_username($uid) !== TRUE ){
                addError("Invalid username.");
                return array();
            }

            $escaped_uid = ldap_escape($uid, '', LDAP_ESCAPE_FILTER);
            $filter = "(uid=$escaped_uid)";
            $attributes = ["uid", "displayName", "department", "departmentNumber", "mail"];

            if( apcu_exists("ldap" . '/' . $filter)){
                return apcu_fetch("ldap" . '/' . $filter);
            }

            $result = ldap_search($this->ldapConn, config('LDAP_BASE'), $filter, $attributes, 0, 1);
            if ($result === FALSE) {
                addError("Error in ldap_search");
                return array();
            }
            $entries = ldap_get_entries($this->ldapConn, $result);

            apcu_store("ldap" . '/' . $filter , $entries, 600);

            return $entries;
        }

        function __destruct(){
            ldap_unbind($this->ldapConn);
        }
    }

}
