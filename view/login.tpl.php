<?php

namespace view\login;

use TemplateLoader;

function get_login_form() : string {
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
    $templateBuilder->setParam("CSRF_TOKEN", \auth\get_csrf_token());
    return $templateBuilder->build();
}

function get_about_page() : string {
    // Show wiki about page IF the wiki feature is enabled AND the page exists.
    // Otherwise show the templates/about.html static page.
    if (class_exists('\wiki\WikiDatabase', FALSE)) {
        $db = \wiki\WikiDatabase::getInstance();
        if ($db !== NULL && $db->pageExists('about')) {
            [, , $html] = \view\wiki\get_wiki_page('about');
            if (!isset($_SESSION['USER'])) {
                $btn  = '<a href="?action=" class="btn btn-info" role="button">&larr; Back to login</a>';
                $html = $btn . $html . $btn;
            }
            return $html;
        }
    }
    // Wiki page does not exist, use static page.
    $templateBuilder = new TemplateLoader("about.html");
    $templateBuilder->setParam("CLUSTER_NAME", config("CLUSTER_NAME"));
    $templateBuilder->setParam("ADMIN_NAMES", config('ADMIN_NAMES'));
    $templateBuilder->setParam("ADMIN_EMAIL", config('ADMIN_EMAIL'));
    $templateBuilder->setParam("SLURM_LOGIN_NODE", config('SLURM_LOGIN_NODE'));
    $templateBuilder->setParam("WIKI_LINK", config('WIKI_LINK'));
    return $templateBuilder->build();
}