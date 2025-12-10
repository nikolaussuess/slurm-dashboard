<?php

namespace exceptions;

use Throwable;
require_once __DIR__ . '/BaseError.inc.php';

class AuthenticationError extends BaseError {

    public function __construct($message='Authentication error.',
                                $debug_info=NULL,
                                $html_message=NULL,
                                $code = 0,
                                Throwable $previous = null){
        parent::__construct($message, $debug_info, $html_message, $code, $previous);
    }

}