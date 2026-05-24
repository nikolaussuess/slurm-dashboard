<?php

/**
 * Some helper functions that help reading the JSON from slurmrestd.
 */

namespace utils;

use InvalidArgumentException;

function get_date_from_unix_if_defined(array $job_arr, string $param, string $default = 'undefined') : string {
    if(! isset($job_arr[$param])){
        return $default;
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

# TODO: Should in the future only do the *view* and NOT look into the original job array
function get_job_state_view(array $job, string $param_name = 'job_state'): string {
    $job_state_array = $job[$param_name];

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
            $job_state == 'RUNNING'
        ) {
            $state_color = "#28a745"; # green
        }

        $job_state_text .= '<span class="badge" style="background-color: ' . $state_color . '">' . htmlspecialchars($job_state, ENT_QUOTES, 'UTF-8') . '</span> ';
    }
    return $job_state_text;
}

function show_errors(array $response) : void {
    if(isset($response['errors']) && !empty($response['errors'])){
        foreach ($response['errors'] as $error){
            addError('<b>' . htmlspecialchars($error['error'], ENT_QUOTES, 'UTF-8') . '</b> (source: ' . htmlspecialchars($error['source'], ENT_QUOTES, 'UTF-8') . ')<br>' . htmlspecialchars($error['description'], ENT_QUOTES, 'UTF-8'));
        }
    }
}

function validate_time_limit(string $str): bool {
    $pattern = '/^(?:(\d+)-)?((0)?[0-9]|1[0-9]|2[0-3]):([0-5][0-9])$/';
    return preg_match($pattern, $str) === 1;
}

function slurmTimeLimitFromString(string $time): array {

    if($time === "infinite"){
        return array("set"=> 0, "infinite"=> 1);
    }

    // Regex to match D-HH:MM:SS or HH:MM
    $pattern = '/^(?:(\d+)-)?((0)?[0-9]|1[0-9]|2[0-3]):([0-5][0-9])$/';

    if (!preg_match($pattern, $time, $matches)) {
        throw new InvalidArgumentException("Invalid time limit format: $time");
    }

    // Extract captured groups
    $days    = isset($matches[1]) ? (int)$matches[1] : 0;
    $hours   = (int)$matches[2];
    $minutes = (int)$matches[4];

    // Convert everything to minutes
    return array("set"=> 1, "infinite"=> 0, "number"=>$minutes + 60 * $hours + 1440 * $days);
}

function format_nullable_int(?int $value, string $suffix = ''): string {
    return $value === NULL ? '' : (number_format($value, 0, ',', '.') . $suffix);
}

function is_valid_username(string $username): bool {
    // Pattern: ^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\$)$
    // Copy from \auth\auth.inc.php
    $pattern = '/^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\\$)$/';
    return preg_match($pattern, $username) === 1;
}

/**
 * Checks whether a node name is contained in a Slurm nodelist string.
 * Handles comma-separated lists and bracket range notation (e.g. node[01-05,08]).
 */
function node_is_in_nodelist(string $node, string $nodelist): bool {
    if ($nodelist === '' || $nodelist === '?') return false;
    if ($node === $nodelist) return true;

    foreach (_split_nodelist($nodelist) as $part) {
        if (preg_match('/^(.+?)\[(.+)\]$/', $part, $m)) {
            $prefix = $m[1];
            if (!str_starts_with($node, $prefix)) continue;
            $suffix = substr($node, strlen($prefix));
            foreach (explode(',', $m[2]) as $range) {
                if (str_contains($range, '-')) {
                    [$lo, $hi] = explode('-', $range, 2);
                    if ((int)$suffix >= (int)$lo && (int)$suffix <= (int)$hi) return true;
                } elseif ($range === $suffix) {
                    return true;
                }
            }
        } elseif ($part === $node) {
            return true;
        }
    }
    return false;
}

/** Splits a Slurm nodelist on top-level commas (skipping commas inside brackets). */
function _split_nodelist(string $nodelist): array {
    $parts = [];
    $depth = 0;
    $current = '';
    for ($i = 0, $len = strlen($nodelist); $i < $len; $i++) {
        $c = $nodelist[$i];
        if ($c === '[') $depth++;
        elseif ($c === ']') $depth--;
        elseif ($c === ',' && $depth === 0) {
            if ($current !== '') { $parts[] = $current; $current = ''; }
            continue;
        }
        $current .= $c;
    }
    if ($current !== '') $parts[] = $current;
    return $parts;
}
