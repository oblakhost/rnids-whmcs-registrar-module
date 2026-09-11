<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !class_exists(\RnidsContractTests\FakeRegistry::class, false)) {
    exit(2);
}

// Capture the logger boundary without bootstrapping WHMCS or writing to its DB.
function logModuleCall(...$arguments): void
{
    \RnidsContractTests\FakeRegistry::$logs[] = $arguments;
}
