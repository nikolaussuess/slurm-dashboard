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
    return $templateBuilder->build();
}

function get_about_page() : string {
    $templateBuilder = new TemplateLoader("about.html");
    $templateBuilder->setParam("CLUSTER_NAME", config("CLUSTER_NAME"));
    $templateBuilder->setParam("ADMIN_NAMES", config('ADMIN_NAMES'));
    $templateBuilder->setParam("ADMIN_EMAIL", config('ADMIN_EMAIL'));
    $templateBuilder->setParam("SLURM_LOGIN_NODE", config('SLURM_LOGIN_NODE'));
    $templateBuilder->setParam("WIKI_LINK", config('WIKI_LINK'));
    return $templateBuilder->build();
}