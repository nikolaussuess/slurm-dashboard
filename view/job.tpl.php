<?php

namespace view\actions;

use TemplateLoader;

/**
 * Renders the parts of the job detail page: as tabs if there are several, otherwise with a plain heading.
 * @param array $sections Rendered HTML of each part, keyed by its title
 * @return string Rendered HTML, empty if there are no parts
 */
function get_job_sections(array $sections) : string {
    if(count($sections) < 2) {
        $contents = '';
        foreach($sections as $section_title => $section)
            $contents .= '<h2>' . $section_title . '</h2>' . $section;
        return $contents;
    }

    $tabs = '';
    $panes = '';
    $index = 0;
    foreach($sections as $section_title => $section) {
        $is_first = ($index === 0);
        $tabs .= '<li class="nav-item" role="presentation">'
               . '<button class="nav-link' . ($is_first ? ' active' : '') . '" type="button" role="tab"'
               . ' id="job-tab-' . $index . '" data-bs-toggle="tab" data-bs-target="#job-pane-' . $index . '"'
               . ' aria-controls="job-pane-' . $index . '" aria-selected="' . ($is_first ? 'true' : 'false') . '">'
               . $section_title . '</button></li>';
        $panes .= '<div class="tab-pane' . ($is_first ? ' show active' : '') . '" id="job-pane-' . $index . '"'
                . ' role="tabpanel" aria-labelledby="job-tab-' . $index . '" tabindex="0">' . $section . '</div>';
        $index++;
    }

    return '<p class="text-body-secondary mb-0">Information about this job comes from two sources: the live job queue and the accounting database (slurmdb). Use the tabs to switch between them.</p>'
         . '<ul class="nav nav-tabs job-tabs" role="tablist">' . $tabs . '</ul>'
         . '<div class="tab-content">' . $panes . '</div>';
}

/**
 * Renders the allocated and requested trackable resources (TRES) of a job.
 * @param array $tres TRES data of the job, with the optional keys 'allocated' and 'requested'
 * @return string Rendered HTML; a list without entries is shown as "none"
 */
function _render_tres_detail(array $tres) : string {
    $contents = '';
    foreach(['allocated' => 'Allocated', 'requested' => 'Requested'] as $key => $label){
        if(empty($tres[$key])){
            $contents .= '<div><b>' . $label . ':</b> <span class="text-body-secondary">none</span></div>';
            continue;
        }

        $contents .= '<b>' . $label . ':</b><ul>';
        foreach($tres[$key] as $entry){
            $contents .= '<li>Name: ' . ($entry['name'] !== '' ? htmlspecialchars($entry['name'], ENT_QUOTES, 'UTF-8') : '-') .
                         ', type: ' . htmlspecialchars($entry['type'], ENT_QUOTES, 'UTF-8') .
                         ', count: ' . $entry['count'] . '</li>';
        }
        $contents .= '</ul>';
    }
    return $contents;
}

/**
 * Renders the job detail view for a live slurmctld job.
 * @param array $query Job array as returned by Client::get_job()
 * @param string $transitive_dependencies Rendered HTML of transitive job dependencies
 * @return string Rendered HTML
 */
function get_slurm_jobinfo(array $query, string $transitive_dependencies = '') : string {

    // The reason is displayed as text here, so the badge does not need a tooltip.
    $job_state_text = \utils\get_job_state_view($query, reason_tooltip: FALSE);
    $reason_text = \utils\get_job_reason_text($query);
    if( $reason_text !== NULL ){
        $job_state_text .= '(Reason: ' . htmlspecialchars($reason_text, ENT_QUOTES, 'UTF-8') . ')';
    }
    // Note: $user can be an empty string if the user does not exist on the host where slurmrestd is running.
    if( ! empty($query['user_name']) ){
        $user = htmlspecialchars($query['user_name'] . " (" . $query['user_id'] . ')', ENT_QUOTES, 'UTF-8');
        $user_link = '<a href="?action=users&user_name='. htmlspecialchars($query['user_name'], ENT_QUOTES, 'UTF-8')
                     . '" title="Note: Currently, only admins can view this user details.">'
                     .         $user
                     . '</a>';
    }
    else {
        // We did not get a username, so display the user id only ...
        $user = $query['user_id'];
        $user_link = $user;
    }

    $group = htmlspecialchars($query['group_name'] . " (" . $query['group_id'] . ')', ENT_QUOTES, 'UTF-8');
    $requeue = $query['requeue'] ? 'allowed' : 'not allowed';

    $templateBuilder = new TemplateLoader("jobinfo.html");
    $templateBuilder->setParam("JOBID",             $query['job_id']                    );
    $templateBuilder->setParam("JOBNAME",           htmlspecialchars($query['job_name'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("USER",              $user_link                          );
    $templateBuilder->setParam("GROUP",             $group                              );
    $templateBuilder->setParam("ACCOUNT",           htmlspecialchars($query['account'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("PARTITIONS",        htmlspecialchars($query['partition'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("PRIORITY",    isset($query['priority']) ? (int)$query['priority'] : '');
    $templateBuilder->setParam("SUBMIT_LINE",       htmlspecialchars($query['submit_line'] ?? "", ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("WORKING_DIRECTORY", htmlspecialchars($query['working_directory'] ?? "", ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("COMMENT",           htmlspecialchars($query['comment'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("EXIT_CODE",         htmlspecialchars($query['exit_code'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("SCHEDNODES",  htmlspecialchars($query['scheduled_nodes'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("REQNODES",    htmlspecialchars($query['required_nodes'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("EXCLUDED_NODES", htmlspecialchars($query['excluded_nodes'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("NODES",             htmlspecialchars($query['nodes'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("QOS",               htmlspecialchars($query['qos'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("NICE",              isset($query['nice']) ? (int)$query['nice'] : '');
    $templateBuilder->setParam("CONTAINER",         htmlspecialchars($query['container'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("CONTAINER_ID",      htmlspecialchars($query['container_id'] ?? "", ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("ALLOCATING_NODE",   htmlspecialchars($query['allocating_node'] ?? "", ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("FLAGS",             implode('<br>', array_map(fn($f) => htmlspecialchars($f, ENT_QUOTES, 'UTF-8'), $query['flags'])));
    $templateBuilder->setParam("CORES_PER_SOCKET",  $query['cores_per_socket'] ?? '' );
    $templateBuilder->setParam("CPUS_PER_TASK",     $query['cpus_per_task'] ?? '' );
    $templateBuilder->setParam("DEADLINE",          $query['deadline'] ?? ''      );
    $templateBuilder->setParam("DEPENDENCY",        htmlspecialchars($query['dependency'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("TRANSITIVE_DEPENDENCIES", $transitive_dependencies      );
    $templateBuilder->setParam("FEATURES",          htmlspecialchars($query['features'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("GRES_DETAIL",       htmlspecialchars(implode(",", $query['gres'] ?? []), ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("CPUS",              $query['cpus'] ?? ''           );
    $templateBuilder->setParam("NODE_COUNT",        $query['node_count'] ?? ''     );
    $templateBuilder->setParam("TASKS",             $query['tasks'] ?? ''          );
    $templateBuilder->setParam("MEMORY_PER_CPU",    \utils\format_nullable_int($query['memory_per_cpu'], " MB"));
    $templateBuilder->setParam("MEMORY_PER_NODE",   \utils\format_nullable_int($query['memory_per_node'], " MB"));
    $templateBuilder->setParam("REQUEUE",           $requeue                            );
    $templateBuilder->setParam("RESTART_COUNT",     $query['restart_cnt'] ?? ''         );
    $templateBuilder->setParam("STANDARD_OUTPUT",   htmlspecialchars($query['standard_output'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("STANDARD_ERROR",    htmlspecialchars($query['standard_error'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("SUBMIT_TIME",       $query['submit_time']               );
    $templateBuilder->setParam("START_TIME",        $query['time_start']                );
    $templateBuilder->setParam("TIME_LIMIT",        $query['time_limit']                );
    $templateBuilder->setParam("JOB_STATE",         $job_state_text                     );

    return $templateBuilder->build();
}


/**
 * Renders the job detail view for a slurmdbd job history entry.
 * @param array $query Job array as returned by Client::get_job_from_slurmdb()
 * @return string Rendered HTML
 */
function get_slurmdb_jobinfo(array $query) : string {
    $job_state_text = \utils\get_job_state_view($query);

    $comment = '<ul>';
    if(is_array($query['comment'])){
        if(!empty($query['comment']['administrator']))
            $comment .= '<li><b>Admin comment:</b> ' . htmlspecialchars($query['comment']['administrator'], ENT_QUOTES, 'UTF-8') . '</li>';
        if(!empty($query['comment']['job']))
            $comment .= '<li><b>Job comment:</b> ' . htmlspecialchars($query['comment']['job'], ENT_QUOTES, 'UTF-8') . '</li>';
        if(!empty($query['comment']['system']))
            $comment .= '<li><b>System comment:</b> ' . htmlspecialchars($query['comment']['system'], ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $comment .= '</ul>';

    $flags = $query['flags'] ?? array();

    $templateBuilder = new TemplateLoader("jobinfo_slurmdb.html");
    $templateBuilder->setParam("JOBID",             $query['job_id']);
    $templateBuilder->setParam("JOBNAME",           htmlspecialchars($query['job_name'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("USER",              htmlspecialchars($query['user_name'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("GROUP",             htmlspecialchars($query['group_name'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("ACCOUNT",           htmlspecialchars($query['account'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("PARTITIONS",        htmlspecialchars($query['partition'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("PRIORITY",          isset($query['priority']) ? (int)$query['priority'] : '');
    $templateBuilder->setParam("SUBMIT_LINE",       htmlspecialchars($query['submit_line'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("WORKING_DIRECTORY", htmlspecialchars($query['working_directory'] ?? "", ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("COMMENT",           $comment);
    $templateBuilder->setParam("EXIT_CODE",         htmlspecialchars($query['exit_code'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("NODES",             htmlspecialchars($query['nodes'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("QOS",               htmlspecialchars($query['qos'] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("CONTAINER",         htmlspecialchars($query['container'] ?? '', ENT_QUOTES, 'UTF-8'));
    $escaped_flags = array_map(fn($f) => htmlspecialchars($f, ENT_QUOTES, 'UTF-8'), $flags);
    $templateBuilder->setParam("FLAGS", count($escaped_flags) > 0 ? '<li><span class="monospaced">' . implode('</span></li><li><span class="monospaced">', $escaped_flags) . '</span></li>' : '');
    $templateBuilder->setParam("GRES_DETAIL",       htmlspecialchars($query['gres'] ?? "", ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("TRES_DETAIL",       _render_tres_detail($query['tres'] ?? []));

    $templateBuilder->setParam("SUBMIT_TIME",       $query['time_submit']);
    $templateBuilder->setParam("TIME_LIMIT",        $query['time_limit']);
    $templateBuilder->setParam("TIME_ELAPSED",      $query['time_elapsed']);
    $templateBuilder->setParam("START_TIME",        $query['time_start']);
    $templateBuilder->setParam("END_TIME",          $query['time_end']);
    $templateBuilder->setParam("TIME_ELIGIBLE",     $query['time_eligible']);

    $templateBuilder->setParam("JOB_STATE",         $job_state_text);

    return $templateBuilder->build();
}