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
 * Slurm reason code => human-readable description.
 * Codes that are missing here are shown as they are.
 * See https://slurm.schedmd.com/job_reason_codes.html
 */
const SLURM_JOB_REASONS = [
    'AccountingPolicy'         => 'An accounting policy limit prevents the job from starting',
    'AccountNotAllowed'        => 'The job\'s account is not allowed to use this partition',
    'AssociationJobLimit'      => 'The job limit of the association has been reached',
    'AssociationResourceLimit' => 'A resource limit of the association has been reached',
    'AssociationTimeLimit'     => 'The time limit of the association has been reached',
    'BadConstraints'           => 'The job\'s constraints cannot be satisfied',
    'BeginTime'                => 'The requested earliest start time has not been reached yet',
    'Cleaning'                 => 'The job is being requeued and is still cleaning up from its previous run',
    'Constraints'              => 'The job\'s constraints cannot currently be satisfied',
    'Dependency'               => 'The job is waiting for a dependency to be satisfied',
    'DependencyNeverSatisfied' => 'A dependency of the job can never be satisfied',
    'FedJobLock'               => 'The job is waiting for the federated clusters to synchronize',
    'InvalidAccount'           => 'The job\'s account is invalid',
    'InvalidQOS'               => 'The job\'s QOS is invalid',
    'JobArrayTaskLimit'        => 'The maximum number of simultaneously running array tasks has been reached',
    'JobHeldAdmin'             => 'The job is held by an administrator',
    'JobHeldUser'              => 'The job is held by the user',
    'JobHoldMaxRequeue'        => 'The job is held because it reached the maximum number of requeues',
    'JobLaunchFailure'         => 'The job could not be launched',
    'Licenses'                 => 'The job is waiting for licenses',
    'NodeDown'                 => 'A node required by the job is down',
    'None'                     => 'The job has not been evaluated by the scheduler yet',
    'PartitionConfig'          => 'The job\'s request does not fit the partition configuration',
    'PartitionDown'            => 'The partition is down',
    'PartitionInactive'        => 'The partition is inactive',
    'PartitionNodeLimit'       => 'The requested number of nodes is outside the limits of the partition',
    'PartitionTimeLimit'       => 'The requested time limit exceeds the limit of the partition',
    'Priority'                 => 'Jobs with a higher priority are waiting for this partition or reservation',
    'Prolog'                   => 'The job\'s prolog is still running',
    'QOSJobLimit'              => 'The job limit of the QOS has been reached',
    'QOSNotAllowed'            => 'The job\'s QOS is not allowed to use this partition',
    'QOSResourceLimit'         => 'A resource limit of the QOS has been reached',
    'QOSTimeLimit'             => 'The time limit of the QOS has been reached',
    'QOSUsageThreshold'        => 'The usage threshold of the QOS has been exceeded',
    'ReqNodeNotAvail'          => 'A node required by the job is currently not available',
    'Reservation'              => 'The job is waiting for its reservation to become available',
    'ReservationDeleted'       => 'The job\'s reservation has been deleted',
    'Resources'                => 'The requested resources are currently not available',
    'SchedDefer'               => 'Immediate scheduling is deferred by the scheduler configuration',
    'SystemFailure'            => 'A failure of the Slurm system, file system or network occurred',
];

/**
 * Regex => human-readable description for families of reason codes, e.g. QOSGrpCpuLimit.
 * Only used for codes that are not listed in SLURM_JOB_REASONS.
 */
const SLURM_JOB_REASON_PATTERNS = [
    '/^AssocGrp/'         => 'An aggregate limit of the association has been reached',
    '/^AssocMax/'         => 'The request exceeds a maximum set for the association',
    '/^QOSGrp/'           => 'An aggregate limit of the QOS has been reached',
    '/^QOSMax/'           => 'The request exceeds a maximum set for the QOS',
    '/^QOSMin/'           => 'The request is below the minimum required by the QOS',
    '/^Max.*PerAccount$/' => 'The request exceeds a per-account limit of the QOS',
    '/^BurstBuffer/'      => 'The job is waiting for burst buffer resources or staging',
];

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
 * Translates a Slurm reason code into a human-readable description.
 * @param string $reason Slurm reason code, e.g. "Resources".
 * @return string The description, or $reason itself if the code is unknown.
 */
function get_job_reason_description(string $reason): string {
    if(isset(SLURM_JOB_REASONS[$reason])){
        return SLURM_JOB_REASONS[$reason];
    }
    foreach(SLURM_JOB_REASON_PATTERNS as $pattern => $description){
        if(preg_match($pattern, $reason)){
            return $description;
        }
    }
    return $reason;
}

/**
 * Builds the plain-text explanation why a job is pending. Must be escaped before output.
 * @param array $job Job array as returned by the client.
 * @return ?string The explanation, or NULL if the job is not pending or no reason is known.
 */
function get_job_reason_text(array $job): ?string {
    $reason = $job['state_reason'] ?? NULL;
    if(empty($reason) || !in_array('PENDING', $job['job_state'] ?? [], TRUE)){
        return NULL;
    }

    $description = get_job_reason_description($reason);
    $text = $description === $reason ? $reason : $description . ' (' . $reason . ')';

    // state_description may hold details like the names of unavailable nodes.
    $details = $job['state_description'] ?? NULL;
    if(!empty($details) && $details !== $reason){
        $text .= ': ' . $details;
    }
    return $text;
}

/**
 * Checks whether at least one of the given jobs is pending.
 * @param array $jobs Job arrays as returned by the client.
 * @return bool TRUE if a pending job was found, FALSE otherwise.
 */
function has_pending_job(array $jobs): bool {
    foreach($jobs as $job){
        if(in_array('PENDING', $job['job_state'] ?? [], TRUE)){
            return TRUE;
        }
    }
    return FALSE;
}

/**
 * Copies the pending reasons from the live queue to job history entries.
 * @param array $history_jobs Job arrays as returned by Client::get_jobs_from_slurmdb().
 * @param array $live_jobs Job arrays as returned by Client::get_jobs().
 * @return array $history_jobs, with the reasons of all jobs that are also in $live_jobs.
 */
function merge_live_job_reasons(array $history_jobs, array $live_jobs): array {
    $live_jobs_by_id = array_column($live_jobs, NULL, 'job_id');
    foreach($history_jobs as &$job){
        $live_job = $live_jobs_by_id[$job['job_id']] ?? NULL;
        if($live_job !== NULL){
            $job['state_reason'] = $live_job['state_reason'];
            $job['state_description'] = $live_job['state_description'];
        }
    }
    unset($job);
    return $history_jobs;
}

# TODO: Should in the future only do the *view* and NOT look into the original job array
/**
 * Renders the states of a job as badges.
 * @param array $job Job array as returned by the client.
 * @param string $param_name Key of the state array in $job.
 * @param bool $reason_tooltip Whether the reason of a pending job is shown as a tooltip.
 * @return string Rendered HTML
 */
function get_job_state_view(array $job, string $param_name = 'job_state', bool $reason_tooltip = TRUE): string {
    $job_state_array = $job[$param_name] ?? [];
    $reason_text = $reason_tooltip ? get_job_reason_text($job) : NULL;

    $job_state_text = '';
    foreach($job_state_array as $job_state) {
        $group = SLURM_JOB_STATES[$job_state]['group'] ?? NULL;
        $state_color = $group !== NULL ? SLURM_JOB_STATE_GROUP_META[$group]['color'] : '#ffc107';
        $badge = '<span class="badge" style="background-color: ' . $state_color . '">' . htmlspecialchars($job_state, ENT_QUOTES, 'UTF-8') . '</span> ';
        if($job_state === 'PENDING' && $reason_text !== NULL) {
            // Badge and icon form one button, so that a tap on either opens the reason.
            // The tooltip is initialized in public/index.php.
            $job_state_text .= '<button type="button" class="bg-transparent border-0 p-0 text-reset job-reason"'
                             . ' title="Reason: ' . htmlspecialchars($reason_text, ENT_QUOTES, 'UTF-8') . '">'
                             . $badge . '<i title="Click here for more information">&#9432;</i></button> ';
        }
        else {
            $job_state_text .= $badge;
        }
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
