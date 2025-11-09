<?php
namespace view\maintenances;

function get_maintenances(array $maintenances) : string {
    $contents = '';
    if(! empty($maintenances)){
        $contents .= '<div class="alert alert-info" role="alert"><strong>Scheduled maintenances:</strong><ul>';
    }
    foreach( $maintenances as $maintenance ){
        $contents .= '<li>Node(s) ';
        if(isset($maintenance['node_list']))
            $contents .= '<span class="monospaced">' . $maintenance['node_list'] . '</span>';
        else
            $contents .= '(any)';
        $contents .= " will be unavailable from " . \utils\get_date_from_unix_if_defined($maintenance, 'start_time')
            . " until " . \utils\get_date_from_unix_if_defined($maintenance, 'end_time') . ".";
        $contents .= '</li>';
    }
    if(! empty($maintenances)){
        $contents .= '</ul><p>All jobs that are guaranteed to end before the maintenance window due to the time limit are scheduled normally. Jobs that are not guaranteed to end before the start of the maintenance window can only start after the maintenance window on affected nodes. Tip: You could run shorter jobs for the time being, use breakpoints to interrupt your job for maintenance or use other nodes that are not affected from maintenance.</p></div>';
    }

    return $contents;
}