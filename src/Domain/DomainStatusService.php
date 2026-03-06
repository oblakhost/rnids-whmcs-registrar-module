<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Domain;

use DateTimeImmutable;
use DateTimeInterface;

final class DomainStatusService
{
    /**
     * @param mixed $statuses
     * @return list<string>
     */
    public function normalizeDomainStatuses($statuses): array
    {
        if (!is_array($statuses)) {
            return [];
        }

        $normalized = [];
        foreach ($statuses as $status) {
            if (!is_string($status)) {
                continue;
            }

            $value = strtolower(trim($status));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $statuses
     */
    public function isTransferLocked(array $statuses): bool
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
    public function isRestorable(array $statuses): bool
    {
        return in_array('redemptionperiod', $statuses, true)
            || in_array('pendingrestore', $statuses, true);
    }

    /**
     * @param list<string> $statuses
     */
    public function isPendingSuspension(array $statuses): bool
    {
        return in_array('serverhold', $statuses, true)
            || in_array('clienthold', $statuses, true)
            || in_array('pendingdelete', $statuses, true);
    }

    /**
     * @param list<string> $statuses
     */
    public function registrationStatus(array $statuses, mixed $expiryDate): string
    {
        if (in_array('pendingtransfer', $statuses, true)) {
            return 'Pending Transfer';
        }

        if (in_array('pendingdelete', $statuses, true)) {
            return 'Pending Delete';
        }

        if (in_array('inactive', $statuses, true)) {
            return 'Inactive';
        }

        if ($expiryDate instanceof DateTimeInterface && $expiryDate < new DateTimeImmutable('today')) {
            return 'Expired';
        }

        return 'Active';
    }
}
