<?php
#error_reporting(E_ALL);
#ini_set('display_errors', '1');
session_start();
date_default_timezone_set('Europe/Vienna');

require_once 'TemplateLoader.inc.php';
require_once 'client.inc.php';
require_once 'globals.inc.php';
require_once 'auth/auth.inc.php';
require_once 'utils.inc.php';
require_once 'actions.inc.php';

$dao = new Client();
$title = "Clusterinfo " . CLUSTER_NAME;
$contents = "";

if( isset($_GET['action']) && $_GET['action'] == "logout"){
    session_destroy();
    unset($_SESSION['USER']);
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

        $methods_string = '';
        foreach(\auth\get_methods() as $method => $settings ){
            $methods_string .= '<option value="' . $method . '"';
            if(isset($settings['default']) &&  $settings['default'] === TRUE){
                 $methods_string .= ' selected ';
            }

            if( ! isset($settings['supported']) ||  $settings['supported'] !== TRUE){
                $methods_string .= ' disabled ';
            }

            $methods_string .= '>' . $method . '</option>';
        }

        $templateBuilder = new TemplateLoader("loginForm.html");
        $templateBuilder->setParam("action", "login");
        $templateBuilder->setParam("buttontext", "Login");
        $templateBuilder->setParam("methods", $methods_string);
        $contents .= $templateBuilder->build();
    }
}

# About page
if(isset($_GET['action']) && $_GET['action'] == "about"){
    $title = "About the cluster " . CLUSTER_NAME;

    $templateBuilder = new TemplateLoader("about.html");
    $templateBuilder->setParam("CLUSTER_NAME", CLUSTER_NAME);
    $templateBuilder->setParam("ADMIN_NAMES", ADMIN_NAMES);
    $templateBuilder->setParam("ADMIN_EMAIL", ADMIN_EMAIL);
    $templateBuilder->setParam("SLURM_LOGIN_NODE", SLURM_LOGIN_NODE);
    $templateBuilder->setParam("WIKI_LINK", WIKI_LINK);
    $contents .= $templateBuilder->build();
}

# User is logged in
if( isset($_SESSION['USER']) ){

    $action = $_GET['action'] ?? "usage";
    if( $action == "login") $action = "usage";

    // Show maintenance dates if there are some
    $maintenances = $dao->get_maintenances();
    if(! empty($maintenances)){
        $contents .= '<div class="alert alert-info" role="alert"><strong>Scheduled maintenances:</strong><ul>';
    }
    foreach( $maintenances as $maintenance ){
        $contents .= '<li>Node(s) ';
        if(isset($maintenance['node_list']))
            $contents .= '<span class="monospaced">' . $maintenance['node_list'] . '</span>';
        else
            $contents .= '(any)';
        $contents .= " will be unavailable from " . \utils\get_date_from_unix_if_defined($maintenance, 'start_time')
            . " until " . \utils\get_date_from_unix_if_defined($maintenance, 'end_time') . ".";
        $contents .= '</li>';
    }
    if(! empty($maintenances)){
        $contents .= '</ul><p>All jobs that are guaranteed to end before the maintenance window due to the time limit are scheduled normally. Jobs that are not guaranteed to end before the start of the maintenance window can only start after the maintenance window on affected nodes. Tip: You could run shorter jobs for the time being, use breakpoints to interrupt your job for maintenance or use other nodes that are not affected from maintenance.</p></div>';
    }
    // END of maintenance

    switch($action){

        case "usage":
            $contents .= \action\get_cluster_usage($dao);
            break;

        case "job":
            if( ! isset($_GET['job_id'])){
                addError("No job ID given.");
                break;
            }
            $job_id = $_GET['job_id'];
            $contents .= \action\get_slurm_job_info($dao, $job_id);
            $contents .= \action\get_slurmdb_job_info($dao, $job_id);

            break;

        case "jobs":
            $contents .= \action\get_job_queue($dao);
            break;

        case 'job_history':

            // BEGIN evaluate filter form
            $filter = array();
            if( isset($_GET['do']) && $_GET['do'] == 'search' ){

                $start_time = $_POST['form_time_min'] ?? '';
                if($start_time != ''){
                    $filter['start_time_value'] = $start_time;
                    try {
                        $dateTimeObject = new DateTime($start_time);
                        $start_time = $dateTimeObject->getTimestamp();
                        $filter['start_time'] = $start_time;
                    } catch (Exception $e) {
                        addError("Start time value (" . $filter['start_time_value'] . ") invalid: " .
                            $e->getMessage() . "; Ignoring value");
                    }
                }

                $end_time = $_POST['form_time_max'] ?? '';
                if($end_time != ''){
                    $filter['end_time_value'] = $end_time;
                    try {
                        $dateTimeObject = new DateTime($end_time);
                        $end_time = $dateTimeObject->getTimestamp();
                        $filter['end_time'] = $end_time;
                    } catch (Exception $e) {
                        addError("End time value (" . $filter['end_time_value'] . ") invalid: " . $e->getMessage() .
                            "; Ignoring value");
                    }
                }

                $user = $_POST['form_user'] ?? '';
                if($user != ''){
                    $filter['users'] = $user;
                }

                $account = $_POST['form_account'] ?? '';
                if($account != ''){
                    $filter['account'] = $account;
                }

                $node = $_POST['form_nodename'] ?? '';
                if($node != ''){
                    $filter['node'] = $node;
                }

                $job_name = $_POST['form_job_name'] ?? '';
                if($job_name != ''){
                    $filter['job_name'] = $job_name;
                }

                $constraints = $_POST['form_constraints'] ?? '';
                if($constraints != ''){
                    $filter['constraints'] = $constraints;
                }

                $state = $_POST['form_state'] ?? '';
                if($state != ''){
                    $filter['state'] = $state;
                }
            }
            // END evaluate filter form

            $contents .= \action\get_job_history($dao, $filter);
            break;

        case 'cancel-job':
            // Check if job_id parameter exists.
            if(! isset($_GET['job_id']) || intval($_GET['job_id']) != $_GET['job_id']){
                addError("No job id provided or job id is not a valid number.");
                break;
            }

            $job_id = $_GET['job_id'];

            // Check for sufficient privileges
            $job_data = $dao->get_job($job_id);

            if(count($job_data['jobs']) == 0){
                addError("Job " . $_GET['job_id'] . " not in active queue any more.");
                break;
            }

            if( ! \auth\current_user_is_admin() && $job_data['jobs'][0]['user_name'] != $_SESSION['USER'] ){
                addError(
                        "The job belongs to user " . $job_data['jobs'][0]['user_name'] . " but current user is "
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
            elseif($_GET['do'] == "cancel") {
                $res = $dao->cancel_job($job_id);
                if(isset($res['errors']) && !empty($res['errors'])){
                    \utils\show_errors($res);
                }
                else {
                    addSuccess("Job " . $job_id . " cancelled.");
                    apcu_delete("slurm/jobs"); // Delete cached entry because we KNOW that it has changed.
                    $contents .= \action\get_job_queue($dao);
                }
            }
            else {
                addError("Error!");
                break;
            }

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

            $contents .= \action\get_user_list($dao);
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
        &copy; 2024 by <a href="https://ufind.univie.ac.at/de/person.html?id=109904" target="_blank">Nikolaus Süß</a>, University of Vienna
    </footer>

</body>
</html>

