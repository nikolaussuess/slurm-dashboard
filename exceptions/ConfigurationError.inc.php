<?php

namespace exceptions;

use Throwable;
require_once __DIR__ . '/BaseError.inc.php';

class ConfigurationError extends BaseError {

    /** @inheritDoc */
    public function __construct(string $message = 'Configuration error.',
                                ?string $debug_info = NULL,
                                ?string $html_message = NULL,
                                int $code = 0,
                                ?Throwable $previous = null){
        parent::__construct($message, $debug_info, $html_message, $code, $previous);
    }

}