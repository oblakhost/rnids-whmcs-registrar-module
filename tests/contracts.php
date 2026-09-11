<?php

declare(strict_types=1);

/**
 * Offline RNIDS module contract checks. Run: php tests/contracts.php [--filter=substring]
 *
 * This is deliberately independent of a WHMCS bootstrap, its database, and registry
 * credentials. The RNIDS client and WHMCS boundary objects are replaced BEFORE the
 * module autoloader is loaded. Every registry operation must have a scripted result;
 * unconfigured operations throw and can never reach a socket. The module's actual
 * validators, mappers, services, Registrar, XML updater, and handlers are exercised.
 * WHMCS runtime behavior and the SDK's protocol/transport need separate live tests.
 * Failures are retained as failures, rather than marked as expected or skipped.
 */

namespace RnidsContractTests;

use Closure;
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
use Oblak\WHMCS\RSREG\Registrar;
use Oblak\WHMCS\RSREG\Support\ClientFactory;
use Oblak\WHMCS\RSREG\Support\ErrorMessageFormatter;
use Oblak\WHMCS\RSREG\Support\ModuleLogger;
use Oblak\WHMCS\RSREG\Validation\DomainInputValidator;
use Oblak\WHMCS\RSREG\Validation\RegistrationProfileResolver;
use RNIDS\Exception\ObjectAlreadyExists;
use RNIDS\Exception\ObjectMissing;
use RNIDS\Exception\ProtocolException;
use RNIDS\Xml\Response\ResponseMetadata;
use RuntimeException;
use Throwable;

if (PHP_SAPI !== 'cli' || defined('WHMCS') || class_exists('RNIDS\\Client', false)) {
    throw new RuntimeException('Run this offline harness in a fresh CLI process without WHMCS.');
}

final class OfflineCallBlocked extends RuntimeException
{
}

final class FakeRegistry
{
    public static array $calls = [];
    public static array $accesses = [];
    public static array $responses = [];
    public static array $logs = [];

    public static function reset(): void
    {
        self::$calls = self::$accesses = self::$responses = self::$logs = [];
    }

    public static function queue(string $operation, mixed ...$responses): void
    {
        self::$responses[$operation] = array_merge(self::$responses[$operation] ?? [], $responses);
    }

    public static function invoke(string $operation, array $arguments): mixed
    {
        self::$calls[] = ['operation' => $operation, 'arguments' => $arguments];
        if (empty(self::$responses[$operation])) {
            throw new OfflineCallBlocked('Offline registry call blocked: ' . $operation);
        }
        $result = array_shift(self::$responses[$operation]);
        if ($result instanceof Throwable) {
            throw $result;
        }
        return $result instanceof Closure ? $result(...$arguments) : $result;
    }

    public static function operations(): array
    {
        return array_column(self::$calls, 'operation');
    }
}

final class FakeService
{
    public function __construct(private string $service)
    {
    }

    public function __call(string $method, array $arguments): mixed
    {
        return FakeRegistry::invoke($this->service . '.' . $method, $arguments);
    }
}

final class FakeClient
{
    private bool $closed = false;

    public function __construct(public array $config = [])
    {
    }

    public static function ready(array $config): self
    {
        FakeRegistry::$accesses[] = 'client.ready';
        return new self($config);
    }

    public function __call(string $method, array $arguments): mixed
    {
        FakeRegistry::$accesses[] = 'client.' . $method;
        if ($this->closed) {
            throw new RuntimeException('Offline client is closed.');
        }
        if (in_array($method, ['domain', 'contact', 'host', 'transport'], true)) {
            return new FakeService($method);
        }
        return FakeRegistry::invoke('client.' . $method, $arguments);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class FakeDomain
{
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_PENDING_DELETE = 'Pending Delete';
    public const STATUS_DELETED = 'Deleted';
    public const STATUS_ARCHIVED = 'Archived';
    public const STATUS_EXPIRED = 'Expired';
    public const STATUS_SUSPENDED = 'Suspended';
    public const STATUS_INACTIVE = 'Inactive';
    public array $values = [];

    public function __call(string $method, array $arguments): self
    {
        $this->values[$method] = $arguments[0] ?? null;
        return $this;
    }
}

final class FakeResultsList extends \ArrayObject
{
}

final class FakeSearchResult
{
    public const STATUS_TLD_NOT_SUPPORTED = 'unsupported';
    public const STATUS_NOT_REGISTERED = 'available';
    public const STATUS_REGISTERED = 'registered';
    public string $status = '';

    public function __construct(private string $sld, private string $tld)
    {
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSecondLevel(): string
    {
        return $this->sld;
    }

    public function getTopLevel(): string
    {
        return $this->tld;
    }
}

final class FakeInvalidConfiguration extends RuntimeException
{
}

class_alias(FakeClient::class, 'RNIDS\\Client');
class_alias(FakeDomain::class, 'WHMCS\\Domain\\Registrar\\Domain');
class_alias(FakeResultsList::class, 'WHMCS\\Domains\\DomainLookup\\ResultsList');
class_alias(FakeSearchResult::class, 'WHMCS\\Domains\\DomainLookup\\SearchResult');
class_alias(FakeInvalidConfiguration::class, 'WHMCS\\Exception\\Module\\InvalidConfiguration');

// The only global function used by ModuleLogger is a capture-only test boundary.
require __DIR__ . '/contracts/whmcs-functions.php';
require dirname(__DIR__) . '/rnids.php';

// Keep KnownHostRepository away from developer configuration; use an empty fixture.
define('ROOTDIR', sys_get_temp_dir() . '/rnids-offline-contracts-no-whmcs');

final class Suite
{
    private int $passed = 0;
    private int $failed = 0;

    public function __construct(private string $filter)
    {
    }

    public function test(string $name, callable $test): void
    {
        if ($this->filter !== '' && !str_contains($name, $this->filter)) {
            return;
        }
        FakeRegistry::reset();
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ((error_reporting() & $severity) !== 0) {
                $warnings[] = $message;
            }
            return true;
        });
        try {
            $test();
            self::same([], $warnings, 'Unexpected PHP warnings');
            $this->passed++;
            echo "PASS $name\n";
        } catch (Throwable $exception) {
            $this->failed++;
            echo "FAIL $name: " . $exception->getMessage() . "\n";
            if ($warnings !== []) {
                echo '  PHP warnings: ' . json_encode($warnings, JSON_UNESCAPED_UNICODE) . "\n";
            }
        } finally {
            restore_error_handler();
        }
    }

    public static function same(mixed $expected, mixed $actual, string $reason = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(($reason !== '' ? $reason . ': ' : '')
                . 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public static function truth(bool $condition, string $reason): void
    {
        self::same(true, $condition, $reason);
    }

    public static function rejects(callable $call, string $class = InvalidArgumentException::class): void
    {
        try {
            $call();
        } catch (Throwable $exception) {
            if (!$exception instanceof $class) {
                throw new RuntimeException('Expected ' . $class . ', got ' . get_class($exception) . ': ' . $exception->getMessage());
            }
            return;
        }
        throw new RuntimeException('Expected ' . $class . '; input was accepted');
    }

    public static function noRegistry(): void
    {
        self::same([], FakeRegistry::$accesses, 'Validation must precede registry client access');
        self::same([], FakeRegistry::$calls, 'Validation must precede registry commands');
    }

    public static function errorResponse(mixed $result): void
    {
        self::truth(is_array($result) && array_keys($result) === ['error']
            && is_string($result['error']) && $result['error'] !== '', 'Expected only a nonempty WHMCS error');
    }

    public function finish(): never
    {
        echo sprintf("\n%d passed; %d failed; %d total. Offline only; zero real registry calls.\n", $this->passed, $this->failed, $this->passed + $this->failed);
        exit($this->failed === 0 && $this->passed > 0 ? 0 : 1);
    }
}

function baseContact(): array
{
    return [
        'First Name' => 'Milan', 'Last Name' => 'Petrovic', 'Company Name' => '',
        'Company Number' => '', 'Tax Number' => '', 'Address 1' => 'Test Street 1',
        'Address 2' => '', 'City' => 'Belgrade', 'State' => '', 'Postcode' => '11000',
        'Country' => 'RS', 'Email Address' => 'contract@example.invalid',
        'Phone Number' => '+381.111234567',
    ];
}

function baseParams(): array
{
    return [
        'sld' => 'contract', 'tld' => 'rs', 'regperiod' => 1,
        'ns1' => 'ns1.example.invalid', 'ns2' => 'ns2.example.invalid',
        'admin_id' => 'TECH-OFFLINE', 'testmode' => 'on',
        'contactdetails' => ['Registrant' => baseContact()],
    ];
}

function domainInfo(array $overrides = []): array
{
    return array_replace([
        'name' => 'contract.rs', 'statuses' => ['ok'],
        'expirationDate' => new DateTimeImmutable('2099-01-02T03:04:05Z'),
        'createDate' => new DateTimeImmutable('2025-01-02T03:04:05Z'),
        'registrant' => 'OLD-REG', 'adminContact' => 'OLD-ADMIN', 'techContact' => 'OLD-TECH',
        'nameservers' => ['ns1.example.invalid' => ['ipv4' => [], 'ipv6' => []],
            'ns2.example.invalid' => ['ipv4' => [], 'ipv6' => []]],
        'isDomainVerified' => true,
    ], $overrides);
}

function registryContact(array $overrides = []): array
{
    $payload = ContactNormalizer::toRnidsCreatePayload(array_replace(baseContact(), $overrides));
    return array_merge($payload, $payload['extension']);
}

function registryError(int $code): ProtocolException
{
    $metadata = new ResponseMetadata($code, 'Offline registry response', 'TEST-CLIENT', 'TEST-SERVER');
    return match ($code) {
        2302 => new ObjectAlreadyExists($metadata),
        2303 => new ObjectMissing($metadata),
        default => new ProtocolException($metadata),
    };
}

function scriptContacts(array $infoOverrides = []): void
{
    FakeRegistry::queue('domain.info', domainInfo($infoOverrides));
    FakeRegistry::queue('contact.info', registryContact(), registryContact(), registryContact());
}

$filter = '';
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--filter=')) {
        fwrite(STDERR, "Usage: php tests/contracts.php [--filter=substring]\n");
        exit(2);
    }
    $filter = substr($argument, strlen('--filter='));
}
$suite = new Suite($filter);
$validator = new DomainInputValidator();
$profile = new RegistrationProfileResolver();
$mapper = new ContactDataMapper();
$statusService = new DomainStatusService();

foreach (['rs', 'in.rs', 'срб', 'од.срб', 'co.rs', 'org.rs', 'edu.rs', 'пр.срб', 'орг.срб', 'обр.срб'] as $tld) {
    $suite->test('tld/supported/' . $tld, static function () use ($validator, $tld): void {
        $validator->validateRegistrationTld($tld);
        $validator->validateTransferTld($tld);
    });
}
foreach (['com', 'net', 'gov.rs', 'ac.rs', '', 'invalid.rs'] as $tld) {
    $suite->test('tld/unsupported/' . ($tld ?: 'empty'), static function () use ($validator, $tld): void {
        Suite::rejects(static fn() => $validator->validateRegistrationTld($tld));
        Suite::rejects(static fn() => $validator->validateTransferTld($tld));
    });
}
$suite->test('domain/ascii-normalization', static function () use ($validator): void {
    Suite::same('example.co.rs', $validator->normalizeDomainName(['sld' => ' EXAMPLE ', 'tld' => ' .CO.RS ']));
});
$suite->test('domain/idn-uppercase-normalization', static function () use ($validator): void {
    Suite::same('пример.срб', $validator->normalizeDomainName(['sld' => 'ПРИМЕР', 'tld' => '.СРБ']));
});
$suite->test('domain/idn-lowercase', static function () use ($validator): void {
    Suite::same('пример.срб', $validator->normalizeDomainName(['sld' => 'пример', 'tld' => 'срб']));
});
foreach (['', 'with space', '-leading', 'trailing-', 'two.labels', 'bad/name', 'under_score', str_repeat('a', 64)] as $sld) {
    $suite->test('domain/reject-invalid-label/' . ($sld ?: 'empty'), static function () use ($validator, $sld): void {
        Suite::rejects(static fn() => $validator->normalizeDomainName(['sld' => $sld, 'tld' => 'rs']));
    });
}
foreach ([1, 10, '1', '10'] as $period) {
    $suite->test('period/valid/' . get_debug_type($period) . '/' . $period, static function () use ($validator, $period): void {
        Suite::same((int) $period, $validator->resolveRegistrationPeriod(['regperiod' => $period]));
    });
}
foreach ([0, 11, -1, '', 'abc', null, false, true, 1.5, '2years', '1.5', [1], new \stdClass()] as $index => $period) {
    $suite->test('period/reject/' . $index . '/' . get_debug_type($period), static function () use ($validator, $period): void {
        Suite::rejects(static fn() => $validator->resolveRegistrationPeriod(['regperiod' => $period]));
    });
}
$suite->test('nameservers/normalize-and-deduplicate', static function () use ($validator): void {
    Suite::same(['ns1.example.invalid', 'ns2.example.invalid'], $validator->extractRequestedNameservers([
        'ns1' => ' NS1.EXAMPLE.INVALID. ', 'ns2' => 'ns2.example.invalid', 'ns3' => 'ns1.example.invalid', 'ns4' => '',
    ]));
});
foreach (['bad host.invalid', '_srv.example.invalid', '-ns.example.invalid', 'ns..invalid', 'https://ns.invalid'] as $host) {
    $suite->test('nameservers/reject/' . $host, static function () use ($validator, $host): void {
        Suite::rejects(static fn() => $validator->extractRequestedNameservers(['ns1' => $host]));
    });
}
foreach (['eppcode', 'authcode', 'authCode', 'authinfo', 'authInfo'] as $key) {
    $suite->test('transfer/auth-alias/' . $key, static function () use ($validator, $key): void {
        Suite::same('OFFLINE-AUTH', $validator->extractTransferAuthCode([$key => ' OFFLINE-AUTH ']));
    });
}
$suite->test('transfer/auth-missing', static function () use ($validator): void {
    Suite::rejects(static fn() => $validator->extractTransferAuthCode(['eppcode' => ' ']));
});
foreach (['rs', 'in.rs', 'срб', 'од.срб'] as $tld) {
    $suite->test('profile/individual-default/' . $tld, static function () use ($profile, $tld): void {
        Suite::same('individual', $profile->resolveRegistrantType([], $tld));
    });
}
foreach (['co.rs', 'org.rs', 'edu.rs', 'пр.срб', 'орг.срб', 'обр.срб'] as $tld) {
    $suite->test('profile/company-only/' . $tld, static function () use ($profile, $tld): void {
        Suite::same('company', $profile->resolveRegistrantType([], $tld));
        Suite::rejects(static fn() => $profile->resolveRegistrantType(['additionalfields' => ['Registrant Type' => 'Individual']], $tld));
    });
}
$suite->test('profile/reject-invalid-type', static function () use ($profile): void {
    Suite::rejects(static fn() => $profile->resolveRegistrantType(['additionalfields' => ['Registrant Type' => 'Robot']], 'rs'));
});
foreach (['keyed' => [12 => '12345678', 13 => '100000001'], 'list' => [['id' => 12, 'value' => '12345678'], ['id' => 13, 'value' => '100000001']]] as $shape => $fields) {
    $suite->test('profile/custom-field-map/' . $shape, static function () use ($profile, $mapper, $fields): void {
        $result = $profile->buildRegistrantContactData(array_replace(baseParams(), [
            'reg_mb' => '12', 'reg_pib' => '13', 'customfields' => $fields,
            'additionalfields' => ['Company Name' => 'Offline Ltd', 'Country' => 'de'],
        ]), 'company', $mapper);
        Suite::same('12345678', $result['Company Number']);
        Suite::same('100000001', $result['Tax Number']);
        Suite::same('DE', $result['Country']);
    });
}
$suite->test('profile/company-requires-separate-company-number', static function () use ($profile, $mapper): void {
    Suite::rejects(static fn() => $profile->buildRegistrantContactData(array_replace(baseParams(), [
        'additionalfields' => ['Company Name' => 'Offline Ltd', 'Tax Number' => '100000001'],
    ]), 'company', $mapper));
});
$suite->test('contact/field-aliases', static function () use ($mapper): void {
    $actual = $mapper->normalizeWhmcsContactData(['firstname' => ' Milan ', 'lastname' => 'Petrovic',
        'countrycode' => 'RS', 'vatNo' => '100000001', 'ident' => '12345678', 'email' => 'contract@example.invalid', 'address2' => 'Floor 2']);
    Suite::same('Milan', $actual['First Name']);
    Suite::same('12345678', $actual['Company Number']);
    Suite::same('100000001', $actual['Tax Number']);
    Suite::same('Floor 2', $actual['Address 2']);
});
$suite->test('contact/explicit-role-does-not-fill-other-roles-from-base', static function () use ($mapper): void {
    $params = array_replace(baseParams(), baseContact(), [
        'contactdetails' => ['Admin' => array_replace(baseContact(), ['Address 2' => 'Admin floor'])],
    ]);
    Suite::same(['Admin'], array_keys($mapper->extractSubmittedContacts($params)));
});
$suite->test('contact/empty-explicit-role-does-not-fall-back-to-base', static function () use ($mapper): void {
    $params = array_replace(baseParams(), baseContact(), ['contactdetails' => ['Admin' => []]]);
    Suite::same([], $mapper->extractSubmittedContacts($params));
});
foreach (['malformed-role' => ['Registrant' => 'invalid'], 'billing-only' => ['Billing' => baseContact()],
    'unknown-role' => ['Unknown' => baseContact()], 'malformed-map' => 'invalid'] as $shape => $details) {
    $suite->test('contact/explicit-invalid-' . $shape . '-does-not-fall-back', static function () use ($details): void {
        $params = array_replace(baseParams(), baseContact(), ['contactdetails' => $details]);
        Suite::errorResponse(\rnids_SaveContactDetails($params));
        Suite::noRegistry();
    });
}
$suite->test('contact/individual-payload', static function (): void {
    $payload = ContactNormalizer::toRnidsCreatePayload(baseContact());
    Suite::same('Milan Petrovic', $payload['postalInfo']['name']);
    Suite::same('RS', $payload['postalInfo']['address']['countryCode']);
    Suite::same('0', $payload['extension']['isLegalEntity']);
    Suite::same(null, $payload['extension']['vatNo']);
});
$suite->test('contact/company-payload', static function (): void {
    $payload = ContactNormalizer::toRnidsCreatePayload(array_replace(baseContact(), ['Company Name' => 'Offline Ltd', 'Company Number' => '12345678', 'Tax Number' => '100000001']));
    Suite::same('Offline Ltd', $payload['postalInfo']['organization']);
    Suite::same('1', $payload['extension']['isLegalEntity']);
    Suite::same('12345678', $payload['extension']['ident']);
    Suite::same('100000001', $payload['extension']['vatNo']);
});
foreach (['Email Address', 'City', 'Country'] as $field) {
    $suite->test('contact/reject-missing/' . $field, static function () use ($field): void {
        Suite::rejects(static fn() => ContactNormalizer::toRnidsCreatePayload(array_replace(baseContact(), [$field => ''])));
    });
}
$suite->test('contact/reject-missing-name', static function (): void {
    Suite::rejects(static fn() => ContactNormalizer::toRnidsCreatePayload(array_replace(baseContact(), ['First Name' => '', 'Last Name' => ''])));
});
$suite->test('contact/reject-partial-company', static function (): void {
    Suite::rejects(static fn() => ContactNormalizer::toRnidsCreatePayload(array_replace(baseContact(), ['Company Name' => 'Offline Ltd'])));
});
$suite->test('contact/reject-orphan-identifiers', static function (): void {
    Suite::rejects(static fn() => ContactNormalizer::toRnidsCreatePayload(array_replace(baseContact(), ['Tax Number' => '100000001'])));
});
$suite->test('contact/two-address-lines-round-trip', static function (): void {
    $actual = ContactNormalizer::normalizeSingleForWhmcs(registryContact(['Address 2' => 'Floor 2']));
    Suite::same('Floor 2', $actual['Address 2'] ?? null);
});
$suite->test('contact/comparison-normalizes-case-and-space', static function (): void {
    Suite::same(true, ContactNormalizer::equivalent(baseContact(), array_replace(baseContact(), ['First Name' => ' milan ', 'Country' => 'rs'])));
});
$suite->test('contact/comparison-detects-second-address-change', static function (): void {
    Suite::same(false, ContactNormalizer::equivalent(baseContact(), array_replace(baseContact(), ['Address 2' => 'Floor 2'])));
});
$suite->test('contact/update-payload-preserves-other-roles', static function (): void {
    $result = (new ContactService())->buildDomainContactUpdatePayload('contract.rs',
        ['Registrant' => 'OLD-REG', 'Admin' => 'OLD-ADMIN', 'Tech' => 'OLD-TECH'], ['Admin' => 'NEW-ADMIN']);
    Suite::same(['name' => 'contract.rs', 'add' => ['contacts' => [['type' => 'admin', 'handle' => 'NEW-ADMIN']]],
        'remove' => ['contacts' => [['type' => 'admin', 'handle' => 'OLD-ADMIN']]]], $result);
});

foreach (['ok' => 'Active', 'pendingDelete' => 'Pending Delete', 'deleted' => 'Deleted', 'archived' => 'Archived',
    'expired' => 'Expired', 'redemptionPeriod' => 'Expired', 'serverHold' => 'Suspended', 'clientHold' => 'Suspended', 'inactive' => 'Inactive'] as $status => $expected) {
    $suite->test('info/status/' . $status, static function () use ($status, $expected): void {
        Suite::same($expected, InfoNormalizer::normalize(domainInfo(['statuses' => [$status]]))['status']);
    });
}
$suite->test('info/status-flags-and-deduplication', static function (): void {
    $info = InfoNormalizer::normalize(domainInfo(['statuses' => ['clientTransferProhibited', 'pendingUpdate', 'pendingRestore', 'clientTransferProhibited', 42]]));
    Suite::same(['clienttransferprohibited', 'pendingupdate', 'pendingrestore'], $info['statuses']);
    Suite::same(true, $info['transferlock']);
    Suite::same(true, $info['restorable']);
    Suite::same(true, $info['contactchangepending']);
});
foreach (['iso' => '2030-07-08T09:10:11Z', 'serialized' => ['date' => '2030-07-08 09:10:11', 'timezone' => 'UTC'],
    'datetime' => new DateTimeImmutable('2030-07-08T09:10:11Z')] as $shape => $value) {
    $suite->test('info/date/' . $shape, static function () use ($value): void {
        $date = InfoNormalizer::normalize(domainInfo(['expirationDate' => $value]))['expirydate'];
        Suite::truth($date instanceof DateTimeInterface, 'Dates must remain date objects');
        Suite::same('2030-07-08T09:10:11+00:00', $date->format(DATE_ATOM));
    });
}
$suite->test('info/null-dates-and-nameservers', static function (): void {
    $info = InfoNormalizer::normalize(domainInfo(['expirationDate' => null]));
    Suite::same(null, $info['expirydate']);
    Suite::same(['ns1' => 'ns1.example.invalid', 'ns2' => 'ns2.example.invalid'], $info['nameservers']);
});
$suite->test('status/normalization', static function () use ($statusService): void {
    Suite::same(['ok', 'clienthold'], $statusService->normalizeDomainStatuses([' OK ', 'clientHold', 'ok', null, '']));
});
$suite->test('status/past-expiry', static function () use ($statusService): void {
    Suite::same('Expired', $statusService->registrationStatus(['ok'], new DateTimeImmutable('2000-01-01')));
});
foreach (['on' => ['epp-test.rnids.rs', false, true], 'off' => ['epp.rnids.rs', true, false]] as $mode => [$host, $verify, $selfSigned]) {
    $suite->test('client/tls/' . $mode, static function () use ($mode, $host, $verify, $selfSigned): void {
        $config = ClientFactory::buildClientParams(['testmode' => $mode, 'epp_username' => 'OFFLINE-USER',
            'epp_password' => 'OFFLINE-PASSWORD', 'epp_certificate_password' => ' OFFLINE-PASSPHRASE ']);
        Suite::same($host, $config['host']);
        Suite::same(700, $config['port']);
        Suite::same($verify, $config['tls']['verifyPeer']);
        Suite::same($verify, $config['tls']['verifyPeerName']);
        Suite::same($selfSigned, $config['tls']['allowSelfSigned']);
        Suite::same('OFFLINE-PASSPHRASE', $config['tls']['clientCertificatePassword']);
    });
}
foreach (['on' => 'hello', 'off' => 'unsolicited'] as $mode => $greetingMode) {
    $suite->test('client/greeting-mode/' . $mode, static function () use ($mode, $greetingMode): void {
        Suite::same($greetingMode, ClientFactory::buildClientParams(['testmode' => $mode])['greetingMode'] ?? null);
    });
    $suite->test('client/transaction-id-policy/' . $mode, static function () use ($mode): void {
        Suite::same($mode !== 'on', ClientFactory::buildClientParams(['testmode' => $mode])['requireClientTransactionId'] ?? null);
    });
}
$suite->test('client/production-is-default', static function (): void {
    $config = ClientFactory::buildClientParams([]);
    Suite::same('epp.rnids.rs', $config['host']);
    Suite::same(true, $config['tls']['verifyPeer']);
    Suite::same(false, array_key_exists('clientCertificatePassword', $config['tls']));
});
$suite->test('client/test-production-session-isolation', static function (): void {
    $test = Registrar::fromParams(['testmode' => 'on', 'epp_username' => 'OFFLINE-TEST']);
    $production = Registrar::fromParams(['testmode' => 'off', 'epp_username' => 'OFFLINE-PROD']);
    Suite::truth($test->client() !== $production->client(), 'Test and production registrars must not share one client');
});

foreach (['request', 'response'] as $direction) {
    foreach (['epp_password', 'password', 'epp_certificate_password', 'clientCertificatePassword', 'eppcode', 'authCode', 'authInfo', 'auth_code', 'passphrase', 'secret', 'token', 'clientCertificate', 'privateKey'] as $key) {
        $suite->test('logging/redact/' . $direction . '/' . $key, static function () use ($direction, $key): void {
            $payload = ['nested' => [$key => 'OFFLINE-SENSITIVE-MARKER'], 'public' => 'visible'];
            ModuleLogger::logModuleCall('contract', $direction === 'request' ? $payload : [], $direction === 'response' ? $payload : []);
            $encoded = json_encode(FakeRegistry::$logs);
            Suite::same(false, str_contains($encoded, 'OFFLINE-SENSITIVE-MARKER'), 'Sensitive value persisted in captured log');
            Suite::same(true, str_contains($encoded, 'visible'));
        });
    }
}
$suite->test('logging/normalizes-date-and-object', static function (): void {
    ModuleLogger::logModuleCall('contract', (object) ['date' => new DateTimeImmutable('2030-01-01T00:00:00Z')], null);
    Suite::same(['date' => '2030-01-01T00:00:00+00:00'], FakeRegistry::$logs[0][2]);
    Suite::same(['_empty' => true], FakeRegistry::$logs[0][3]);
});
$suite->test('logging/exception-metadata', static function (): void {
    $context = ModuleLogger::exceptionContext(registryError(2303));
    Suite::same(2303, $context['responseMetadata']['resultCode']);
    Suite::same('TEST-SERVER', $context['responseMetadata']['serverTransactionId']);
});
$suite->test('error/empty-message-fallback', static function (): void {
    Suite::same('Unable to proceed', ErrorMessageFormatter::safeMessage(new RuntimeException('  '), 'Unable to proceed'));
});
$suite->test('error/actionable-validation', static function (): void {
    Suite::truth(str_contains(ErrorMessageFormatter::safeMessage(new InvalidArgumentException('At least two nameservers are required.'), 'Unable to register'), 'two nameservers'), 'Validation explanation must be retained');
});
foreach (['xml' => '<epp><authInfo><pw>OFFLINE-SENSITIVE-MARKER</pw></authInfo></epp>',
    'tls' => 'SSL operation failed: private key /private/certificate.pem passphrase=OFFLINE-SENSITIVE-MARKER',
    'password' => 'Authentication failed password=OFFLINE-SENSITIVE-MARKER'] as $kind => $message) {
    $suite->test('error/suppress-internals/' . $kind, static function () use ($message): void {
        $safe = ErrorMessageFormatter::safeMessage(new RuntimeException($message), 'Unable to proceed');
        Suite::same(false, str_contains($safe, 'OFFLINE-SENSITIVE-MARKER'), 'Raw exception details exposed to WHMCS user');
    });
}

foreach (['on', 'off'] as $mode) {
    $invalidCases = [
        ['RegisterDomain', ['regperiod' => 11]],
        ['RegisterDomain', ['tld' => 'com']],
        ['RegisterDomain', ['ns2' => '']],
        ['RegisterDomain', ['tld' => 'co.rs']],
        ['RenewDomain', ['regperiod' => 0]],
        ['RenewDomain', ['tld' => 'com']],
        ['TransferDomain', ['eppcode' => '']],
        ['SaveNameservers', ['ns1' => '', 'ns2' => '']],
        ['SaveRegistrarLock', ['lockenabled' => 'ambiguous']],
        ['SaveContactDetails', ['contactdetails' => []]],
        ['RegisterNameserver', ['nameserver' => 'bad host', 'ipaddress' => '192.0.2.1']],
        ['RegisterNameserver', ['nameserver' => 'ns.contract.rs', 'ipaddress' => '192.0.2.999']],
        ['ModifyNameserver', ['nameserver' => 'ns.contract.rs', 'newipaddress' => 'not-an-ip']],
        ['DeleteNameserver', ['nameserver' => '']],
    ];
    foreach ($invalidCases as $index => [$handler, $changes]) {
        $suite->test('handler/invalid/' . $mode . '/' . $handler . '/' . $index, static function () use ($handler, $changes, $mode): void {
            $result = ('rnids_' . $handler)(array_replace(baseParams(), ['testmode' => $mode], $changes));
            Suite::errorResponse($result);
            Suite::noRegistry();
        });
    }
}
foreach (['Email Address', 'City', 'Country'] as $field) {
    $suite->test('registration/fail-fast/missing-' . $field, static function () use ($field): void {
        $result = \rnids_RegisterDomain(array_replace(baseParams(), ['contactdetails' => ['Registrant' => array_replace(baseContact(), [$field => ''])]]));
        Suite::errorResponse($result);
        Suite::noRegistry();
    });
}
$suite->test('registration/fail-fast/missing-tech-handle', static function (): void {
    Suite::errorResponse(\rnids_RegisterDomain(array_replace(baseParams(), ['admin_id' => ''])));
    Suite::noRegistry();
});
$suite->test('registration/success-uses-new-contact-and-configured-tech', static function (): void {
    FakeRegistry::queue('host.info', ['ipv4' => [], 'ipv6' => []], ['ipv4' => [], 'ipv6' => []]);
    FakeRegistry::queue('contact.create', ['id' => 'NEW-REG']);
    FakeRegistry::queue('domain.register', []);
    Suite::same(['success' => true], \rnids_RegisterDomain(baseParams()));
    Suite::same(['host.info', 'host.info', 'contact.create', 'domain.register'], FakeRegistry::operations());
    Suite::same(['contract.rs', 'NEW-REG', 'NEW-REG', 'TECH-OFFLINE', ['ns1.example.invalid', 'ns2.example.invalid'], 1], FakeRegistry::$calls[3]['arguments']);
});
$suite->test('renew/success-period-forwarding', static function (): void {
    FakeRegistry::queue('domain.renew', []);
    Suite::same(['success' => true], \rnids_RenewDomain(array_replace(baseParams(), ['regperiod' => 10])));
    Suite::same(['contract.rs', 10], FakeRegistry::$calls[0]['arguments']);
});
$suite->test('transfer/success-auth-not-logged', static function (): void {
    FakeRegistry::queue('domain.transfer', []);
    Suite::same(['success' => true], \rnids_TransferDomain(array_replace(baseParams(), ['eppcode' => 'OFFLINE-AUTH-MARKER'])));
    Suite::same(['contract.rs', 'OFFLINE-AUTH-MARKER'], FakeRegistry::$calls[0]['arguments']);
    Suite::same(false, str_contains(json_encode(FakeRegistry::$logs), 'OFFLINE-AUTH-MARKER'));
});

foreach (['Registrant', 'Admin', 'Tech'] as $invalidRole) {
    $suite->test('contact-save/fail-fast-invalid-' . $invalidRole, static function () use ($invalidRole): void {
        $roles = array_fill_keys(['Registrant', 'Admin', 'Tech'], baseContact());
        $roles[$invalidRole]['Email Address'] = '';
        Suite::errorResponse(\rnids_SaveContactDetails(array_replace(baseParams(), ['contactdetails' => $roles])));
        Suite::noRegistry();
    });
}

foreach (['Registrant', 'Admin', 'Tech'] as $role) {
    $suite->test('contact-save/immutable/' . $role, static function () use ($role): void {
        scriptContacts();
        FakeRegistry::queue('contact.create', ['id' => 'NEW-' . $role]);
        FakeRegistry::queue('domain.update', []);
        $params = array_replace(baseParams(), ['contactdetails' => [$role => array_replace(baseContact(), ['City' => 'Novi Sad'])]]);
        Suite::same(['success' => true], \rnids_SaveContactDetails($params));
        Suite::same(['domain.info', 'contact.info', 'contact.info', 'contact.info', 'contact.create', 'domain.update'], FakeRegistry::operations());
        $update = FakeRegistry::$calls[5]['arguments'][0];
        if ($role === 'Registrant') {
            Suite::same(['name' => 'contract.rs', 'registrant' => 'NEW-Registrant'], $update);
        } else {
            Suite::same('NEW-' . $role, $update['add']['contacts'][0]['handle']);
            Suite::same($role === 'Admin' ? 'OLD-ADMIN' : 'OLD-TECH', $update['remove']['contacts'][0]['handle']);
            Suite::same(strtolower($role), $update['add']['contacts'][0]['type']);
        }
        Suite::same(false, in_array('contact.update', FakeRegistry::operations(), true));
    });
}
$suite->test('contact-save/unchanged-is-no-op', static function (): void {
    scriptContacts();
    Suite::same(['success' => true], \rnids_SaveContactDetails(baseParams()));
    Suite::same(['domain.info', 'contact.info', 'contact.info', 'contact.info'], FakeRegistry::operations());
});
$suite->test('contact-save/multiple-roles-send-registrant-separately-last', static function (): void {
    scriptContacts();
    FakeRegistry::queue('contact.create', ['id' => 'NEW-REG'], ['id' => 'NEW-ADMIN'], ['id' => 'NEW-TECH']);
    FakeRegistry::queue('domain.update', [], []);
    $roles = array_fill_keys(['Registrant', 'Admin', 'Tech'], array_replace(baseContact(), ['City' => 'Novi Sad']));
    Suite::same(['success' => true], \rnids_SaveContactDetails(array_replace(baseParams(), ['contactdetails' => $roles])));
    $updates = array_values(array_filter(FakeRegistry::$calls, static fn(array $call): bool => $call['operation'] === 'domain.update'));
    Suite::same(2, count($updates), 'RNIDS requires a standalone registrant change');
    Suite::same(['name', 'add', 'remove'], array_keys($updates[0]['arguments'][0]));
    Suite::same(['name' => 'contract.rs', 'registrant' => 'NEW-REG'], $updates[1]['arguments'][0]);
});
$suite->test('contact-save/address-two-change-creates-new-contact', static function (): void {
    scriptContacts();
    FakeRegistry::queue('contact.create', ['id' => 'NEW-ADDRESS']);
    FakeRegistry::queue('domain.update', []);
    Suite::same(['success' => true], \rnids_SaveContactDetails(array_replace(baseParams(), [
        'contactdetails' => ['Registrant' => array_replace(baseContact(), ['Address 2' => 'Floor 2'])],
    ])));
    Suite::same(true, in_array('contact.create', FakeRegistry::operations(), true), 'Changed address must be persisted');
});

foreach (['192.0.2.1' => 'v4', '2001:db8::1' => 'v6'] as $ip => $version) {
    $suite->test('host/register-single/' . $version, static function () use ($ip, $version): void {
        FakeRegistry::queue('host.info', registryError(2303));
        FakeRegistry::queue('host.create', []);
        Suite::same(['success' => true], \rnids_RegisterNameserver(['nameserver' => ' NS.CONTRACT.RS. ', 'ipaddress' => $ip]));
        Suite::same(['host.info', 'host.create'], FakeRegistry::operations());
        Suite::same(['name' => 'ns.contract.rs', 'addresses' => [['address' => $ip, 'ipVersion' => $version]]], FakeRegistry::$calls[1]['arguments'][0]);
    });
}
$suite->test('host/register-existing-exact-is-idempotent', static function (): void {
    FakeRegistry::queue('host.info', ['ipv4' => ['192.0.2.1'], 'ipv6' => []]);
    Suite::same(['success' => true], \rnids_RegisterNameserver(['nameserver' => 'ns.contract.rs', 'ipaddress' => '192.0.2.1']));
    Suite::same(['host.info'], FakeRegistry::operations());
});
$suite->test('host/register-existing-extra-ip-is-conflict', static function (): void {
    FakeRegistry::queue('host.info', ['ipv4' => ['192.0.2.1', '192.0.2.2'], 'ipv6' => []]);
    Suite::errorResponse(\rnids_RegisterNameserver(['nameserver' => 'ns.contract.rs', 'ipaddress' => '192.0.2.1']));
    Suite::same(['host.info'], FakeRegistry::operations());
});
$suite->test('host/register-existing-different-ip-never-calls-registry-upsert', static function (): void {
    $stored = ['ipv4' => ['192.0.2.1'], 'ipv6' => []];
    FakeRegistry::queue('host.info', static function () use (&$stored): array { return $stored; });
    // RNIDS dev accepts create on an existing host and replaces its glue. A
    // successful create response must not make a conflicting retry destructive.
    FakeRegistry::queue('host.create', static function (array $payload) use (&$stored): array {
        $stored = ['ipv4' => [$payload['addresses'][0]['address']], 'ipv6' => []];
        return [];
    });
    $response = \rnids_RegisterNameserver(['nameserver' => 'ns.contract.rs', 'ipaddress' => '192.0.2.2']);
    Suite::same(['ipv4' => ['192.0.2.1'], 'ipv6' => []], $stored, 'A registration retry must not change existing glue');
    Suite::errorResponse($response);
    Suite::same(['host.info'], FakeRegistry::operations());
});
$suite->test('host/register-existing-equivalent-ipv6-is-idempotent', static function (): void {
    FakeRegistry::queue('host.info', ['ipv4' => [], 'ipv6' => ['2001:db8::1']]);
    Suite::same(['success' => true], \rnids_RegisterNameserver(['nameserver' => 'ns.contract.rs', 'ipaddress' => '2001:0DB8:0000:0000:0000:0000:0000:0001']));
    Suite::same(['host.info'], FakeRegistry::operations());
});
foreach ([true, false] as $sameGlue) {
    $suite->test('host/register-create-race/' . ($sameGlue ? 'matching' : 'conflicting'), static function () use ($sameGlue): void {
        FakeRegistry::queue('host.info', registryError(2303), ['ipv4' => [$sameGlue ? '192.0.2.1' : '192.0.2.2'], 'ipv6' => []]);
        FakeRegistry::queue('host.create', registryError(2302));
        $response = \rnids_RegisterNameserver(['nameserver' => 'ns.contract.rs', 'ipaddress' => '192.0.2.1']);
        if ($sameGlue) {
            Suite::same(['success' => true], $response);
        } else {
            Suite::errorResponse($response);
        }
        Suite::same(['host.info', 'host.create', 'host.info'], FakeRegistry::operations());
    });
}
$suite->test('host/register-info-failure-never-creates', static function (): void {
    FakeRegistry::queue('host.info', registryError(2201));
    FakeRegistry::queue('host.create', []);
    Suite::errorResponse(\rnids_RegisterNameserver(['nameserver' => 'ns.contract.rs', 'ipaddress' => '192.0.2.1']));
    Suite::same(['host.info'], FakeRegistry::operations());
});
$suite->test('host/modify-replaces-full-address-set', static function (): void {
    FakeRegistry::queue('host.info', ['ipv4' => ['192.0.2.1', '192.0.2.2'], 'ipv6' => ['2001:db8::1']]);
    FakeRegistry::queue('host.update', []);
    Suite::same(['success' => true], \rnids_ModifyNameserver(['nameserver' => 'ns.contract.rs', 'currentipaddress' => '192.0.2.1', 'newipaddress' => '192.0.2.3']));
    $payload = FakeRegistry::$calls[1]['arguments'][0];
    Suite::same([['address' => '192.0.2.3', 'ipVersion' => 'v4']], $payload['add']['addresses']);
    Suite::same(3, count($payload['remove']['addresses']));
});
$suite->test('host/delete-missing-is-idempotent', static function (): void {
    FakeRegistry::queue('host.delete', registryError(2303));
    Suite::same(['success' => true], \rnids_DeleteNameserver(['nameserver' => 'ns.contract.rs']));
});
$suite->test('host/lookup-reuses-existing-glue', static function (): void {
    FakeRegistry::queue('host.check', [['name' => 'ns.contract.rs', 'available' => false]]);
    FakeRegistry::queue('host.info', ['ipv4' => ['192.0.2.1', '192.0.2.1'], 'ipv6' => ['2001:db8::1']]);
    Suite::same(['ipv4' => ['192.0.2.1'], 'ipv6' => ['2001:db8::1']], (new HostService())->findExistingHostAddresses(new FakeClient(), 'ns.contract.rs'));
    Suite::same(['host.check', 'host.info'], FakeRegistry::operations());
});
$suite->test('nameservers/save-unknown-hosts-looked-up-before-update', static function (): void {
    FakeRegistry::queue('host.check', [['available' => false]], [['available' => false]]);
    FakeRegistry::queue('host.info', ['ipv4' => ['192.0.2.1'], 'ipv6' => []], ['ipv4' => ['192.0.2.2'], 'ipv6' => []],
        ['ipv4' => ['192.0.2.1'], 'ipv6' => []], ['ipv4' => ['192.0.2.2'], 'ipv6' => []]);
    FakeRegistry::queue('domain.info', domainInfo());
    Suite::same(['success' => true], \rnids_SaveNameservers(baseParams()));
    Suite::same(['host.check', 'host.info', 'host.check', 'host.info', 'host.info', 'host.info', 'domain.info'], FakeRegistry::operations());
});
$suite->test('nameservers/structured-update', static function (): void {
    FakeRegistry::queue('domain.update', []);
    (new DomainNameserverUpdater())->rawDomainNameserverUpdate(new FakeClient(), 'contract.rs', ['ns3.example.invalid'], ['ns1.example.invalid'], []);
    Suite::same(['domain.update'], FakeRegistry::operations());
    Suite::same(['name' => 'contract.rs',
        'add' => ['nameservers' => [['name' => 'ns3.example.invalid']]],
        'remove' => ['nameservers' => [['name' => 'ns1.example.invalid']]],
    ], FakeRegistry::$calls[0]['arguments'][0]);
});

foreach (['active' => [[], false], 'date-expired' => [['expirationDate' => new DateTimeImmutable('2000-01-01')], true],
    'status-expired' => [['statuses' => ['expired']], true], 'redemption' => [['statuses' => ['redemptionPeriod']], true]] as $name => [$changes, $expired]) {
    $suite->test('sync/' . $name, static function () use ($changes, $expired): void {
        FakeRegistry::queue('domain.info', domainInfo($changes));
        $result = \rnids_Sync(baseParams());
        Suite::same(!$expired, $result['active']);
        Suite::same($expired, $result['expired']);
        Suite::same(false, $result['transferredAway']);
        Suite::same(true, is_string($result['expirydate']));
    });
}
$suite->test('sync/missing-domain-is-transferred-away', static function (): void {
    FakeRegistry::queue('domain.info', registryError(2303));
    Suite::same(['active' => false, 'expired' => false, 'transferredAway' => true], \rnids_Sync(baseParams()));
});
$suite->test('sync/authorization-denied-is-transferred-away', static function (): void {
    FakeRegistry::queue('domain.info', registryError(2201));
    Suite::same(['active' => false, 'expired' => false, 'transferredAway' => true], \rnids_Sync(baseParams()));
});
$suite->test('transfer-sync/pending-is-not-completed', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo(['statuses' => ['pendingTransfer']]));
    $result = \rnids_TransferSync(baseParams());
    Suite::same(false, $result['completed'] ?? false, 'Pending transfer must not be marked completed');
});
$suite->test('transfer-sync/completed', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo());
    Suite::same(['completed' => true, 'expirydate' => '2099-01-02'], \rnids_TransferSync(baseParams()));
});
foreach (['clientTransferProhibited', 'serverTransferProhibited', 'clientUpdateProhibited', 'serverUpdateProhibited'] as $status) {
    $suite->test('lock/recognized/' . $status, static function () use ($status): void {
        FakeRegistry::queue('domain.info', domainInfo(['statuses' => [$status]]));
        Suite::same(['lockenabled' => 'locked'], \rnids_GetRegistrarLock(baseParams()));
    });
}
$suite->test('lock/enable', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo());
    FakeRegistry::queue('domain.update', []);
    Suite::same(['success' => true], \rnids_SaveRegistrarLock(array_replace(baseParams(), ['lockenabled' => 'locked'])));
    Suite::same(['name' => 'contract.rs', 'add' => ['statuses' => ['clientTransferProhibited']]], FakeRegistry::$calls[1]['arguments'][0]);
});
$suite->test('lock/unlock-removes-client-locks', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo(['statuses' => ['clientTransferProhibited', 'clientUpdateProhibited']]));
    FakeRegistry::queue('domain.update', []);
    Suite::same(['success' => true], \rnids_SaveRegistrarLock(array_replace(baseParams(), ['lockenabled' => 'unlocked'])));
    Suite::same(['name' => 'contract.rs', 'remove' => ['statuses' => ['clientTransferProhibited', 'clientUpdateProhibited']]], FakeRegistry::$calls[1]['arguments'][0]);
});
$suite->test('lock/server-lock-cannot-report-successful-unlock', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo(['statuses' => ['serverTransferProhibited']]));
    Suite::errorResponse(\rnids_SaveRegistrarLock(array_replace(baseParams(), ['lockenabled' => 'unlocked'])));
});
$suite->test('epp-code/success-only-and-redacted-response', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo());
    FakeRegistry::queue('domain.getCode', ['authCode' => 'OFFLINE-AUTH-MARKER']);
    Suite::same(['success' => true], \rnids_GetEPPCode(baseParams()));
    Suite::same(false, str_contains(json_encode(FakeRegistry::$logs), 'OFFLINE-AUTH-MARKER'));
});
$suite->test('epp-code/pending-transfer-prevents-repeat-request', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo(['statuses' => ['pendingTransfer']]));
    Suite::errorResponse(\rnids_GetEPPCode(baseParams()));
    Suite::same(['domain.info'], FakeRegistry::operations());
});
$suite->test('availability/input-fallbacks', static function (): void {
    $service = new AvailabilityLookupService();
    Suite::same(['sld' => 'example', 'tlds' => ['rs', 'co.rs']], $service->normalizeAvailabilityInput(['searchTerm' => '.EXAMPLE.', 'tldsToInclude' => ['.RS', 'co.rs']]));
    Suite::same(null, $service->normalizeAvailabilityInput([]));
});
$suite->test('availability/registered-available-and-unsupported', static function (): void {
    FakeRegistry::queue('domain.check', [['name' => 'contract.rs', 'available' => true], ['name' => 'contract.co.rs', 'available' => false]]);
    $results = \rnids_CheckAvailability(['sld' => 'contract', 'tlds' => ['rs', 'co.rs', 'com']]);
    Suite::same(['available', 'registered', 'unsupported'], array_map(static fn(FakeSearchResult $result): string => $result->status, $results->getArrayCopy()));
    Suite::same([['contract.rs', 'contract.co.rs']], FakeRegistry::$calls[0]['arguments']);
});
$suite->test('availability/empty-input-does-not-connect', static function (): void {
    Suite::same(0, \rnids_CheckAvailability([])->count());
    Suite::same([], FakeRegistry::$accesses);
});
$suite->test('availability/unsupported-tld-does-not-connect', static function (): void {
    $result = \rnids_CheckAvailability(['sld' => 'contract', 'tlds' => ['com']]);
    Suite::same('unsupported', $result[0]->status);
    Suite::same([], FakeRegistry::$accesses);
});
$suite->test('availability/idn-punycode-response-matches-unicode-request', static function (): void {
    $ascii = idn_to_ascii('пример.срб', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    $asciiTld = explode('.', $ascii)[1];
    FakeRegistry::queue('domain.check', [['name' => $ascii, 'available' => true]]);
    $result = \rnids_CheckAvailability(['sld' => 'ПРИМЕР', 'tlds' => [$asciiTld]]);
    Suite::same(1, $result->count());
    Suite::same('available', $result[0]->status);
    Suite::same('пример', $result[0]->getSecondLevel());
    Suite::same('срб', $result[0]->getTopLevel());
    Suite::same([['пример.срб']], FakeRegistry::$calls[0]['arguments']);
});
$suite->test('domain-information/maps-whmcs-domain-object', static function (): void {
    FakeRegistry::queue('domain.info', domainInfo(['statuses' => ['clientTransferProhibited']]));
    $domain = \rnids_GetDomainInformation(baseParams());
    Suite::truth($domain instanceof FakeDomain, 'Expected WHMCS Domain boundary object');
    Suite::same('contract.rs', $domain->values['setDomain']);
    Suite::same(true, $domain->values['setTransferLock']);
    Suite::same(['ns1' => 'ns1.example.invalid', 'ns2' => 'ns2.example.invalid'], $domain->values['setNameservers']);
});
foreach (['RenewDomain', 'TransferDomain', 'Sync', 'TransferSync', 'GetDomainInformation', 'GetNameservers', 'GetRegistrarLock', 'GetContactDetails', 'GetEPPCode'] as $handler) {
    $suite->test('handler/registry-error/' . $handler, static function () use ($handler): void {
        $operation = match ($handler) {
            'RenewDomain' => 'domain.renew',
            'TransferDomain' => 'domain.transfer',
            default => 'domain.info',
        };
        FakeRegistry::queue($operation, registryError(2400));
        Suite::errorResponse(('rnids_' . $handler)(array_replace(baseParams(), ['eppcode' => 'OFFLINE-AUTH'])));
    });
}
$suite->test('config-validation/closed-client-not-reused', static function (): void {
    FakeRegistry::queue('client.responseMeta', ['resultCode' => 1000]);
    \rnids_config_validate(baseParams());
    FakeRegistry::queue('domain.renew', []);
    Suite::same(['success' => true], \rnids_RenewDomain(baseParams()));
});
$suite->test('config-validation/redacts-echoed-secret', static function (): void {
    $secret = 'OFFLINE-CONFIG-PASSWORD';
    FakeRegistry::queue('client.responseMeta', ['resultCode' => 1000, 'message' => 'password=' . $secret]);
    \rnids_config_validate(array_replace(baseParams(), ['epp_password' => $secret]));
    Suite::same(false, str_contains(json_encode(FakeRegistry::$logs), $secret));
});
$suite->test('config-validation/sanitizes-exception', static function (): void {
    $secret = 'OFFLINE-CONFIG-EXCEPTION';
    FakeRegistry::queue('client.responseMeta', new RuntimeException('password=' . $secret));
    try {
        \rnids_config_validate(baseParams());
        throw new RuntimeException('Expected configuration failure');
    } catch (FakeInvalidConfiguration $exception) {
        Suite::same(false, str_contains($exception->getMessage(), $secret));
        Suite::same(false, str_contains(json_encode(FakeRegistry::$logs), $secret));
    }
});

$suite->finish();
