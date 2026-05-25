<?php

namespace SocialDept\AtpSignals\Tap\Exceptions;

use RuntimeException;

class ReadOnlyException extends RuntimeException
{
    public function __construct(string $operation = 'write')
    {
        parent::__construct(
            "Cannot {$operation} on Tap database model. The Tap database is read-only and managed by the Tap Go binary."
        );
    }
}
