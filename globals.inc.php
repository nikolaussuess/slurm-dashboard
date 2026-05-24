<?php

/**
 * Default value for variables that should be replaced.
 * Do not edit this value, since it is used in other classes to check the respective module is
 * configured or not.
 */
const TO_BE_REPLACED = '<TO BE REPLACED>';
/**
 * Helper to read environment vars with fallback.
 */
function cfg_env(string $name, string $default = TO_BE_REPLACED) : string {
    $value = getenv($name);
    return ($value !== FALSE && $value !== '') ? $value : $default;
}

/**
 * Main configuration array.
 * You can override the values here, but it is recommended to set them as environment variables.
 */
$config = [

    /**
     * Name of the cluster.
     */
    'CLUSTER_NAME' => cfg_env('CLUSTER_NAME'),

    /**
     * Name of the admin. Publicly available in error messages.
     */
    'ADMIN_NAMES' => cfg_env('ADMIN_NAMES'),

    /**
     * E-Mail address of the admin. Publicly available in error messages.
     */
    'ADMIN_EMAIL' => cfg_env('ADMIN_EMAIL'),

    /**
     * Hostname of the login node.
     */
    'SLURM_LOGIN_NODE' => cfg_env('SLURM_LOGIN_NODE'),

    /**
     * Link to a wiki page where one can find more info about the cluster.
     */
    'WIKI_LINK' => cfg_env('WIKI_LINK'),

    // ------------------------------------------------------------------
    // REST API CONNECTION PROPERTIES
    // ------------------------------------------------------------------

    /**
     * Connection mode for connections to slurmrestd.
     * Supported values: 'unix' (Unix socket), 'tcp' (TCP socket).
     */
    'CONNECTION_MODE' => cfg_env('CONNECTION_MODE', 'unix'),

    /**
     * Hostname or IP address for TCP connections to slurmrestd.
     * Only required when CONNECTION_MODE=tcp.
     */
    'SLURM_TCP_HOST' => cfg_env('SLURM_TCP_HOST'),

    /**
     * Port for TCP connections to slurmrestd.
     * Only required when CONNECTION_MODE=tcp. Defaults to 6820.
     */
    'SLURM_TCP_PORT' => cfg_env('SLURM_TCP_PORT', '6820'),

    /**
     * Path to a PEM CA certificate file for verifying the slurmrestd TLS certificate.
     * Only required when CONNECTION_MODE=tcp and slurmrestd uses a self-signed certificate
     * or a certificate signed by a private/internal CA.
     * Leave TO_BE_REPLACED to use the system CA store.
     */
    'SLURM_TCP_CA_CERT' => cfg_env('SLURM_TCP_CA_CERT'),

    /**
     * OpenAPI version to use for communication with slurmrestd.
     * Use 'auto' for auto-detection (if supported), or e.g. v0.0.40.
     * Default is 'auto'.
     */
    'REST_API_VERSION' => cfg_env('REST_API_VERSION', 'auto'),

    // ------------------------------------------------------------------
    // LDAP CONFIGURATION
    // ------------------------------------------------------------------

    /**
     * URI for LDAP server, e.g. 'dc.example.com'.
     * Optional; leave TO_BE_REPLACED if you do not want to use LDAP authentication.
     */
    'LDAP_URI' => cfg_env('LDAP_URI'),

    /**
     * LDAP base, e.g. 'cn=users,dc=i,dc=example,dc=com'.
     * Optional; leave TO_BE_REPLACED if you do not want to use LDAP authentication.
     */
    'LDAP_BASE' => cfg_env('LDAP_BASE'),

    /**
     * LDAP admin user used for querying users, e.g. 'adminuser'.
     * Optional; leave TO_BE_REPLACED if you do not want to use LDAP authentication.
     */
    'LDAP_ADMIN_USER' => cfg_env('LDAP_ADMIN_USER'),

    /**
     * Password for the LDAP admin user.
     * Optional; leave TO_BE_REPLACED if you do not want to use LDAP authentication.
     */
    'LDAP_ADMIN_PASSWORD' => cfg_env('LDAP_ADMIN_PASSWORD'),

    // ------------------------------------------------------------------
    // SSH CONFIGURATION (LOCAL AUTHENTICATION)
    // ------------------------------------------------------------------

    /**
     * URL used for SSH connections, e.g. the login node.
     * Example: slurm01.example.com.
     * Optional; leave TO_BE_REPLACED if you do not want to use local authentication.
     */
    'SSH_SERVER_URL' => cfg_env('SSH_SERVER_URL'),

    // ------------------------------------------------------------------
    // JWT CONFIGURATION
    // ------------------------------------------------------------------

    /**
     * Path to the JWT key.
     * Optional; leave TO_BE_REPLACED if you do not want to use JWT authentication.
     */
    'JWT_PATH' => cfg_env('JWT_PATH'),

    // ------------------------------------------------------------------
    // MISC
    // ------------------------------------------------------------------

    /**
     * Username of the Slurm user.
     * Defaults to 'slurm'.
     */
    'SLURM_USER' => cfg_env('SLURM_USER', 'slurm'),

    'PRIV_USERS' => explode(',', getenv('PRIV_USERS') ?: ''),

    // ------------------------------------------------------------------
    // FEATURE FLAGS
    // ------------------------------------------------------------------

    /**
     * Show p_low partition jobs as a separate striped segment in the cluster-wide overview bars.
     * Requires an additional API call to /slurm/jobs; disabled by default.
     * Set env var FEATURE_P_LOW_IN_CLUSTER_OVERVIEW=enabled to enable.
     */
    'feature_p_low_in_cluster_overview' => cfg_env('FEATURE_P_LOW_IN_CLUSTER_OVERVIEW', 'disabled') === 'enabled',

    /**
     * Show a per-user CPU/RAM/GPU breakdown with stacked progress bars on the cluster usage page.
     * Requires an additional API call to /slurm/jobs; disabled by default.
     * Set env var FEATURE_RESOURCES_PER_USER=all or FEATURE_RESOURCES_PER_USER=privileged to enable.
     */
    'feature_resources_per_user' => in_array(
        cfg_env('FEATURE_RESOURCES_PER_USER', 'disabled'),
        ['disabled', 'all', 'privileged'],
        true
    )
        ? cfg_env('FEATURE_RESOURCES_PER_USER', 'disabled')
        : 'disabled',
];

function config($key = null) {
    global $config;
    return $key ? $config[$key] : $config;
}

// END OF VARIABLES THAT SHOULD BE EDITED
//
// DO NOT EDIT THIS FILE FROM HERE ON!

const LOG_MODE_PHP = 1;
const LOG_MODE_SYSLOG = 2;

function dashboard_is_unconfigured() : bool {
    return config('CLUSTER_NAME') == TO_BE_REPLACED || config('SLURM_LOGIN_NODE') == TO_BE_REPLACED;
}

openlog('slurm-dashboard: ', LOG_PID, LOG_USER);
function log_msg(string $message, int $error_level = LOG_INFO, int $mode = LOG_MODE_PHP): void{

    // We do NOT log if the dashboard is not configured at all.
    // We catch this here, because otherwise people accessing the dashboard via the IP
    // address might result in a lot of "UNAUTHORIZED" log messages if the environment
    // variables are only set for specific virtual hosts (which might be good to exclude
    // bots from accessing the login page).
    if( dashboard_is_unconfigured() )
        return;

    if ($mode & LOG_MODE_SYSLOG) {
        syslog($error_level, $message);
    }

    if ($mode & LOG_MODE_PHP) {
        error_log($message);
    }
}

// Global error handling
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

    log_msg(
        "Uncaught Exception: " . $exception->getMessage() .
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
    // error_reporting() === 0 means the error was suppressed via @, respect that
    if (error_reporting() === 0) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});