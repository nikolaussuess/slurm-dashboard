<?php

namespace auth {
    require_once "auth.inc.php";

    class LDAP implements AuthenticationMethod {
        private const URI = TO_BE_REPLACED;
        private const BASE = TO_BE_REPLACED;
        private const ADMIN_USER = TO_BE_REPLACED;
        private const ADMIN_PASSWORD = TO_BE_REPLACED;
        public const METHOD_NAME = 'ldap';

        public static function is_supported(): bool {
            // LDAP functions must be available
            if (!function_exists("ldap_connect") || !function_exists("ldap_bind")) {
                return FALSE;
            }

            if (self::URI == TO_BE_REPLACED ||
                self::BASE == TO_BE_REPLACED ||
                self::ADMIN_USER == TO_BE_REPLACED ||
                self::ADMIN_PASSWORD == TO_BE_REPLACED) {
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
            $ldapConn = ldap_connect(self::URI);
            if (!$ldapConn) {
                addError("Could not connect to LDAP server.");
                return FALSE;
            }

            ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);
            ldap_start_tls($ldapConn); // Start TLS


            $ldap_dn = 'cn=' . self::ADMIN_USER . ',' . self::BASE;
            $ldap_password = self::ADMIN_PASSWORD;

            $login_ok = FALSE;

            // Bind to the LDAP server
            if (@ldap_bind($ldapConn, $ldap_dn, $ldap_password)) {
                // Search for the user
                $filter = "(uid=$username)"; // Change this filter based on your LDAP schema
                $result = ldap_search($ldapConn, self::BASE, $filter);
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

    if(\auth\LDAP::is_supported()){
        $methods[] = \auth\LDAP::METHOD_NAME;
    }

}
