<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Nameserver;

final class KnownHostRepository
{
    public function __construct(private readonly string $moduleRootPath)
    {
    }

    /**
     * @return array<string, array{ipv4:list<string>, ipv6:list<string>}>
     */
    public function loadKnownHosts(): array
    {
        $preferredPath = $this->moduleRootPath . '/knownhosts.php';
        $fallbackPath = $this->moduleRootPath . '/dist.knownhosts.php';

        $path = is_file($preferredPath) ? $preferredPath : $fallbackPath;
        if (!is_file($path)) {
            return [];
        }

        $raw = require $path;
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $host => $ips) {
            if (!is_string($host) || !is_array($ips)) {
                continue;
            }

            $host = strtolower(rtrim(trim($host), '.'));
            if ($host === '') {
                continue;
            }

            $normalized[$host] = [
                'ipv4' => $this->normalizeKnownIpValues($ips['ip4'] ?? null, FILTER_FLAG_IPV4),
                'ipv6' => $this->normalizeKnownIpValues($ips['ip6'] ?? null, FILTER_FLAG_IPV6),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeKnownIpValues(mixed $value, int $flags): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $candidate) {
            $ip = trim((string) $candidate);
            if ($ip === '') {
                continue;
            }

            if (false === filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                continue;
            }

            $result[] = $ip;
        }

        return array_values(array_unique($result));
    }
}
