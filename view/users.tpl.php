<?php

namespace view\actions;

use Exception;
use TemplateLoader;

function get_users(array $users) : string {
    $contents = <<<EOF
<div class="table-responsive tableFixHead">
    <table class="table" id="usertable-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Accounts</th>
                <th>Privileges</th>
                <th>Full name</th>
                <th>Department</th>
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
            $contents .=    '<td><a href="?action=users&user_name='.$user_arr['name'].'">' . $user_arr['name'] . '</a> (deleted)</td>';
        }
        else {
            $contents .= "<tr>";
            $contents .=    '<td><a href="?action=users&user_name='.$user_arr['name'].'">' . $user_arr['name'] . '</a></td>';
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

function get_user(string $user_name, array $user_arr, array $shares) : string {
    $contents = '<a href="?action=users"><button type="button" class="btn btn-secondary">Back to the user table</button></a>';

    $user_name = htmlspecialchars($user_name);

    // Check if user was deleted
    $deleted = FALSE;
    if( isset($user_arr['flags']) && is_array($user_arr['flags']))
        $deleted = in_array("DELETED", $user_arr['flags']);
    if($deleted)
        $status = '<span style="color: red">X</span> deleted';
    else
        $status = '<span style="color: darkgreen">&#9989;</span> active';

    // Associations
    $accounts = '';
    foreach($user_arr['associations'] as $assoc){
        if($assoc['account'] == $user_arr['default']['account'])
            $accounts .= '<li><b title="Default account">'
                         . $assoc['account']
                         . '</b> (default), cluster ' . $assoc['cluster']
                         . ' (ID ' . $assoc['id'] . ')</li>';
        else
            $accounts .= '<li>' . $assoc['account']
                          . ', cluster ' . $assoc['cluster']
                          . ' (ID ' . $assoc['id'] . ')</li>';
    }

    // Admin privileges
    if( implode(", ", $user_arr['administrator_level']) == 'None' && in_array($user_arr['name'], config('PRIV_USERS')))
        $admin_privs =    "<span title='Web administrators have extended permissions in the dashboard but not in SLURM itself.'>&#x1F3E2; Web admin</span>";
    elseif( implode(", ", $user_arr['administrator_level']) == 'Administrator')
        $admin_privs =    '<span title="Admin on the whole SLURM cluster.">&#128273; Slurm admin</span>';
    elseif( implode(", ", $user_arr['administrator_level']) == 'None')
        $admin_privs =    '<span title="Normal user">-</span>';
    else
        $admin_privs =    implode(", ", $user_arr['administrator_level']);

    // retrieve LDAP data
    $ldap_client = NULL;
    if(\auth\LDAP::is_supported()){
        try {
            $ldap_client = new \auth\LDAP();
        } catch (Exception $e){
            addError($e->getMessage());
        }
    }

    // LDAP
    $full_name = '';
    $department = '';
    $mail = '';
    $telephone = '';
    if (\auth\LDAP::is_supported() && $user_arr['name'] != "root" && $ldap_client !== NULL) {
        $ldap_data = $ldap_client->get_data_for_user($user_arr['name']);
        if($ldap_data["count"] === 1){
            $full_name = $ldap_data[0]["displayname"][0];
            $department .= $ldap_data[0]["department"][0];
            if(isset($ldap_data[0]["departmentnumber"]) && isset($ldap_data[0]["departmentnumber"][0])){
                $department .= ' (' . $ldap_data[0]["departmentnumber"][0] . ')';
            }
            $mail = '<a href="mailto:' . $ldap_data[0]["mail"][0] . '">' . $ldap_data[0]["mail"][0] . '</a>';
            $telephone = $ldap_data[0]["telephonenumber"][0] ?? '';
        }
    }

    $fairshare_table = '';
    foreach($shares as $share){
        $fairshare_table .= '<tr><td colspan="2"><b style="margin-left: 25px">Account <span class="monospaced">' . $share['parent'] . '</span></b></td></tr>';
        $fairshare_table .= '<tr><td><span style="margin-left: 50px">Raw shares</span></td><td>'.$share['shares'].'</td></tr>';
        $fairshare_table .= '<tr><td><span style="margin-left: 50px">Normalized shares</span></td><td>'.$share['shares_normalized'].'</td></tr>';
        $fairshare_table .= '<tr><td><span style="margin-left: 50px">Raw usage</span></td><td>'.$share['usage'].'</td></tr>';
        $fairshare_table .= '<tr><td><span style="margin-left: 50px">Effective usage</span></td><td>'.$share['effective_usage'].'</td></tr>';
        $fairshare_table .= '<tr><td><span style="margin-left: 50px">Fairshare factor</span></td><td>'.$share['fairshare_factor'].'</td></tr>';
    }

    $templateBuilder = new TemplateLoader("userinfo.html");
    $templateBuilder->setParam("USERNAME", $user_name);
    $templateBuilder->setParam("FULLNAME", $full_name);
    $templateBuilder->setParam("DEPARTMENT", $department);
    $templateBuilder->setParam("MAIL", $mail);
    $templateBuilder->setParam("TELEPHONE", $telephone);

    $templateBuilder->setParam("STATUS", $status);
    $templateBuilder->setParam("ACCOUNTS", $accounts);
    $templateBuilder->setParam("DEFAULT_ACCOUNT", $user_arr['default']['account']);
    $templateBuilder->setParam("DEFAULT_QOS", $user_arr['default']['qos'] ?? 'N/A');
    $templateBuilder->setParam("PRIVILEGES", $admin_privs);

    $templateBuilder->setParam("FAIRSHARE", $fairshare_table);

    $contents .= $templateBuilder->build();

    return $contents;
}