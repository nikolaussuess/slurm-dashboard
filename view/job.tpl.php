<?php

namespace view\actions;

use TemplateLoader;

function get_slurm_jobinfo(array $query, string $transitive_dependencies = '') : string {

    $contents = '<h2>Job queue information</h2>';

    $job_state_text = \utils\get_job_state_view($query);
    $user = htmlspecialchars($query['user_name'] . " (" . $query['user_id'] . ')', ENT_QUOTES, 'UTF-8');
    $user_name = htmlspecialchars($query['user_name'], ENT_QUOTES, 'UTF-8');
    $group = htmlspecialchars($query['group_name'] . " (" . $query['group_id'] . ')', ENT_QUOTES, 'UTF-8');
    $requeue = $query['requeue'] ? 'allowed' : 'not allowed';

    $templateBuilder = new TemplateLoader("jobinfo.html");
    $templateBuilder->setParam("JOBID",             $query['job_id']                    );
    $templateBuilder->setParam("JOBNAME",           htmlspecialchars($query['job_name'], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("USER",              $user                               );
    $templateBuilder->setParam("USER_NAME",         $user_name                          );
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
    $templateBuilder->setParam("SUBMIT_TIME",       $query['submit_time']               );
    $templateBuilder->setParam("TIME_LIMIT",        $query['time_limit']                );
    $templateBuilder->setParam("JOB_STATE",         $job_state_text                     );

    $contents .= $templateBuilder->build();

    return $contents;
}


function get_slurmdb_jobinfo(array $query) : string {
    $contents = '<h2>Slurmdb information</h2>';

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

    $tres_detail = '';
    if(isset($query['tres']) && isset($query['tres']['allocated'])){
        $tres_detail .= '<b>Allocated:</b><ul>';
        foreach($query['tres']['allocated'] as $tres){
            $tres_detail .= '<li>Name: ' . htmlspecialchars($tres['name'], ENT_QUOTES, 'UTF-8') .
                            ', type: ' . htmlspecialchars($tres['type'], ENT_QUOTES, 'UTF-8') .
                            ', count: ' . $tres['count'] . '</li>';
        }
        $tres_detail .= '</ul>';
    }
    if(isset($query['tres']) && isset($query['tres']['requested'])){
        $tres_detail .= '<b>Requested:</b><ul>';
        foreach($query['tres']['requested'] as $tres){
            $tres_detail .= '<li>Name: ' . htmlspecialchars($tres['name'], ENT_QUOTES, 'UTF-8') .
                            ', type: ' . htmlspecialchars($tres['type'], ENT_QUOTES, 'UTF-8') .
                            ', count: ' . $tres['count'] . '</li>';
        }
        $tres_detail .= '</ul>';
    }

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
    $templateBuilder->setParam("TRES_DETAIL",       $tres_detail);

    $templateBuilder->setParam("SUBMIT_TIME",       $query['time_submit']);
    $templateBuilder->setParam("TIME_LIMIT",        $query['time_limit']);
    $templateBuilder->setParam("TIME_ELAPSED",      $query['time_elapsed']);
    $templateBuilder->setParam("START_TIME",        $query['time_start']);
    $templateBuilder->setParam("END_TIME",          $query['time_end']);
    $templateBuilder->setParam("TIME_ELIGIBLE",     $query['time_eligible']);

    $templateBuilder->setParam("JOB_STATE",         $job_state_text);

    $contents .= $templateBuilder->build();

    return $contents;
}