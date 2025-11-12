<?php

const CLUSTER_NAME = '<TO BE REPLACED>';
const ADMIN_NAMES = '<TO BE REPLACED>';
const SLURM_LOGIN_NODE = '<TO BE REPLACED>';
const ADMIN_EMAIL = '<TO BE REPLACED>';
const WIKI_LINK = '<TO BE REPLACED>';
const CONNECTION_MODE = 'unix';
const REST_API_VERSION = 'auto'; # 'auto' for auto-detection, or e.g. v0.0.40

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


function internalServerError(Throwable $exception) : void {
    $debug_info='';
    if(is_callable([$exception, 'get_debug_info']))
        $debug_info = $exception->get_debug_info();

    error_log(
        "slurm-dashboard: Uncaught Exception: " . $exception->getMessage() .
        "; at " . $exception->getFile() . ":" . $exception->getLine() . " with code " .
        $exception->getCode() . "; Trace:" . $exception->getTraceAsString() .
        "; Debug info: " . $debug_info
    );

    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code(500);
    include __DIR__ . '/error.php';
}

set_exception_handler('internalServerError');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});