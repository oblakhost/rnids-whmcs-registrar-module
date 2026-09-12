<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Support;

use InvalidArgumentException;
use RNIDS\Exception\ProtocolException;
use Throwable;

final class ErrorMessageFormatter
{
    public static function safeMessage(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof ProtocolException) {
            $detail = match ($exception->resultCode()) {
                2003, 2004, 2005 => 'Check the required domain and contact details.',
                2104 => 'The registry could not process billing. Contact support.',
                2105 => 'This domain is not eligible for renewal.',
                2106 => 'This domain is not eligible for transfer.',
                2200, 2501 => 'Registry authentication failed. Contact support to check the registrar configuration.',
                2201 => 'The registry account is not authorized for this operation.',
                2202 => 'The authorization code is invalid. Check the code and try again.',
                2300 => 'A transfer is already pending for this domain.',
                2301 => 'No transfer is pending for this domain.',
                2302 => 'The requested registry object already exists.',
                2303 => 'The requested registry object was not found.',
                2304 => 'The current registry status prevents this operation.',
                2305 => 'The registry object is still in use. Remove its associations before retrying.',
                2306, 2308 => 'The submitted details do not meet registry requirements. Check the domain and contact details.',
                2400, 2500, 2502 => 'The registry could not complete the operation. Please try again later.',
                default => '',
            };
            return $detail === '' ? $fallback : $fallback . ': ' . $detail;
        }

        $message = trim($exception->getMessage());
        if ($exception instanceof \RuntimeException
            && $message === 'EPP kod je poslat na email adresu administrativnog kontakta domena. Ukoliko nije stigao, kontaktirajte podršku.') {
            return $fallback . ': ' . $message;
        }

        if (!$exception instanceof InvalidArgumentException) {
            return $fallback;
        }

        // Only module-owned wording is safe to expose. SDK argument exceptions can
        // contain credentials, paths, or XML too; exception type alone is insufficient.
        $safeMessages = [
            'At least two nameservers are required.',
            'At least two nameservers are required for registration.',
            'Domain name is missing or invalid for registration.',
            'Domain TLD is missing or invalid.',
            'IDN domain names require the PHP intl extension.',
            'Registration period must be between 1 and 10 years.',
            'Transfer requires a valid EPP/auth code.',
            'Registrant Type must be either Individual or Company.',
            'Registrant contact details are required for registration.',
            'Company registration requires Company Name, Company Number, and Tax Number.',
            'Tech contact is required. Provide a Tech contact or configure Admin ID (admin_id).',
            'No contact details were submitted for update.',
            'Lock value is invalid. Expected locked/unlocked.',
            'The domain is locked by the registry. Contact support to unlock it.',
            'Nameserver hostname is required.',
            'Nameserver IP address is required.',
            'The nameserver already exists with different glue addresses. Use Modify Nameserver to change its IP address.',
            'Company Number and Tax Number are required when Company Name is provided.',
            'Contact name is required.',
            'Company Name is required when Company Number or Tax Number is provided.',
            'Contact email address is required.',
            'Contact phone number is required.',
            'Contact city is required.',
            'Contact country code is required.',
        ];
        if (in_array($message, $safeMessages, true)) {
            return $fallback . ': ' . $message;
        }

        // Never echo the invalid submitted value embedded in these validation errors.
        foreach ([
            'Invalid nameserver hostname provided:' => 'The nameserver hostname is invalid.',
            'Invalid nameserver IP address provided:' => 'The nameserver IP address is invalid.',
            'Unsupported TLD for RNIDS registration:' => 'This domain extension is not supported for RNIDS registration.',
            'Unsupported TLD for RNIDS transfer:' => 'This domain extension is not supported for RNIDS transfer.',
            'Unsupported TLD for RNIDS:' => 'This domain extension is not supported by RNIDS.',
        ] as $prefix => $detail) {
            if (str_starts_with($message, $prefix)) {
                return $fallback . ': ' . $detail;
            }
        }
        if (preg_match('/^TLD \\.[a-z.\x{0400}-\x{04ff}]+ requires a Company registrant type\\.$/u', $message)) {
            return $fallback . ': This domain extension requires a Company registrant type.';
        }
        if (str_starts_with($message, 'Nameserver host ')
            && str_ends_with($message, ' does not exist in RNIDS and has no known glue addresses. Register the child nameserver first.')) {
            return $fallback . ': Register the child nameserver and its glue IP address first.';
        }

        return $fallback;
    }
}
