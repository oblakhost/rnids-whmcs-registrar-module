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

        $requestData = self::sanitizeRequestData(self::normalizeLogData($request));
        $responseData = self::sanitizeResponseData(self::normalizeLogData($response));

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
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ];

        if (method_exists($exception, 'responseMetadata')) {
            try {
                $metadata = $exception->responseMetadata();
                $context['responseMetadata'] = [
                    'resultCode' => $metadata->resultCode ?? null,
                    'message' => $metadata->message ?? null,
                    'clientTransactionId' => $metadata->clientTransactionId ?? null,
                    'serverTransactionId' => $metadata->serverTransactionId ?? null,
                ];
            } catch (\Throwable $ignored) {
                // Ignore metadata extraction failures and keep base exception context.
            }
        }

        return $context;
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
            return [
                'exception' => get_class($value),
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
            ];
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

    private static function sanitizeRequestData($requestData)
    {
        if (!is_array($requestData)) {
            return $requestData;
        }

        $sanitized = [];
        foreach ($requestData as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['epp_username', 'epp_password'], true)) {
                continue;
            }

            $sanitized[$key] = self::sanitizeRequestData($value);
        }

        return $sanitized;
    }

    private static function sanitizeResponseData($responseData)
    {
        if (!is_array($responseData)) {
            return $responseData;
        }

        $sanitized = [];
        foreach ($responseData as $key => $value) {
            if (is_string($key) && self::isSensitiveResponseKey($key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = self::sanitizeResponseData($value);
        }

        return $sanitized;
    }

    private static function isSensitiveResponseKey(string $key): bool
    {
        return in_array(strtolower($key), [
            'password',
            'passphrase',
            'epp_password',
            'clientcertificatepassword',
            'authcode',
            'auth_code',
            'token',
            'secret',
        ], true);
    }
}
