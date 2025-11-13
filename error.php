<?php
include_once "globals.inc.php";

// Protect from direct access
if (!isset($exception) || !($exception instanceof Throwable)) {
    header("Location: /index.php?action=404");
    throw new ErrorException("Website not available.", 403);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <title>500 Internal Server Error | Slurm Dashboard</title>

    <link rel="stylesheet" href="/lib/bootstrap/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="/lib/jquery/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="/lib/popper.js/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="/lib/bootstrap/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>

    <link rel="stylesheet" href="/style.css" crossorigin="anonymous">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavDropdown">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 justify-content-center">
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php">Go to front page</a>
                    </li>
                </ul>
                <div class="text-end">
<?php if(isset($_SESSION['USER'])): ?>
                    <div class="small float-lg-start" style="margin-right: 5px">Angemeldet als<br> <i><?php print $_SESSION['USER']; ?></i></div>
                    <a href="?action=logout"><button type="button" class="btn btn-warning">Logout</button></a>
<?php else: ?>
                    <div class="small float-lg-start" style="margin-right: 5px">Nicht angemeldet</div>
<?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

<div id="content">

    <h1>500 Internal Server Error on the Slurm Dashboard</h1>
<?php if($exception instanceof \exceptions\BaseException || $exception instanceof \exceptions\BaseError): ?>
    <p>An internal server error occurred because of the following reason:</p>
    <div class="alert alert-danger" role="alert" style="display: block">
        <?php print $exception->get_html_message(); ?>
    </div>
<?php else: ?>
    <p>An unknown internal server occurred.</p>
<?php endif; ?>
    <p>The dashboard is currently not available. If the error persists, please write an email to <?php print ADMIN_EMAIL; ?>.</p>

    <p></p>
</div>

<hr>
<footer>
    &copy; 2024-2025 by <a href="https://suess.dev/" target="_blank">Nikolaus Süß</a>, University of Vienna<br>
    Source code available on <a href="https://github.com/nikolaussuess/slurm-dashboard" target="_blank">GitHub</a>.
</footer>

</body>
</html>