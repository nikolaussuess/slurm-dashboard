<?php

const CLUSTER_NAME = '<TO BE REPLACED>';
const ADMIN_NAMES = '<TO BE REPLACED>';
const SLURM_LOGIN_NODE = '<TO BE REPLACED>';
const ADMIN_EMAIL = '<TO BE REPLACED>';
const WIKI_LINK = '<TO BE REPLACED>';
const CONNECTION_MODE = 'unix';
const REST_API_VERSION = 'v0.0.40';

// Grant some users read access to e.g. the list of SLURM users.
// Admins always have access.
$privileged_users = array();

if(!isset($errormsg)){
    $errormsg = "";
}
$successmsg = "";

/**
 * Add an error that will be displayed on the page later.
 * @param $s string Error message
 */
function addError(string $s): void {
    global $errormsg;
    global $error;
    $error = TRUE;

    #$user = isset($_SESSION['USER']) ? $_SESSION['USER'] : "-";
    #$ip = $_SERVER['REMOTE_ADDR'];
    #$host = $_SERVER['HTTP_HOST'] ?? '-';

    $errormsg .= '<li>' . $s . '</li>';
}

/**
 * Add a success message that will be displayed on the website later.
 * @param $s string Success message
 */
function addSuccess(string $s): void {
    global $successmsg;

    $successmsg .= '<li>' . $s . '</li>';
}
