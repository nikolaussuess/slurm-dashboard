<?php

namespace exceptions;

use Throwable;
require_once __DIR__ . '/BaseException.inc.php';

class RequestFailedException extends BaseException {

    public function __construct($message='Request failed.',
                                $debug_info=NULL,
                                $html_message=NULL,
                                $code = 0,
                                Throwable $previous = null){
        parent::__construct($message, $debug_info, $html_message, $code, $previous);
    }

}