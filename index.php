<?php
#error_reporting(E_ALL);
#ini_set('display_errors', '1');
session_start();
date_default_timezone_set('Europe/Vienna');

require_once 'TemplateLoader.inc.php';
require_once 'globals.inc.php';
require_once 'client/Client.inc.php';
require_once 'client/utils/DependencyResolver.inc.php';
require_once 'auth/auth.inc.php';
require_once 'utils.inc.php';

require_once 'view/login.tpl.php';
require_once 'view/maintenances.tpl.php';
require_once 'view/usage.tpl.php';
require_once 'view/job.tpl.php';
require_once 'view/slurm-queue.tpl.php';
require_once 'view/job_history.tpl.php';
require_once 'view/users.tpl.php';

$dao = \client\ClientFactory::newClient();
$title = "Clusterinfo " . CLUSTER_NAME;
$contents = "";

if( isset($_GET['action']) && $_GET['action'] == "logout"){
    session_destroy();
    unset($_SESSION['USER']);
}

// Check if the socket exists and add a warning otherwise
if( ! $dao->is_available() ){
    throw new \exceptions\RequestFailedException(
        "Cannot create socket.",
        '$dao->is_available() failed',
        "Cannot create socket. Is <kbd>slurmrestd</kbd> running? Please report this issue to " . ADMIN_EMAIL
    );
}

if(!isset($_SESSION['USER'])) {

    if(isset($_GET['action']) && $_GET['action'] == "login"){
        if( !isset($_POST['username']) || !isset($_POST['password'])){
            addError("Login failed.");
        }
        else {
            $username = $_POST['username'];
            $password = $_POST['password'];
            $method = $_POST['method'];
            if(auth($username, $password, $method)){
                $_SESSION['USER'] = $username;
                addSuccess("Login successful!");
            }
            else {
                addError("Login failed.");
            }
        }
    }
    // Is set above if the login was successful.
    // Otherwise, the login form is displayed again.
    if( ! isset($_SESSION['USER']) && (!isset($_GET['action']) || $_GET['action'] != "about")) {
        $contents .= \view\login\get_login_form();
    }
}

# About page
if(isset($_GET['action']) && $_GET['action'] == "about"){
    $title = "About the cluster " . CLUSTER_NAME;
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
            $contents .= "<h2>Current cluster usage</h2>";
            foreach ($dao->getNodeList() as $node) {
                $contents .= \view\actions\get_usage($dao->get_node_info($node));
            }
            break;

        case "job":
            if( ! isset($_GET['job_id'])){
                addError("No job ID given.");
                break;
            }

            # SLURM QUEUE information
            $contents .= "<h2>Job " . $_GET['job_id'] . "</h2>";
            $query = $dao->get_job($_GET['job_id']);
            if( $query == NULL ){
                $contents .= "<p>Job " . $_GET['job_id'] . " not in active queue anymore.</p>";
            }
            else {
                $dependency_resolver = new \client\utils\DependencyResolver($dao);
                $contents .= \view\actions\get_slurm_jobinfo($query, $dependency_resolver->renderDependencyListHTML($_GET['job_id']) ?? '');
            }

            # SLURMDB information
            $query = $dao->get_job_from_slurmdb($_GET['job_id']);
            if(count($query) == 0){
                $contents .= "<p>Job " . $_GET['job_id'] . " not found in <span class='monospaced'>slurmdb</span>.</p>";
            }
            else {
                $contents .= \view\actions\get_slurmdb_jobinfo($query);
            }

            break;

        case "jobs":

            // Filter
            // Exclude partition p_low if parameter exclude_p_low=1
            $exclude_p_low = isset($_GET['exclude_p_low']) && $_GET['exclude_p_low'] == 1;
            $filter = array();
            if($exclude_p_low)
                $filter['exclude_p_low'] = 1;

            $jobs = $dao->get_jobs($filter);

            $contents .= \view\actions\get_slurm_queue($jobs, $exclude_p_low);

            break;

        case 'job_history':

            // Get submitted filter form (reads $_GET and $_POST)
            $filter = \view\actions\get_slurmdb_filter_form_evaluation();

            $contents .= "<h2>Jobs</h2>";

            $accounts = $dao->get_account_list();
            $users = $dao->get_users_list();
            $nodes = $dao->getNodeList();

            $contents .= \view\actions\get_slurmdb_filter_form($filter, $accounts, $users, $nodes);

            $jobs = $dao->get_jobs_from_slurmdb($filter);
            $contents .= \view\actions\get_filtered_jobs_from_slurmdb($jobs);

            break;

        case 'users':
            $title = "List of users";

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

            break;

        case 'cancel-job':

            if( ! \client\utils\jwt\JwtAuthentication::is_supported() ){
                addError("Cancelling jobs is currently not supported by the configuration.<br>" .
                           "If you are an administrator: You have to enable JWT authentication in order to use this feature.");
                break;
            }

            // Check if job_id parameter exists.
            if(! isset($_GET['job_id']) || intval($_GET['job_id']) != $_GET['job_id']){
                addError("No job id provided or job id is not a valid number.");
                break;
            }

            $job_id = $_GET['job_id'];

            // Check for sufficient privileges
            $job_data = $dao->get_job($job_id);

            if($job_data === NULL){
                addError("Job " . $_GET['job_id'] . " not in active queue any more.");
                break;
            }

            if( ! \auth\current_user_is_admin() && $job_data['user_name'] != $_SESSION['USER'] ){
                addError(
                    "The job belongs to user " . $job_data['user_name'] . " but current user is "
                    . $_SESSION['USER'] . ". Since you are not an administrator, you can only delete your own jobs."
                );
                break;
            }

            if(! isset($_GET['do']) || $_GET['do'] != "cancel") {
                $templateBuilder = new TemplateLoader("modal_job_cancelling.html");
                $templateBuilder->setParam("JOBID", $job_id);
                $contents .= $templateBuilder->build();
                break;
            }
            else {
                $res = $dao->cancel_job($job_id);
                if(isset($res['errors']) && !empty($res['errors'])){
                    \utils\show_errors($res);
                }
                else {
                    addSuccess("Job " . $job_id . " cancelled.");
                    apcu_delete("slurm/jobs"); // Delete cached entry because we KNOW that it has changed.
                    apcu_delete("slurm/job/".$job_id); // Delete cached entry because we KNOW that it has changed.
                    $contents .= \view\actions\get_slurm_queue($dao->get_jobs(), 0);
                }
            }
            break;

        default:
            http_response_code(404);
            $contents .= "404 Not Found.";
    }
} // endif user_is_logged_in()
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <title><?php print (!empty($title) ? $title . " | " : ""); ?>Slurm Dashboard</title>

    <link rel="stylesheet" href="/lib/bootstrap/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="/lib/jquery/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="/lib/popper.js/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="/lib/bootstrap/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el =>
                new bootstrap.Tooltip(el, { container: 'body' })
            );
        });
    </script>

    <link rel="stylesheet" href="/style.css" crossorigin="anonymous">
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
?>
                </ul>
                <div class="text-end">
                    <div class="small float-lg-start" style="margin-right: 5px">Angemeldet als<br> <i><?php print $_SESSION['USER']; ?></i></div>
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
        &copy; 2024-2025 by <a href="https://suess.dev/" target="_blank">Nikolaus Süß</a>, University of Vienna<br>
        Source code available on <a href="https://github.com/nikolaussuess/slurm-dashboard" target="_blank">GitHub</a>.
    </footer>

</body>
</html>

