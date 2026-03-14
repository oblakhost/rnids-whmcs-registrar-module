---
title: Implement NS host CRUD
created: 2026-03-14T01:26:44.484Z
updated: 2026-03-14T02:50:40.828Z
description: |-
  RegisterNameserver	Called when a child nameserver registration request comes from WHMCS.
  ModifyNameserver	Called when a child nameserver modification request comes from WHMCS.
  DeleteNameserver	Called when a child nameserver deletion request comes from WHMCS.

  Host operations are needed.
sortOrder: 2
slug: implement_ns_host_crud
---

## Conversation

[user]

[agent]

Implemented the `Registrar` lazy-loading refactor requested after the nameserver host CRUD work.

Summary of changes:

- Reduced `Registrar::__construct()` to `array $params` plus optional `KnownHostRepository`.
- Replaced eager helper construction with an internal lazy service cache and static service class map.
- Added `Registrar::__get()` so existing internal `$this->domainInputValidator`, `$this->hostService`, and similar call sites keep working without eager instantiation.
- Left `Registrar::fromParams()` and client initialization behavior unchanged.
- Created the missing task TODO file for this task and completed the items for this refactor.

Verification completed:

- Reflection check for the new two-argument constructor shape
- `php -l src/Registrar.php`

[user]

[agent]

I reviewed the current module flow: `RegisterNameserver`, `ModifyNameserver`, and `DeleteNameserver` are empty in `rnids.php`, while `HostService` already knows how to create a host and sync its glue addresses. To lock the design for `ModifyNameserver`, which behavior do you want when WHMCS sends a single `ipaddress` value?

1. Treat it as the complete desired glue set and replace any existing host IPs with that one address.
2. Only add or update that address, leaving any other existing host IPs untouched.

[user]

RegisterNameserver	Called when a child nameserver registration request comes from WHMCS.
ModifyNameserver	Called when a child nameserver modification request comes from WHMCS.
DeleteNameserver	Called when a child nameserver deletion request comes from WHMCS.

Host operations are needed.

$sld = $params['sld'];
$tld = $params['tld'];

// nameserver parameters
$nameserver = $params['nameserver'];
$ipAddress = $params['ipaddress'];

[agent]

I confirmed this task maps to the empty WHMCS host hooks in `rnids.php` and reviewed the existing `Registrar` and `HostService` code paths. I need one behavior decision before I can lock the design: for `ModifyNameserver`, should the module treat the WHMCS `ipaddress` field as the complete desired IP set for the child host and replace existing glue with that single address, or should it only add/update the provided address and leave any other existing IPs intact?

[user]
one IP for one nameserver. if same hostname - we update.

[agent]

That gives us a clear host model: one child host maps to one glue IP, and `ModifyNameserver` should reconcile the existing host so the hostname stays the same and the single stored IP changes when needed.

I see three reasonable implementation approaches:

1. Add dedicated registrar methods for host register/modify/delete and let `rnids.php` stay thin. This keeps WHMCS entrypoints consistent with the rest of the module and reuses `HostService` for the EPP details. This is my recommendation.
2. Implement everything directly inside the three `rnids_*Nameserver` functions. It is quicker short-term, but it duplicates validation, client lifecycle, and error-handling patterns already centralized in `Registrar`.
3. Expand `HostService` into a full workflow object and call it almost directly from the module hooks. This is workable, but it pushes WHMCS-specific parameter handling into a lower-level service that currently only knows registry operations.

Recommended design, section 1, architecture:

- Add `registerNameserver`, `modifyNameserver`, and `deleteNameserver` methods to `Registrar`.
- Add focused `HostService` operations for `create`, `replaceSingleAddress`, and `deleteIfExists` so the single-IP rule is explicit instead of hidden inside the current sync method.
- Keep `rnids_RegisterNameserver`, `rnids_ModifyNameserver`, and `rnids_DeleteNameserver` as thin wrappers that build log context, call the registrar, and return WHMCS-style success/error arrays.

Does that architecture look right so far?

[user]
ok

[agent]

Recommended design, section 2, data flow:

- Normalize the parent domain from `sld` and `tld` for logging only; the child host object sent to the registry should remain the `nameserver` value WHMCS provides.
- Validate that `nameserver` is present and that `ipaddress` is a single valid IPv4 or IPv6 address for register and modify operations.
- `RegisterNameserver` should create the child host with exactly one address and succeed when the same hostname already exists with that same single IP.
- `ModifyNameserver` should fetch the current host and replace its full address set with the requested single IP when the hostname already exists.
- `DeleteNameserver` should remove the child host and treat a missing host as a successful no-op so repeated WHMCS calls stay idempotent.

This keeps the public contract simple: one hostname, one IP, and safe retries where the requested end state is already true.

Does that data flow match what you want?

[user]
ok

[agent]

Recommended design, section 3, error handling and testing:

- Keep WHMCS-facing functions consistent with the rest of the module: catch throwables in `rnids.php`, log a structured module call with the operation name, parent domain, child host, and requested IP, then return `['error' => ...]` with the registrar-safe message.
- Treat registry "host not found" on delete as success, and treat register or modify as success when the host already matches the requested single-IP state.
- Reject missing or invalid `nameserver` and `ipaddress` input with explicit validation errors before sending any EPP request.
- Add focused PHPUnit coverage around the registrar and host service behavior so we can verify create, replace-single-address, and delete-noop behavior without needing a live registry session.

If you approve this section, I’ll write the design doc, create the implementation plan, and then we can move into TDD-driven implementation.

[user]
no phpunit

[agent]

Understood. I’ll revise the testing strategy to avoid introducing PHPUnit.

Revised testing section:

- Keep the same runtime behavior and error handling design.
- Verify the implementation with targeted manual checks and static validation only: `php -l` on changed PHP files plus a short execution checklist for register, modify, and delete flows in WHMCS.
- Document the manual verification steps in the design doc and implementation plan so the expected host state transitions are still explicit.

If that revised testing approach works for you, I’ll write the design doc next and move into the planning step.

[user]
can we implement it?

[agent]

Implemented the approved nameserver host CRUD flow.

Summary of changes:

- Added `docs/plans/2026-03-14-ns-host-crud-design.md` and `docs/plans/2026-03-14-ns-host-crud.md`.
- Created the missing task TODO file and completed it during implementation.
- Added explicit single-IP host operations in `src/Nameserver/HostService.php`:
  - create host with one IP
  - replace an existing host's full address set with one IP
  - delete host while treating missing hosts as success
- Added `Registrar::registerNameserver()`, `modifyNameserver()`, and `deleteNameserver()` with validation for `nameserver` and `ipaddress`.
- Implemented `rnids_RegisterNameserver()`, `rnids_ModifyNameserver()`, and `rnids_DeleteNameserver()` in `rnids.php` with structured success/error logging.
- Updated `TECHNICAL.md` with the one-IP child host behavior and idempotency notes.

Verification completed:

- `php -l rnids.php`
- `php -l src/Registrar.php`
- `php -l src/Nameserver/HostService.php`

Manual WHMCS verification is still pending:

1. Register a child host with one IP.
2. Modify the same child host to a different IP.
3. Delete the child host.
4. Repeat delete to confirm no-op success.

[user]
ns update not working
            [message] => Nameserver IP address is required.

[agent]

I debugged the failure and confirmed the root cause was a WHMCS parameter mismatch, not the host update flow itself.

Evidence:

- The module was reading only `ipaddress` in `Registrar::extractChildNameserverIp()`.
- A one-off reflection-based reproduction against the current code failed when only `newipaddress` was present:
  - input: `['newipaddress' => '192.0.2.55']`
  - result before fix: `InvalidArgumentException: Nameserver IP address is required.`
- Local registrar module references in this WHMCS install showed `ModifyNameserver` implementations using `newipaddress` and `currentipaddress`.

Implemented fix:

- `Registrar::extractChildNameserverIp()` now accepts `newipaddress` first and falls back to `ipaddress`.
- `rnids_buildNameserverHostLogContext()` now logs the requested IP from `newipaddress` or `ipaddress`, plus `currentipaddress` when WHMCS provides it.

Verification:

- Re-ran the same reproduction after the fix and it returned `192.0.2.55`.
- `php -l src/Registrar.php`
- `php -l rnids.php`

[user]
