<?php

namespace App\Http\Responses;

trait HandlesApiExceptions
{
    protected function safeExceptionMessage(\Throwable $e, string $fallback = 'An unexpected error occurred.'): string
    {
        return config('app.debug') ? $e->getMessage() : $fallback;
    }
}
