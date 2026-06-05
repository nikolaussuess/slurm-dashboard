<?php

namespace exceptions;

use Throwable;
require_once __DIR__ . '/BaseException.inc.php';

class RequestFailedException extends BaseException {

    /** @inheritDoc */
    public function __construct(string $message = 'Request failed.',
                                ?string $debug_info = NULL,
                                ?string $html_message = NULL,
                                int $code = 0,
                                ?Throwable $previous = null){
        parent::__construct($message, $debug_info, $html_message, $code, $previous);
    }

}