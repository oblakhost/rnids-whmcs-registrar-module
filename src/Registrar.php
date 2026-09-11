<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Oblak\WHMCS\RSREG\Contact\ContactDataMapper;
use Oblak\WHMCS\RSREG\Contact\ContactService;
use Oblak\WHMCS\RSREG\Domain\AvailabilityLookupService;
use Oblak\WHMCS\RSREG\Domain\DomainStatusService;
use Oblak\WHMCS\RSREG\Model\ContactNormalizer;
use Oblak\WHMCS\RSREG\Model\InfoNormalizer;
use Oblak\WHMCS\RSREG\Nameserver\DomainNameserverUpdater;
use Oblak\WHMCS\RSREG\Nameserver\HostService;
use Oblak\WHMCS\RSREG\Nameserver\KnownHostRepository;
use Oblak\WHMCS\RSREG\Support\ClientFactory;
use Oblak\WHMCS\RSREG\Support\ErrorMessageFormatter;
use Oblak\WHMCS\RSREG\Validation\DomainInputValidator;
use Oblak\WHMCS\RSREG\Validation\RegistrationProfileResolver;
use RNIDS\Client;
use RNIDS\Exception\ObjectMissing;
use RNIDS\Exception\ProtocolException;
use Throwable;
use WHMCS\Carbon;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Domains\DomainLookup\ResultsList;

class Registrar
{
    private const SERVICE_MAP = [
        'domainInputValidator' => DomainInputValidator::class,
        'registrationProfileResolver' => RegistrationProfileResolver::class,
        'contactDataMapper' => ContactDataMapper::class,
        'availabilityLookupService' => AvailabilityLookupService::class,
        'domainStatusService' => DomainStatusService::class,
        'contactService' => ContactService::class,
        'hostService' => HostService::class,
        'domainNameserverUpdater' => DomainNameserverUpdater::class,
        'knownHostRepository' => KnownHostRepository::class,
    ];

    private ?Client $client = null;


    /**
     * @param array<string,mixed> $params
     */
    private array $params;

    /**
     * @var array<string,object>
     */
    private array $services = [];

    public function __construct(
        array $params,
        ?KnownHostRepository $knownHostRepository = null,
    ) {
        $this->params = $params;

        if (null !== $knownHostRepository) {
            $this->services['knownHostRepository'] = $knownHostRepository;
        }
    }


    /**
     * @param array<string,mixed> $params
     */
    public static function fromParams(array $params): self
    {
        return new self(ClientFactory::buildClientParams($params));
    }

    public function __get(string $name): object
    {
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }

        if (!isset(self::SERVICE_MAP[$name])) {
            throw new InvalidArgumentException(sprintf('Unknown service "%s".', $name));
        }

        $cname = self::SERVICE_MAP[$name] ?? null;

        return $this->services[$name] ??= new $cname();
    }

    public function client(): Client
    {
        if ($this->client === null) {
            $this->client = Client::ready($this->params);
        }

        return $this->client;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function syncFromWhmcs(array $params): array
    {
        $info = $this->safeInfoForSync($params);

        if (null === $info) {
            return [
                'active' => false,
                'expired' => false,
                'transferredAway' => true,
            ];
        }

        $statuses = $this->normalizeDomainStatuses($info['statuses'] ?? []);
        $expiryDate = $info['expirydate'] ?? null;

        $expired = in_array('expired', $statuses, true)
            || in_array('redemptionperiod', $statuses, true)
            || ($expiryDate instanceof DateTimeInterface && $expiryDate < new DateTimeImmutable('today'));

        $result = [
            'active' => !$expired,
            'expired' => $expired,
            'transferredAway' => false,
        ];

        if ($expiryDate instanceof DateTimeInterface) {
            $result['expirydate'] = $expiryDate->format('Y-m-d');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function transferSyncFromWhmcs(array $params): array
    {
        $info = $this->getInfo($params);
        $statuses = $this->normalizeDomainStatuses($info['statuses'] ?? []);
        if (in_array('pendingtransfer', $statuses, true)) {
            return ['completed' => false];
        }

        $expiryDate = $info['expirydate'] ?? null;

        $result = [
            'completed' => true,
        ];

        if ($expiryDate instanceof DateTimeInterface) {
            $result['expirydate'] = $expiryDate->format('Y-m-d');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function checkAvailabilityFromWhmcs(array $params): ResultsList
    {
        return $this->availabilityLookupService->checkFromWhmcs(
            fn(): Client => $this->client(),
            $this->domainInputValidator,
            $params
        );
    }

    /**
     * @param array<string,mixed>|string $params
     * @return array<string,mixed>
     */
    public function getInfo(array|string $params)
    {
        $domain = $this->domainInputValidator->paramsToDomain($params);
        $result = $this->client()->domain()->info($domain);

        return InfoNormalizer::normalize($result);
    }

    /**
     * @param array<string,mixed>|string $params
     */
    public function getDomain(array|string $params): Domain
    {
        $info = $this->getInfo($params);

        $domain = (new Domain())
            ->setIsIrtpEnabled(true)
            ->setIrtpOptOutStatus(false)
            ->setDomain($info['domain'])
            ->setNameservers($info['nameservers'])
            ->setExpiryDate($info['expirydate'])
            ->setRegistrationStatus($info['status'])
            ->setTransferLock($info['transferlock'])
            ->setRestorable($info['restorable'])
            ->setIdProtectionStatus($info['whoisguard'])
            ->setDnsManagementStatus(true)
            ->setDomainContactChangePending($info['contactchangepending'] ?? false)
            ->setIrtpTransferLock($info['irtp']['lockstatus'] ?? false)
            ->setIrtpVerificationTriggerFields([
                'Registrant' => [
                    'First Name',
                    'Last Name',
                    'Company Name',
                    'Email',
                ],
            ]);

        if (!$info['isVerified']) {
            $domain->setPendingSuspension($info['pendingsuspension'] ?? false);
            $domain->setDomainContactChangeExpiryDate($info['confirmdate'] ?? null);
        }

        return $domain;
    }

    /**
     * @param array<string,mixed>|string $params
     * @return array<string,array<string,mixed>>
     */
    public function getContacts(string|array $params): array
    {
        $contactIds = $this->getDomainContactIds($params);

        return $this->contactService->fetchContactsByRole($this->client(), $contactIds);
    }

    /**
     * @param array<string,mixed>|string $params
     * @return array<string,array<string,string>>
     */
    public function getContactDetailsForWhmcs(string|array $params): array
    {
        $contacts = $this->getContacts($params);

        return ContactNormalizer::normalizeForWhmcs($contacts);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function registerDomainFromWhmcs(array $params): array
    {
        $domainName = $this->domainInputValidator->normalizeDomainName($params);
        $tld = $this->domainInputValidator->normalizeTld($params);
        $registrationPeriod = $this->domainInputValidator->resolveRegistrationPeriod($params);
        $requestedNameservers = $this->domainInputValidator->extractRequestedNameservers($params);

        if (count($requestedNameservers) < 2) {
            throw new InvalidArgumentException('At least two nameservers are required for registration.');
        }

        $this->domainInputValidator->validateRegistrationTld($tld);

        $registrantType = $this->registrationProfileResolver->resolveRegistrantType($params, $tld);
        $registrantContactData = $this->registrationProfileResolver->buildRegistrantContactData(
            $params,
            $registrantType,
            $this->contactDataMapper,
        );

        // Validate the complete contact and technical handle before any EPP call.
        ContactNormalizer::toRnidsCreatePayload($registrantContactData, 'Registrant');
        $techHandle = $this->registrationProfileResolver->resolveTechHandleFromConfig($params);

        $knownHosts = $this->knownHostRepository->loadKnownHosts();
        foreach ($requestedNameservers as $nameserver) {
            $knownAddresses = $knownHosts[$nameserver] ?? ['ipv4' => [], 'ipv6' => []];
            $this->hostService->ensureHostExistsAndSynced($this->client(), $nameserver, $knownAddresses);
        }

        $registrantHandle = $this->contactService->createContactFromWhmcsDetails(
            $this->client(),
            'Registrant',
            $registrantContactData,
        );

        $adminHandle = $registrantHandle;
        $this->client()->domain()->register(
            $domainName,
            $registrantHandle,
            $adminHandle,
            $techHandle,
            $requestedNameservers,
            $registrationPeriod,
        );

        return ['success' => true];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function renewDomain(array $params): array
    {
        $domainName = $this->domainInputValidator->normalizeDomainName($params);
        $tld = $this->domainInputValidator->normalizeTld($params);
        $renewalPeriod = $this->domainInputValidator->resolveRegistrationPeriod($params);

        $this->domainInputValidator->validateTransferTld($tld);

        $this->client()->domain()->renew($domainName, $renewalPeriod);

        return ['success' => true];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function transferDomainFromWhmcs(array $params): array
    {
        $domainName = $this->domainInputValidator->normalizeDomainName($params);
        $this->domainInputValidator->validateTransferTld($this->domainInputValidator->normalizeTld($params));

        $authCode = $this->domainInputValidator->extractTransferAuthCode($params);

        $this->client()->domain()->transfer($domainName, $authCode);

        return ['success' => true];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function saveContactDetailsFromWhmcs(array $params): array
    {
        $domainName = $this->domainInputValidator->paramsToDomain($params);
        $submitted = $this->contactDataMapper->extractSubmittedContacts($params);

        if ($submitted === []) {
            throw new InvalidArgumentException('No contact details were submitted for update.');
        }

        // Reject an invalid later role before any earlier role can create a contact.
        foreach ($submitted as $role => $details) {
            ContactNormalizer::toRnidsCreatePayload($details, $role);
        }

        $existingContactIds = $this->getDomainContactIds($params);
        $existingContacts = $this->contactService->fetchContactsByRole($this->client(), $existingContactIds);
        $existingWhmcsContacts = ContactNormalizer::normalizeForWhmcs($existingContacts);

        $newContactIds = [];
        foreach (['Registrant', 'Admin', 'Tech'] as $role) {
            if (!isset($submitted[$role])) {
                continue;
            }

            $existing = $existingWhmcsContacts[$role] ?? [];
            if ($existing !== [] && ContactNormalizer::equivalent($existing, $submitted[$role])) {
                continue;
            }

            $newContactIds[$role] = $this->contactService->createContactFromWhmcsDetails(
                $this->client(),
                $role,
                $submitted[$role],
            );
        }

        if ($newContactIds === []) {
            return ['success' => true];
        }

        $updatePayload = $this->contactService->buildDomainContactUpdatePayload(
            $domainName,
            $existingContactIds,
            $newContactIds,
        );

        // RNIDS ignores other changes when a registrant change is included. Apply
        // ordinary roles first, before the separate registrant approval flow starts.
        $registrantHandle = $updatePayload['registrant'] ?? null;
        unset($updatePayload['registrant']);
        if (count($updatePayload) > 1) {
            $this->client()->domain()->update($updatePayload);
        }
        if ($registrantHandle !== null) {
            $this->client()->domain()->update(['name' => $domainName, 'registrant' => $registrantHandle]);
        }

        return ['success' => true];
    }

    /**
     * @param array<string,mixed>|string $params
     * @return array<string,mixed>
     */
    public function getEPPCode(string|array $params): array
    {
        $info = $this->getInfo($params);

        if (in_array('pendingtransfer', $info['statuses'], true)) {
            throw new \RuntimeException('EPP kod je poslat na email adresu administrativnog kontakta domena. Ukoliko nije stigao, kontaktirajte podršku.');
        }

        return $this->client()->domain()->getCode($this->domainInputValidator->paramsToDomain($params));
    }

    /**
     * @param array<string,mixed>|string $params
     * @return array<string,mixed>
     */
    public function getState(string|array $params): array
    {
        return $this->client()->domain()->getState($this->domainInputValidator->paramsToDomain($params));
    }

    /**
     * @param mixed $statuses
     * @return list<string>
     */
    public function normalizeDomainStatuses($statuses): array
    {
        return $this->domainStatusService->normalizeDomainStatuses($statuses);
    }

    /**
     * @param list<string> $statuses
     */
    public function isTransferLocked(array $statuses): bool
    {
        return $this->domainStatusService->isTransferLocked($statuses);
    }

    /**
     * @param list<string> $statuses
     */
    public function isRestorable(array $statuses): bool
    {
        return $this->domainStatusService->isRestorable($statuses);
    }

    /**
     * @param list<string> $statuses
     */
    public function isPendingSuspension(array $statuses): bool
    {
        return $this->domainStatusService->isPendingSuspension($statuses);
    }

    /**
     * @param list<string> $statuses
     */
    public function registrationStatus(array $statuses, mixed $expiryDate): string
    {
        return $this->domainStatusService->registrationStatus($statuses, $expiryDate);
    }

    public function dateToCarbon(mixed $date): ?Carbon
    {
        if (!$date instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance(DateTime::createFromInterface($date));
    }

    /**
     * @param list<mixed> $arguments
     */
    public function applyDomainValue(Domain $domain, string $method, array $arguments): void
    {
        if (!method_exists($domain, $method)) {
            return;
        }

        $domain->{$method}(...$arguments);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{lockenabled:'locked'|'unlocked'}
     */
    public function getRegistrarLockFromWhmcs(array $params): array
    {
        $info = $this->getInfo($params);
        $statuses = $this->normalizeDomainStatuses($info['statuses'] ?? []);

        return [
            'lockenabled' => $this->isTransferLocked($statuses) ? 'locked' : 'unlocked',
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function saveRegistrarLockFromWhmcs(array $params): array
    {
        $domainName = strtolower($this->domainInputValidator->paramsToDomain($params));
        $requestedLockState = $this->resolveRequestedLockState($params['lockenabled'] ?? null);

        if (null === $requestedLockState) {
            throw new InvalidArgumentException(
                'Lock value is invalid. Expected locked/unlocked.',
            );
        }

        $info = $this->getInfo($params);
        $statuses = $this->normalizeDomainStatuses($info['statuses'] ?? []);
        $isCurrentlyLocked = $this->isTransferLocked($statuses);

        if ($requestedLockState === $isCurrentlyLocked) {
            return ['success' => true];
        }

        if ($requestedLockState) {
            $this->applyLockEnableWithFallback($domainName);
        } else {
            $this->applyLockDisable($domainName, $statuses);
        }

        return ['success' => true];
    }

    public function safeErrorMessage(Throwable $exception, string $fallback): string
    {
        return ErrorMessageFormatter::safeMessage($exception, $fallback);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function safeInfoForSync(array $params): ?array
    {
        try {
            return $this->getInfo($params);
        } catch (ObjectMissing $exception) {
            return null;
        } catch (ProtocolException $exception) {
            if (2201 === $exception->resultCode()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed>|string $params
     * @return array{Registrant:?string,Admin:?string,Tech:?string}
     */
    private function getDomainContactIds(string|array $params): array
    {
        $info = $this->getInfo($params);

        return $this->contactService->getDomainContactIdsFromInfo($info);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function setNameServers(array $params): array
    {
        $domainName = strtolower($this->domainInputValidator->paramsToDomain($params));
        $requestedNameservers = $this->domainInputValidator->extractRequestedNameservers($params);

        if (count($requestedNameservers) < 2) {
            throw new InvalidArgumentException('At least two nameservers are required.');
        }

        $knownHosts = $this->resolveKnownHostsForNameservers($requestedNameservers);

        foreach ($requestedNameservers as $nameserver) {
            $knownAddresses = $knownHosts[$nameserver] ?? ['ipv4' => [], 'ipv6' => []];

            if ($knownAddresses['ipv4'] === [] && $knownAddresses['ipv6'] === []) {
                $this->assertNameserverCanBeUsedWithoutKnownGlue($domainName, $nameserver);
                continue;
            }

            $this->hostService->ensureHostExistsAndSynced($this->client(), $nameserver, $knownAddresses);
        }

        $currentInfo = $this->client()->domain()->info($domainName);
        $currentNameservers = array_map('strtolower', array_keys($currentInfo['nameservers'] ?? []));

        $toAdd = array_values(array_diff($requestedNameservers, $currentNameservers));
        $toRemove = array_values(array_diff($currentNameservers, $requestedNameservers));

        if ($toAdd === [] && $toRemove === []) {
            return ['success' => true];
        }

        $this->domainNameserverUpdater->rawDomainNameserverUpdate(
            $this->client(),
            $domainName,
            $toAdd,
            $toRemove,
            $knownHosts,
        );

        return ['success' => true];
    }

    /**
     * @param list<string> $nameservers
     * @return array<string, array{ipv4:list<string>, ipv6:list<string>}>
     */
    private function resolveKnownHostsForNameservers(array $nameservers): array
    {
        $knownHosts = $this->knownHostRepository->loadKnownHosts();

        foreach ($nameservers as $nameserver) {
            if (isset($knownHosts[$nameserver])) {
                continue;
            }

            $existingAddresses = $this->hostService->findExistingHostAddresses($this->client(), $nameserver);
            if (null === $existingAddresses) {
                continue;
            }

            $knownHosts[$nameserver] = $existingAddresses;
        }

        return $knownHosts;
    }

    private function assertNameserverCanBeUsedWithoutKnownGlue(string $domainName, string $nameserver): void
    {
        if (!$this->isInBailiwickNameserver($domainName, $nameserver)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Nameserver host %s does not exist in RNIDS and has no known glue addresses. Register the child nameserver first.',
            $nameserver,
        ));
    }

    private function isInBailiwickNameserver(string $domainName, string $nameserver): bool
    {
        $domainName = strtolower(\RNIDS\Xml\DnsNameEncoder::toAscii($domainName));
        $nameserver = strtolower(\RNIDS\Xml\DnsNameEncoder::toAscii($nameserver));

        return $nameserver === $domainName
            || str_ends_with($nameserver, '.' . $domainName);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function registerNameserver(array $params): array
    {
        $nameserver = $this->extractChildNameserver($params);
        $ipAddress = $this->extractChildNameserverIp($params);

        $this->hostService->createSingleAddressHost($this->client(), $nameserver, $ipAddress);

        return ['success' => true];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function modifyNameserver(array $params): array
    {
        $nameserver = $this->extractChildNameserver($params);
        $ipAddress = $this->extractChildNameserverIp($params);

        $this->hostService->replaceSingleAddressHost($this->client(), $nameserver, $ipAddress);

        return ['success' => true];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true}
     */
    public function deleteNameserver(array $params): array
    {
        $nameserver = $this->extractChildNameserver($params);

        $this->hostService->deleteHostIfExists($this->client(), $nameserver);

        return ['success' => true];
    }

    private function resolveRequestedLockState(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['locked', 'lock', 'enabled', 'enable', 'on', 'yes', 'true', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['unlocked', 'unlock', 'disabled', 'disable', 'off', 'no', 'false', '0'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function extractChildNameserver(array $params): string
    {
        $nameserver = strtolower(trim((string) ($params['nameserver'] ?? '')));
        $nameserver = rtrim($nameserver, '.');

        if ($nameserver === '') {
            throw new InvalidArgumentException('Nameserver hostname is required.');
        }

        if (!filter_var($nameserver, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException(sprintf('Invalid nameserver hostname provided: %s', $nameserver));
        }

        return $nameserver;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function extractChildNameserverIp(array $params): string
    {
        $ipAddress = trim((string) ($params['newipaddress'] ?? $params['ipaddress'] ?? ''));

        if ($ipAddress === '') {
            throw new InvalidArgumentException('Nameserver IP address is required.');
        }

        if (false === filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(sprintf('Invalid nameserver IP address provided: %s', $ipAddress));
        }

        return $ipAddress;
    }

    private function applyLockEnableWithFallback(string $domainName): void
    {
        $candidates = [
            'clientTransferProhibited',
            'clientUpdateProhibited',
        ];

        $lastException = null;

        foreach ($candidates as $status) {
            try {
                $this->client()->domain()->update([
                    'name' => $domainName,
                    'add' => [
                        'statuses' => [$status],
                    ],
                ]);

                return;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException instanceof Throwable) {
            throw $lastException;
        }
    }

    /**
     * @param list<string> $currentStatuses
     */
    private function applyLockDisable(string $domainName, array $currentStatuses): void
    {
        if (array_intersect(['servertransferprohibited', 'serverupdateprohibited'], $currentStatuses) !== []) {
            throw new InvalidArgumentException('The domain is locked by the registry. Contact support to unlock it.');
        }

        $removableStatuses = [];

        foreach (['clienttransferprohibited', 'clientupdateprohibited'] as $status) {
            if (in_array($status, $currentStatuses, true)) {
                $removableStatuses[] = $status === 'clienttransferprohibited'
                    ? 'clientTransferProhibited'
                    : 'clientUpdateProhibited';
            }
        }

        if ($removableStatuses === []) {
            return;
        }

        $this->client()->domain()->update([
            'name' => $domainName,
            'remove' => [
                'statuses' => $removableStatuses,
            ],
        ]);
    }
}
