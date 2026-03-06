<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Support;

use Throwable;

final class ErrorMessageFormatter
{
    public static function safeMessage(Throwable $exception, string $fallback): string
    {
        $message = trim((string) $exception->getMessage());
        if ($message === '') {
            return $fallback;
        }

        return $fallback . ': ' . $message;
    }
}
