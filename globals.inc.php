<?php

const CLUSTER_NAME = 'csunivie';
const ADMIN_EMAIL = '<TO BE REPLACED>';

if(!isset($errormsg)){
    $errormsg = "";
}
$successmsg = "";

/**
 * Add an error that will be displayed on the page later.
 * @param $s string Error message
 */
function addError($s){
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
function addSuccess($s){
    global $successmsg;

    $successmsg .= '<li>' . $s . '</li>';
}
