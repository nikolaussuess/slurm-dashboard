<?php

namespace exceptions;

use Exception;
use Throwable;

abstract class BaseException extends Exception {

    protected ?string $debug_info;

    public function __construct($message, $code=0, Throwable $previous = null, $debug_info=NULL){
        parent::__construct($message, $code, $previous);
        $this->debug_info = $debug_info;
    }

    public function get_debug_info() : ?string {
        return $this->debug_info;
    }
}