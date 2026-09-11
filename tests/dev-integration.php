<?php

declare(strict_types=1);

// Run only in dev WHMCS: php tests/dev-integration.php --allow-mutations [--report=/tmp/new.json]
// This creates disposable registry objects. The report is a cleanup ledger, not a credential dump.
// Exit codes: 0 = scoped tests and cleanup complete, 1 = failure/unknown resources,
// 2 = CLI guard rejection, 3 = scoped checks passed with registry approval or cleanup pending.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['allow-mutations', 'report:']);
if (!isset($options['allow-mutations']) || count($options) > 2) {
    fwrite(STDERR, "Usage: php tests/dev-integration.php --allow-mutations [--report=/tmp/new.json]\n");
    exit(2);
}

use Oblak\WHMCS\RSREG\Nameserver\KnownHostRepository;
use Oblak\WHMCS\RSREG\Model\ContactNormalizer;
use Oblak\WHMCS\RSREG\Registrar;
use Oblak\WHMCS\RSREG\Support\ClientFactory;
use Oblak\WHMCS\RSREG\Support\ModuleLogger;
use RNIDS\Exception\ObjectMissing;
use RNIDS\Exception\ProtocolException;
use WHMCS\Database\Capsule;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Domains\DomainLookup\SearchResult;

$token = 'whmcs-' . gmdate('ymdHis') . '-' . bin2hex(random_bytes(4));
$path = $options['report'] ?? sys_get_temp_dir() . '/rnids-' . $token . '.json';
$tmp = realpath(sys_get_temp_dir());
$directory = is_string($path) ? realpath(dirname($path)) : false;
if ($directory === false || ($directory !== $tmp && !str_starts_with($directory, $tmp . '/'))) {
    fwrite(STDERR, "Report must be a new file beneath the temporary directory.\n");
    exit(2);
}
umask(0077);
$reportFile = @fopen($path, 'x');
if ($reportFile === false) {
    fwrite(STDERR, "Cannot create report file; existing files are never overwritten.\n");
    exit(2);
}
$report = ['run' => $token, 'php' => PHP_VERSION, 'endpoint' => 'epp-test.rnids.rs:700', 'state' => 'running',
    'checks' => [], 'owned' => ['domains' => [], 'hosts' => [], 'contacts' => []], 'possible_orphans' => [],
    'cleanup_pending' => ['domains' => [], 'contacts' => []],
    'workflow_pending' => [], 'contact_events' => [],
    'unexecuted' => ['Full registrar transfer requires a second registrar fixture.', 'Registrant approval completion (M161/M162/M163) requires a separate approval fixture.', 'Notification, IRTP email, GetEPPCode and poll acknowledgement are excluded.']];
$save = static function () use (&$report, $reportFile): void {
    rewind($reportFile);
    ftruncate($reportFile, 0);
    fwrite($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fflush($reportFile);
};
$save();
register_shutdown_function(static function () use (&$report, $save): void {
    if ($report['state'] === 'running') {
        $report['state'] = 'interrupted';
        $report['unexecuted'][] = 'Process interrupted; inspect the ownership ledger before rerunning. Cleanup is not confirmed.';
        $save();
    }
});

final class IntegrationAssertion extends RuntimeException {}
final class IntegrationPending extends RuntimeException {}
function expectIntegration(bool $condition, string $assertion): void
{
    if (!$condition) {
        throw new IntegrationAssertion($assertion);
    }
}
function callHandler(string $handler, array $params): mixed
{
    $result = ('rnids_' . $handler)($params);
    if (is_array($result) && isset($result['error'])) {
        // Production handlers return wording controlled by ErrorMessageFormatter.
        throw new IntegrationAssertion($handler . ': ' . (string) $result['error']);
    }
    return $result;
}
function addressSet(array $info): array
{
    $ips = array_map(static fn(string $ip): string => (string) inet_pton($ip), array_merge($info['ipv4'] ?? [], $info['ipv6'] ?? []));
    sort($ips);
    return $ips;
}
function contactFingerprint(array $info): string
{
    // Association statuses can change as a contact becomes unlinked; contact fields must not.
    return hash('sha256', serialize(array_intersect_key($info, array_flip(['postalInfo', 'email', 'voice', 'fax', 'ident', 'identDescription', 'identExpiry', 'identKind', 'legalEntity', 'vatNo', 'disclose', 'extension']))));
}
function fixtureGlue(array $info): array
{
    $result = [];
    foreach (['ipv4', 'ipv6'] as $family) {
        $result[$family] = array_values(array_unique($info[$family] ?? []));
        sort($result[$family]);
    }
    return $result;
}
function canonicalDomain(string $name): string
{
    return mb_strtolower(function_exists('idn_to_utf8') ? (idn_to_utf8($name) ?: $name) : $name);
}
$run = static function (string $name, callable $test) use (&$report, $save): bool {
    try {
        $test();
        $report['checks'][$name] = ['status' => 'pass'];
    } catch (IntegrationPending $pending) {
        $report['checks'][$name] = ['status' => 'pending', 'reason' => $pending->getMessage()];
    } catch (Throwable $error) {
        $report['checks'][$name] = ['status' => 'fail', 'diagnostics' => class_exists(ModuleLogger::class)
            ? ModuleLogger::exceptionContext($error) : ['exception' => get_class($error), 'code' => $error->getCode()]];
        if ($error instanceof IntegrationAssertion) {
            $report['checks'][$name]['assertion'] = $error->getMessage();
        }
    }
    $save();
    echo strtoupper($report['checks'][$name]['status']), ' ', $name, PHP_EOL;
    return $report['checks'][$name]['status'] === 'pass';
};

$client = null;
$params = [];
$moduleRoot = dirname(__DIR__);
try {
    // Suppress bootstrap output; the structured report is the only diagnostic channel.
    ob_start();
    try {
        require $moduleRoot . '/vendor/autoload.php';
        require dirname($moduleRoot, 3) . '/init.php';
        require_once $moduleRoot . '/rnids.php';
    } finally {
        ob_end_clean();
    }
    set_error_handler(static function (int $severity): bool {
        if ((error_reporting() & $severity) !== 0) {
            throw new RuntimeException('PHP warning during integration');
        }
        return true;
    });
    $settings = ['testmode', 'epp_username', 'epp_password', 'epp_certificate', 'epp_certificate_password', 'epp_ca', 'admin_id'];
    foreach (Capsule::table('tblregistrars')->where('registrar', 'rnids')->whereIn('setting', $settings)->get() as $row) {
        $params[$row->setting] = decrypt($row->value);
    }
    $config = ClientFactory::buildClientParams($params);
    expectIntegration(($params['testmode'] ?? '') === 'on' && $config['host'] === 'epp-test.rnids.rs' && $config['port'] === 700, 'Saved settings must select the RNIDS test endpoint.');
    expectIntegration(trim($params['admin_id'] ?? '') !== '', 'A configured existing technical contact is required.');
    ModuleLogger::logModuleCall('IntegrationLogProbe', null, json_encode(['probe' => $token], JSON_THROW_ON_ERROR));
    $probe = Capsule::table('tblmodulelog')->where('module', 'rnids')->where('action', 'IntegrationLogProbe')->orderByDesc('id')->first(['response']);
    expectIntegration((json_decode($probe->response ?? '', true)['probe'] ?? '') === $token, 'WHMCS module logging must be enabled and observable before creating contacts.');
    $client = Registrar::fromParams($params)->client();
    $adminFingerprint = contactFingerprint($client->contact()->info($params['admin_id']));
    $dist = require $moduleRoot . '/dist.knownhosts.php';
    $known = (new KnownHostRepository($moduleRoot))->loadKnownHosts();
    foreach (['ns1.oblak.host', 'ns2.oblak.host'] as $host) {
        $expected = ['ipv4' => (array) $dist[$host]['ip4'], 'ipv6' => (array) $dist[$host]['ip6']];
        // The module compares literal IP strings; canonical equivalence is insufficient here.
        expectIntegration(fixtureGlue($known[$host] ?? []) === fixtureGlue($expected), 'Active known-host configuration must match distributed fixture glue.');
        expectIntegration(fixtureGlue($client->host()->info($host)) === fixtureGlue($expected), 'Registry nameserver glue must match distributed fixture glue before any wrapper can synchronize it.');
    }
    $report['checks']['fixture-preconditions'] = ['status' => 'pass'];
    $save();
    $logCursor = static fn(): int => (int) Capsule::table('tblmodulelog')->where('module', 'rnids')->max('id');
    $captureCreated = static function (int $cursor, string $domain, ?string $expectedEmail = null) use ($client, &$report, $params, $token, $save): array {
        $ids = [];
        foreach (Capsule::table('tblmodulelog')->where('module', 'rnids')->where('action', 'ContactCreated')->where('id', '>', $cursor)->orderBy('id')->get(['id', 'response']) as $row) {
            $event = json_decode($row->response, true);
            $id = $event['contactId'] ?? null;
            if (!is_string($id) || $id === '' || $id === $params['admin_id']) {
                continue;
            }
            $info = $client->contact()->info($id);
            $email = (string) ($info['email'] ?? '');
            if (!str_starts_with($email, $token . '-') || !str_ends_with($email, '@example.invalid')) {
                continue;
            }
            $report['owned']['contacts'][$id] ??= 'present';
            $report['contact_events'][(string) $row->id] = ['domain' => $domain, 'contactId' => $id, 'role' => $event['role'] ?? ''];
            if ($expectedEmail === null || $email === $expectedEmail) {
                $ids[] = $id;
            }
        }
        $save();
        return array_values(array_unique($ids));
    };
    $capture = static function (string $domain) use ($client, &$report, $params, $token, $save): array {
        $info = $client->domain()->info($domain);
        $state = $report['owned']['domains'][$domain] ?? '';
        expectIntegration(in_array($state, ['registration-attempted', 'registration-acknowledged', 'present'], true)
            && canonicalDomain((string) ($info['name'] ?? '')) === canonicalDomain($domain), 'Ownership requires a recorded unique candidate and matching returned domain name.');
        $registrant = $client->contact()->info((string) ($info['registrant'] ?? ''));
        $ownsEmail = static fn(array $contact): bool => str_starts_with((string) ($contact['email'] ?? ''), $token . '-')
            && str_ends_with((string) ($contact['email'] ?? ''), '@example.invalid');
        expectIntegration(in_array($state, ['registration-acknowledged', 'present'], true) || $ownsEmail($registrant), 'An ambiguous registration needs this run\'s unique registrant marker before cleanup ownership is claimed.');
        $report['owned']['domains'][$domain] = 'present';
        foreach (['registrant', 'adminContact', 'techContact'] as $key) {
            $id = (string) ($info[$key] ?? '');
            if ($id === '' || $id === $params['admin_id']) {
                continue;
            }
            $contact = $key === 'registrant' ? $registrant : $client->contact()->info($id);
            if ($ownsEmail($contact)) {
                $report['owned']['contacts'][$id] ??= 'present';
            } elseif (!isset($report['owned']['contacts'][$id])) {
                $report['possible_orphans'][] = ['domain' => $domain, 'contact' => $id, 'reason' => 'An assigned contact has no ownership marker and will not be deleted.'];
            }
        }
        $save();
        return $info;
    };
    $availability = static function (array $p, bool $available): void {
        $results = callHandler('CheckAvailability', $p + ['tldsToInclude' => [$p['tld']]]);
        expectIntegration(count($results) === 1, 'Availability must return exactly the requested domain.');
        $result = $results->offsetGet(0);
        expectIntegration($result->getStatus() === ($available ? SearchResult::STATUS_NOT_REGISTERED : SearchResult::STATUS_REGISTERED), 'Availability status must match registry state.');
    };
    $profiles = [['rs', $token], ['co.rs', $token]];
    if (function_exists('idn_to_ascii')) {
        $profiles[] = ['срб', 'тест-' . preg_replace('/[^0-9]/', '', $token)];
    } else {
        $report['unexecuted'][] = 'Cyrillic registration requires the PHP intl extension.';
    }
    foreach ($profiles as [$tld, $sld]) {
        $domain = $sld . '.' . $tld;
        $p = $params + ['sld' => $sld, 'tld' => $tld, 'regperiod' => 1, 'ns1' => 'ns1.oblak.host', 'ns2' => 'ns2.oblak.host'];
        $contact = ['First Name' => 'Synthetic', 'Last Name' => 'Registry Test', 'Company Name' => '', 'Company Number' => '', 'Tax Number' => '',
            'Address 1' => 'Test Street 1', 'Address 2' => '', 'City' => 'Belgrade', 'State' => 'BG', 'Postcode' => '11000',
            'Country' => 'RS', 'Email Address' => $token . '-' . str_replace('.', '-', $tld === 'срб' ? 'idn' : $tld) . '@example.invalid', 'Phone Number' => '+381.111111'];
        if ($tld === 'co.rs') {
            $contact = array_replace($contact, ['First Name' => '', 'Last Name' => '', 'Company Name' => 'Synthetic RNIDS Test Company', 'Company Number' => '00000000', 'Tax Number' => '000000000']);
        }
        if (!$run($domain . '/available-before', static fn() => $availability($p, true))) {
            continue;
        }
        $cursor = $logCursor();
        $createdIds = [];
        $registered = $run($domain . '/register', static function () use ($p, $contact, $tld, $domain, &$report, $save): void {
            $report['owned']['domains'][$domain] = 'registration-attempted';
            $save();
            $result = callHandler('RegisterDomain', $p + ['contactdetails' => ['Registrant' => $contact], 'additionalfields' => ['Registrant Type' => $tld === 'co.rs' ? 'Company' : 'Individual']]);
            expectIntegration(($result['success'] ?? false) === true, 'Registration must report WHMCS success.');
            $report['owned']['domains'][$domain] = 'registration-acknowledged';
        });
        $run($domain . '/capture-registration-contact', static function () use ($captureCreated, $cursor, $domain, &$createdIds): void {
            $createdIds = $captureCreated($cursor, $domain);
        });
        $discovered = $run($domain . '/discover-created-objects', static fn() => $capture($domain));
        if (!$registered || !$discovered) {
            if (!$discovered && $createdIds === []) {
                $report['possible_orphans'][] = ['domain' => $domain, 'operation' => 'registration', 'reason' => 'An unassigned contact may have been created; its handle is not observable through the handler.'];
            }
            $save();
            continue;
        }
        $run($domain . '/available-after', static fn() => $availability($p, false));
        $run($domain . '/read-handlers', static function () use ($p, $client, $domain): void {
            $object = callHandler('GetDomainInformation', $p);
            expectIntegration($object instanceof Domain, 'Domain information must return the WHMCS domain object.');
            $name = $object->getDomain();
            expectIntegration(canonicalDomain($name) === canonicalDomain($domain), 'Domain information must identify the requested domain.');
            $nameservers = array_values(callHandler('GetNameservers', $p));
            sort($nameservers);
            expectIntegration($nameservers === ['ns1.oblak.host', 'ns2.oblak.host'], 'Registered nameservers must match requested fixtures.');
            $contacts = callHandler('GetContactDetails', $p);
            expectIntegration(isset($contacts['Registrant']['Email Address'], $contacts['Admin']['Email Address'], $contacts['Tech']['Email Address']), 'All contact roles must have normalized WHMCS fields.');
            $expiry = $client->domain()->info($domain)['expirationDate']->format('Y-m-d');
            $sync = callHandler('Sync', $p);
            expectIntegration(($sync['active'] ?? false) === true && ($sync['expirydate'] ?? '') === $expiry, 'Sync must report this owned domain active with its actual expiry.');
            expectIntegration($object->getExpiryDate()?->format('Y-m-d') === $expiry, 'Domain information expiry must match the registry.');
            $transferSync = callHandler('TransferSync', $p);
            expectIntegration(($transferSync['completed'] ?? false) === true && ($transferSync['expirydate'] ?? '') === $expiry, 'TransferSync read contract must preserve expiry; this is not an actual transfer test.');
        });
        $run($domain . '/lock-roundtrip', static function () use ($p): void {
            foreach (['locked', 'unlocked'] as $state) {
                callHandler('SaveRegistrarLock', $p + ['lockenabled' => $state]);
                expectIntegration((callHandler('GetRegistrarLock', $p)['lockenabled'] ?? '') === $state, 'Lock state must roundtrip through WHMCS.');
            }
        });
        foreach (['Registrant' => 'registrant', 'Admin' => 'adminContact', 'Tech' => 'techContact'] as $role => $key) {
            $attempted = $observed = false;
            $oldId = null;
            $changed = $run($domain . '/change-' . strtolower($role), static function () use ($p, $client, $capture, $captureCreated, $logCursor, $run, $domain, $role, $key, $contact, $token, &$attempted, &$observed, &$oldId, &$report): void {
                $before = $capture($domain);
                $oldId = $before[$key];
                $fingerprint = contactFingerprint($client->contact()->info($oldId));
                $details = array_replace($contact, ['Email Address' => $token . '-' . strtolower($role) . '-' . bin2hex(random_bytes(3)) . '@example.invalid']);
                $cursor = $logCursor();
                $attempted = true;
                $operationError = null;
                try { callHandler('SaveContactDetails', $p + ['contactdetails' => [$role => $details]]); } catch (Throwable $error) { $operationError = $error; }
                $targets = [];
                $eventsCaptured = $run($domain . '/capture-created-' . strtolower($role), static function () use ($captureCreated, $cursor, $domain, $details, &$targets): void {
                    $targets = $captureCreated($cursor, $domain, $details['Email Address']);
                });
                $observed = $targets !== [];
                if ($role === 'Registrant' && $targets !== []) {
                    // Retain observed targets even if the command or subsequent status read
                    // fails: an approval request may already exist at the registry.
                    $report['workflow_pending'][$domain] = ['operation' => 'registrant-change', 'status' => 'unverified', 'old_contact' => $oldId, 'target_contacts' => $targets];
                    foreach ($targets as $target) {
                        $report['owned']['contacts'][$target] = 'unverified-registrant-change';
                    }
                }
                if ($operationError !== null) {
                    throw $operationError;
                }
                expectIntegration($eventsCaptured && count($targets) === 1, 'Exactly one newly created contact handle must be observable for a changed role.');
                $after = $capture($domain);
                $pending = $role === 'Registrant' && $after[$key] === $oldId && in_array('pendingupdate', array_map('strtolower', $after['statuses'] ?? []), true);
                if ($pending) {
                    $report['workflow_pending'][$domain] = ['operation' => 'registrant-change', 'status' => 'pendingUpdate', 'old_contact' => $oldId, 'target_contact' => $targets[0]];
                    $report['owned']['contacts'][$targets[0]] = 'pending-registrant-change';
                } elseif ($role === 'Registrant' && $after[$key] === $targets[0]) {
                    unset($report['workflow_pending'][$domain]);
                    $report['owned']['contacts'][$targets[0]] = 'present';
                }
                expectIntegration($pending || $after[$key] === $targets[0], 'An immediate contact reassignment must use the newly created handle.');
                expectIntegration(contactFingerprint($client->contact()->info($oldId)) === $fingerprint, 'The previously assigned contact fields must remain immutable.');
                foreach (['registrant', 'adminContact', 'techContact'] as $other) {
                    expectIntegration($other === $key || $before[$other] === $after[$other], 'Unchanged contact roles must retain their handles.');
                }
                $actual = $pending ? ContactNormalizer::normalizeSingleForWhmcs($client->contact()->info($targets[0]), $role) : callHandler('GetContactDetails', $p)[$role];
                foreach ($details as $field => $expected) {
                    expectIntegration(trim($actual[$field] ?? '') === trim($expected), 'Updated contact fields must roundtrip through WHMCS.');
                }
                if ($pending) {
                    throw new IntegrationPending('Registrant change was accepted and pendingUpdate is visible; approval completion is unexecuted and its target contact is retained.');
                }
            });
            if (!$changed && $attempted && !$observed) {
                $run($domain . '/discover-after-failed-' . strtolower($role), static function () use ($capture, $domain, $key, $oldId, &$observed, &$report): void {
                    $after = $capture($domain);
                    $observed = $after[$key] !== $oldId && isset($report['owned']['contacts'][$after[$key]]);
                });
                if (!$observed) {
                    $report['possible_orphans'][] = ['domain' => $domain, 'operation' => 'contact-change-' . strtolower($role), 'reason' => 'A newly created but unassigned contact cannot be discovered after an update failure.'];
                    $save();
                }
            }
        }
        $run($domain . '/contact-noop', static function () use ($p, $capture, $domain): void {
            $before = $capture($domain);
            callHandler('SaveContactDetails', $p + ['contactdetails' => callHandler('GetContactDetails', $p)]);
            $after = $capture($domain);
            foreach (['registrant', 'adminContact', 'techContact'] as $key) {
                expectIntegration($before[$key] === $after[$key], 'An unchanged contact submission must retain every handle.');
            }
        });
        if ($tld === 'rs') {
            $run($domain . '/child-host-lifecycle', static function () use ($client, $p, $domain, &$report, $save): void {
                $host = 'ns.' . $domain;
                expectIntegration(($client->host()->check($host)[0]['available'] ?? false) === true, 'Disposable child host must not already exist.');
                $report['owned']['hosts'][$host] = 'creation-attempted';
                $save();
                $hp = $p + ['nameserver' => $host, 'ipaddress' => '192.0.2.1'];
                callHandler('RegisterNameserver', $hp);
                callHandler('RegisterNameserver', $hp);
                expectIntegration(addressSet($client->host()->info($host)) === addressSet(['ipv4' => ['192.0.2.1']]), 'A register retry must preserve the requested single IP.');
                $mismatch = rnids_RegisterNameserver(array_replace($hp, ['ipaddress' => '192.0.2.2']));
                expectIntegration(isset($mismatch['error']), 'Register retry with different glue must fail.');
                expectIntegration(addressSet($client->host()->info($host)) === addressSet(['ipv4' => ['192.0.2.1']]), 'Rejected register retry must leave existing glue unchanged.');
                $client->host()->update(['name' => $host, 'add' => ['addresses' => [['address' => '192.0.2.2', 'ipVersion' => 'v4']]]]);
                callHandler('ModifyNameserver', $hp + ['newipaddress' => '192.0.2.3']);
                callHandler('ModifyNameserver', $hp + ['newipaddress' => '192.0.2.3']);
                expectIntegration(addressSet($client->host()->info($host)) === addressSet(['ipv4' => ['192.0.2.3']]), 'Modify must replace the entire existing address set with one IP.');
                callHandler('SaveNameservers', array_replace($p, ['ns1' => $host]));
                expectIntegration(in_array($host, callHandler('GetNameservers', $p), true), 'Domain must use the newly assigned child host.');
                callHandler('SaveNameservers', $p);
                callHandler('DeleteNameserver', $hp);
                callHandler('DeleteNameserver', $hp);
                expectIntegration(($client->host()->check($host)[0]['available'] ?? false) === true, 'Child host must remain absent after delete retry.');
                $report['owned']['hosts'][$host] = 'deleted';
            });
        }
        $run($domain . '/renew-one-year', static function () use ($p, $client, $domain): void {
            $before = $client->domain()->info($domain)['expirationDate'];
            callHandler('RenewDomain', $p);
            $after = $client->domain()->info($domain)['expirationDate'];
            expectIntegration($before instanceof DateTimeInterface && $after instanceof DateTimeInterface
                && $after->format('Y-m-d') === DateTimeImmutable::createFromInterface($before)->modify('+1 year')->format('Y-m-d'), 'Renewal must increase registry expiry by exactly one year.');
        });
    }
} catch (Throwable $error) {
    $run('preconditions-or-runner', static function () use ($error): void { throw $error; });
} finally {
    if ($client !== null) {
        // Detach owned child hosts, then delete hosts, domains, and finally contacts.
        foreach ($report['owned']['domains'] as $domain => $state) {
            if ($state !== 'present') {
                continue;
            }
            [$sld, $tld] = explode('.', $domain, 2);
            $p = $params + ['sld' => $sld, 'tld' => $tld, 'ns1' => 'ns1.oblak.host', 'ns2' => 'ns2.oblak.host'];
            $run($domain . '/cleanup-unlock', static fn() => callHandler('SaveRegistrarLock', $p + ['lockenabled' => 'unlocked']));
            $run($domain . '/cleanup-detach-hosts', static fn() => callHandler('SaveNameservers', $p));
        }
        foreach (['hosts' => 'host', 'domains' => 'domain', 'contacts' => 'contact'] as $collection => $service) {
            foreach ($report['owned'][$collection] as $id => $state) {
                if ($collection === 'contacts' && in_array($state, ['pending-registrant-change', 'unverified-registrant-change'], true)) {
                    $report['cleanup_pending']['contacts'][$id] = ['reason' => 'Contact is the target of a registrant request; deletion was not attempted.', 'verified_pending' => $state === 'pending-registrant-change'];
                    $run($id . '/cleanup-retained-approval-target', static function () use ($state): void {
                        expectIntegration($state === 'pending-registrant-change', 'Registrant request outcome is unverified; its target is retained for manual inspection.');
                        throw new IntegrationPending('Registrant approval target is retained until approval or cancellation is resolved.');
                    });
                    continue;
                }
                if ($state === 'deleted' || ($collection === 'domains' && $state !== 'present') || $id === ($params['admin_id'] ?? '')) {
                    continue;
                }
                $run($id . '/cleanup-delete', static function () use ($client, $service, $collection, $id, &$report): void {
                    $accepted = false;
                    try {
                        $client->$service()->delete($id);
                        $accepted = true;
                    } catch (ObjectMissing) {
                        // Verify absence below, including retries after an ambiguous delete.
                    } catch (ProtocolException $error) {
                        $linkedPendingDomains = [];
                        foreach ($report['cleanup_pending']['domains'] as $domain => $pending) {
                            if (in_array($id, $pending['contacts'], true)) {
                                $linkedPendingDomains[] = $domain;
                            }
                        }
                        if ($collection !== 'contacts' || $error->resultCode() !== 2305 || $linkedPendingDomains === []) {
                            throw $error;
                        }
                        $report['owned']['contacts'][$id] = 'pending-domain-delete';
                        $report['cleanup_pending']['contacts'][$id] = ['result_code' => 2305, 'linked_owned_domains' => $linkedPendingDomains];
                        throw new IntegrationPending('Contact deletion is deferred while its verified owned domain is pendingDelete.');
                    }
                    try {
                        $info = $client->$service()->info($id);
                        $statuses = array_map('strtolower', $info['statuses'] ?? []);
                        if ($collection === 'domains' && $accepted && canonicalDomain((string) ($info['name'] ?? '')) === canonicalDomain($id)
                            && in_array('pendingdelete', $statuses, true)) {
                            $report['owned']['domains'][$id] = 'pending-delete';
                            $report['cleanup_pending']['domains'][$id] = ['statuses' => $statuses,
                                'contacts' => array_values(array_filter([$info['registrant'] ?? null, $info['adminContact'] ?? null, $info['techContact'] ?? null]))];
                            throw new IntegrationPending('The registry accepted deletion and confirms pendingDelete; final removal is pending.');
                        }
                        throw new IntegrationAssertion('Cleanup deletion was not confirmed; the object is still present.');
                    } catch (ObjectMissing) {
                        $report['owned'][$collection][$id] = 'deleted';
                    }
                });
            }
        }
        if (isset($adminFingerprint)) {
            $run('configured-tech-immutable', static fn() => expectIntegration(contactFingerprint($client->contact()->info($params['admin_id'])) === $adminFingerprint, 'The configured technical contact must remain unchanged.'));
        }
        $run('logout', static fn() => $client->close());
    }
    $failed = count(array_filter($report['checks'], static fn(array $check): bool => $check['status'] === 'fail'));
    $remaining = array_filter($report['owned'], static fn(array $objects): bool => count(array_filter($objects, static fn(string $state): bool => $state !== 'deleted')) > 0);
    $unresolved = array_filter($report['owned'], static fn(array $objects): bool => count(array_filter($objects, static fn(string $state): bool => !in_array($state, ['deleted', 'pending-delete', 'pending-domain-delete', 'pending-registrant-change'], true))) > 0);
    $report['state'] = $failed > 0 || $unresolved !== [] || $report['possible_orphans'] !== [] ? 'incomplete'
        : ($report['workflow_pending'] !== [] ? 'passed-with-approval-pending' : ($remaining !== [] ? 'passed-with-cleanup-pending' : 'passed-scoped-lifecycle'));
    $report['cleanup_complete'] = $remaining === [] && $report['possible_orphans'] === [];
    $save();
    echo 'Report: ', $path, PHP_EOL;
}
exit(match ($report['state']) { 'passed-scoped-lifecycle' => 0, 'passed-with-cleanup-pending', 'passed-with-approval-pending' => 3, default => 1 });
