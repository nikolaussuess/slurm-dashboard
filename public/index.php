<?php
#error_reporting(E_ALL);
#ini_set('display_errors', '1');
session_start();
date_default_timezone_set('Europe/Vienna');
// Nonce for CSP to allow secure inline JS.
$csp_nonce = base64_encode(random_bytes(16));
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$csp_nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self';");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

require_once __DIR__ . '/../TemplateLoader.inc.php';
require_once __DIR__ . '/../globals.inc.php';
require_once __DIR__ . '/../cache/CacheWrapper.inc.php';
require_once __DIR__ . '/../client/Client.inc.php';
require_once __DIR__ . '/../client/utils/DependencyResolver.inc.php';
require_once __DIR__ . '/../auth/auth.inc.php';
require_once __DIR__ . '/../utils.inc.php';

require_once __DIR__ . '/../view/login.tpl.php';
require_once __DIR__ . '/../view/maintenances.tpl.php';
require_once __DIR__ . '/../view/usage.tpl.php';
require_once __DIR__ . '/../view/job.tpl.php';
require_once __DIR__ . '/../view/slurm-queue.tpl.php';
require_once __DIR__ . '/../view/job_history.tpl.php';
require_once __DIR__ . '/../view/users.tpl.php';
require_once __DIR__ . '/../exceptions/ValidationException.inc.php';
// Load wiki files only when FEATURE_WIKI_DB is configured so pdo_sqlite is not required otherwise.
$wiki_enabled = config('FEATURE_WIKI_DB') !== TO_BE_REPLACED && config('FEATURE_WIKI_DB') !== '';
if ($wiki_enabled) {
    require_once __DIR__ . '/../wiki/Wiki.inc.php';
    require_once __DIR__ . '/../wiki/WikiFiles.inc.php';
    require_once __DIR__ . '/../wiki/wiki.tpl.php';
}

$dao = \client\ClientFactory::newClient();
$title = "Clusterinfo " . config('CLUSTER_NAME');
$contents = "";

if( isset($_GET['action']) && $_GET['action'] == "logout"){
    // Clear all session variables (USER, csrf_token, etc.)
    session_unset();
    // Regenerate session ID and delete the old session data server-side.
    // This keeps the session active but empty, so a new CSRF token can be
    // generated immediately for the login form without a second session_start().
    session_regenerate_id(true);
}

// Check if the socket exists and add a warning otherwise
if( ! $dao->is_available() ){
    throw new \exceptions\RequestFailedException(
        "Cannot create socket.",
        '$dao->is_available() failed',
        "Cannot create socket. Is <kbd>slurmrestd</kbd> running? Please report this issue to " . config('ADMIN_EMAIL')
    );
}

// Set to TRUE once a public wiki page has been rendered for an unauthenticated user,
// so the login form is suppressed and the authenticated wiki case is skipped.
$wiki_handled = FALSE;

if( ! isset($_SESSION['USER']) ) {

    // Public wiki pages are served without login; $wiki_handled prevents the login form from appearing below.
    if ($wiki_enabled && isset($_GET['action']) && $_GET['action'] === 'wiki' && isset($_GET['url'])) {
        $wiki_url = trim($_GET['url']);
        if (\wiki\is_valid_wiki_url($wiki_url)) {
            $wiki_page = \wiki\WikiDatabase::getInstance()->getPage($wiki_url);
            if ($wiki_page !== NULL && $wiki_page['visibility'] === \wiki\WikiDatabase::VISIBILITY_PUBLIC) {
                [$wiki_status, $wiki_title, $wiki_html] = \view\wiki\get_wiki_page($wiki_url, $csp_nonce);
                http_response_code($wiki_status);
                $title     = $wiki_title;
                $contents .= $wiki_html;
                $wiki_handled = TRUE;
            }
        }
    }

    if(isset($_GET['action']) && $_GET['action'] == "login"){
        if( !isset($_POST['username']) || !isset($_POST['password']) || !isset($_POST['method']) ){
            addError("Login failed.");
        }
        elseif (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
            http_response_code(403);
            addError("Invalid request (CSRF token mismatch).");
        }
        else {
            $username = $_POST['username'];
            $password = $_POST['password'];
            $method = $_POST['method'];
            if(auth($username, $password, $method)){
                session_regenerate_id(true);
                $_SESSION['USER'] = $username;
                addSuccess("Login successful!");
                log_msg("Successful login for '$username' from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
            }
            else {
                addError("Login failed.");
                $safe_username = preg_replace('/[^\x20-\x7E]/', '?', $username);
                log_msg("Failed login attempt for '$safe_username' from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_WARNING, LOG_MODE_PHP|LOG_MODE_SYSLOG);
            }
        }
    }
    // Is set above if the login was successful.
    // Otherwise, the login form is displayed again.
    if( ! isset($_SESSION['USER']) && ! $wiki_handled && (!isset($_GET['action']) || $_GET['action'] != "about")) {
        $contents .= \view\login\get_login_form();
    }
}

# About page
if(isset($_GET['action']) && $_GET['action'] == "about"){
    $title = "About the cluster " . config("CLUSTER_NAME");
    $contents .= \view\login\get_about_page();
}

# User is logged in
if( isset($_SESSION['USER']) ){

    $action = $_GET['action'] ?? "usage";
    if( $action == "login") $action = "usage";

    // Show maintenance dates if there are some
    $maintenances = $dao->get_maintenances();
    $contents .= \view\maintenances\get_maintenances($maintenances);
    // END of maintenance

    switch($action){

        case "usage":
            $title = 'Cluster usage';
            $contents .= \view\actions\get_all_nodes_usage(
                    $dao,
                    $csp_nonce,
                    isset($_GET['show_users']) && $_GET['show_users'] === '1'
            );
            break;

        case "job":
            if( ! isset($_GET['job_id']) || !ctype_digit($_GET['job_id'])){
                http_response_code(404);
                addError("No job ID given.");
                $title = '404 Not Found';
                $contents .= "404 Not Found.";
                break;
            }
            $job_id_view = (int)$_GET['job_id'];

            # SLURM QUEUE information
            // Title will also set <h1>
            $title = 'Job ' . $job_id_view;
            $query = $dao->get_job($job_id_view);
            if( $query == NULL ){
                $contents .= "<p>Job " . $job_id_view . " not in active queue anymore.</p>";
                // Here, it is not a 404 if the job is not found, because a job
                // is removed vom the queue when it is finished but persists in slurmdb.
                $in_slurm_queue = FALSE;
            }
            else {
                $dependency_resolver = new \client\utils\DependencyResolver($dao);
                $contents .= \view\actions\get_slurm_jobinfo($query, $dependency_resolver->renderDependencyListHTML($job_id_view) ?? '');
                $in_slurm_queue = TRUE;
            }

            # SLURMDB information
            $query = $dao->get_job_from_slurmdb($job_id_view);
            if($query == NULL){
                $contents .= "<p>Job " . $job_id_view . " not found in <span class='monospaced'>slurmdb</span>.</p>";
                // Here, it is a 404 if the job cannot be found.
                // However, there might have been a delay while writing into slurmdb. So we only consider it to be a
                // 404 if it was neither in slurmdb nor in slurm queue.
                if( ! $in_slurm_queue) {
                    http_response_code(404);
                    $title = '404 Not Found';
                }
            }
            else {
                $contents .= \view\actions\get_slurmdb_jobinfo($query);
            }

            break;

        case "jobs":

            $title = 'Slurm queue';

            // Filter
            // Exclude partition p_low if parameter exclude_p_low=1
            $exclude_p_low = isset($_GET['exclude_p_low']) && $_GET['exclude_p_low'] == 1;
            $filter = array();
            if($exclude_p_low)
                $filter['exclude_p_low'] = 1;
            if(isset($_GET['orderby']))
                $filter['orderby'] = $_GET['orderby'];

            $jobs = $dao->get_jobs($filter);

            $contents .= \view\actions\get_slurm_queue($jobs, $exclude_p_low, $csp_nonce);

            break;

        case 'job_history':

            // Get submitted filter form (reads $_GET and $_POST)
            $filter = \view\actions\get_slurmdb_filter_form_evaluation();

            $contents .= "<h2>Jobs</h2>";
            $title = 'Job history';

            $accounts = $dao->get_account_list();
            $users = $dao->get_users_list();
            $nodes = $dao->getNodeList();
            $partitions = $dao->get_partition_list();

            $contents .= \view\actions\get_slurmdb_filter_form($filter, $accounts, $users, $nodes, $partitions);

            $jobs = $dao->get_jobs_from_slurmdb($filter);
            $contents .= \view\actions\get_filtered_jobs_from_slurmdb($jobs, $filter);

            break;

        case 'users':
            $title = "List of users";

            // Normal users should currently be allowed to view their own "profile" page, only.
            // Otherwise, they should get a 403.
            // Admins, however, should see any content.

            // Show user table if no specific user was requested
            if( ! isset( $_GET['user_name'] ) || empty($_GET['user_name']) ){
                // Check if user is administrator, otherwise show 403.
                if( ! \auth\current_user_is_privileged() ){
                    http_response_code(403);
                    $contents .= "403 Forbidden.<br>";
                    $contents .= "Only admins are allowed to list all users.";
                    break;
                }
                // User is administrator and therefore allowed to visit this page.

                $users = $dao->get_users();
                $contents .= \view\actions\get_users($users);
            }
            else {
                $user_name = $_GET['user_name'];
                if(!\utils\is_valid_username($user_name)){
                    http_response_code(400);
                    addError("Invalid user name.");
                    break;
                }
                // Check if user is administrator -> show
                // If user requests his own page  ->
                // --> otherwise show 403.
                if( ! \auth\current_user_is_privileged() && $user_name !== $_SESSION['USER']){
                    http_response_code(403);
                    $contents .= "403 Forbidden.<br>";
                    $contents .= "Only admins are allowed to list all users or other user's profiles.";
                    break;
                }
                // User is administrator and therefore allowed to visit this page.
                // OR User requested his own profile.

                $user_slurm = $dao->get_user($user_name, TRUE);
                if(empty($user_slurm['users'])){
                    http_response_code(404); // Not found
                    addError("User " . htmlspecialchars($_GET['user_name'], ENT_QUOTES, 'UTF-8') . " does not exist.");
                    break;
                }
                $user_slurm = $user_slurm['users'][0];
                $shares = $dao->get_fairshare($user_name);

                $title = 'User info';
                $contents .= \view\actions\get_user($user_name, $user_slurm, $shares);
            }

            break;

        case 'cancel-job':
            $title = "Cancel job";

            if( ! \client\utils\jwt\JwtAuthentication::is_supported() ){
                http_response_code(503); // Service unavailable
                addError("Cancelling jobs is currently not supported by the configuration.<br>" .
                           "If you are an administrator: You have to enable JWT authentication in order to use this feature.");
                break;
            }

            // Check if job_id parameter exists.
            if(! isset($_GET['job_id']) || !ctype_digit($_GET['job_id'])){
                http_response_code(400); // Bad request
                addError("No job id provided or job id is not a valid number.");
                break;
            }

            $job_id = (int)$_GET['job_id'];

            // Check for sufficient privileges
            $job_data = $dao->get_job($job_id);

            if($job_data === NULL){
                addError("Job " . $job_id . " not in active queue any more.");
                break;
            }

            if( ! \auth\current_user_is_admin() && $job_data['user_name'] !== $_SESSION['USER'] ){
                http_response_code(403); // Forbidden
                addError(
                    "The job belongs to user " . htmlspecialchars($job_data['user_name'], ENT_QUOTES, 'UTF-8') . " but current user is "
                    . htmlspecialchars($_SESSION['USER'], ENT_QUOTES, 'UTF-8') . ". Since you are not an administrator, you can only delete your own jobs."
                );
                break;
            }

            if(! isset($_GET['do']) || $_GET['do'] !== "cancel") {
                $templateBuilder = new TemplateLoader("modal_job_cancelling.html");
                $templateBuilder->setParam("JOBID", htmlspecialchars($job_id, ENT_QUOTES, 'UTF-8'));
                $templateBuilder->setParam("CSRF_TOKEN", \auth\get_csrf_token());
                $contents .= $templateBuilder->build();
                break;
            }
            else {
                if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                    http_response_code(403);
                    addError("Invalid request (CSRF token mismatch).");
                    break;
                }
                if( $dao->cancel_job($job_id) ) {
                    addSuccess("Job " . $job_id . " cancelled.");
                    log_msg("User '{$_SESSION['USER']}' cancelled job $job_id",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                }
                else {
                    addError("Something went wrong when cancelling job " . $job_id);
                }
                $cache = \cache\CacheWrapper::getInstance();
                $cache->delete("slurm/jobs"); // Delete cached entry because we KNOW that it has changed.
                $cache->delete("slurm/job/".$job_id); // Delete cached entry because we KNOW that it has changed.
                $contents .= \view\actions\get_slurm_queue($dao->get_jobs(), 0, $csp_nonce);
            }
            break;

        case 'edit-job':
            $title = "Edit job";

            if( ! \client\utils\jwt\JwtAuthentication::is_supported() ){
                http_response_code(503); // Service unavailable
                addError("Editing jobs is currently not supported by the configuration.<br>" .
                    "If you are an administrator: You have to enable JWT authentication in order to use this feature.");
                break;
            }

            // Check if job_id parameter exists.
            if(! isset($_GET['job_id']) || !ctype_digit($_GET['job_id'])){
                http_response_code(400); // Bad request
                addError("No job id provided or job id is not a valid number.");
                break;
            }

            $job_id = (int)$_GET['job_id'];

            // Check for sufficient privileges
            $job_data = $dao->get_job($job_id);

            if($job_data === NULL){
                http_response_code(404); // Not found
                // 410 Gone would also be a valid choice, but if someone mistyped the ID in the url and chose
                // a larger ID, that ID will likely exist in the future. Since GONE is a permanent error, and we
                // cannot (easily) check whether the ID ever existed (we could, but that would require a query to
                // slurmdb), we instead just send 404 Not found which is safe.
                addError("Job " . $job_id . " not in active queue any more.");
                break;
            }

            if( ! \auth\current_user_is_admin() && $job_data['user_name'] !== $_SESSION['USER'] ){
                http_response_code(403); // Forbidden
                addError(
                    "The job belongs to user " . htmlspecialchars($job_data['user_name'], ENT_QUOTES, 'UTF-8') . " but current user is "
                    . htmlspecialchars($_SESSION['USER'], ENT_QUOTES, 'UTF-8') . ". Since you are not an administrator, you can only modify your own jobs."
                );
                break;
            }

            if(! isset($_GET['do']) || $_GET['do'] != "edit") {
                $templateBuilder = new TemplateLoader("edit_job_form.html");
                $templateBuilder->setParam("JOBID", htmlspecialchars($job_id, ENT_QUOTES, 'UTF-8'));
                $templateBuilder->setParam("CSRF_TOKEN", \auth\get_csrf_token());
                $templateBuilder->setParam("JOB_NAME", htmlspecialchars($job_data['job_name'], ENT_QUOTES, 'UTF-8'));
                $templateBuilder->setParam("USER_NAME", htmlspecialchars($job_data['user_name'], ENT_QUOTES, 'UTF-8'));
                $templateBuilder->setParam("USER_ID", $job_data['user_id']);
                $templateBuilder->setParam("TIME_LIMIT", preg_replace('/:\d{2}$/', '', $job_data['time_limit']));
                $templateBuilder->setParam("NICE_VALUE", isset($job_data['nice']) ? (int)$job_data['nice'] : '');
                $templateBuilder->setParam("COMMENT", htmlspecialchars($job_data['comment'] ?? '', ENT_QUOTES, 'UTF-8'));
                // Admin-only settings - currently not supported
                $templateBuilder->setParam("PRIORITY", $job_data['priority'] ?? '');
                $templateBuilder->setParam("QOS", $job_data['qos'] ?? '');
                $templateBuilder->setParam("PARTITION", $job_data['partition'] ?? '');
                $contents .= $templateBuilder->build();
                break;
            }
            else {
                if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                    http_response_code(403);
                    addError("Invalid request (CSRF token mismatch).");
                    break;
                }

                $new_job_data = array(
                    'job_id' => $job_id,
                );

                // Skip time limit (do not update) if the form field was empty.
                // Other possible values are:
                // - infinite
                // - a value of the form D-HH:MM
                if(isset($_POST['time_limit']) && !empty($_POST['time_limit'])){
                    if($_POST['time_limit'] == 'infinite'){
                        $new_job_data['time_limit'] = array('infinite'=>1);
                    } else {
                        try {
                            // Convert D-HH:MM to minutes (NOT seconds!)
                            $new_job_data['time_limit'] = \utils\slurmTimeLimitFromString($_POST['time_limit']);
                        } catch (InvalidArgumentException $e){
                            throw new \exceptions\ValidationException(
                                $e->getMessage()
                            );
                        }
                    }
                }

                // Skip updating nice value if field is empty.
                if(isset($_POST['nice_value']) && !empty($_POST['nice_value'])){
                    $new_job_data['nice'] = $_POST['nice_value'];
                }
                // Always update job comment.
                if(isset($_POST['comment'])){
                    $new_job_data['comment'] = $_POST['comment'];
                }

                // Perform the update and then show the job queue
                if( $dao->update_job($new_job_data) ) {
                    addSuccess("Job " . $job_id . " updated.");
                    log_msg("User '{$_SESSION['USER']}' updated job $job_id",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                }
                else {
                    addError("Something went wrong when updating job " . $job_id);
                }
                $cache = \cache\CacheWrapper::getInstance();
                $cache->delete("slurm/jobs"); // Delete cached entry because we KNOW that it has changed.
                $cache->delete("slurm/job/".$job_id); // Delete cached entry because we KNOW that it has changed.
                $contents .= \view\actions\get_slurm_queue($dao->get_jobs(), 0, $csp_nonce);
            }
            break;

        case 'node-set-state':

            $title = "Set node state";

            if( ! \client\utils\jwt\JwtAuthentication::is_supported() ){
                http_response_code(503); // Service unavailable
                addError("Setting node states is currently not supported by the configuration.<br>" .
                    "If you are an administrator: You have to enable JWT authentication in order to use this feature.");
                break;
            }

            // Check if job_id parameter exists.
            if(! isset($_GET['nodename']) || ! isset($_GET['state'])){
                http_response_code(400); // Bad request
                addError("No node name or no state provided. Bad request.");
                break;
            }

            if( ! \auth\current_user_is_admin() ){
                http_response_code(403); // Forbidden
                addError(
                    "Only admins are allowed to perform this action!"
                );
                break;
            }

            $nodename = $_GET['nodename'];
            $new_state = $_GET['state'];

            $valid_states = ['resume', 'drain'];
            if (!in_array($new_state, $valid_states, true)) {
                http_response_code(400);
                addError("Invalid node state.");
                break;
            }

            if( ! isset($_GET['do']) || $_GET['do'] !== 'perform' ){
                $templateBuilder = new TemplateLoader("modal_node_new_state.html");
                $templateBuilder->setParam("NODE_NAME", htmlspecialchars($nodename, ENT_QUOTES, 'UTF-8'));
                $templateBuilder->setParam("STATE", htmlspecialchars($new_state, ENT_QUOTES, 'UTF-8'));
                $templateBuilder->setParam("CSRF_TOKEN", \auth\get_csrf_token());
                $contents .= $templateBuilder->build();
                break;
            }

            if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                http_response_code(403);
                addError("Invalid request (CSRF token mismatch).");
                break;
            }

            // Perform the update and then show cluster utilization
            if( $dao->set_node_state($nodename, $new_state) ) {
                addSuccess("Node " . htmlspecialchars($nodename, ENT_QUOTES, 'UTF-8') . " set to new state " . htmlspecialchars($new_state, ENT_QUOTES, 'UTF-8') . ".");
                log_msg("Admin '{$_SESSION['USER']}' set node '$nodename' to state '$new_state'",
                    LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
            }
            else {
                addError("Something went wrong when updating node " . htmlspecialchars($nodename, ENT_QUOTES, 'UTF-8'));
            }
            \cache\CacheWrapper::getInstance()->delete("slurm/node/".$nodename); // Delete cached entry because we KNOW that it has changed.

            $title = 'Cluster usage';
            $contents .= \view\actions\get_all_nodes_usage(
                    $dao,
                    $csp_nonce,
                    isset($_GET['show_users']) && $_GET['show_users'] === '1'
            );
            break;

        case 'wiki':

            if ( ! $wiki_enabled || $wiki_handled )
                break;

            $wiki_do  = $_POST['do']  ?? $_GET['do']  ?? '';
            $wiki_url = trim($_GET['url'] ?? '');

            if ($wiki_url !== '' && !\wiki\is_valid_wiki_url($wiki_url)) {
                http_response_code(400);
                addError("Invalid wiki URL.");
                break;
            }

            if ($wiki_do === 'image_picker_json') {

                // Returns JSON array of image files for the image picker in the editor.
                // No CSRF needed — read-only, same visibility rules as page access.
                header('Content-Type: application/json; charset=utf-8');
                if (!\auth\current_user_is_privileged() || $wiki_url === '') {
                    echo '[]';
                    exit;
                }
                $picker_files  = \wiki\WikiDatabase::getInstance()->getFilesForPage($wiki_url);
                $picker_images = [];
                foreach ($picker_files as $f) {
                    if (!str_starts_with($f['mime_type'], 'image/')) continue;
                    $picker_images[] = [
                        'url'  => '/get_file.php?id=' . $f['stored_name'],
                        'name' => $f['filename'],
                    ];
                }
                echo json_encode($picker_images, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                exit;

            } elseif ($wiki_do === 'files') {

                if (!\auth\current_user_is_privileged()) {
                    http_response_code(403);
                    addError("403 Forbidden.");
                    break;
                }
                if ($wiki_url === '') {
                    http_response_code(400);
                    addError("No wiki URL given.");
                    break;
                }
                $title     = 'Files: ' . htmlspecialchars($wiki_url, ENT_QUOTES, 'UTF-8');
                $contents .= \view\wiki\get_wiki_files_page($wiki_url, \auth\get_csrf_token());

            } elseif ($wiki_do === 'upload_file') {

                if (!\auth\current_user_is_privileged()) {
                    http_response_code(403);
                    addError("403 Forbidden.");
                    break;
                }
                if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                    http_response_code(403);
                    addError("Invalid request (CSRF token mismatch).");
                    break;
                }
                $upload_page_url = trim($_POST['page_url'] ?? '');
                if (!\wiki\is_valid_wiki_url($upload_page_url)) {
                    http_response_code(400);
                    addError("Invalid wiki URL.");
                    break;
                }
                $result = \wiki\handle_upload($_FILES['file'] ?? [], $upload_page_url, $_SESSION['USER']);
                if (!$result['ok']) {
                    addError(htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8'));
                } else {
                    log_msg("User '{$_SESSION['USER']}' uploaded file to wiki page '$upload_page_url'",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                }
                header('Location: ?action=wiki&url=' . urlencode($upload_page_url) . '&do=files');
                exit;

            } elseif ($wiki_do === 'delete_file') {

                if (!\auth\current_user_is_privileged()) {
                    http_response_code(403);
                    addError("403 Forbidden.");
                    break;
                }
                if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                    http_response_code(403);
                    addError("Invalid request (CSRF token mismatch).");
                    break;
                }
                $del_stored = trim($_POST['stored_name'] ?? '');
                $del_page   = trim($_POST['page_url']    ?? '');
                if (!\wiki\is_valid_stored_name($del_stored)) {
                    http_response_code(400);
                    addError("Invalid file ID.");
                    break;
                }
                $wiki_db  = \wiki\WikiDatabase::getInstance();
                $fileRow  = $wiki_db->getFile($del_stored);
                if ($fileRow !== NULL) {
                    $filePath = \wiki\get_file_path($del_stored);
                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                    $wiki_db->deleteFile($del_stored);
                    log_msg("User '{$_SESSION['USER']}' deleted wiki file '$del_stored' ({$fileRow['filename']}) from page '{$fileRow['page_url']}'",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                    $del_page = $del_page !== '' && \wiki\is_valid_wiki_url($del_page)
                        ? $del_page
                        : $fileRow['page_url'];
                }
                header('Location: ?action=wiki&url=' . urlencode($del_page) . '&do=files');
                exit;

            } elseif ($wiki_do === 'edit') {

                // Only privileged users may edit wiki pages
                if ( ! \auth\current_user_is_privileged()) {
                    http_response_code(403);
                    addError("403 Forbidden.");
                    break;
                }

                $title     = $wiki_url !== '' ? 'Edit: ' . htmlspecialchars($wiki_url, ENT_QUOTES, 'UTF-8') : 'New wiki page';
                $contents .= \view\wiki\get_wiki_edit_form($wiki_url, \auth\get_csrf_token(), $csp_nonce);

            } elseif ($wiki_do === 'save_alias' || $wiki_do === 'delete_alias') {

                if (!\auth\current_user_is_privileged()) {
                    http_response_code(403);
                    addError("403 Forbidden.");
                    break;
                }
                if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                    http_response_code(403);
                    addError("Invalid request (CSRF token mismatch).");
                    break;
                }

                $alias_source = trim($_POST['source_url'] ?? '');
                if (!\wiki\is_valid_wiki_url($alias_source)) {
                    http_response_code(400);
                    addError("Invalid source URL.");
                    break;
                }

                if ($wiki_do === 'delete_alias') {
                    \wiki\WikiDatabase::getInstance()->deleteAlias($alias_source);
                    log_msg("User '{$_SESSION['USER']}' deleted wiki alias '$alias_source'",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                    header('Location: ?action=wiki');
                    exit;
                }

                // save_alias
                $alias_target = trim($_POST['target_url'] ?? '');
                $alias_anchor = trim($_POST['anchor']     ?? '');
                if (!\wiki\is_valid_wiki_url($alias_target)) {
                    http_response_code(400);
                    addError("Invalid target URL.");
                    break;
                }
                if ($alias_anchor !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/', $alias_anchor)) {
                    http_response_code(400);
                    addError("Invalid anchor (allowed: a-z, 0-9, hyphens, underscores; max 64 chars).");
                    break;
                }
                \wiki\WikiDatabase::getInstance()->saveAlias($alias_source, $alias_target, $alias_anchor);
                log_msg("User '{$_SESSION['USER']}' saved wiki alias '$alias_source' → '$alias_target" . ($alias_anchor ? "#$alias_anchor" : '') . "'",
                    LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                header('Location: ?action=wiki');
                exit;

            } elseif ($wiki_do === 'save' || $wiki_do === 'delete') {

                // Only privileged users may edit wiki pages
                if (!\auth\current_user_is_privileged()) {
                    http_response_code(403);
                    addError("403 Forbidden.");
                    break;
                }
                if (!isset($_POST['csrf_token']) || !\auth\validate_csrf_token($_POST['csrf_token'])) {
                    http_response_code(403);
                    addError("Invalid request (CSRF token mismatch).");
                    break;
                }

                if ($wiki_do === 'delete') {
                    $del_url = trim($_POST['original_url'] ?? '');
                    if (!\wiki\is_valid_wiki_url($del_url)) {
                        http_response_code(400);
                        addError("Invalid wiki URL.");
                        break;
                    }
                    $wiki_db = \wiki\WikiDatabase::getInstance();
                    if ($wiki_db->deletePage($del_url)) {
                        log_msg("User '{$_SESSION['USER']}' deleted wiki page '$del_url'",
                            LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                        // Redirect to main wiki page
                        header('Location: ?action=wiki');
                        exit;
                    }
                    addError("Page not found.");
                    break;
                }

                // Save
                $save_url        = trim($_POST['url']        ?? '');
                $save_title      = trim($_POST['title']      ?? '');
                $save_content    = $_POST['content']         ?? '';
                $save_visibility = $_POST['visibility']      ?? \wiki\WikiDatabase::VISIBILITY_USERS;
                $save_show_in_nav = max(0, (int)($_POST['show_in_nav'] ?? 0));

                if (!\wiki\is_valid_wiki_url($save_url)) {
                    http_response_code(400);
                    addError("Invalid wiki URL.");
                    break;
                }
                if (empty($save_title)) {
                    http_response_code(400);
                    addError("Title is required.");
                    break;
                }
                if (strlen($save_title) > 255) {
                    http_response_code(400);
                    addError("Title must not exceed 255 characters.");
                    break;
                }
                if (strlen($save_content) > 4 * 1024 * 1024) {
                    http_response_code(400);
                    addError("Page content exceeds maximum allowed size (4 MB).");
                    break;
                }
                if (!in_array($save_visibility, \wiki\WikiDatabase::VALID_VISIBILITIES, TRUE)) {
                    http_response_code(400);
                    addError("Invalid visibility.");
                    break;
                }

                $original_url = trim($_POST['original_url'] ?? '');
                $is_rename    = $original_url !== '' && $original_url !== $save_url;
                if ($is_rename && !\wiki\is_valid_wiki_url($original_url)) {
                    http_response_code(400);
                    addError("Invalid original URL.");
                    break;
                }

                $wiki_db = \wiki\WikiDatabase::getInstance();
                // Save new URL first so data is never lost even if the delete below fails.
                $wiki_db->savePage(
                    $save_url, $save_title, $save_content, $save_visibility, $save_show_in_nav, $_SESSION['USER']
                );

                if ($is_rename) {
                    $wiki_db->deletePage($original_url);
                    if (!empty($_POST['rename_alias'])) {
                        $wiki_db->saveAlias($original_url, $save_url, '');
                    }
                    log_msg("User '{$_SESSION['USER']}' renamed wiki page '$original_url' → '$save_url'",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                } else {
                    log_msg("User '{$_SESSION['USER']}' saved wiki page '$save_url'",
                        LOG_INFO, LOG_MODE_PHP|LOG_MODE_SYSLOG);
                }
                header('Location: ?action=wiki&url=' . urlencode($save_url));
                exit;

            }
            elseif ($wiki_url !== '') {
                // View normal wiki page; for node/* pages, build the sidebar block first
                // so it can float right inside the wiki content area.
                $node_sidebar = '';
                if (str_starts_with($wiki_url, 'node/')) {
                    $node_segment = substr($wiki_url, 5);
                    foreach ($dao->getNodeList() as $node) {
                        if (\utils\canonical_wiki_segment($node) === $node_segment) {
                            $node_sidebar = \view\wiki\get_node_slurm_block($dao->get_node_info($node));
                            break;
                        }
                    }
                }

                // If no page exists at this URL, check for an alias and redirect.
                $wiki_db_inst = \wiki\WikiDatabase::getInstance();
                if (!$wiki_db_inst->pageExists($wiki_url)) {
                    $alias = $wiki_db_inst->getAlias($wiki_url);
                    if ($alias !== NULL) {
                        $redir = '?action=wiki&url=' . urlencode($alias['target_url']);
                        if ($alias['anchor'] !== '') {
                            $redir .= '#' . rawurlencode($alias['anchor']);
                        }
                        header('Location: ' . $redir);
                        exit;
                    }
                }

                [$wiki_status, $wiki_title, $wiki_html] = \view\wiki\get_wiki_page($wiki_url, $csp_nonce, $node_sidebar);
                http_response_code($wiki_status);
                $title     = $wiki_title;
                $contents .= $wiki_html;

            } else {

                $title     = 'Wiki';
                $contents .= \view\wiki\get_wiki_overview();

            }
            break;

        default:
            http_response_code(404);
            $title = '404 Not Found';
            $contents .= "404 Not Found.";
    }
} // endif user_is_logged_in()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php print (!empty($title) ? $title . " | " : ""); ?>Slurm Dashboard</title>

    <link rel="stylesheet" href="/lib/bootstrap/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="/lib/jquery/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="/lib/popper.js/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="/lib/bootstrap/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>
    <script nonce="<?php echo $csp_nonce; ?>">
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el =>
                new bootstrap.Tooltip(el, { container: 'body' })
            );

            // Close tooltip when there is a click outside
            document.addEventListener('click', function(e) {
                tooltipTriggerList.forEach(function(el) {
                    if (!el.contains(e.target)) {
                        bootstrap.Tooltip.getInstance(el)?.hide();
                    }
                });
            });

        });
    </script>

    <link rel="stylesheet" href="/style.css" crossorigin="anonymous">
    <script src="/multiselect.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>

<?php if(isset($_SESSION['USER'])): ?>
    <nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavDropdown">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 justify-content-center">
                    <li class="nav-item">
                        <a class="nav-link" href="?action=usage">Cluster Usage</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=jobs">Queue</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=job_history">Job history</a>
                    </li>
<?php
    if( \auth\current_user_is_privileged() ):
?>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=users">Users</a>
                    </li>
<?php
    endif;
    if ($wiki_enabled):
        print \view\wiki\get_wiki_nav_item();
    endif;
?>
                </ul>
                <div class="text-end">
                    <div class="small float-lg-start" style="margin-right: 5px">Logged in as<br> <a href="?action=users&user_name=<?php print htmlspecialchars($_SESSION['USER'], ENT_QUOTES, 'UTF-8'); ?>"><i><?php print htmlspecialchars($_SESSION['USER'], ENT_QUOTES, 'UTF-8'); ?></a></i></div>
                    <a href="?action=logout"><button type="button" class="btn btn-warning">Logout</button></a>
                </div>
            </div>
        </div>
    </nav>
<?php endif; ?>

<div id="content">
    <div class="alert alert-danger" role="alert" style="display: <?php echo !empty($errormsg) ? "block" : "none"; ?>">
        <strong>Error:</strong>
        <ul>
            <?php print $errormsg; ?>
        </ul>
    </div>
    <div class="alert alert-success" role="alert" style="display: <?php echo !empty($successmsg) ? "block" : "none"; ?>">
        <strong>Success:</strong>
        <ul>
            <?php print $successmsg; ?>
        </ul>
    </div>

    <h1><?php print (!empty($title) ? $title : "Slurm Dashboard"); ?></h1>

    <?php
    print $contents;
    ?>
</div>

    <hr>
    <footer>
        &copy; 2024-2026 by <a href="https://suess.dev/" target="_blank">Nikolaus Süß</a>, University of Vienna<br>
        Source code available on <a href="https://github.com/nikolaussuess/slurm-dashboard" target="_blank">GitHub</a>.
    </footer>

</body>
</html>

