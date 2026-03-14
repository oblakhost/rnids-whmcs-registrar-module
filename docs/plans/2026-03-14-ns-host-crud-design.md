# NS Host CRUD Design

**Date:** 2026-03-14

## Goal

Implement WHMCS child nameserver registration, modification, and deletion for the RNIDS registrar module.

## Approved Behavior

- Each child nameserver maps to exactly one glue IP.
- `ModifyNameserver` updates the existing host in place when the hostname matches.
- `RegisterNameserver` is idempotent when the host already exists with the requested single IP.
- `DeleteNameserver` is idempotent when the host is already missing.

## Architecture

- Keep `rnids.php` entrypoints thin and consistent with the rest of the module.
- Add host CRUD workflows to `Registrar` so validation, client access, and safe error handling stay centralized.
- Extend `HostService` with explicit single-IP operations rather than overloading the existing host sync helper.

## Data Flow

### RegisterNameserver

1. Validate `nameserver` and `ipaddress`.
2. Normalize the parent domain from `sld` and `tld` for logging only.
3. Create the host with exactly one address.
4. If the same host already exists with the same single address, treat the request as success.

### ModifyNameserver

1. Validate `nameserver` and `ipaddress`.
2. Load the existing host from the registry.
3. Compare its current address set to the requested single IP.
4. Replace the full address set when the requested IP differs.

### DeleteNameserver

1. Validate `nameserver`.
2. Delete the host from the registry.
3. Treat "host not found" as success.

## Error Handling

- Reject missing or invalid `nameserver` input before any EPP call.
- Reject missing or invalid `ipaddress` on register/modify before any EPP call.
- Catch throwables in `rnids.php`, log structured context, and return WHMCS-safe error arrays.
- Include operation name, parent domain, host name, and requested IP in module logs.

## Verification

- Run `php -l` on each changed PHP file.
- Manually verify in WHMCS:
  - Register child host with one IP.
  - Modify the same child host to a different single IP.
  - Delete the child host.
  - Repeat delete to confirm no-op success behavior.
