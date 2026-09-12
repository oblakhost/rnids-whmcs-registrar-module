<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Model;

use InvalidArgumentException;

final class ContactNormalizer
{
    /**
     * @param array<string, array<string, mixed>> $contacts
     * @return array<string, array<string, string>>
     */
    public static function normalizeForWhmcs(array $contacts): array
    {
        $normalized = [];

        foreach ($contacts as $role => $contact) {
            $normalized[$role] = self::normalizeSingleForWhmcs($contact, $role);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    public static function normalizeSingleForWhmcs(array $contact, ?string $role = null): array
    {
        $postalInfo = isset($contact['postalInfo']) && is_array($contact['postalInfo'])
            ? $contact['postalInfo']
            : [];

        $address = isset($postalInfo['address']) && is_array($postalInfo['address'])
            ? $postalInfo['address']
            : [];

        $streets = isset($address['streets']) && is_array($address['streets'])
            ? array_values(array_filter(array_map(static fn($street) => trim((string) $street), $address['streets'])))
            : [];

        [$firstName, $lastName] = self::splitName((string) ($postalInfo['name'] ?? ''));

        $companyName = trim((string) ($postalInfo['organization'] ?? ''));
        $companyNumber = trim((string) ($contact['ident'] ?? ''));
        $taxNumber = trim((string) ($contact['vatNo'] ?? ''));

        if ($role === 'Tech' && $companyName !== '') {
            $firstName = '';
            $lastName = '';
        }

        return [
            'First Name' => $firstName,
            'Last Name' => $lastName,
            'Company Name' => $companyName,
            'Company Number' => $companyNumber,
            'Tax Number' => $taxNumber,
            'Address 1' => $streets[0] ?? '',
            'Address 2' => $streets[1] ?? '',
            'City' => trim((string) ($address['city'] ?? '')),
            'State' => trim((string) ($address['province'] ?? '')),
            'Postcode' => trim((string) ($address['postalCode'] ?? '')),
            'Country' => strtoupper(trim((string) ($address['countryCode'] ?? ''))),
            'Email Address' => trim((string) ($contact['email'] ?? '')),
            'Phone Number' => trim((string) ($contact['voice'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    public static function toRnidsCreatePayload(array $contact, ?string $role = null): array
    {
        $firstName = trim((string) ($contact['First Name'] ?? ''));
        $lastName = trim((string) ($contact['Last Name'] ?? ''));
        $companyName = trim((string) ($contact['Company Name'] ?? ''));
        $companyNumber = trim((string) ($contact['Company Number'] ?? ''));
        $taxNumber = trim((string) ($contact['Tax Number'] ?? ''));

        $fullName = trim($firstName . ' ' . $lastName);
        $isLegalEntityWithRequiredIdentifiers = $companyName !== ''
            && $companyNumber !== ''
            && $taxNumber !== '';

        if ($isLegalEntityWithRequiredIdentifiers) {
            $fullName = '';
        }

        $email = trim((string) ($contact['Email Address'] ?? ''));
        $phoneNumber = trim((string) ($contact['Phone Number'] ?? ''));
        $city = trim((string) ($contact['City'] ?? ''));
        $country = strtoupper(trim((string) ($contact['Country'] ?? '')));

        self::assertRequiredCreateFields(
            $fullName,
            $companyName,
            $companyNumber,
            $taxNumber,
            $isLegalEntityWithRequiredIdentifiers,
            $email,
            $phoneNumber,
            $city,
            $country
        );

        $postalInfo = [
            'type' => 'loc',
            'name' => $fullName,
            'organization' => $companyName !== '' ? $companyName : null,
            'address' => [
                'streets' => self::buildStreets($contact),
                'city' => $city,
                'countryCode' => $country,
                'province' => self::nullable(trim((string) ($contact['State'] ?? ''))),
                'postalCode' => self::nullable(trim((string) ($contact['Postcode'] ?? ''))),
            ],
        ];

        return [
            'postalInfo' => $postalInfo,
            'email' => $email,
            'voice' => $phoneNumber,
            'extension' => [
                'ident' => self::nullable($companyNumber),
                'identKind' => ($companyNumber !== '' || $taxNumber !== '') ? 'personal_ID' : null,
                'isLegalEntity' => $companyName !== '' ? '1' : '0',
                'vatNo' => self::nullable($taxNumber),
            ],
        ];
    }

    /**
     * @param array<string, string> $left
     * @param array<string, string> $right
     */
    public static function equivalent(array $left, array $right): bool
    {
        foreach (self::compareKeys() as $key) {
            if (self::normalizeComparableValue($left[$key] ?? '') !== self::normalizeComparableValue($right[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function compareKeys(): array
    {
        return [
            'First Name',
            'Last Name',
            'Company Name',
            'Company Number',
            'Tax Number',
            'Address 1',
            'Address 2',
            'City',
            'State',
            'Postcode',
            'Country',
            'Email Address',
            'Phone Number',
        ];
    }

    /**
     * @return array{string,string}
     */
    private static function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $firstName = (string) array_shift($parts);

        return [$firstName, trim(implode(' ', $parts))];
    }

    /**
     * @param array<string, mixed> $contact
     * @return list<string>
     */
    private static function buildStreets(array $contact): array
    {
        $streets = [];
        foreach (['Address 1', 'Address 2'] as $key) {
            $value = trim((string) ($contact[$key] ?? ''));
            if ($value !== '') {
                $streets[] = $value;
            }
        }

        if ($streets === []) {
            $streets[] = 'N/A';
        }

        return $streets;
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function normalizeComparableValue(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function assertRequiredCreateFields(
        string $fullName,
        string $companyName,
        string $companyNumber,
        string $taxNumber,
        bool $isLegalEntityWithRequiredIdentifiers,
        string $email,
        string $phoneNumber,
        string $city,
        string $country
    ): void {
        if ($companyName !== '' && !$isLegalEntityWithRequiredIdentifiers) {
            throw new InvalidArgumentException('Company Number and Tax Number are required when Company Name is provided.');
        }

        if (!$isLegalEntityWithRequiredIdentifiers && $fullName === '') {
            throw new InvalidArgumentException('Contact name is required.');
        }

        if ($companyName === '' && ($companyNumber !== '' || $taxNumber !== '')) {
            throw new InvalidArgumentException('Company Name is required when Company Number or Tax Number is provided.');
        }

        if ($email === '') {
            throw new InvalidArgumentException('Contact email address is required.');
        }

        if ($phoneNumber === '') {
            throw new InvalidArgumentException('Contact phone number is required.');
        }

        if ($city === '') {
            throw new InvalidArgumentException('Contact city is required.');
        }

        if ($country === '') {
            throw new InvalidArgumentException('Contact country code is required.');
        }
    }

}
