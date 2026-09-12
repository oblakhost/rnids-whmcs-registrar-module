<?php

declare(strict_types=1);

namespace RnidsContactEventTests;

if (PHP_SAPI !== 'cli' || defined('WHMCS') || class_exists('RNIDS\\Client', false) || function_exists('logModuleCall')) {
    exit(2);
}

final class FakeClient
{
    public function __construct(private array $result) {}
    public function contact(): object
    {
        return new class($this->result) {
            public function __construct(private array $result) {}
            public function create(array $payload): array { return $this->result; }
        };
    }
}
class_alias(FakeClient::class, 'RNIDS\\Client');
require dirname(__DIR__) . '/vendor/autoload.php';

final class LogCapture
{
    public static array $logs = [];
}

// Reuse the global logger boundary from the standalone contract harness.
class_alias(LogCapture::class, 'RnidsContractTests\\FakeRegistry');
require __DIR__ . '/contracts/whmcs-functions.php';

$contact = ['First Name' => 'Synthetic', 'Last Name' => 'Person', 'City' => 'Belgrade', 'Country' => 'RS', 'Email Address' => 'private-fixture@example.invalid', 'Phone Number' => '+381.111234567'];
$service = new \Oblak\WHMCS\RSREG\Contact\ContactService();
$id = $service->createContactFromWhmcsDetails(new FakeClient(['id' => 'OFFLINE-CREATED-CONTACT']), 'Registrant', $contact);
$event = LogCapture::$logs[0] ?? [];
$response = json_decode($event[3] ?? '', true);
$checks = [
    'successful creation exposes the actual handle to diagnostics' => $id === 'OFFLINE-CREATED-CONTACT'
        && ($event[1] ?? '') === 'ContactCreated' && ($response['contactId'] ?? '') === $id && ($response['role'] ?? '') === 'Registrant',
    'contact metadata event contains no submitted personal fields' => count(LogCapture::$logs) === 1
        && !str_contains(json_encode(LogCapture::$logs), 'private-fixture') && !str_contains(json_encode(LogCapture::$logs), 'Synthetic'),
];
LogCapture::$logs = [];
try {
    $service->createContactFromWhmcsDetails(new FakeClient([]), 'Registrant', $contact);
    $checks['missing registry handle cannot produce a creation event'] = false;
} catch (\InvalidArgumentException) {
    $checks['missing registry handle cannot produce a creation event'] = LogCapture::$logs === [];
}
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL '), $name, PHP_EOL;
}
$failures = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo sprintf("\n%d passed; %d failed; %d total. Offline only.\n", count($checks) - $failures, $failures, count($checks));
exit($failures === 0 ? 0 : 1);
