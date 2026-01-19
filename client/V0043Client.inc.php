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

    function get_fairshare(?string $user_name) : array {
        $parameters = '';
        if(!empty($user_name)){
            $parameters .= '?users='.$user_name;
        }
        $json = RequestFactory::newRequest()->request_json("shares{$parameters}", 'slurm', static::api_version);

        $shares = [];
        foreach ($json['shares']['shares'] as $json_shares){
            // If a user name is given, we are not interested in the whole account hierarchy
            if(
                !empty($user_name) && $user_name !== $json_shares['name'] ||
                !empty($user_name) && !in_array('USER', $json_shares['type'])
            )
                continue;

            $share = array(
                'cluster'          => $json_shares['cluster'],     // String
                'parent'           => $json_shares['parent'],      // String
                'partition'        => $json_shares['partition'],   // String
                'name'             => $json_shares['name'],        // String
                'type'             => $json_shares['type'],        // Array of strings
                'shares_normalized'=> $this->_get_number_if_defined($json_shares['shares_normalized'], ''),
                'usage'            => $json_shares['usage'], // int
                // Different from v0.0.40!
                'fairshare_level'  => $this->_get_number_if_defined($json_shares['fairshare']['level'], ''),
                // Different from v0.0.40!
                'fairshare_factor' => $this->_get_number_if_defined($json_shares['fairshare']['factor'], ''),
                'effective_usage'  => $this->_get_number_if_defined($json_shares['effective_usage'], ''),
                'shares'           => $this->_get_number_if_defined($json_shares['shares'], ''),
                'usage_normalized' => $this->_get_number_if_defined($json_shares['usage_normalized'], ''),
                // tres was skipped here

            );
            $shares[] = $share;
        }

        return $shares;
    }

}