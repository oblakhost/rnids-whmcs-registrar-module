<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Support;

final class ModuleLogger
{
    public static function logModuleCall(string $action, $request = null, $response = null): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $requestData = self::normalizeLogData($request);
        $responseData = self::normalizeLogData($response);
        $secrets = [];
        self::collectSecrets([$requestData, $responseData], $secrets);
        $requestData = self::sanitizeLogData($requestData, $secrets);
        $responseData = self::sanitizeLogData($responseData, $secrets);

        if ($requestData === [] || $requestData === '' || $requestData === null) {
            $requestData = ['_empty' => true];
        }

        if ($responseData === [] || $responseData === '' || $responseData === null) {
            $responseData = ['_empty' => true];
        }

        logModuleCall(
            'rnids',
            $action,
            $requestData,
            $responseData,
            $responseData,
        );
    }

    public static function exceptionContext(\Throwable $exception): array
    {
        $context = [
            'exception' => get_class($exception),
            'message' => ErrorMessageFormatter::safeMessage($exception, 'Registry operation failed'),
            'code' => $exception->getCode(),
        ];

        if (method_exists($exception, 'responseMetadata')) {
            try {
                $metadata = $exception->responseMetadata();
                $context['responseMetadata'] = [
                    'resultCode' => $metadata->resultCode ?? null,
                    'clientTransactionId' => $metadata->clientTransactionId ?? null,
                    'serverTransactionId' => $metadata->serverTransactionId ?? null,
                ];
            } catch (\Throwable $ignored) {
                // Ignore metadata extraction failures and keep base exception context.
            }
        }

        return self::sanitizeLogData($context, []);
    }

    private static function normalizeLogData($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Throwable) {
            return self::exceptionContext($value);
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return self::normalizeLogData($value->toArray());
            }

            if ($value instanceof \JsonSerializable) {
                return self::normalizeLogData($value->jsonSerialize());
            }

            return self::normalizeLogData(get_object_vars($value));
        }

        if (!is_array($value)) {
            return (string) $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalizeLogData($item);
        }

        return $normalized;
    }

    private static function collectSecrets($value, array &$secrets, bool $sensitive = false): void
    {
        if (!is_array($value)) {
            if ($sensitive && is_scalar($value) && (string) $value !== '') {
                $secrets[(string) $value] = '[REDACTED]';
            }
            return;
        }

        foreach ($value as $key => $item) {
            self::collectSecrets($item, $secrets, $sensitive || (is_string($key) && self::isSensitiveKey($key)));
        }
    }

    private static function sanitizeLogData($value, array $secrets)
    {
        if (is_string($value)) {
            // Do not persist wire payloads, key material, or credential assignments.
            if (preg_match('/<\/?[a-z_][a-z0-9_:.-]*(?:\s|>|\/)|<\?xml\b|-----BEGIN [A-Z0-9 ]+-----/i', $value)
                || preg_match('/(?:password|passphrase|auth(?:info|code)|eppcode|private[\s_-]*key|token|secret)\s*[:=]/i', $value)) {
                return '[REDACTED]';
            }
            return $secrets === [] ? $value : strtr($value, $secrets);
        }

        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = self::sanitizeLogData($item, $secrets);
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        return in_array($key, [
            'username',
            'eppusername',
            'pw',
            'password',
            'newpassword',
            'passphrase',
            'epppassword',
            'eppcertificatepassword',
            'clientcertificatepassword',
            'eppcode',
            'authcode',
            'authinfo',
            'token',
            'secret',
            'certificate',
            'clientcertificate',
            'clientcertificatepath',
            'eppcertificate',
            'privatekey',
            'privatekeypath',
            'eppca',
            'cafilepath',
        ], true);
    }
}
