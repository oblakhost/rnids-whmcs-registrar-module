<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Nameserver;

use RNIDS\Client;
use RNIDS\Exception\ObjectMissing;

final class HostService
{
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
}
