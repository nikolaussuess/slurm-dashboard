<?php

namespace client;

require_once 'Request.inc.php';
use Error;

interface Client{
    function is_available() : bool;
    function getNodeList(): array;
    function get_jobs(?array $filter = NULL) : array;
    function get_jobs_from_slurmdb(?array $filter = NULL) : array;
    function get_account_list(): array;
    function get_users_list(): array;
    function get_job(string $id) : ?array;
    function get_job_from_slurmdb(int|string $id) : ?array;
    function get_user(string $user_name) : array;
    function get_users() : array;
    function get_node_info(string $nodename) : array;
    function get_maintenances() : array;
}

abstract class AbstractClient implements Client {
    function __get_number_if_defined(array $arr, string $default = 'undefined') : string {
        if($arr['set'])
            return $arr['number'];
        else
            return $default;
    }

    function __get_date_from_unix(array $job_arr, string $param): string {
        if(! isset($job_arr[$param]) || $job_arr[$param] == 0){
            return "?";
        }

        return date('Y-m-d H:i:s', $job_arr[$param]);
    }

    function __get_date_from_unix_if_defined(array $job_arr, string $param, string $default = 'undefined') : string {
        if(! isset($job_arr[$param])){
            return "?";
        }

        if($job_arr[$param]['set']){
            if($job_arr[$param]['number'] == 0){
                // When a dependency can never be satisfied, a depending job may never be started.
                // In this case, 0 is reported as start_time (which is 1970/1/1).
                // However, 0 is also returned if start_time cannot yet be determined.
                //
                // This is a fix for \utils\get_date_from_unix_if_defined to handle this,
                // i.e. it prints "undefined" (or $default) instead of 1970/1/1.
                return $default;
            }
            else {
                return date('Y-m-d H:i:s', $job_arr[$param]['number']);
            }
        }
        else {
            return $default;
        }
    }

    /**
     * Elapsed time is in seconds. Display it accordingly.
     * @param $job_arr array Job array as from JSON
     * @param $param string Array index (e.g. elapsed)
     * @return string The time in D-HH:MM:SS
     */
    function __get_elapsed_time(array $job_arr, string $param = 'elapsed'): string {
        if(! isset($job_arr[$param]) || $job_arr[$param] == 0 ){
            return "?";
        }

        $totalSeconds = $job_arr[$param]; // Assuming $job_arr[$param] is in seconds
        $days = floor($totalSeconds / 86400); // 86400 seconds in a day
        $hours = floor(($totalSeconds % 86400) / 3600); // 3600 seconds in an hour
        $remainingMinutes = floor(($totalSeconds % 3600) / 60); // Remaining minutes
        $remainingSeconds = $totalSeconds % 60; // Remaining seconds

        return sprintf('%d-%02d:%02d:%02d', $days, $hours, $remainingMinutes, $remainingSeconds);
    }

    /**
     * Time limit is in minutes, so the input is minutes.
     * @param $job_arr array Job array as from JSON
     * @param $param string Array index (e.g. time_limit)
     * @param $default string what to return if not set.
     * @return string The time limit in D-HH:MM:SS
     */
    function __get_timelimit_if_defined(array $job_arr, string $param, string $default = 'undefined'): string {
        if(! isset($job_arr[$param])){
            return "?";
        }

        if($job_arr[$param]['set']){
            $days = floor($job_arr[$param]['number'] / 1440); // 1440 minutes in a day
            $hours = floor(($job_arr[$param]['number'] % 1440) / 60); // 60 minutes in an hour
            $remainingMinutes = $job_arr[$param]['number'] % 60; // Remaining minutes
            return sprintf('%d-%02d:%02d:00', $days, $hours, $remainingMinutes);
        }
        else {
            return $default;
        }
    }


}

class ClientFactory {
    public static function newClient($version = REST_API_VERSION) : Client {
        $classname = strtoupper(str_replace('.', '', $version)) . "Client";
        if(file_exists(__DIR__ . "/{$classname}.inc.php")){
            require_once __DIR__ . "/{$classname}.inc.php";
        }
        else {
            throw new Error("API version currently unsupported. No client found.");
        }

        $classname = '\\client\\' . $classname;
        return new $classname();
    }

}
