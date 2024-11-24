<?php

/**
 * Some helper functions that help reading the JSON from slurmrestd.
 */

namespace utils;
function get_number_if_defined(array $arr, string $default = 'undefined') : string {
    if($arr['set'])
        return $arr['number'];
    else
        return $default;
}

function read_exit_code(array $job_arr): string {
    if(! isset($job_arr['exit_code'])){
        return "?";
    }

    if(isset($job_arr['exit_code']['return_code'])){
        if(isset($job_arr['exit_code']['signal']) && isset($job_arr['exit_code']['signal']['name']) && $job_arr['exit_code']['signal']['id']['set'])
            return get_number_if_defined($job_arr['exit_code']['return_code']) . " with Signal " . $job_arr['exit_code']['signal']['name'] . ' (' . $job_arr['exit_code']['signal']['id']['number'] . ')';
        else
            return get_number_if_defined($job_arr['exit_code']['return_code']);
    }
    else {
        return get_number_if_defined($job_arr['exit_code']);
    }
}

function get_date_from_unix(array $job_arr, string $param): string {
    if(! isset($job_arr[$param]) || $job_arr[$param] == 0){
        return "?";
    }

    return date('Y-m-d H:i:s', $job_arr[$param]);
}

function get_date_from_unix_if_defined(array $job_arr, string $param, string $default = 'undefined') : string {
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
function get_elapsed_time(array $job_arr, string $param = 'elapsed'): string {
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
function get_timelimit_if_defined(array $job_arr, string $param, string $default = 'undefined'): string {
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

function get_job_state_view(array $job, string $param_name = 'job_state', ?string $param2 = NULL): string {
    $job_state_array = $job[$param_name];
    if($param2 != null)
        $job_state_array = $job_state_array[$param2];

    $job_state_text = '';
    foreach($job_state_array as $job_state) {
        $state_color = "#ffc107"; # orange
        if ($job_state == 'BOOT_FAIL' ||
            $job_state == 'CANCELLED' ||
            $job_state == 'DEADLINE' ||
            $job_state == 'FAILED' ||
            $job_state == 'NODE_FAIL' ||
            $job_state == 'OUT_OF_MEMORY' ||
            $job_state == 'STOPPED' ||
            $job_state == 'TIMEOUT'
        ) {
            $state_color = "#dc3545"; # Red
        } elseif (
            $job_state == 'COMPLETED' ||
            $job_state == 'CONFIGURING' ||
            $job_state == 'COMPLETING' ||
            $job_state == 'RUNNING' ||
            $job_state == 'RUNNING'
        ) {
            $state_color = "#28a745"; # green
        }

        $job_state_text .= '<span class="badge" style="background-color: ' . $state_color . '">' . $job_state . '</span> ';
    }
    return $job_state_text;
}

function get_nodes(array $job_arr) : string {
    if(isset($job_arr['job_resources']) && isset($job_arr['job_resources']['nodes']))
        return $job_arr['job_resources']['nodes'];
    else
        return "?";
}