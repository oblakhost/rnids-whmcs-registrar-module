<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Oblak\WHMCS\RSREG\Contact\ContactDataMapper;
use Oblak\WHMCS\RSREG\Contact\ContactService;
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

class Registrar
{
    /**
     * @param array<string,mixed> $params
     */
    public function __construct(
        private array $params,
        private readonly DomainInputValidator $domainInputValidator = new DomainInputValidator(),
        private readonly RegistrationProfileResolver $registrationProfileResolver = new RegistrationProfileResolver(),
        private readonly ContactDataMapper $contactDataMapper = new ContactDataMapper(),
        private readonly DomainStatusService $domainStatusService = new DomainStatusService(),
        private readonly ContactService $contactService = new ContactService(),
        private readonly KnownHostRepository $knownHostRepository = new KnownHostRepository(dirname(__DIR__)),
        private readonly HostService $hostService = new HostService(),
        private readonly DomainNameserverUpdater $domainNameserverUpdater = new DomainNameserverUpdater(),
    ) {
    }

    private static Client $client;

    /**
     * @param array<string,mixed> $params
     */
    public static function fromParams(array $params): self
    {
        return new self(ClientFactory::buildClientParams($params));
    }

    public function client(): Client
    {
        if (!isset(self::$client)) {
            self::$client = Client::ready($this->params);
        }

        return self::$client;
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
        $techHandle = $this->registrationProfileResolver->resolveTechHandleFromConfig($params);

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

        if (count($updatePayload) > 1) {
            $this->client()->domain()->update($updatePayload);
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

        $knownHosts = $this->knownHostRepository->loadKnownHosts();

        foreach ($requestedNameservers as $nameserver) {
            $knownAddresses = $knownHosts[$nameserver] ?? ['ipv4' => [], 'ipv6' => []];
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
}
