<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Validation;

use InvalidArgumentException;

final class DomainInputValidator
{
    public const REGISTRATION_TLDS_INDIVIDUAL_OR_COMPANY = [
        'rs',
        'in.rs',
        'срб',
        'од.срб',
    ];

    public const REGISTRATION_TLDS_COMPANY_ONLY = [
        'co.rs',
        'org.rs',
        'edu.rs',
        'пр.срб',
        'орг.срб',
        'обр.срб',
    ];

    private const TRANSFER_AUTH_KEYS = ['eppcode', 'authcode', 'authCode', 'authinfo', 'authInfo'];

    /**
     * @param array<string,mixed>|string $params
     */
    public function paramsToDomain(array|string $params): string
    {
        if (is_string($params)) {
            $labels = explode('.', $params, 2);
            $params = ['sld' => $labels[0], 'tld' => $labels[1] ?? ''];
        }

        $domain = $this->normalizeDomainName($params);
        $tld = $this->normalizeTld($params);
        if (!in_array($tld, $this->supportedTlds(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported TLD for RNIDS: .%s', $tld));
        }

        return $domain;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function normalizeDomainName(array $params): string
    {
        $sld = $this->normalizeLabel($params['sld'] ?? null);
        $tld = $this->normalizeTld($params);
        $domain = $sld . '.' . $tld;
        $asciiDomain = function_exists('idn_to_ascii')
            ? idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
            : $domain;

        if ($asciiDomain === false || strlen($asciiDomain) > 253) {
            throw new InvalidArgumentException('Domain name is missing or invalid for registration.');
        }

        return $domain;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function normalizeTld(array $params): string
    {
        $tld = $params['tld'] ?? null;
        if (!is_string($tld) || str_contains($tld, "\0")) {
            throw new InvalidArgumentException('Domain TLD is missing or invalid.');
        }

        $tld = trim($tld);
        if (preg_match('/\s/u', $tld) !== 0) {
            throw new InvalidArgumentException('Domain TLD is missing or invalid.');
        }
        if (str_starts_with($tld, '.')) {
            $tld = substr($tld, 1);
        }

        return implode('.', array_map($this->normalizeLabel(...), explode('.', $tld)));
    }

    public function validateRegistrationTld(string $tld): void
    {
        $tld = $this->normalizeTld(['tld' => $tld]);
        if (!in_array($tld, $this->supportedTlds(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported TLD for RNIDS registration: .%s', $tld));
        }
    }

    public function validateTransferTld(string $tld): void
    {
        $tld = $this->normalizeTld(['tld' => $tld]);
        if (!in_array($tld, $this->supportedTlds(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported TLD for RNIDS transfer: .%s', $tld));
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    public function extractTransferAuthCode(array $params): string
    {
        foreach (self::TRANSFER_AUTH_KEYS as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        throw new InvalidArgumentException('Transfer requires a valid EPP/auth code.');
    }

    /**
     * @param array<string,mixed> $params
     */
    public function resolveRegistrationPeriod(array $params): int
    {
        $period = $params['regperiod'] ?? null;
        if (is_string($period) && preg_match('/^(?:[1-9]|10)$/D', $period) === 1) {
            return (int) $period;
        }

        if (!is_int($period) || $period < 1 || $period > 10) {
            throw new InvalidArgumentException('Registration period must be between 1 and 10 years.');
        }

        return $period;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<string>
     */
    public function extractRequestedNameservers(array $params): array
    {
        $nameservers = [];

        for ($i = 1; $i <= 5; $i++) {
            $key = "ns{$i}";
            $value = isset($params[$key]) ? strtolower(trim((string) $params[$key])) : '';
            $value = rtrim($value, '.');

            if ($value === '') {
                continue;
            }

            if (!filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new InvalidArgumentException(sprintf('Invalid nameserver hostname provided: %s', $value));
            }

            $nameservers[] = $value;
        }

        return array_values(array_unique($nameservers));
    }

    /**
     * @return list<string>
     */
    public function supportedTlds(): array
    {
        return array_merge(self::REGISTRATION_TLDS_INDIVIDUAL_OR_COMPANY, self::REGISTRATION_TLDS_COMPANY_ONLY);
    }

    /**
     * Keep Unicode at the module boundary; enforce DNS limits on the IDNA form.
     */
    private function normalizeLabel(mixed $value): string
    {
        $error = 'Domain name is missing or invalid for registration.';
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new InvalidArgumentException($error);
        }

        $label = mb_strtolower(trim($value), 'UTF-8');
        if ($label === '') {
            throw new InvalidArgumentException($error);
        }

        $ascii = $label;
        if (preg_match('/[^\x00-\x7f]/', $label) === 1 || str_starts_with($label, 'xn--')) {
            if (!function_exists('idn_to_ascii') || !function_exists('idn_to_utf8')) {
                throw new InvalidArgumentException('IDN domain names require the PHP intl extension.');
            }

            $validationFlags = IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;
            $ascii = idn_to_ascii($label, $validationFlags | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46, $details);
            if ($ascii === false || ($details['errors'] ?? 0) !== 0) {
                throw new InvalidArgumentException($error);
            }

            $label = idn_to_utf8($ascii, $validationFlags | IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $details);
            if ($label === false || ($details['errors'] ?? 0) !== 0) {
                throw new InvalidArgumentException($error);
            }
        }

        if (strlen($ascii) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $ascii) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $label;
    }
}
