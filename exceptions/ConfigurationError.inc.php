<?php

namespace exceptions;

use Throwable;
require_once 'BaseError.inc.php';

class ConfigurationError extends BaseError {

    public function __construct($message = "Configuration error.", $code = 0, Throwable $previous = null, $debug_info=NULL){
        parent::__construct($message, $code, $previous, $debug_info);
    }

}