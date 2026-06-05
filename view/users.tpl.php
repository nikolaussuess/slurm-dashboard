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
            addError(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }

    foreach($users as $user_arr) {

        $deleted = FALSE;
        if( isset($user_arr['flags']) && is_array($user_arr['flags']))
            $deleted = in_array("DELETED", $user_arr['flags']);

        $name_e = htmlspecialchars($user_arr['name'], ENT_QUOTES, 'UTF-8');
        if( $deleted ){
            $contents .= '<tr class="deleted-user" title="User was already deleted.">';
            $contents .=    '<td><a href="?action=users&user_name=' . $name_e . '">' . $name_e . '</a> (deleted)</td>';
        }
        else {
            $contents .= "<tr>";
            $contents .=    '<td><a href="?action=users&user_name=' . $name_e . '">' . $name_e . '</a></td>';
        }
        $contents .=    "<td><ul>";
        foreach($user_arr['associations'] ?? [] as $assoc){
            $account_e = htmlspecialchars($assoc['account'], ENT_QUOTES, 'UTF-8');
            if(is_array($user_arr['default']) && $assoc['account'] == $user_arr['default']['account'])
                $contents .= '<li><b title="Default account">' . $account_e . '</b> (default)</li>';
            else
                $contents .= '<li>' . $account_e . '</li>';
        }
        $contents .=           "</ul></td>";
        if( implode(", ", $user_arr['administrator_level'] ?? []) == 'None' && in_array($user_arr['name'], config('PRIV_USERS')))
            $contents .=    "<td title='Web administrators have extended permissions in the dashboard but not in SLURM itself.'>Web admin</td>";
        elseif( implode(", ", $user_arr['administrator_level'] ?? []) == 'Administrator')
            $contents .=    '<td title="Admin on the whole SLURM cluster.">Slurm admin</td>';
        elseif( implode(", ", $user_arr['administrator_level'] ?? []) == 'None')
            $contents .=    '<td title="Normal user">-</td>';
        else
            $contents .=    "<td>" . htmlspecialchars(implode(", ", $user_arr['administrator_level'] ?? []), ENT_QUOTES, 'UTF-8') . "</td>";

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
                    $contents .=    "<td>" . htmlspecialchars($ldap_data[$i]["displayname"][0] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    $contents .=    "<td>" . htmlspecialchars($ldap_data[$i]["department"][0] ?? '', ENT_QUOTES, 'UTF-8');
                    if(isset($ldap_data[$i]["departmentnumber"]) && isset($ldap_data[$i]["departmentnumber"][0])){
                        $contents .= " (" . htmlspecialchars($ldap_data[$i]["departmentnumber"][0], ENT_QUOTES, 'UTF-8') . ")";
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
        if(is_array($user_arr['default']) && $assoc['account'] == $user_arr['default']['account'])
            $accounts .= '<li><b title="Default account">'
                         . htmlspecialchars($assoc['account'], ENT_QUOTES, 'UTF-8')
                         . '</b> (default), cluster ' . htmlspecialchars($assoc['cluster'], ENT_QUOTES, 'UTF-8')
                         . ' (ID ' . (int)$assoc['id'] . ')</li>';
        else
            $accounts .= '<li>' . htmlspecialchars($assoc['account'], ENT_QUOTES, 'UTF-8')
                          . ', cluster ' . htmlspecialchars($assoc['cluster'], ENT_QUOTES, 'UTF-8')
                          . ' (ID ' . (int)$assoc['id'] . ')</li>';
    }

    // Admin privileges
    if( implode(", ", $user_arr['administrator_level'] ?? []) == 'None' && in_array($user_arr['name'], config('PRIV_USERS')))
        $admin_privs =    "<span title='Web administrators have extended permissions in the dashboard but not in SLURM itself.'>&#x1F3E2; Web admin</span>";
    elseif( implode(", ", $user_arr['administrator_level'] ?? []) == 'Administrator')
        $admin_privs =    '<span title="Admin on the whole SLURM cluster.">&#128273; Slurm admin</span>';
    elseif( implode(", ", $user_arr['administrator_level'] ?? []) == 'None')
        $admin_privs =    '<span title="Normal user">-</span>';
    else
        $admin_privs =    htmlspecialchars(implode(", ", $user_arr['administrator_level'] ?? []), ENT_QUOTES, 'UTF-8');

    // retrieve LDAP data
    $ldap_client = NULL;
    if(\auth\LDAP::is_supported()){
        try {
            $ldap_client = new \auth\LDAP();
        } catch (Exception $e){
            addError(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
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
            $full_name = htmlspecialchars($ldap_data[0]["displayname"][0] ?? '', ENT_QUOTES, 'UTF-8');
            $department .= htmlspecialchars($ldap_data[0]["department"][0] ?? '', ENT_QUOTES, 'UTF-8');
            if(isset($ldap_data[0]["departmentnumber"]) && isset($ldap_data[0]["departmentnumber"][0])){
                $department .= ' (' . htmlspecialchars($ldap_data[0]["departmentnumber"][0], ENT_QUOTES, 'UTF-8') . ')';
            }
            $raw_mail = $ldap_data[0]["mail"][0] ?? '';
            if(filter_var($raw_mail, FILTER_VALIDATE_EMAIL)){
                $mail = '<a href="mailto:' . htmlspecialchars($raw_mail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($raw_mail, ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $mail = htmlspecialchars($raw_mail, ENT_QUOTES, 'UTF-8');
            }
            $telephone = htmlspecialchars($ldap_data[0]["telephonenumber"][0] ?? '', ENT_QUOTES, 'UTF-8');
        }
    }

    $fairshare_table = '';
    foreach($shares as $share){
        $parent_e = htmlspecialchars($share['parent'], ENT_QUOTES, 'UTF-8');
        $fairshare_table .=<<<EOF
<tr>
    <td colspan="2">
        <b class="intent-left-level1">Account <span class="monospaced">{$parent_e}</span></b>
    </td>
</tr>
<tr>
    <td>
        <span class="intent-left-level2">Raw shares</span>
        <button type="button"
                class="btn p-0"
                data-bs-toggle="tooltip"
                data-bs-trigger="click focus"
                data-bs-placement="top"
                title="shares_raw (Raw Shares) represents the assigned fairshare weight of an association (user, account, or account hierarchy node). It defines how much of the cluster's resources an association is entitled to relative to others, independent of current or past usage.">
            <i title="Click here for more information">&#9432;</i>
        </button>
    </td>
    <td>{$share['shares']}</td>
</tr>
<tr>
    <td>
        <span class="intent-left-level2">Normalized shares</span>
        <button type="button"
               class="btn p-0"
               data-bs-toggle="tooltip"
               data-bs-trigger="click focus"
               data-bs-placement="top"
               title="Normalized share value; i.e., shares_raw divided by the sum of all (relevant) sibling accounts.">
            <i title="Click here for more information">&#9432;</i>
        </button>
    </td>
    <td>{$share['shares_normalized']}</td>
</tr>
<tr>
    <td>
        <span class="intent-left-level2">Raw usage</span>
        <button type="button"
                class="btn p-0"
                data-bs-toggle="tooltip"
                data-bs-trigger="click focus"
                data-bs-placement="top"
                title="Raw Usage is the total amount of compute resource usage that has been charged to a user or account in the SLURM accounting system. It is measured in TRES-seconds (e.g., CPU-seconds or GPU seconds, depending on the configuration).">
            <i title="Click here for more information">&#9432;</i>
        </button>
    </td>
    <td>{$share['usage']}</td>
</tr>
<tr>
    <td>
        <span class="intent-left-level2">Effective usage</span>
        <button type="button"
                class="btn p-0"
                data-bs-toggle="tooltip"
                data-bs-trigger="click focus"
                data-bs-placement="top"
                title="Effective Usage is a usage value that augments raw usage by including usage from sibling associations in the hierarchy. It represents how much of the cluster’s capacity effectively appears to have been used by an account or user, after accounting for both their own usage and the usage of their siblings relative to their shares.">
            <i title="Click here for more information">&#9432;</i>
        </button>
    </td>
    <td>{$share['effective_usage']}</td>
</tr>
<tr>
    <td>
        <span class="intent-left-level2">Fairshare factor</span>
        <button type="button"
                class="btn p-0"
                data-bs-toggle="tooltip"
                data-bs-trigger="click focus"
                data-bs-placement="top"
                data-bs-html="true"
                title="The Fairshare factor is a floating-point number between 0.0 and 1.0 that SLURM computes to reflect how much of its fair share of cluster resources an account, user, or association has used relative to its entitlement (shares). It might be used by the priority/multifactor scheduling plugin to influence job priorities (depending on the configuration).<ul><li>Closer to 1.0 → Under-served (you have used less than your share) → higher priority</li><li>Closer to 0.0 → Over-served (you have used more than your share) → lower priority</li></ul>">
            <i title="Click here for more information">&#9432;</i>
        </button>
    </td>
    <td>{$share['fairshare_factor']}</td>
</tr>
EOF;
    }
    if(count($shares) == 0){
        $fairshare_table .= <<<EOF
<tr>
    <td colspan="2" style="text-align: center"><i>Currently no data available.</i></td>
</tr>
EOF;

    }

    $templateBuilder = new TemplateLoader("userinfo.html");
    $templateBuilder->setParam("USERNAME", $user_name);
    $templateBuilder->setParam("FULLNAME", $full_name);
    $templateBuilder->setParam("DEPARTMENT", $department);
    $templateBuilder->setParam("MAIL", $mail);
    $templateBuilder->setParam("TELEPHONE", $telephone);

    $templateBuilder->setParam("STATUS", $status);
    $templateBuilder->setParam("ACCOUNTS", $accounts);
    $templateBuilder->setParam("DEFAULT_ACCOUNT", htmlspecialchars(is_array($user_arr['default']) ? $user_arr['default']['account'] : '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("DEFAULT_QOS", htmlspecialchars($user_arr['default']['qos'] ?? 'N/A', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("PRIVILEGES", $admin_privs);

    $templateBuilder->setParam("FAIRSHARE", $fairshare_table);

    $contents .= $templateBuilder->build();

    return $contents;
}