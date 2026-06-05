<?php

/**
 * Some helper functions that help reading the JSON from slurmrestd.
 */

namespace utils;

use InvalidArgumentException;

/**
 * Metadata per state group (label, badge color, CSS class).
 * Used together with SLURM_JOB_STATES to render optgroups and derive colors/classes.
 */
const SLURM_JOB_STATE_GROUP_META = [
    'Fail states'    => ['color' => '#dc3545', 'css_class' => 'state-fail'],
    'Success states' => ['color' => '#28a745', 'css_class' => 'state-success'],
    'Other states'   => ['color' => '#ffc107', 'css_class' => 'state-other'],
];

/**
 * Flat state → attribute map. Authoritative source for all state-related
 * lookups. Color and CSS class are resolved via SLURM_JOB_STATE_GROUP_META.
 * Used by get_job_state_view(), the filter form, and the active-filter chip bar.
 *
 * Structure: state name => [
 *   'group'    => group label (matches a key in SLURM_JOB_STATE_GROUP_META),
 *   'disabled' => bool (shown greyed-out in the filter dropdown),
 * ]
 */
const SLURM_JOB_STATES = [
    'BOOT_FAIL'     => ['group' => 'Fail states',    'disabled' => FALSE],
    'CANCELLED'     => ['group' => 'Fail states',    'disabled' => FALSE],
    'DEADLINE'      => ['group' => 'Fail states',    'disabled' => FALSE],
    'FAILED'        => ['group' => 'Fail states',    'disabled' => FALSE],
    'NODE_FAIL'     => ['group' => 'Fail states',    'disabled' => FALSE],
    'OUT_OF_MEMORY' => ['group' => 'Fail states',    'disabled' => FALSE],
    'STOPPED'       => ['group' => 'Fail states',    'disabled' => FALSE],
    'TIMEOUT'       => ['group' => 'Fail states',    'disabled' => FALSE],
    'RESV_DEL_HOLD' => ['group' => 'Fail states',    'disabled' => TRUE],
    'COMPLETED'     => ['group' => 'Success states', 'disabled' => FALSE],
    'COMPLETING'    => ['group' => 'Success states', 'disabled' => FALSE],
    'CONFIGURING'   => ['group' => 'Success states', 'disabled' => FALSE],
    'RUNNING'       => ['group' => 'Success states', 'disabled' => FALSE],
    'PENDING'       => ['group' => 'Other states',   'disabled' => FALSE],
    'PREEMPTED'     => ['group' => 'Other states',   'disabled' => FALSE],
    'SUSPENDED'     => ['group' => 'Other states',   'disabled' => FALSE],
    'REQUEUED'      => ['group' => 'Other states',   'disabled' => FALSE],
    'REQUEUE_FED'   => ['group' => 'Other states',   'disabled' => TRUE],
    'REQUEUE_HOLD'  => ['group' => 'Other states',   'disabled' => TRUE],
    'RESIZING'      => ['group' => 'Other states',   'disabled' => TRUE],
    'REVOKED'       => ['group' => 'Other states',   'disabled' => TRUE],
    'SIGNALING'     => ['group' => 'Other states',   'disabled' => TRUE],
    'SPECIAL_EXIT'  => ['group' => 'Other states',   'disabled' => TRUE],
    'STAGE_OUT'     => ['group' => 'Other states',   'disabled' => TRUE],
];

/**
* Extracts a date string from a Slurm time-object field in a job array.
 * Value 0 is treated as absent because Slurm reports 0 for indeterminate start times
* (e.g. jobs with unsatisfiable dependencies) as well as for unset fields.
 *
 * @param array  $job_arr Slurm job data array.
 * @param string $param   Key to look up; expects a Slurm time object with 'set' and 'number' keys.
 * @param string $default Fallback string returned when the field is absent, unset, or zero.
 * @return string Formatted date string ('Y-m-d H:i:s'), or $default.
 */
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

/**
 * Renders job state badges as HTML.
 *
 * @param array  $job        Slurm job data array.
 * @param string $param_name Key containing the job state array (default: 'job_state').
 * @return string HTML string of colored badge <span> elements, one per state value.
 */
# TODO: Should in the future only do the *view* and NOT look into the original job array
function get_job_state_view(array $job, string $param_name = 'job_state'): string {
    $job_state_array = $job[$param_name] ?? [];

    $job_state_text = '';
    foreach($job_state_array as $job_state) {
        $group = SLURM_JOB_STATES[$job_state]['group'] ?? NULL;
        $state_color = $group !== NULL ? SLURM_JOB_STATE_GROUP_META[$group]['color'] : '#ffc107';
        $job_state_text .= '<span class="badge" style="background-color: ' . $state_color . '">' . htmlspecialchars($job_state, ENT_QUOTES, 'UTF-8') . '</span> ';
    }
    return $job_state_text;
}

/**
 * Renders slurmrestd errors and optionally warnings as user-facing addError() messages.
 * Used after write operations (update_job, set_node_state) where the caller does not want to throw.
 * @param array $response Decoded slurmrestd response containing optional 'errors'/'warnings' keys.
 * @param bool $show_warnings Whether to also render warnings, not just errors.
 * @param bool $verbose Include error source and description in addition to the error string.
 */
function show_errors(array $response, bool $show_warnings = TRUE, bool $verbose = FALSE) : void {
    if(isset($response['errors']) && !empty($response['errors'])){
        foreach ($response['errors'] as $error){
            if($verbose)
                addError('<b>' . htmlspecialchars($error['error'], ENT_QUOTES, 'UTF-8') . '</b> (source: ' . htmlspecialchars($error['source'], ENT_QUOTES, 'UTF-8') . ')<br>' . htmlspecialchars($error['description'], ENT_QUOTES, 'UTF-8'));
            else
                addError(htmlspecialchars($error['error'], ENT_QUOTES, 'UTF-8'));
        }
    }
    if($show_warnings && isset($response['warnings']) && !empty($response['warnings'])){
        foreach ($response['warnings'] as $warning){
            if($verbose)
                addError('Warning: <b>' . htmlspecialchars($warning['warning'] ?? '', ENT_QUOTES, 'UTF-8') . '</b> (source: ' . htmlspecialchars($warning['source'] ?? '', ENT_QUOTES, 'UTF-8') . ')<br>' . htmlspecialchars($warning['description'] ?? '', ENT_QUOTES, 'UTF-8'));
            else
                addError('<b>Warning:</b> ' . htmlspecialchars($warning['warning'] ?? '', ENT_QUOTES, 'UTF-8'));
        }
    }
}

function log_errors_and_warnings_in_slurmrestd_response(array $json, string $prefix) : void {
    if ( ! empty($json['errors']) )
        log_msg($prefix . json_encode($json['errors']));
    if ( ! empty($json['warnings']) )
        log_msg("Warning/Notice: " . $prefix . json_encode($json['warnings']));
}

/**
 * Validates a Slurm time limit string.
 * Accepted formats: HH:MM or D-HH:MM.
 *
 * @param string $str Time limit string to validate.
 * @return bool TRUE if the format is valid, FALSE otherwise.
 */
function validate_time_limit(string $str): bool {
    $pattern = '/^(?:(\d+)-)?((0)?[0-9]|1[0-9]|2[0-3]):([0-5][0-9])$/';
    return preg_match($pattern, $str) === 1;
}

/**
 * Parses a Slurm time limit string (D-HH:MM or "infinite") into a slurmrestd number object.
 * @param string $time Time limit string, e.g. "1-12:30" or "infinite"
 * @return array Slurmrestd number object with 'set', 'infinite', and 'number' keys
 * @throws \InvalidArgumentException If the format is not recognized
 */
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

/**
 * Formats an integer with thousands separator and an optional unit suffix.
 *
 * @param int|null $value  Integer to format, or NULL.
 * @param string   $suffix Optional unit suffix appended after the formatted number.
 * @return string Formatted string (e.g. '1.234 GB'), or an empty string if $value is NULL.
 */
function format_nullable_int(?int $value, string $suffix = ''): string {
    return $value === NULL ? '' : (number_format($value, 0, ',', '.') . $suffix);
}

/**
 * Checks whether a string is a valid POSIX username.
 *
 * @param string $username Username to validate.
 * @return bool TRUE if valid, FALSE otherwise.
 */
function is_valid_username(string $username): bool {
    // Pattern: ^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\$)$
    // Copy from \auth\auth.inc.php
    $pattern = '/^[a-z_]([a-z0-9_-]{0,31}|[a-z0-9_-]{0,30}\\$)$/';
    return preg_match($pattern, $username) === 1;
}

/**
 * Converts a Slurm entity name (node, feature, partition, …) to a valid wiki URL segment.
 * Lowercases and replaces any sequence of non-alphanumeric characters with a hyphen.
 *
 * @param string $name Slurm entity name to convert.
 * @return string Lowercase hyphenated URL segment suitable for wiki page URLs.
 */
function canonical_wiki_segment(string $name): string {
    return trim(preg_replace('/[^a-z0-9_]+/', '-', strtolower($name)), '-');
}

/**
 * Wraps $innerHtml in a wiki link if a page or alias exists at $wikiUrl.
 * Falls back to alias resolution before giving up.
 *
 * @param string $wikiUrl   Wiki page URL to look up (e.g. 'node/gpu01').
 * @param string $innerHtml Already-escaped HTML content to wrap in the link.
 * @return string $innerHtml wrapped in an <a> tag, or $innerHtml unchanged if the wiki is
 *                disabled or neither a page nor an alias exists at $wikiUrl.
 */
function auto_link_if_exists(string $wikiUrl, string $innerHtml): string {
    if (!class_exists('\wiki\WikiDatabase', FALSE)) {
        return $innerHtml;
    }
    $db = \wiki\WikiDatabase::getInstance();
    if ($db === NULL) {
        return $innerHtml;
    }
    if ($db->pageExists($wikiUrl)) {
        return '<a href="?action=wiki&amp;url=' . urlencode($wikiUrl) . '" class="wiki-auto-link">'
             . $innerHtml . '</a>';
    }
    $alias = $db->getAlias($wikiUrl);
    if ($alias !== NULL) {
        $href = '?action=wiki&amp;url=' . urlencode($alias['target_url']);
        if ($alias['anchor'] !== '') {
            $href .= '#' . htmlspecialchars($alias['anchor'], ENT_QUOTES, 'UTF-8');
        }
        return '<a href="' . $href . '" class="wiki-auto-link">'
             . $innerHtml . '</a>';
    }
    return $innerHtml;
}

/**
 * @param string $innerHtml   Already-escaped HTML content to wrap in the link.
 * @param string $featureName Slurm feature name used to derive the wiki URL (feature/<segment>).
 * @return string $innerHtml wrapped in a wiki link, or unchanged if no page/alias exists.
 */
function auto_link_feature(string $innerHtml, string $featureName): string {
    return auto_link_if_exists('feature/' . canonical_wiki_segment($featureName), $innerHtml);
}

/**
 * @param string $innerHtml Already-escaped HTML content to wrap in the link.
 * @param string $nodeName  Slurm node name used to derive the wiki URL (node/<segment>).
 * @return string $innerHtml wrapped in a wiki link, or unchanged if no page/alias exists.
 */
function auto_link_node(string $innerHtml, string $nodeName): string {
    return auto_link_if_exists('node/' . canonical_wiki_segment($nodeName), $innerHtml);
}

/**
 * @param string $innerHtml     Already-escaped HTML content to wrap in the link.
 * @param string $partitionName Slurm partition name used to derive the wiki URL (partition/<segment>).
 * @return string $innerHtml wrapped in a wiki link, or unchanged if no page/alias exists.
 */
function auto_link_partition(string $innerHtml, string $partitionName): string {
    return auto_link_if_exists('partition/' . canonical_wiki_segment($partitionName), $innerHtml);
}

/**
 * @param string $innerHtml   Already-escaped HTML content to wrap in the link.
 * @param string $accountName Slurm account name used to derive the wiki URL (account/<segment>).
 * @return string $innerHtml wrapped in a wiki link, or unchanged if no page/alias exists.
 */
function auto_link_account(string $innerHtml, string $accountName): string {
    return auto_link_if_exists('account/' . canonical_wiki_segment($accountName), $innerHtml);
}

/**
 * Splits a comma-separated list, auto-links each entry via $linker, and rejoins with ', '.
 *
 * @param string   $csv    Comma-separated raw (unescaped) values.
 * @param callable $linker Callback with signature function(string $innerHtml, string $raw): string.
 * @return string Comma-separated HTML string with each entry passed through $linker.
 */
function auto_link_csv(string $csv, callable $linker): string {
    return implode(', ', array_map(
        fn($item) => $linker(htmlspecialchars($item, ENT_QUOTES, 'UTF-8'), $item),
        explode(',', $csv)
    ));
}

/**
 * Checks whether a node name is contained in a Slurm nodelist string.
 * Handles comma-separated lists and bracket range notation (e.g. node[01-05,08]).
 *
 * @param string $node     Single node name to search for.
 * @param string $nodelist Slurm nodelist expression to search in.
 * @return bool TRUE if $node is part of $nodelist, FALSE otherwise.
 */
function node_is_in_nodelist(string $node, string $nodelist): bool {
    if ($nodelist === '' || $nodelist === '?') return FALSE;
    if ($node === $nodelist) return TRUE;

    foreach (_split_nodelist($nodelist) as $part) {
        if (preg_match('/^(.+?)\[(.+)\]$/', $part, $m)) {
            $prefix = $m[1];
            if (!str_starts_with($node, $prefix)) continue;
            $suffix = substr($node, strlen($prefix));
            foreach (explode(',', $m[2]) as $range) {
                if (str_contains($range, '-')) {
                    [$lo, $hi] = explode('-', $range, 2);
                    if ((int)$suffix >= (int)$lo && (int)$suffix <= (int)$hi) return TRUE;
                } elseif ($range === $suffix) {
                    return TRUE;
                }
            }
        } elseif ($part === $node) {
            return TRUE;
        }
    }
    return FALSE;
}

/**
 * Splits a Slurm nodelist on top-level commas (skipping commas inside brackets).
 *
 * @param string $nodelist Slurm nodelist expression to split.
 * @return string[] Array of individual nodelist tokens.
 */
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
