<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Domain;

use Oblak\WHMCS\RSREG\Validation\DomainInputValidator;
use RNIDS\Client;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

final class AvailabilityLookupService
{
    /**
     * @param array<string,mixed> $params
     */
    public function checkFromWhmcs(Client|\Closure $client, DomainInputValidator $domainInputValidator, array $params): ResultsList
    {
        $input = $this->normalizeAvailabilityInput($params);
        if ($input === null) {
            return new ResultsList();
        }

        [$supportedLookups, $unsupportedLookups] = $this->partitionLookupsBySupport($domainInputValidator, $input['sld'], $input['tlds']);
        $resultsByDomain = [];

        if ($supportedLookups !== []) {
            $client = $client instanceof \Closure ? $client() : $client;
            foreach ($this->mapAvailabilityCheckResults($client->domain()->check(array_column($supportedLookups, 'name'))) as $result) {
                $resultsByDomain[$this->searchResultKey($result)] = $result;
            }
        }

        foreach ($unsupportedLookups as $lookup) {
            $result = new SearchResult($lookup['sld'], $lookup['tld']);
            $result->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
            $resultsByDomain[$lookup['name']] = $result;
        }

        return $this->buildOrderedResultsList($resultsByDomain, array_merge($supportedLookups, $unsupportedLookups));
    }

    /**
     * @param array<string,mixed> $params
     * @return array{sld: string, tlds: list<string>}|null
     */
    public function normalizeAvailabilityInput(array $params): ?array
    {
        $sld = strtolower(trim((string) ($params['sld'] ?? '')));
        if ($sld === '') {
            $sld = trim((string) ($params['searchTerm'] ?? ''));
        }
        if ($sld === '') {
            $sld = trim((string) ($params['punyCodeSearchTerm'] ?? ''));
        }

        $sld = mb_strtolower(trim($sld, ". \t\n\r\0\x0B"));
        if ($sld === '') {
            return null;
        }

        $tlds = isset($params['tlds']) && is_array($params['tlds'])
            ? $params['tlds']
            : (isset($params['tldsToInclude']) && is_array($params['tldsToInclude']) ? $params['tldsToInclude'] : []);

        $normalizedTlds = [];
        $validator = new DomainInputValidator();
        foreach ($tlds as $rawTld) {
            $tld = strtolower(ltrim(trim((string) $rawTld), '.'));
            if ($tld === '') {
                continue;
            }

            $normalizedTlds[] = $validator->normalizeTld(['tld' => $tld]);
        }

        if ($normalizedTlds === []) {
            return null;
        }

        return [
            'sld' => $sld,
            'tlds' => $normalizedTlds,
        ];
    }

    /**
     * @param list<string> $tlds
     * @return array{0: list<array{name: string, sld: string, tld: string}>, 1: list<array{name: string, sld: string, tld: string}>}
     */
    public function partitionLookupsBySupport(DomainInputValidator $domainInputValidator, string $sld, array $tlds): array
    {
        $supportedTlds = $domainInputValidator->supportedTlds();
        $supportedLookups = [];
        $unsupportedLookups = [];

        foreach ($tlds as $tld) {
            $lookup = [
                'name' => $sld . '.' . $tld,
                'sld' => $sld,
                'tld' => $tld,
            ];

            if (in_array($tld, $supportedTlds, true)) {
                $lookup['name'] = $domainInputValidator->normalizeDomainName(['sld' => $sld, 'tld' => $tld]);
                [$lookup['sld'], $lookup['tld']] = explode('.', $lookup['name'], 2);
                $supportedLookups[] = $lookup;
                continue;
            }

            $unsupportedLookups[] = $lookup;
        }

        return [$supportedLookups, $unsupportedLookups];
    }

    /**
     * @param list<array{name?: mixed, available?: mixed, reason?: mixed}> $checks
     */
    public function mapAvailabilityCheckResults(array $checks): ResultsList
    {
        $results = new ResultsList();
        $validator = new DomainInputValidator();

        foreach ($checks as $check) {
            $name = strtolower(trim((string) ($check['name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $labels = explode('.', $name, 2);
            if (count($labels) !== 2 || $labels[0] === '' || $labels[1] === '') {
                continue;
            }

            $canonicalName = $validator->normalizeDomainName(['sld' => $labels[0], 'tld' => $labels[1]]);
            $labels = explode('.', $canonicalName, 2);
            $searchResult = new SearchResult($labels[0], $labels[1]);
            $searchResult->setStatus(
                !empty($check['available'])
                    ? SearchResult::STATUS_NOT_REGISTERED
                    : SearchResult::STATUS_REGISTERED
            );

            $results->append($searchResult);
        }

        return $results;
    }

    /**
     * @param array<string,SearchResult> $resultsByDomain
     * @param list<array{name: string, sld: string, tld: string}> $orderedLookups
     */
    private function buildOrderedResultsList(array $resultsByDomain, array $orderedLookups): ResultsList
    {
        $results = new ResultsList();

        foreach ($orderedLookups as $lookup) {
            if (!isset($resultsByDomain[$lookup['name']])) {
                continue;
            }

            $results->append($resultsByDomain[$lookup['name']]);
        }

        return $results;
    }

    private function searchResultKey(SearchResult $result): string
    {
        return mb_strtolower($result->getSecondLevel() . '.' . ltrim($result->getTopLevel(), '.'));
    }
}
