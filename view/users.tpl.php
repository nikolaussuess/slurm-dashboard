<?php

namespace view\actions;

use Exception;

function get_users(array $users) : string {
    $contents = <<<EOF
<div class="table-responsive">
    <table class="tableFixHead table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Accounts</th>
                <th>Default account</th>
                <th>Admin level</th>
                <th>Full name</th>
                <th>Department</th>
                <th>E-Mail</th>
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
        $contents .= "<tr>";
        $contents .=    "<td>" . $user_arr['name'] . "</td>";
        $contents .=    "<td><ul>";
        foreach($user_arr['associations'] as $assoc){
            if($assoc['account'] == $user_arr['default']['account'])
                $contents .= '<li><b>' . $assoc['account'] . '</b></li>';
            else
                $contents .= '<li>' . $assoc['account'] . '</li>';
        }
        $contents .=           "</ul></td>";
        $contents .=    "<td>" . $user_arr['default']['account'] . "</td>";
        global $privileged_users;
        if( implode(", ", $user_arr['administrator_level']) == 'None' && in_array($user_arr['name'], $privileged_users))
            $contents .=    "<td>Web</td>";
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