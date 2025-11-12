<?php

namespace view\actions;

use TemplateLoader;

function get_slurm_jobinfo(array $query, string $transitive_dependencies = '') : string {

    $contents = '<h3>Job queue information</h3>';

    $job_state_text = \utils\get_job_state_view($query);
    $user = $query['user_name'] . " (" . $query['user_id'] . ')';
    $group = $query['group_name'] . " (" . $query['group_id'] . ')';
    $requeue = $query['requeue'] ? 'yes' : 'no';

    $templateBuilder = new TemplateLoader("jobinfo.html");
    $templateBuilder->setParam("JOBID",             $query['job_id']                    );
    $templateBuilder->setParam("JOBNAME",           $query['job_name']                  );
    $templateBuilder->setParam("USER",              $user                               );
    $templateBuilder->setParam("GROUP",             $group                              );
    $templateBuilder->setParam("ACCOUNT",           $query['account']                   );
    $templateBuilder->setParam("PARTITIONS",        $query['partition']                 );
    $templateBuilder->setParam("PRIORITY",    $query['priority'] ?? ''            );
    $templateBuilder->setParam("SUBMIT_LINE", $query['submit_line'] ?? ""         );
    $templateBuilder->setParam("WORKING_DIRECTORY", $query['working_directory'] ?? "");
    $templateBuilder->setParam("COMMENT",           $query['comment'] ?? ''       );
    $templateBuilder->setParam("EXIT_CODE",         $query['exit_code'] ?? ''     );
    $templateBuilder->setParam("SCHEDNODES",  $query['scheduled_nodes'] ?? ''     );
    $templateBuilder->setParam("REQNODES",    $query['required_nodes'] ?? ''      );
    $templateBuilder->setParam("NODES",             $query['nodes']                     );
    $templateBuilder->setParam("QOS",               $query['qos'] ?? ''           );
    $templateBuilder->setParam("CONTAINER",         $query['container'] ?? ''     );
    $templateBuilder->setParam("CONTAINER_ID", $query['container_id'] ?? ""       );
    $templateBuilder->setParam("ALLOCATING_NODE",$query['allocating_node'] ?? ""  );
    $templateBuilder->setParam("FLAGS",             implode('<br>', $query['flags']));
    $templateBuilder->setParam("CORES_PER_SOCKET",  $query['cores_per_socket'] ?? '' );
    $templateBuilder->setParam("CPUS_PER_TASK",     $query['cpus_per_task'] ?? '' );
    $templateBuilder->setParam("DEADLINE",          $query['deadline'] ?? ''      );
    $templateBuilder->setParam("DEPENDENCY",        $query['dependency'] ?? ''    );
    $templateBuilder->setParam("TRANSITIVE_DEPENDENCIES", $transitive_dependencies      );
    $templateBuilder->setParam("FEATURES",          $query['features']                  );
    $templateBuilder->setParam("GRES_DETAIL",       implode(",", $query['gres']));
    $templateBuilder->setParam("CPUS",              $query['cpus']                      );
    $templateBuilder->setParam("NODE_COUNT",        $query['node_count']                );
    $templateBuilder->setParam("TASKS",             $query['tasks']                     );
    $templateBuilder->setParam("MEMORY_PER_CPU",   $query['memory_per_cpu'] ?? '' );
    $templateBuilder->setParam("MEMORY_PER_NODE",   $query['memory_per_node'] ?? '' );
    $templateBuilder->setParam("REQUEUE",           $requeue                            );
    $templateBuilder->setParam("SUBMIT_TIME",       $query['submit_time']               );
    $templateBuilder->setParam("TIME_LIMIT",        $query['time_limit']                );
    $templateBuilder->setParam("JOB_STATE",         $job_state_text                     );

    $contents .= $templateBuilder->build();

    return $contents;
}


function get_slurmdb_jobinfo(array $query) : string {
    $contents = '<h3>Slurmdb information</h3>';

    $job_state_text = \utils\get_job_state_view($query);

    $comment = '<ul>';
    if($query['comment']['administrator'] != '')
        $comment .= '<li><b>Admin comment:</b> ' .$query['comment']['administrator'] . '</li>';
    if($query['comment']['job'] != '')
        $comment .= '<li><b>Job comment:</b> ' .$query['comment']['job'] . '</li>';
    if($query['comment']['system'] != '')
        $comment .= '<li><b>System comment:</b> ' .$query['comment']['system'] . '</li>';
    $comment .= '</ul>';

    $flags = $query['flags'] ?? array();

    $tres_detail = '';
    if(isset($query['tres']) && isset($query['tres']['allocated'])){
        $tres_detail .= '<b>Allocated:</b><ul>';
        foreach($query['tres']['allocated'] as $tres){
            $tres_detail .= '<li>Name: ' . $tres['name'] . ', type: ' . $tres['type'] . ', count: ' . $tres['count'] . '</li>';
        }
        $tres_detail .= '</ul>';
    }
    if(isset($query['tres']) && isset($query['tres']['requested'])){
        $tres_detail .= '<b>Requested:</b><ul>';
        foreach($query['tres']['requested'] as $tres){
            $tres_detail .= '<li>Name: ' . $tres['name'] . ', type: ' . $tres['type'] . ', count: ' . $tres['count'] . '</li>';
        }
        $tres_detail .= '</ul>';
    }

    $templateBuilder = new TemplateLoader("jobinfo_slurmdb.html");
    $templateBuilder->setParam("JOBID",             $query['job_id']);
    $templateBuilder->setParam("JOBNAME",           $query['job_name']);
    $templateBuilder->setParam("USER",              $query['user_name']);
    $templateBuilder->setParam("GROUP",             $query['group_name']);
    $templateBuilder->setParam("ACCOUNT",           $query['account']);
    $templateBuilder->setParam("PARTITIONS",        $query['partition']);
    $templateBuilder->setParam("PRIORITY",          $query['priority']);
    $templateBuilder->setParam("SUBMIT_LINE",       $query['submit_line']);
    $templateBuilder->setParam("WORKING_DIRECTORY", $query['working_directory'] ?? "");
    $templateBuilder->setParam("COMMENT",           $comment);
    $templateBuilder->setParam("EXIT_CODE",         $query['exit_code']);
    $templateBuilder->setParam("NODES",             $query['nodes']);
    $templateBuilder->setParam("QOS",               $query['qos']);
    $templateBuilder->setParam("CONTAINER",         $query['container']);
    $templateBuilder->setParam("FLAGS", count($flags) > 0 ? '<li><span class="monospaced">' . implode('</li><li><span class="monospaced">', $flags) . '</span></li>' : '');
    $templateBuilder->setParam("GRES_DETAIL",       $query['gres'] ?? "");
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