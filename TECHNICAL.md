# TECHNICAL

## 2026-03-14 - Release packaging from composer metadata

- `.github/release.sh` now reads every `extra.whmcs[]` package entry from `composer.json`.
- The release script recreates `/tmp/release` on each run and stages package contents under `/tmp/release/<path>`.
- Each declared `files` entry is copied from the repository root into the package destination, with shell glob support for patterns such as `logo*`.
- The script exits with an error when a configured file pattern matches nothing, so broken release metadata fails fast during packaging.

## 2026-03-14 - RNIDS transfer completed tech contact override

- Added `DomainTransferCompleted` handling in `hooks.php` to enforce RNIDS tech contact after transfer completion.
- Scope is RNIDS transfer TLDs: `.rs`, `.in.rs`, `.co.rs`, `.org.rs`, `.edu.rs`.
- Hook exits early for non-`rnids` registrar events or unsupported domains.
- Hook loads RNIDS module config and uses `admin_id` as the target tech contact handle.
- If `admin_id` is missing, hook skips update and logs a warning.
- Hook fetches current domain info via `rnids_App(...)->getInfo($domain)` and no-ops when tech already matches target handle.
- Hook sends `domain()->update(...)` payload with `add.contacts` (`tech` => configured handle) and optional `remove.contacts` for previous tech handle.
- All failure/skip/noop/success paths are logged through `logModuleCall` under action `DomainTransferCompleted`.

## 2026-03-14 - RNIDS availability lookup

- Implemented `rnids_CheckAvailability()` in `rnids.php` and delegated the lookup logic to `Registrar::checkAvailabilityFromWhmcs()`.
- Extracted the availability lookup normalization and result mapping into `src/Domain/AvailabilityLookupService.php` to keep `Registrar` thin.
- RNIDS availability now accepts classic registrar-style `sld` + `tlds` input and falls back to `searchTerm` / `tldsToInclude` / `punyCodeSearchTerm` for compatibility.
- Unsupported TLDs now produce explicit `SearchResult::STATUS_TLD_NOT_SUPPORTED` entries instead of being skipped.
- Registry availability is mapped into WHMCS `SearchResult` items with `STATUS_NOT_REGISTERED` for available domains and `STATUS_REGISTERED` for taken domains.
- Availability success and failure paths are logged through `ModuleLogger` under action `CheckAvailability`.

## 2026-03-14 - RNIDS child nameserver host CRUD

- Implemented `rnids_RegisterNameserver()`, `rnids_ModifyNameserver()`, and `rnids_DeleteNameserver()` in `rnids.php`.
- Added `Registrar::registerNameserver()`, `Registrar::modifyNameserver()`, and `Registrar::deleteNameserver()` to centralize validation and host operations.
- Added explicit single-IP host helpers in `src/Nameserver/HostService.php` for create, replace, and delete flows.
- Child host behavior now assumes exactly one glue IP per nameserver.
- Register is idempotent when the host already exists with the same single IP.
- Modify replaces the host's full address set with the requested single IP.
- Delete treats a missing host as a successful no-op.
- WHMCS wrappers log `operation`, parent `domain`, child `nameserver`, and requested `ipAddress` through `ModuleLogger`.
- WHMCS `ModifyNameserver` passes the target IP as `newipaddress`; the module now accepts that field and logs `currentipaddress` when present.

## 2026-03-14 - RNIDS domain nameserver reuse fallback

- `Registrar::setNameServers()` now augments `knownhosts.php` entries by checking RNIDS for unknown hostnames before composing the domain update.
- When a requested nameserver is not in local known hosts, the module calls `host()->check($hostname)` and, if the host already exists in RNIDS, follows with `host()->info($hostname)` to reuse its current glue addresses.
- Existing RNIDS hosts discovered this way are treated like known hosts for both host sync and `domain:update` payload generation, which avoids duplicate host creation and preserves `hostAttr` glue data.
- If RNIDS reports the hostname as available, behavior is unchanged and the module continues with a plain host object update for that nameserver.
- For in-bailiwick nameservers under the domain being updated, the module now fails early with a clear message if the child host is neither known locally nor present in RNIDS with glue, instead of attempting an empty host create during save.

## 2026-03-14 - Registrar lazy-loaded services

- Refactored `src/Registrar.php` so helper collaborators are no longer instantiated eagerly in `__construct()`.
- `Registrar::__construct()` now takes only module params plus an optional `KnownHostRepository` override.
- Helper services are resolved on first access through an internal `$services` cache and `SERVICE_MAP`.
- `KnownHostRepository` remains special-cased because its default constructor depends on the module root path.
- Existing internal property-style access like `$this->domainInputValidator` and `$this->hostService` is preserved through `Registrar::__get()`.
