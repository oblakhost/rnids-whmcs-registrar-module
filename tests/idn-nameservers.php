<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Oblak\WHMCS\RSREG\Nameserver\DomainNameserverUpdater;
use Oblak\WHMCS\RSREG\Registrar;
use RNIDS\Client;
use RNIDS\Connection\Transport;
use RNIDS\Exception\ProtocolException;

// Exercise the actual SDK client, service, builder and parser; replace only I/O.
final class NameserverRecordingTransport implements Transport
{
    public array $frames = [];
    public int $resultCode = 1000;

    public function connect(): void
    {
        // This in-memory transport has no socket or network implementation.
    }

    public function disconnect(): void
    {
    }

    public function writeFrame(string $payload): void
    {
        $this->frames[] = $payload;
    }

    public function readFrame(): string
    {
        if ($this->frames === []) {
            return '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><greeting><svID>Offline</svID>'
                . '<svDate>2026-01-01T00:00:00Z</svDate><svcMenu><version>1.0</version><lang>en</lang>'
                . '<objURI>urn:ietf:params:xml:ns:domain-1.0</objURI></svcMenu></greeting></epp>';
        }

        $document = new DOMDocument();
        $document->loadXML($this->frames[array_key_last($this->frames)]);
        $transactionId = $document->getElementsByTagName('clTRID')->item(0)->textContent;
        $resultCode = $document->getElementsByTagName('login')->length > 0 ? 1000
            : ($document->getElementsByTagName('logout')->length > 0 ? 1500 : $this->resultCode);

        return '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="' . $resultCode
            . '"><msg>Offline response</msg></result><trID><clTRID>' . htmlspecialchars($transactionId, ENT_XML1)
            . '</clTRID>'
            . '<svTRID>OFFLINE</svTRID></trID></response></epp>';
    }
}

function nameserverTestClient(NameserverRecordingTransport $transport): Client
{
    // Older SDKs cannot inject transport: refuse them before creating any client.
    if ((new ReflectionMethod(Client::class, 'ready'))->getNumberOfParameters() < 2) {
        throw new RuntimeException('These checks require the updated SDK with injectable transport.');
    }
    return Client::ready(['host' => 'registry.invalid', 'username' => 'OFFLINE', 'password' => 'OFFLINE'], $transport);
}

function nameserverXml(string $domain, array $add, array $remove, array $known): DOMXPath
{
    $transport = new NameserverRecordingTransport();
    (new DomainNameserverUpdater())->rawDomainNameserverUpdate(nameserverTestClient($transport), $domain, $add, $remove, $known);
    $updates = array_values(array_filter($transport->frames, static fn(string $frame): bool => str_contains($frame, '<update>')));
    if (count($updates) !== 1) {
        throw new RuntimeException('Expected exactly one update frame.');
    }
    $document = new DOMDocument();
    if (!$document->loadXML($updates[0])) {
        throw new RuntimeException('Invalid update XML.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('d', 'urn:ietf:params:xml:ns:domain-1.0');
    return $xpath;
}

function nameserverSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

$passed = 0;
$failed = 0;
function nameserverCheck(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL $name: {$exception->getMessage()}\n";
    }
}

nameserverCheck('IDN domain is ASCII on the wire', static function (): void {
    $xml = nameserverXml('пример.срб', ['ns1.example.invalid'], [], []);
    nameserverSame('xn--e1afmkfd.xn--90a3ac', $xml->evaluate('string(//d:name)'));
});
nameserverCheck('IDN host objects are ASCII on add and remove', static function (): void {
    $xml = nameserverXml('пример.срб', ['ns1.пример.срб'], ['ns2.пример.срб'], []);
    nameserverSame('ns1.xn--e1afmkfd.xn--90a3ac', $xml->evaluate('string(//d:add/d:ns/d:hostObj)'));
    nameserverSame('ns2.xn--e1afmkfd.xn--90a3ac', $xml->evaluate('string(//d:rem/d:ns/d:hostObj)'));
});
nameserverCheck('IDN host attributes are ASCII with IPv6 glue', static function (): void {
    $xml = nameserverXml('пример.срб', ['ns1.пример.срб'], [], [
        'ns1.пример.срб' => ['ipv4' => [], 'ipv6' => ['2001:db8::1']],
    ]);
    nameserverSame('ns1.xn--e1afmkfd.xn--90a3ac', $xml->evaluate('string(//d:hostName)'));
    nameserverSame('v6', $xml->evaluate('string(//d:hostAddr/@ip)'));
    nameserverSame('2001:db8::1', $xml->evaluate('string(//d:hostAddr)'));
});
nameserverCheck('mixed glue inputs use one consistent nameserver form', static function (): void {
    $xml = nameserverXml('contract.rs', ['ns1.example.invalid', 'ns2.example.invalid'], [], [
        'ns1.example.invalid' => ['ipv4' => ['192.0.2.1'], 'ipv6' => []],
    ]);
    nameserverSame(2.0, $xml->evaluate('count(//d:add/d:ns/d:hostAttr)'));
    nameserverSame(0.0, $xml->evaluate('count(//d:add/d:ns/d:hostObj)'));
    nameserverSame('v4', $xml->evaluate('string(//d:hostAddr/@ip)'));
});
nameserverCheck('IDN punycode child remains in bailiwick', static function (): void {
    $registrar = new Registrar([]);
    $method = new ReflectionMethod($registrar, 'isInBailiwickNameserver');
    nameserverSame(true, $method->invoke($registrar, 'пример.срб', 'ns1.xn--e1afmkfd.xn--90a3ac'));
    nameserverSame(true, $method->invoke($registrar, 'xn--e1afmkfd.xn--90a3ac', 'ns1.пример.срб'));
    nameserverSame(false, $method->invoke($registrar, 'пример.срб', 'ns1.other.rs'));
});
nameserverCheck('IDN missing glue is rejected for an ASCII child name', static function (): void {
    try {
        (new ReflectionMethod(Registrar::class, 'assertNameserverCanBeUsedWithoutKnownGlue'))
            ->invoke(new Registrar([]), 'пример.срб', 'ns1.xn--e1afmkfd.xn--90a3ac');
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Expected missing-glue validation failure.');
});
nameserverCheck('registry update failure remains a protocol exception', static function (): void {
    $transport = new NameserverRecordingTransport();
    $transport->resultCode = 2306;
    try {
        (new DomainNameserverUpdater())->rawDomainNameserverUpdate(nameserverTestClient($transport), 'contract.rs', ['ns1.example.invalid'], [], []);
    } catch (ProtocolException $exception) {
        nameserverSame(2306, $exception->resultCode());
        return;
    }
    throw new RuntimeException('Expected registry protocol exception.');
});

echo "\n$passed passed; $failed failed; " . ($passed + $failed) . " total. Offline SDK transport only.\n";
exit($failed === 0 ? 0 : 1);
