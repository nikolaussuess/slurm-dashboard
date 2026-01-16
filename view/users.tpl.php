<?php

namespace view\actions;

use Exception;

function get_users(array $users) : string {
    $contents = <<<EOF
<div class="table-responsive tableFixHead">
    <table class="tableFixHead table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Accounts</th>
                <th>Privileges</th>
                <th>Full name</th>
                <th>Department</th>
                <th>Mail</th>
            </tr>
        </thead>
        <tbody>
EOF;

    $ldap_client = NULL;
    if(\auth\LDAP::is_supported()){
        try {
            $ldap_client = new \auth\LDAP();
        } catch (Exception $e){
            addError($e->getMessage());
        }
    }

    foreach($users as $user_arr) {

        $deleted = FALSE;
        if( isset($user_arr['flags']) && is_array($user_arr['flags']))
            $deleted = in_array("DELETED", $user_arr['flags']);

        if( $deleted ){
            $contents .= '<tr class="deleted-user" title="User was already deleted.">';
            $contents .=    "<td>" . $user_arr['name'] . " (deleted)</td>";
        }
        else {
            $contents .= "<tr>";
            $contents .=    "<td>" . $user_arr['name'] . "</td>";
        }
        $contents .=    "<td><ul>";
        foreach($user_arr['associations'] as $assoc){
            if($assoc['account'] == $user_arr['default']['account'])
                $contents .= '<li><b title="Default account">' . $assoc['account'] . '</b> (default)</li>';
            else
                $contents .= '<li>' . $assoc['account'] . '</li>';
        }
        $contents .=           "</ul></td>";
        if( implode(", ", $user_arr['administrator_level']) == 'None' && in_array($user_arr['name'], config('PRIV_USERS')))
            $contents .=    "<td title='Web administrators have extended permissions in the dashboard but not in SLURM itself.'>Web admin</td>";
        elseif( implode(", ", $user_arr['administrator_level']) == 'Administrator')
            $contents .=    '<td title="Admin on the whole SLURM cluster.">Slurm admin</td>';
        elseif( implode(", ", $user_arr['administrator_level']) == 'None')
            $contents .=    '<td title="Normal user">-</td>';
        else
            $contents .=    "<td>" . implode(", ", $user_arr['administrator_level']) . "</td>";

        // LDAP
        if( ! \auth\LDAP::is_supported() || $user_arr['name'] == "root" || $ldap_client === NULL ){
            $contents .= '<td colspan="4"><i>No LDAP server available</i></td>';
        }
        else {
            $ldap_data = $ldap_client->get_data_for_user($user_arr['name']);
            if($ldap_data["count"] == 0){
                $contents .= '<td colspan="4"><i>No LDAP data available</i></td>';
            }
            else {
                for($i = 0; $i < $ldap_data["count"]; $i++){
                    $contents .=    "<td>" . $ldap_data[$i]["displayname"][0] . "</td>";
                    $contents .=    "<td>" . $ldap_data[$i]["department"][0];
                    if(isset($ldap_data[$i]["departmentnumber"]) && isset($ldap_data[$i]["departmentnumber"][0])){
                        $contents .= " (" . $ldap_data[$i]["departmentnumber"][0] . ")";
                    }
                    $contents .= "</td>";
                    $contents .=    "<td>" . $ldap_data[$i]["mail"][0] . "</td>";

                    break;
                }
            }
        }
        $contents .= '</tr>';
    }

    if(isset($ldap_client))
        unset($ldap_client);

    $contents .= <<<EOF
        </tbody>
    </table>
</div>
EOF;

    return $contents;
}