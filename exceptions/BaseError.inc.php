<?php

namespace exceptions;

use Error;
use Throwable;

abstract class BaseError extends Error {

    protected ?string $debug_info;
    protected ?string $html_message;

    /**
     * @param string $message User-facing error message
     * @param ?string $debug_info Additional debug information for the log
     * @param ?string $html_message HTML-safe message; defaults to htmlspecialchars($message) if NULL
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message,
                                ?string $debug_info = NULL,
                                ?string $html_message = NULL,
                                int $code = 0,
                                ?Throwable $previous = null){
        parent::__construct($message, $code, $previous);
        if($html_message == NULL)
            $this->html_message = htmlspecialchars($message);
        else
            $this->html_message = $html_message;
        $this->debug_info = $debug_info;
    }

    /**
     * @return ?string Additional debug information, or NULL if none was provided
     */
    public function get_debug_info() : ?string {
        return $this->debug_info;
    }

    /**
     * @return string HTML-safe error message for display in the UI
     */
    public function get_html_message() : string {
        return $this->html_message;
    }
}