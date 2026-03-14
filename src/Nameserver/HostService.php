<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Nameserver;

use RNIDS\Client;
use RNIDS\Exception\ObjectAlreadyExists;
use RNIDS\Exception\ObjectMissing;

final class HostService
{
    /**
     * @return array{ipv4:list<string>, ipv6:list<string>}|null
     */
    public function findExistingHostAddresses(Client $client, string $nameserver): ?array
    {
        $hostService = $client->host();
        $checkResults = $hostService->check($nameserver);
        $checkResult = $checkResults[0] ?? null;

        if (!is_array($checkResult) || ($checkResult['available'] ?? null) !== false) {
            return null;
        }

        try {
            $info = $hostService->info($nameserver);
        } catch (ObjectMissing) {
            return null;
        }

        return [
            'ipv4' => array_values(array_unique(array_map('strval', $info['ipv4'] ?? []))),
            'ipv6' => array_values(array_unique(array_map('strval', $info['ipv6'] ?? []))),
        ];
    }

    public function createSingleAddressHost(Client $client, string $nameserver, string $ipAddress): void
    {
        $addresses = $this->singleAddressSet($ipAddress);

        try {
            $client->host()->create([
                'name' => $nameserver,
                'addresses' => $this->toHostAddressRecords($addresses['ipv4'], $addresses['ipv6']),
            ]);

            return;
        } catch (ObjectAlreadyExists $exception) {
            $existing = $client->host()->info($nameserver);

            if ($this->hostHasExactAddressSet($existing, $addresses['ipv4'], $addresses['ipv6'])) {
                return;
            }

            throw $exception;
        }
    }

    public function replaceSingleAddressHost(Client $client, string $nameserver, string $ipAddress): void
    {
        $hostService = $client->host();
        $existing = $hostService->info($nameserver);
        $addresses = $this->singleAddressSet($ipAddress);

        if ($this->hostHasExactAddressSet($existing, $addresses['ipv4'], $addresses['ipv6'])) {
            return;
        }

        $existingIpv4 = array_values(array_unique(array_map('strval', $existing['ipv4'] ?? [])));
        $existingIpv6 = array_values(array_unique(array_map('strval', $existing['ipv6'] ?? [])));

        $removeAddresses = $this->toHostAddressRecords($existingIpv4, $existingIpv6);
        $addAddresses = $this->toHostAddressRecords($addresses['ipv4'], $addresses['ipv6']);

        $updatePayload = ['name' => $nameserver];

        if ($removeAddresses !== []) {
            $updatePayload['remove'] = ['addresses' => $removeAddresses];
        }

        if ($addAddresses !== []) {
            $updatePayload['add'] = ['addresses' => $addAddresses];
        }

        $hostService->update($updatePayload);
    }

    public function deleteHostIfExists(Client $client, string $nameserver): void
    {
        try {
            $client->host()->delete($nameserver);
        } catch (ObjectMissing) {
            // Repeated delete requests should be treated as success.
        }
    }

    /**
     * @param array{ipv4:list<string>, ipv6:list<string>} $knownAddresses
     */
    public function ensureHostExistsAndSynced(Client $client, string $nameserver, array $knownAddresses): void
    {
        $hostService = $client->host();

        try {
            $info = $hostService->info($nameserver);

            if ($knownAddresses['ipv4'] === [] && $knownAddresses['ipv6'] === []) {
                return;
            }

            $existingIpv4 = array_values(array_unique(array_map('strval', $info['ipv4'] ?? [])));
            $existingIpv6 = array_values(array_unique(array_map('strval', $info['ipv6'] ?? [])));

            $addIpv4 = array_values(array_diff($knownAddresses['ipv4'], $existingIpv4));
            $removeIpv4 = array_values(array_diff($existingIpv4, $knownAddresses['ipv4']));
            $addIpv6 = array_values(array_diff($knownAddresses['ipv6'], $existingIpv6));
            $removeIpv6 = array_values(array_diff($existingIpv6, $knownAddresses['ipv6']));

            $addAddresses = $this->toHostAddressRecords($addIpv4, $addIpv6);
            $removeAddresses = $this->toHostAddressRecords($removeIpv4, $removeIpv6);

            if ($addAddresses === [] && $removeAddresses === []) {
                return;
            }

            $updatePayload = ['name' => $nameserver];
            if ($addAddresses !== []) {
                $updatePayload['add'] = ['addresses' => $addAddresses];
            }
            if ($removeAddresses !== []) {
                $updatePayload['remove'] = ['addresses' => $removeAddresses];
            }

            $hostService->update($updatePayload);

            return;
        } catch (ObjectMissing) {
            // Host does not exist and should be created.
        }

        $addresses = $this->toHostAddressRecords($knownAddresses['ipv4'], $knownAddresses['ipv6']);
        $hostService->create([
            'name' => $nameserver,
            'addresses' => $addresses,
        ]);
    }

    /**
     * @param list<string> $ipv4
     * @param list<string> $ipv6
     * @return list<array{address:string, ipVersion:string}>
     */
    private function toHostAddressRecords(array $ipv4, array $ipv6): array
    {
        $records = [];

        foreach ($ipv4 as $address) {
            $records[] = [
                'address' => $address,
                'ipVersion' => 'v4',
            ];
        }

        foreach ($ipv6 as $address) {
            $records[] = [
                'address' => $address,
                'ipVersion' => 'v6',
            ];
        }

        return $records;
    }

    /**
     * @return array{ipv4:list<string>, ipv6:list<string>}
     */
    private function singleAddressSet(string $ipAddress): array
    {
        return false !== filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? ['ipv4' => [], 'ipv6' => [$ipAddress]]
            : ['ipv4' => [$ipAddress], 'ipv6' => []];
    }

    /**
     * @param array<string,mixed> $hostInfo
     * @param list<string> $expectedIpv4
     * @param list<string> $expectedIpv6
     */
    private function hostHasExactAddressSet(array $hostInfo, array $expectedIpv4, array $expectedIpv6): bool
    {
        $existingIpv4 = array_values(array_unique(array_map('strval', $hostInfo['ipv4'] ?? [])));
        $existingIpv6 = array_values(array_unique(array_map('strval', $hostInfo['ipv6'] ?? [])));

        return $existingIpv4 === $expectedIpv4 && $existingIpv6 === $expectedIpv6;
    }
}
