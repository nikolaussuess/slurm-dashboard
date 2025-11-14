<?php

namespace client;
require_once __DIR__ . '/AbstractClient.inc.php';

class V0043Client extends AbstractClient {

    const api_version = 'v0.0.43';

    protected function get_nodes(array $job_arr) : string {
        // TODO: Eventually also read the other values in that new array ...
        if(isset($job_arr['job_resources']) && isset($job_arr['job_resources']['nodes']['list']))
            return $job_arr['job_resources']['nodes']['list'];
        else
            return "?";
    }

}