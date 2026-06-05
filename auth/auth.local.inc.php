<?php

namespace auth {

    require_once __DIR__ .  "/auth.inc.php";
    require_once __DIR__ .  "/../exceptions/AuthenticationError.php";

    use exceptions\AuthenticationError;

    /**
     * Authentication via local SSH connection.
     * I.e., this class connects with password authentication to a server via SSH.
     */
    class Local implements AuthenticationMethod {
        public const METHOD_NAME = 'local';

        public static function is_supported(): bool {
            // LDAP functions must be available
            if (!function_exists("ssh2_connect") || !function_exists("ssh2_auth_password")) {
                return FALSE;
            }

            if (config('SSH_SERVER_URL') == TO_BE_REPLACED) {
                return FALSE;
            }

            return TRUE;
        }

        /**
         * @param string $username Username to be checked
         * @param string $password Password
         * @throws AuthenticationError If the SSH connection fails
         * @return bool TRUE if authentication was successful, FALSE otherwise
         */
        public static function login(string $username, string $password): bool {
            $ssh = ssh2_connect(config('SSH_SERVER_URL'));
            if( $ssh === FALSE ){
                throw new AuthenticationError("Could not connect to local authentication server.");
            }

            // To catch a 500 error because of a wrong password
            try {
                $ok = @ssh2_auth_password($ssh, $username, $password);
            } catch (\Exception $e) {
                $ok = false;
            }

            if(function_exists("ssh2_disconnect")){
                @ssh2_disconnect($ssh);
            }
            return $ok;
        }
    }

}
