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
        return is_array($params) ? "{$params['sld']}.{$params['tld']}" : $params;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function normalizeDomainName(array $params): string
    {
        $sld = strtolower(trim((string) ($params['sld'] ?? '')));
        $tld = $this->normalizeTld($params);

        if ($sld === '' || $tld === '') {
            throw new InvalidArgumentException('Domain name is missing or invalid for registration.');
        }

        return $sld . '.' . $tld;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function normalizeTld(array $params): string
    {
        return strtolower(ltrim(trim((string) ($params['tld'] ?? '')), '.'));
    }

    public function validateRegistrationTld(string $tld): void
    {
        if (!in_array($tld, $this->supportedTlds(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported TLD for RNIDS registration: .%s', $tld));
        }
    }

    public function validateTransferTld(string $tld): void
    {
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
        $period = (int) ($params['regperiod'] ?? 0);
        if ($period < 1 || $period > 10) {
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
}
