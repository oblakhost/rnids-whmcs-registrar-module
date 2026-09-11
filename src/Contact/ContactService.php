<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Contact;

use InvalidArgumentException;
use Oblak\WHMCS\RSREG\Model\ContactNormalizer;
use Oblak\WHMCS\RSREG\Support\ModuleLogger;
use RNIDS\Client;

final class ContactService
{
    /**
     * @param array<string,mixed> $domainInfo
     * @return array{Registrant:?string,Admin:?string,Tech:?string}
     */
    public function getDomainContactIdsFromInfo(array $domainInfo): array
    {
        $admin = isset($domainInfo['admin']) ? trim((string) $domainInfo['admin']) : '';

        return [
            'Registrant' => isset($domainInfo['registrant']) ? trim((string) $domainInfo['registrant']) : null,
            'Admin' => $admin !== '' ? $admin : null,
            'Tech' => isset($domainInfo['tech']) ? trim((string) $domainInfo['tech']) : null,
        ];
    }

    /**
     * @param array{Registrant:?string,Admin:?string,Tech:?string} $contactIds
     * @return array<string,array<string,mixed>>
     */
    public function fetchContactsByRole(Client $client, array $contactIds): array
    {
        $contacts = [];
        foreach (['Registrant', 'Admin', 'Tech'] as $role) {
            $contactId = trim((string) ($contactIds[$role] ?? ''));
            if ($contactId === '') {
                continue;
            }

            $contacts[$role] = $client->contact()->info($contactId);
        }

        return $contacts;
    }

    /**
     * @param array<string,string> $contactData
     */
    public function createContactFromWhmcsDetails(Client $client, string $role, array $contactData): string
    {
        $payload = ContactNormalizer::toRnidsCreatePayload($contactData, $role);
        $result = $client->contact()->create($payload);

        $id = trim((string) ($result['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException(sprintf('Registry did not return contact ID for role %s.', $role));
        }

        // Preserve the created handle for diagnosing pending or failed domain reassignment.
        ModuleLogger::logModuleCall('ContactCreated', ['operation' => 'contact.create', 'role' => $role],
            json_encode(['contactId' => $id, 'role' => $role], JSON_THROW_ON_ERROR));

        return $id;
    }

    /**
     * @param array{Registrant:?string,Admin:?string,Tech:?string} $existingContactIds
     * @param array<string,string> $newContactIds
     * @return array<string,mixed>
     */
    public function buildDomainContactUpdatePayload(string $domainName, array $existingContactIds, array $newContactIds): array
    {
        $payload = ['name' => $domainName];

        if (isset($newContactIds['Registrant'])) {
            $payload['registrant'] = $newContactIds['Registrant'];
        }

        $addContacts = [];
        $removeContacts = [];

        foreach (['Admin' => 'admin', 'Tech' => 'tech'] as $role => $type) {
            if (!isset($newContactIds[$role])) {
                continue;
            }

            $newHandle = trim((string) $newContactIds[$role]);
            if ($newHandle !== '') {
                $addContacts[] = ['type' => $type, 'handle' => $newHandle];
            }

            $oldHandle = trim((string) ($existingContactIds[$role] ?? ''));
            if ($oldHandle !== '') {
                $removeContacts[] = ['type' => $type, 'handle' => $oldHandle];
            }
        }

        if ($addContacts !== []) {
            $payload['add'] = ['contacts' => $addContacts];
        }

        if ($removeContacts !== []) {
            $payload['remove'] = ['contacts' => $removeContacts];
        }

        return $payload;
    }
}
