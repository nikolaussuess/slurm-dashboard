<?php

namespace auth {

    require_once "auth.inc.php";

    /**
     * Authentication via local SSH connection.
     * I.e., this class connects with password authentication to a server via SSH.
     */
    class Local implements AuthenticationMethod {
        private const SERVER_URL = TO_BE_REPLACED;
        public const METHOD_NAME = 'local';

        public static function is_supported(): bool {
            // LDAP functions must be available
            if (!function_exists("ssh2_connect") || !function_exists("ssh2_auth_password")) {
                return FALSE;
            }

            if (self::SERVER_URL == TO_BE_REPLACED) {
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
            $ssh = ssh2_connect(self::SERVER_URL);
            if( $ssh === FALSE ){
                addError("Could not connect to local authentication server.");
                return FALSE;
            }

            $ok = @ssh2_auth_password($ssh, $username, $password);

            if(function_exists("ssh2_disconnect")){
                @ssh2_disconnect($ssh);
            }
            return $ok;
        }
    }

}
