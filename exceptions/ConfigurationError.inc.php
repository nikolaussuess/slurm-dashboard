<?php

namespace exceptions;

use Throwable;
require_once 'BaseError.inc.php';

class ConfigurationError extends BaseError {

    public function __construct($message='Configuration error.',
                                $debug_info=NULL,
                                $html_message=NULL,
                                $code = 0,
                                Throwable $previous = null){
        parent::__construct($message, $debug_info, $html_message, $code, $previous);
    }

}