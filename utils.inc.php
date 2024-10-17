<?php

/**
 * Some helper functions that help reading the JSON from slurmrestd.
 */

namespace utils;
function get_number_if_defined($arr, $default = 'undefined'){
    if($arr['set'])
        return $arr['number'];
    else
        return $default;
}

function read_exit_code($job_arr){
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

function get_date_from_unix($job_arr, $param){
    if(! isset($job_arr[$param]) || $job_arr[$param] == 0){
        return "?";
    }

    return date('Y-m-d H:i:s', $job_arr[$param]);
}

function get_date_from_unix_if_defined($job_arr, $param, $default = 'undefined'){
    if(! isset($job_arr[$param])){
        return "?";
    }

    if($job_arr[$param]['set'])
        return date('Y-m-d H:i:s', $job_arr[$param]['number']);
    else
        return $default;
}

function get_time_from_unix($job_arr, $param){
    if(! isset($job_arr[$param]) || $job_arr[$param] == 0 ){
        return "?";
    }

    # TODO: FIX BUG: elapsed is in seconds, not minutes!
    $days = floor($job_arr[$param] / 1440); // 1440 minutes in a day
    $hours = floor(($job_arr[$param] % 1440) / 60); // 60 minutes in an hour
    $remainingMinutes = $job_arr[$param] % 60; // Remaining minutes
    return sprintf('%d-%02d:%02d', $days, $hours, $remainingMinutes);
}

function get_time_from_unix_if_defined($job_arr, $param, $default = 'undefined'){
    if(! isset($job_arr[$param])){
        return "?";
    }

    if($job_arr[$param]['set']){
        $days = floor($job_arr[$param]['number'] / 1440); // 1440 minutes in a day
        $hours = floor(($job_arr[$param]['number'] % 1440) / 60); // 60 minutes in an hour
        $remainingMinutes = $job_arr[$param]['number'] % 60; // Remaining minutes
        return sprintf('%d-%02d:%02d', $days, $hours, $remainingMinutes);
    }
    else {
        return $default;
    }
}

function get_job_state_view($job, $param_name = 'job_state', $param2 = NULL){
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

function get_nodes($job_arr){
    return $job_arr['job_resources']['nodes'];
}