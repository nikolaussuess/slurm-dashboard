<?php

namespace client;

require_once 'Request.inc.php';
use Error;

interface Client{
    function is_available() : bool;
    function getNodeList(): array;
    function get_jobs(?array $filter = NULL) : array;
    function get_jobs_from_slurmdb(?array $filter = NULL) : array;
    function get_account_list(): array;
    function get_users_list(): array;
    function get_job(string $id) : ?array;
    function get_job_from_slurmdb(int|string $id) : ?array;
    function get_user(string $user_name) : array;
    function get_users() : array;
    function get_node_info(string $nodename) : array;
    function get_maintenances() : array;
}

class ClientFactory {
    public static function newClient($version = REST_API_VERSION) : Client {

        if( $version == 'auto' ){
            $response = \RequestFactory::newRequest()->request_json2("openapi/v3", 0);
            if(! isset($response['info']['x-slurm']['data_parsers']) ){
                die("Could not detect SLURM REST API version. If the error persists, please contact " . ADMIN_EMAIL);
            }
            $parsers = array_column($response['info']['x-slurm']['data_parsers'], 'plugin');
            rsort($parsers, SORT_NATURAL | SORT_FLAG_CASE);

            syslog(LOG_INFO, "slurm-dashboard: Detected the following data parsers: " . implode(', ', $parsers));
        }

        if( ! isset($parsers) )
            $parsers = [$version];

        // Use the first matching version.
        foreach ($parsers as $version){
            $classname = strtoupper(str_replace('.', '', $version)) . "Client";
            if(file_exists(__DIR__ . "/{$classname}.inc.php")){
                require_once __DIR__ . "/{$classname}.inc.php";
                $classname = '\\client\\' . $classname;
                return new $classname();
            }
        }
        throw new Error("API version currently unsupported. No client found.");
    }

}
