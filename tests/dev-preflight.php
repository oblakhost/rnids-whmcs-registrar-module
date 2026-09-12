<?php

declare(strict_types=1);

// Run inside the dev WHMCS environment; this performs login/logout only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$moduleRoot = dirname(__DIR__);
require dirname($moduleRoot, 3) . '/init.php';
require $moduleRoot . '/vendor/autoload.php';
require_once $moduleRoot . '/rnids.php';

use Oblak\WHMCS\RSREG\Registrar;
use Oblak\WHMCS\RSREG\Support\ClientFactory;
use WHMCS\Database\Capsule;

$params = [];
$settings = ['testmode', 'epp_username', 'epp_password', 'epp_certificate', 'epp_certificate_password', 'epp_ca'];
foreach (Capsule::table('tblregistrars')->where('registrar', 'rnids')->whereIn('setting', $settings)->get() as $row) {
    $params[$row->setting] = decrypt($row->value);
}

$report = [
    'php' => PHP_VERSION,
    'endpoint' => 'epp-test.rnids.rs:700',
    'checks' => [],
    'live' => 'blocked',
    'mutations' => 0,
];
$record = static function (string $name, bool $passed) use (&$report): bool {
    $report['checks'][$name] = $passed ? 'pass' : 'fail';
    return $passed;
};
$finish = static function (int $code) use (&$report): never {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($code);
};

if (!$record('testmode_enabled', ($params['testmode'] ?? '') === 'on')) {
    $finish(2);
}
$config = ClientFactory::buildClientParams($params);
if (!$record('test_endpoint_selected', $config['host'] === 'epp-test.rnids.rs' && $config['port'] === 700)) {
    $finish(2);
}
if (!$record('credentials_configured', trim($config['username']) !== '' && trim($config['password']) !== '')) {
    $finish(2);
}
$certPath = $config['tls']['clientCertificatePath'];
$caPath = $config['tls']['caFilePath'];
if (!$record('certificate_files_readable', is_readable($certPath) && is_readable($caPath))) {
    $finish(2);
}
$pem = file_get_contents($certPath);
$cert = openssl_x509_read($pem);
$key = openssl_pkey_get_private($pem, $config['tls']['clientCertificatePassword'] ?? '');
if (!$record('certificate_and_key_match', $cert !== false && $key !== false && openssl_x509_check_private_key($cert, $key))) {
    $finish(2);
}
$details = openssl_x509_parse($cert);
if (!$record('certificate_current', $details !== false && $details['validFrom_time_t'] <= time() && $details['validTo_time_t'] > time())) {
    $finish(2);
}
$report['certificate_expires'] = gmdate('Y-m-d', $details['validTo_time_t']);
if (!$record('certificate_chain_valid', openssl_x509_checkpurpose($pem, X509_PURPOSE_SSL_CLIENT, [$caPath], $certPath) === true)) {
    $finish(2);
}

$socket = @stream_socket_client('tcp://epp-test.rnids.rs:700', $errorCode, $errorMessage, 5);
if (!$record('tcp_connection', is_resource($socket))) {
    // Socket errno is sufficient for diagnosis; never print raw TLS exceptions.
    $report['socket_errno'] = $errorCode;
    $finish(2);
}
fclose($socket);

$client = null;
try {
    rnids_config_validate($params);
    $record('whmcs_config_validation', true);
    $client = Registrar::fromParams($params)->client();
    $code = (int) ($client->responseMeta()['resultCode'] ?? 0);
    $report['login_result_code'] = $code;
    if (!$record('epp_login', $code === 1000)) {
        $finish(1);
    }
    $client->close();
    $record('epp_logout', true);
    $report['live'] = 'ready';
    $finish(0);
} catch (Throwable $exception) {
    $record('epp_login_logout', false);
    $report['exception_class'] = get_class($exception);
    $report['exception_code'] = $exception->getCode();
    $finish(1);
}
