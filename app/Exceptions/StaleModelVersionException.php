<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StaleModelVersionException extends ConflictHttpException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(
            'This record changed after you loaded it. Your action was not applied; review the refreshed data and try again.'
        );
    }
}
