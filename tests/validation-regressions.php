<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Oblak\WHMCS\RSREG\Validation\DomainInputValidator;

$validator = new DomainInputValidator();
$passed = 0;
$failed = 0;

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

function rejects(callable $call): void
{
    try {
        $call();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Invalid domain input was accepted');
}

function check(string $name, callable $test): void
{
    global $passed, $failed;
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });
    try {
        $test();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL $name: {$exception->getMessage()}\n";
    } finally {
        restore_error_handler();
    }
}

foreach (['xn--90a3ac' => 'срб', 'xn--d1at.xn--90a3ac' => 'од.срб',
    'xn--o1ac.xn--90a3ac' => 'пр.срб', 'xn--c1avg.xn--90a3ac' => 'орг.срб',
    'xn--90azh.xn--90a3ac' => 'обр.срб'] as $ascii => $unicode) {
    check('punycode TLD ' . $ascii, static function () use ($validator, $ascii, $unicode): void {
        same($unicode, $validator->normalizeTld(['tld' => '.' . strtoupper($ascii)]));
        $validator->validateRegistrationTld($ascii);
        $validator->validateTransferTld($ascii);
    });
}
check('punycode SLD and TLD normalize to Unicode', static function () use ($validator): void {
    same('пример.срб', $validator->normalizeDomainName(['sld' => 'XN--E1AFMKFD', 'tld' => 'XN--90A3AC']));
});
check('common read path normalizes array input', static function () use ($validator): void {
    same('пример.срб', $validator->paramsToDomain(['sld' => 'ПРИМЕР', 'tld' => '.СРБ']));
});
check('common read path normalizes string input', static function () use ($validator): void {
    same('пример.срб', $validator->paramsToDomain('XN--E1AFMKFD.XN--90A3AC'));
});
check('63-byte ASCII label remains valid', static function () use ($validator): void {
    same(str_repeat('a', 63) . '.rs', $validator->normalizeDomainName(['sld' => str_repeat('a', 63), 'tld' => 'rs']));
});
check('IDN length is measured in its ASCII representation', static function () use ($validator): void {
    same(str_repeat('я', 50) . '.срб', $validator->normalizeDomainName(['sld' => str_repeat('я', 50), 'tld' => 'срб']));
});

foreach (['malformed ACE' => 'xn--a', 'empty ACE' => 'xn--', 'Unicode dot' => 'one。two',
    'IDN over 63 ASCII bytes' => str_repeat('я', 60), 'invalid joiner' => "a\u{200D}b", 'NUL byte' => "\0contract",
    'array' => ['contract'], 'object' => new stdClass(), 'boolean' => true, 'integer' => 42] as $name => $sld) {
    check('reject ' . $name, static function () use ($validator, $sld): void {
        rejects(static fn() => $validator->normalizeDomainName(['sld' => $sld, 'tld' => 'rs']));
    });
}
foreach (['malformed ACE' => 'xn--a', 'empty label' => 'co..rs', 'duplicate leading dot' => '..rs',
    'internal space' => 'co. rs', 'NUL byte' => "\0rs", 'array' => ['rs'], 'integer' => 42] as $name => $tld) {
    check('reject TLD ' . $name, static function () use ($validator, $tld): void {
        rejects(static fn() => $validator->normalizeDomainName(['sld' => 'contract', 'tld' => $tld]));
    });
}
check('read path rejects NUL byte', static function () use ($validator): void {
    rejects(static fn() => $validator->paramsToDomain("\0contract.rs"));
});
foreach (['contract.com', 'sub.contract.rs', 'contract', ''] as $domain) {
    check('read path rejects unsupported object ' . $domain, static function () use ($validator, $domain): void {
        rejects(static fn() => $validator->paramsToDomain($domain));
    });
}
check('read path rejects unsupported TLD in array', static function () use ($validator): void {
    rejects(static fn() => $validator->paramsToDomain(['sld' => 'contract', 'tld' => 'com']));
});
check('read path preserves company suffix', static function () use ($validator): void {
    same('contract.co.rs', $validator->paramsToDomain('CONTRACT.CO.RS'));
});
check('reject oversized complete name', static function () use ($validator): void {
    rejects(static fn() => $validator->normalizeDomainName(['sld' => str_repeat('a', 63),
        'tld' => implode('.', array_fill(0, 4, str_repeat('b', 63)))]));
});

echo "\n$passed passed; $failed failed; " . ($passed + $failed) . " total. Offline validation only.\n";
exit($failed === 0 ? 0 : 1);
