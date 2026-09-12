<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Nameserver;

use RNIDS\Client;

final class DomainNameserverUpdater
{
    /**
     * @param list<string> $toAdd
     * @param list<string> $toRemove
     * @param array<string, array{ipv4:list<string>, ipv6:list<string>}> $knownHosts
     */
    public function rawDomainNameserverUpdate(
        Client $client,
        string $domainName,
        array $toAdd,
        array $toRemove,
        array $knownHosts,
    ): void {
        $payload = ['name' => $domainName];
        foreach (['add' => $toAdd, 'remove' => $toRemove] as $section => $nameservers) {
            if ($nameservers !== []) {
                $payload[$section] = ['nameservers' => $this->nameserverPayloads($nameservers, $knownHosts)];
            }
        }

        // The SDK owns IDNA encoding, consistent host forms, and IP version tags.
        $client->domain()->update($payload);
    }

    /**
     * @param list<string> $nameservers
     * @param array<string, array{ipv4:list<string>, ipv6:list<string>}> $knownHosts
     * @return list<array{name:string, addresses?:list<array{address:string, ipVersion:string}>}>
     */
    private function nameserverPayloads(array $nameservers, array $knownHosts): array
    {
        $payloads = [];
        foreach ($nameservers as $nameserver) {
            $known = $knownHosts[$nameserver] ?? ['ipv4' => [], 'ipv6' => []];
            $payload = ['name' => $nameserver];
            foreach (['ipv4' => 'v4', 'ipv6' => 'v6'] as $key => $version) {
                foreach ($known[$key] as $address) {
                    $payload['addresses'][] = ['address' => $address, 'ipVersion' => $version];
                }
            }
            $payloads[] = $payload;
        }

        return $payloads;
    }
}
