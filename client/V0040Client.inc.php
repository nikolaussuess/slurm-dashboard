<?php

namespace client;
require_once 'AbstractClient.inc.php';

class V0040Client extends AbstractClient {

    const api_version = 'v0.0.40';

    protected function get_nodes(array $job_arr) : string {
        if(isset($job_arr['job_resources']) && isset($job_arr['job_resources']['nodes']))
            return $job_arr['job_resources']['nodes'];
        else
            return "?";
    }


}