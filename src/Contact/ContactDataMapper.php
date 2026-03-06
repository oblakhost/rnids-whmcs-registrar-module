<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Contact;

final class ContactDataMapper
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    public function extractRoleContactFromParams(array $params, string $role, bool $allowBaseFallback): array
    {
        $source = [];
        if (isset($params['contactdetails']) && is_array($params['contactdetails'])) {
            $source = $params['contactdetails'];
        }

        if ($source === [] && isset($params[$role]) && is_array($params[$role])) {
            $source = [$role => $params[$role]];
        }

        if (isset($source[$role]) && is_array($source[$role])) {
            $normalizedRole = $this->normalizeWhmcsContactData($source[$role]);
            if (!$this->isContactDataEmpty($normalizedRole)) {
                return $normalizedRole;
            }
        }

        if (!$allowBaseFallback) {
            return [];
        }

        $baseContact = $this->normalizeWhmcsContactData($params);

        return $this->isContactDataEmpty($baseContact) ? [] : $baseContact;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string, array<string, string>>
     */
    public function extractSubmittedContacts(array $params): array
    {
        $source = isset($params['contactdetails']) && is_array($params['contactdetails'])
            ? $params['contactdetails']
            : $params;

        $contacts = [];
        foreach (['Registrant', 'Admin', 'Tech'] as $role) {
            if (!isset($source[$role]) || !is_array($source[$role])) {
                continue;
            }

            $normalized = $this->normalizeWhmcsContactData($source[$role]);

            if ($this->isContactDataEmpty($normalized)) {
                continue;
            }

            $contacts[$role] = $normalized;
        }

        $baseContact = $this->normalizeWhmcsContactData($params);
        if (!$this->isContactDataEmpty($baseContact)) {
            foreach (['Registrant', 'Admin', 'Tech'] as $role) {
                if (!isset($contacts[$role])) {
                    $contacts[$role] = $baseContact;
                }
            }
        }

        return $contacts;
    }

    /**
     * @param array<string,mixed> $contactData
     * @return array<string,string>
     */
    public function normalizeWhmcsContactData(array $contactData): array
    {
        $aliases = [
            'First Name' => ['First Name', 'firstname', 'first_name'],
            'Last Name' => ['Last Name', 'lastname', 'last_name'],
            'Company Name' => ['Company Name', 'companyname', 'company_name'],
            'Company Number' => ['Company Number', 'companynumber', 'company_number', 'ident'],
            'Tax Number' => [
                'Tax Number',
                'tax_number',
                'taxnumber',
                'Tax ID',
                'tax_id',
                'VAT Number',
                'vat number',
                'vatnumber',
                'vat_number',
                'vatNo',
                'vat',
            ],
            'Address 1' => ['Address 1', 'address1', 'address_1', 'address'],
            'Address 2' => ['Address 2', 'address2', 'address_2'],
            'City' => ['City', 'city'],
            'State' => ['State', 'state', 'province'],
            'Postcode' => ['Postcode', 'postcode', 'zip', 'postalcode', 'postal_code'],
            'Country' => ['Country', 'country', 'countrycode', 'country_code'],
            'Email Address' => ['Email Address', 'email', 'emailaddress', 'email_address'],
            'Phone Number' => ['Phone Number', 'phone', 'phonenumber', 'phone_number', 'tel'],
        ];

        $normalized = [];
        foreach ($aliases as $targetKey => $possibleKeys) {
            $value = '';
            foreach ($possibleKeys as $possibleKey) {
                if (!array_key_exists($possibleKey, $contactData)) {
                    continue;
                }

                $value = trim((string) $contactData[$possibleKey]);
                break;
            }

            $normalized[$targetKey] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string,string> $contactData
     */
    public function isContactDataEmpty(array $contactData): bool
    {
        foreach ($contactData as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
