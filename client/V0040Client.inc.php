<?php

namespace client;
require_once __DIR__ . '/AbstractClient.inc.php';

class V0040Client extends AbstractClient {

    const api_version = 'v0.0.40';

    protected function get_nodes(array $job_arr) : string {
        if(isset($job_arr['job_resources']) && isset($job_arr['job_resources']['nodes']))
            return $job_arr['job_resources']['nodes'];
        else
            return "?";
    }

    function get_fairshare(?string $user_name) : array {

        $parameters = '';
        if(!empty($user_name)){
            $parameters .= '?users='.$user_name;
        }
        $response = RequestFactory::newRequest()
            ->request_plain("shares{$parameters}", 'slurm', static::api_version);

        // There is a bug in the v0.0.40 endpoint!
        // Data might look like
        //         "usage_normalized": {
        //          "set": true,
        //          "infinite": false,
        //          "number": 0.0
        //        },
        //        "usage": 0,
        //        "fairshare": {
        //          "factor": 0.62068965517241381,
        //          "level": Infinity
        //        },
        //        "type": [
        //          "USER"
        //        ]
        //      }
        // but Infinity (without quotes) is no correct JSON and json_decode() does not accept it.
        // So we replace it by a string ...
        $body = str_replace('"level": Infinity', '"level": "Infinity"', $response);

        // Decode the JSON response
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \exceptions\RequestFailedException(
                "Server response could not be interpreted.",
                json_last_error_msg(),
                NULL,
                json_last_error()
            );
        }


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
                // Difference to v0.0.43!
                'fairshare_level'  => $json_shares['fairshare']['level'] ?? '',
                // Difference to v0.0.43!
                'fairshare_factor' => $json_shares['fairshare']['factor'] ?? '',
                'effective_usage'  => $json_shares['effective_usage'] ?? '',
                'shares'           => $this->_get_number_if_defined($json_shares['shares'], ''),
                'usage_normalized' => $this->_get_number_if_defined($json_shares['usage_normalized'], ''),
                // tres was skipped here

            );
            $shares[] = $share;
        }

        return $shares;
    }


}