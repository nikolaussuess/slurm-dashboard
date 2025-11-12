<?php

namespace exceptions;

use Throwable;
require_once 'BaseException.inc.php';

class RequestFailedException extends BaseException {

    public function __construct($message = "Request failed.", $code = 0, Throwable $previous = null, $debug_info=NULL){
        parent::__construct($message, $code, $previous, $debug_info);
    }

}