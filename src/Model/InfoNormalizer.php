<?php

namespace Oblak\WHMCS\RSREG\Model;

use DateTimeInterface;
use WHMCS\Domain\Registrar\Domain;

class InfoNormalizer {
    /**
     * 
     * @param  array{
     *   name: string|null,
     *   roid: string|null,
     *   statuses: list<string>,
     *   registrant: string|null,
     *   adminContact: string|null,
     *   techContact: string|null,
     *   nameservers: array<string, array{ipv4: list<string>, ipv6: list<string>}>,
     *   clientId: string|null,
     *   createClientId: string|null,
     *   updateClientId: string|null,
     *   createDate: \DateTimeImmutable|null,
     *   updateDate: \DateTimeImmutable|null,
     *   expirationDate: \DateTimeImmutable|null,
     *   whoisPrivacy: bool,
     *   isDomainVerified: bool,
     *   domainVerifiedOn: \DateTimeImmutable|null,
     *   domainVerificationRequestExpiresOn: \DateTimeImmutable|null,
     *   isWhoisPrivacyPaid: bool,
     *   operationMode: string|null,
     *   notifyAdmin: bool,
     *   dnsSec: bool,
     *   remark: string|null
     * } $data Domain information data to normalize.
     * @return array{
     *   domain: string,
     *   statuses: list<string>,
     *   status: string,
     *   expirydate: DateTimeInterface|null,
     *   createdate: DateTimeInterface|null,
     *   updatedate: DateTimeInterface|null,
     *   nameservers: array<string,string>,
     *   isVerified: bool,
     *   verifydate: DateTimeInterface|null,
     *   confirmdate: DateTimeInterface|null,
     *   registrant: string,
     *   admin: string,
     *   tech: string,
     *   whoisguard: bool,
     *   transferlock: bool,
     *   restorable: bool,
     *   pendingsuspension: bool,
     *   contactchangepending: bool,
     *   irtp: array{
     *     enabled: bool,
     *     optoutstatus: bool,
     *     lockstatus: bool,
     *     transferlockexpirydate: DateTimeInterface|null,
     *   },
     * }
     */
    public static function normalize(array $data): array {
        $domain = (string) ($data['name'] ?? '');
        $normalizedStatuses = self::normalizeStatuses($data['statuses'] ?? []);
        $isTransferLocked = self::isTransferLocked($normalizedStatuses);

        return [
            'domain' => $domain,
            'statuses' => $normalizedStatuses,
            'status' => self::parseStatus($normalizedStatuses),
            'expirydate' => self::parseDate($data['expirationDate'] ?? null),
            'createdate' => self::parseDate($data['createDate'] ?? null),
            'updatedate' => self::parseDate($data['updateDate'] ?? null),
            'nameservers' => self::parseNameservers($data['nameservers'] ?? []),
            'isVerified' => (bool) ($data['isDomainVerified'] ?? false),
            'verifydate'  => self::parseDate($data['domainVerifiedOn'] ?? null),
            'confirmdate' => self::parseDate($data['domainVerificationRequestExpiresOn'] ?? null),
            'registrant' => $data['registrant'] ?? null,
            'admin' => $data['adminContact'] ?? null,
            'tech' => $data['techContact'] ?? null,
            'whoisguard' => (bool) ($data['whoisPrivacy'] ?? false),
            'transferlock' => $isTransferLocked,
            'restorable' => self::isRestorable($normalizedStatuses),
            'pendingsuspension' => self::isPendingSuspension($normalizedStatuses),
            'contactchangepending' => in_array('pendingupdate', $normalizedStatuses, true),
            'irtp' => [
                'enabled' => str_ends_with(strtolower($domain), '.rs'),
                'optoutstatus' => false,
                'lockstatus' => $isTransferLocked,
                'transferlockexpirydate' => null,
            ],
        ];
    }

    /**
     * 
     * @param array<string, array{ipv4: list<string>, ipv6: list<string>}> $nameservers
     * @return array<string,string>
     */
    private static function parseNameservers(array $nameservers): array {
        $i = 1;
        $p = [];

        foreach ($nameservers as $host => $ips) {
            $p["ns{$i}"] = $host;
            $i++;
        }

        return $p;
    }

    /**
     *
     * @param null|array{date: string, timezone_type: int, timezone: string}|string|DateTimeInterface|null $date Date to parse.
     * @return null|DateTimeInterface 
     */
    private static function parseDate($date): ?DateTimeInterface {
        $date = match(true) {
            is_string($date) => new \DateTimeImmutable($date),
            is_array($date) => new \DateTimeImmutable($date['date'], new \DateTimeZone($date['timezone'] ?? 'UTC')),
            $date instanceof DateTimeInterface => $date,
            default => null,
        };

        if ($date === null) {
            return null;
        }

        return class_exists('\WHMCS\Carbon') ? \WHMCS\Carbon::instance($date) : $date;
    }

    private static function parseStatus(array $normalizedStatuses): string {
        if (in_array('pendingdelete', $normalizedStatuses, true)) {
            return Domain::STATUS_PENDING_DELETE;
        }

        if (in_array('deleted', $normalizedStatuses, true)) {
            return Domain::STATUS_DELETED;
        }

        if (in_array('archived', $normalizedStatuses, true)) {
            return Domain::STATUS_ARCHIVED;
        }

        if (in_array('expired', $normalizedStatuses, true) || in_array('redemptionperiod', $normalizedStatuses, true)) {
            return Domain::STATUS_EXPIRED;
        }

        if (in_array('serverhold', $normalizedStatuses, true) || in_array('clienthold', $normalizedStatuses, true) || in_array('suspended', $normalizedStatuses, true)) {
            return Domain::STATUS_SUSPENDED;
        }

        if (in_array('inactive', $normalizedStatuses, true)) {
            return Domain::STATUS_INACTIVE;
        }

        return Domain::STATUS_ACTIVE;
    }

    /**
     * @param list<string>|mixed $statuses
     * @return list<string>
     */
    private static function normalizeStatuses($statuses): array
    {
        if (!is_array($statuses)) {
            return [];
        }

        $normalized = [];

        foreach ($statuses as $status) {
            if (!is_string($status)) {
                continue;
            }

            $value = preg_replace('/[^a-z]/', '', strtolower($status));
            if ($value !== null && $value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $statuses
     */
    private static function isTransferLocked(array $statuses): bool
    {
        $lockStatuses = [
            'clienttransferprohibited',
            'servertransferprohibited',
            'clientupdateprohibited',
            'serverupdateprohibited',
        ];

        foreach ($lockStatuses as $status) {
            if (in_array($status, $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $statuses
     */
    private static function isRestorable(array $statuses): bool
    {
        return in_array('redemptionperiod', $statuses, true)
            || in_array('pendingrestore', $statuses, true);
    }

    /**
     * @param list<string> $statuses
     */
    private static function isPendingSuspension(array $statuses): bool
    {
        return in_array('serverhold', $statuses, true)
            || in_array('clienthold', $statuses, true)
            || in_array('pendingdelete', $statuses, true);
    }
}
/*
{
    
  "adminContact": "MRG691c218ee818b",
  "clientId": "Oblak Solutions d.o.o.",
  "createClientId": null,
  "createDate": {
    "date": "2025-11-18 08:34:39.043254",
    "timezone_type": 3,
    "timezone": "UTC"
  },
  "dnsSec": false,
  "domainVerificationRequestExpiresOn": {
    "date": "2025-12-08 08:34:39.000000",
    "timezone_type": 3,
    "timezone": "UTC"
  },
  "domainVerifiedOn": null,
  "expirationDate": {
    "date": "2026-11-18 08:34:39.043254",
    "timezone_type": 3,
    "timezone": "UTC"
  },
  "isDomainVerified": false,
  "isWhoisPrivacyPaid": false,
  "name": "komodarstvo.rs",
  "nameservers": {
    "ns1.oblak.host": {
      "ipv4": [
        "157.90.164.42"
      ],
      "ipv6": [
        "2a01:4f8:c0c:813a::1"
      ]
    },
    "ns2.oblak.host": {
      "ipv4": [
        "78.47.152.85"
      ],
      "ipv6": [
        "2a01:4f8:c2c:cf73::1"
      ]
    },
    "ns3.oblak.host": {
      "ipv4": [
        "88.198.119.4"
      ],
      "ipv6": [
        "2a01:4f8:c0c:813a::2"
      ]
    },
    "ns4.oblak.host": {
      "ipv4": [
        "88.99.120.143"
      ],
      "ipv6": [
        "2a01:4f8:c010:4712::1"
      ]
    }
  },
  "notifyAdmin": false,
  "operationMode": "normal",
  "registrant": "MRG691c218ee818b",
  "remark": "",
  "roid": "505351",
  "statuses": [
    "ok",
    "pendingUpdate"
  ],
  "techContact": "domendomenkontakt",
  "updateClientId": null,
  "updateDate": {
    "date": "2026-03-01 17:18:21.905364",
    "timezone_type": 3,
    "timezone": "UTC"
  },
  "whoisPrivacy": false
}
 */