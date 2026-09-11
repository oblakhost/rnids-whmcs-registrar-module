<?php

declare(strict_types=1);

// Offline diagnostics regressions: no WHMCS bootstrap or registry client calls.
if (PHP_SAPI !== 'cli' || defined('WHMCS') || function_exists('logModuleCall')) {
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Oblak\WHMCS\RSREG\Support\ErrorMessageFormatter;
use Oblak\WHMCS\RSREG\Support\ModuleLogger;
use RNIDS\Exception\ProtocolException;
use RNIDS\Xml\Response\ResponseMetadata;

$logs = [];
if (!function_exists('logModuleCall')) {
    function logModuleCall(...$arguments): void
    {
        $GLOBALS['logs'][] = $arguments;
    }
}

$failures = 0;
$checks = 0;
$check = static function (string $name, bool $passed) use (&$failures, &$checks): void {
    $checks++;
    if (!$passed) {
        $failures++;
    }
    echo ($passed ? 'PASS ' : 'FAIL '), $name, PHP_EOL;
};

ModuleLogger::logModuleCall('diagnostics', [
    'tls' => ['private-key' => 'OFFLINE-PRIVATE', 'CLIENT_CERTIFICATE' => 'OFFLINE-CERT'],
    'epp_password' => 'OFFLINE-PASSWORD',
    'domain' => 'contract.rs',
], ['message' => 'Registry echoed OFFLINE-PASSWORD', 'resultCode' => 2200]);
$encoded = json_encode($logs, JSON_THROW_ON_ERROR);
$check('normalized sensitive keys', !str_contains($encoded, 'OFFLINE-PRIVATE') && !str_contains($encoded, 'OFFLINE-CERT'));
$check('secrets echoed in responses', !str_contains($encoded, 'OFFLINE-PASSWORD'));
$check('public diagnostics retained', str_contains($encoded, 'contract.rs') && str_contains($encoded, '2200'));

foreach ([
    '<epp><authInfo><pw>OFFLINE-XML</pw></authInfo></epp>',
    "-----BEGIN PRIVATE KEY-----\nOFFLINE-PEM\n-----END PRIVATE KEY-----",
    'Authentication failed password=OFFLINE-PASSWORD',
] as $index => $raw) {
    $logs = [];
    ModuleLogger::logModuleCall('diagnostics', ['raw' => $raw], $raw);
    $check('raw secret payload ' . $index, !str_contains(json_encode($logs, JSON_THROW_ON_ERROR), 'OFFLINE-'));
}

$exception = new ProtocolException(new ResponseMetadata(2202, 'OFFLINE-REGISTRY-SECRET', 'TEST-CLIENT', 'TEST-SERVER'));
$context = ModuleLogger::exceptionContext($exception);
$check('exception context excludes registry free text', !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'OFFLINE-REGISTRY-SECRET'));
$check('exception identifiers retained', ($context['responseMetadata']['resultCode'] ?? null) === 2202
    && ($context['responseMetadata']['serverTransactionId'] ?? null) === 'TEST-SERVER');
$message = ErrorMessageFormatter::safeMessage($exception, 'Unable to transfer');
$check('EPP authorization error is actionable', str_contains(strtolower($message), 'auth') && !str_contains($message, 'OFFLINE-'));

$message = ErrorMessageFormatter::safeMessage(new InvalidArgumentException('Invalid nameserver hostname provided: <pw>OFFLINE-INPUT</pw>'), 'Unable to register');
$check('unsafe interpolated validation input omitted', !str_contains($message, 'OFFLINE-') && str_contains(strtolower($message), 'nameserver'));
$message = ErrorMessageFormatter::safeMessage(new InvalidArgumentException('password=OFFLINE-SDK-SECRET'), 'Unable to register');
$check('unknown SDK argument messages suppressed', $message === 'Unable to register');
$message = ErrorMessageFormatter::safeMessage(new RuntimeException('OFFLINE-UNCLASSIFIED'), 'Unable to register');
$check('unknown runtime messages suppressed', $message === 'Unable to register');
$message = ErrorMessageFormatter::safeMessage(new InvalidArgumentException('Unsupported TLD for RNIDS: .com'), 'Unable to fetch domain');
$check('unsupported read-domain guidance retained', str_contains($message, 'not supported'));
$message = ErrorMessageFormatter::safeMessage(new InvalidArgumentException('The domain is locked by the registry. Contact support to unlock it.'), 'Unable to unlock');
$check('registry lock guidance retained', str_contains($message, 'Contact support to unlock'));
$message = ErrorMessageFormatter::safeMessage(new InvalidArgumentException('The nameserver already exists with different glue addresses. Use Modify Nameserver to change its IP address.'), 'Unable to register nameserver');
$check('existing glue conflict guidance retained', str_contains($message, 'Use Modify Nameserver'));
$message = ErrorMessageFormatter::safeMessage(new RuntimeException('EPP kod je poslat na email adresu administrativnog kontakta domena. Ukoliko nije stigao, kontaktirajte podršku.'), 'Unable to get EPP code');
$check('registry email-delivery guidance retained', str_contains($message, 'email adresu administrativnog kontakta'));

echo sprintf("\n%d passed; %d failed; %d total. Offline only.\n", $checks - $failures, $failures, $checks);
exit($failures === 0 ? 0 : 1);
