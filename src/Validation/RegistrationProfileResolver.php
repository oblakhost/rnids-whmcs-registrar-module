<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Validation;

use InvalidArgumentException;
use Oblak\WHMCS\RSREG\Contact\ContactDataMapper;

final class RegistrationProfileResolver
{
    public function resolveRegistrantType(array $params, string $tld): string
    {
        $additionalFields = $this->extractAdditionalFields($params);
        $registrantType = strtolower(trim((string) ($additionalFields['Registrant Type'] ?? '')));
        $isCompanyOnlyTld = in_array($tld, DomainInputValidator::REGISTRATION_TLDS_COMPANY_ONLY, true);

        if ($registrantType === '') {
            return $isCompanyOnlyTld ? 'company' : 'individual';
        }

        if (!in_array($registrantType, ['individual', 'company'], true)) {
            throw new InvalidArgumentException('Registrant Type must be either Individual or Company.');
        }

        if ($isCompanyOnlyTld && $registrantType !== 'company') {
            throw new InvalidArgumentException(sprintf('TLD .%s requires a Company registrant type.', $tld));
        }

        return $registrantType;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    public function buildRegistrantContactData(array $params, string $registrantType, ContactDataMapper $contactDataMapper): array
    {
        $contact = $contactDataMapper->extractRoleContactFromParams($params, 'Registrant', true);
        if ($contact === []) {
            throw new InvalidArgumentException('Registrant contact details are required for registration.');
        }

        $additionalFields = $this->extractAdditionalFields($params);

        $companyName = $this->firstNonEmptyValue([
            $additionalFields['Company Name'] ?? null,
            $contact['Company Name'] ?? null,
            $params['companyname'] ?? null,
        ]);

        $companyNumber = $this->firstNonEmptyValue([
            $additionalFields['Company Number'] ?? null,
            $this->extractMappedCustomField($params, 'reg_mb'),
            $contact['Company Number'] ?? null,
        ]);

        $taxNumber = $this->firstNonEmptyValue([
            $additionalFields['Tax Number'] ?? null,
            $this->extractMappedCustomField($params, 'reg_pib'),
            $contact['Tax Number'] ?? null,
        ]);

        if ($companyName !== '' && $companyNumber === '' && $taxNumber !== '') {
            $companyNumber = $taxNumber;
        }

        if ($registrantType === 'company') {
            if ($companyName === '' || $companyNumber === '' || $taxNumber === '') {
                throw new InvalidArgumentException(
                    'Company registration requires Company Name, Company Number, and Tax Number.',
                );
            }
        }

        $contact['Company Name'] = $companyName;
        $contact['Company Number'] = $companyNumber;
        $contact['Tax Number'] = $taxNumber;

        $country = $this->firstNonEmptyValue([
            $additionalFields['Country'] ?? null,
            $contact['Country'] ?? null,
            $params['countrycode'] ?? null,
            $params['country'] ?? null,
        ]);

        if ($country !== '') {
            $contact['Country'] = strtoupper($country);
        }

        return $contact;
    }

    public function resolveTechHandleFromConfig(array $params): string
    {
        $adminId = trim((string) ($params['admin_id'] ?? ''));
        if ($adminId === '') {
            throw new InvalidArgumentException(
                'Tech contact is required. Provide a Tech contact or configure Admin ID (admin_id).',
            );
        }

        return $adminId;
    }

    /**
     * @return array<string,string>
     */
    private function extractAdditionalFields(array $params): array
    {
        $fields = $params['additionalfields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $normalized = [];
        foreach ($fields as $key => $value) {
            $normalized[(string) $key] = trim((string) $value);
        }

        return $normalized;
    }

    private function extractMappedCustomField(array $params, string $mappingKey): string
    {
        $fieldId = trim((string) ($params[$mappingKey] ?? ''));
        if ($fieldId === '') {
            return '';
        }

        $customFields = $params['customfields'] ?? [];
        if (!is_array($customFields)) {
            return '';
        }

        if (array_key_exists($fieldId, $customFields)) {
            return trim((string) $customFields[$fieldId]);
        }

        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $id = isset($field['id']) ? trim((string) $field['id']) : '';
            if ($id !== $fieldId) {
                continue;
            }

            return trim((string) ($field['value'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstNonEmptyValue(array $values): string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
