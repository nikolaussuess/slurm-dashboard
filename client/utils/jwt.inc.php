<?php

namespace client\utils\jwt;

use const \auth\TO_BE_REPLACED;

/**
 * IMPORTANT:
 * This authentication class is NOT for (end) user authentication but for
 * authentication at slurmrestd.
 */
class JwtAuthentication {

    private const JWT_PATH = TO_BE_REPLACED;
    private const JWT_DEFAULT_LIFESPAN = 120;

    public static function is_supported() : bool {
        if(self::JWT_PATH == TO_BE_REPLACED)
            return FALSE;
        if(! file_exists(self::JWT_PATH) || ! is_readable(self::JWT_PATH) )
            return FALSE;
        return TRUE;
    }

    /**
     * per https://stackoverflow.com/questions/2040240/php-function-to-generate-v4-uuid/15875555#15875555
     * @param $text string String to encode
     * @return string base64-encoded string
     */
    static function base64_url_encode(string $text) : string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($text));
    }

    /**
     * based on: https://stackoverflow.com/a/73271804
     * But Slurm uses HS256 and SHA256, and the payload is structured as described at
     * https://slurm.schedmd.com/jwt.html#compatibility
     * @param $user string username
     * @param $lifespan int Lifespan in seconds
     * @return string JWT key
     */
    static function gen_jwt(string $user, int $lifespan = self::JWT_DEFAULT_LIFESPAN) : string {
        $signing_key = file_get_contents(self::JWT_PATH);

        $header = [
            "alg" => "HS256",
            "typ" => "JWT"
        ];
        $header = self::base64_url_encode(json_encode($header));
        $payload =  [
            "iat" => time(),
            "exp" => time() + $lifespan,
            "username" => $user
        ];
        $payload = self::base64_url_encode(json_encode($payload));
        $signature = self::base64_url_encode(hash_hmac('sha256', "$header.$payload", $signing_key, true));
        $jwt = "$header.$payload.$signature";
        return $jwt;
    }
}