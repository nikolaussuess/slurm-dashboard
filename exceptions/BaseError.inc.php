<?php

namespace exceptions;

use Error;
use Throwable;

abstract class BaseError extends Error {

    protected ?string $debug_info;
    protected ?string $html_message;

    public function __construct($message,
                                $debug_info=NULL,
                                $html_message=NULL,
                                $code = 0,
                                Throwable $previous = null){
        parent::__construct($message, $code, $previous);
        if($html_message == NULL)
            $this->html_message = htmlspecialchars($message);
        else
            $this->html_message = $html_message;
        $this->debug_info = $debug_info;
    }

    public function get_debug_info() : ?string {
        return $this->debug_info;
    }

    public function get_html_message() : string {
        return $this->html_message;
    }
}