---
title: Standardize ns update and set
created: 2026-03-14T02:51:23.794Z
updated: 2026-03-14T02:51:40.061Z
description: |-
  When setting nameservers (for the domain, not private).
  If not in known hosts, run host:check for hostname, and get existing if exists - to prevent dups.
sortOrder: 1
slug: standardize_ns_update_and_set
---

## Conversation

[user]

An issue was encountered while updating the domain nameservers. Please contact support.
ns1.wp.rs - exists in rnids
ns2.wp.rs - exists in rnids
adding ns3 - not exists

[agent]

Investigated as a regression in the updated `SaveNameservers` flow. The failure point is that a brand new in-bailiwick hostname like `ns3.wp.rs` can reach host sync without any known or reusable glue addresses.

Adjusted the implementation so:

- Existing RNIDS hosts like `ns1.wp.rs` and `ns2.wp.rs` are still reused via `host:check` + `host:info`.
- Unknown external nameservers can still pass through without forced host sync.
- Unknown in-bailiwick child hosts now fail fast with a specific validation error telling the caller to register the child nameserver first, instead of attempting an empty host create and surfacing a generic update failure.

[user]

When setting nameservers (for the domain, not private).
If not in known hosts, run host:check for hostname, and get existing if exists - to prevent dups.

[agent]

Planned and implemented a narrow fallback for domain nameserver updates:

- Keep `knownhosts.php` as the first source of truth.
- For requested nameservers missing from known hosts during `SaveNameservers`, call RNIDS `host()->check($hostname)`.
- If RNIDS reports the host already exists, fetch `host()->info($hostname)` and reuse its current IPv4/IPv6 glue in the domain update payload.
- If RNIDS reports the host is available, preserve the existing behavior and send the hostname without glue.

Implemented in `Registrar::setNameServers()` plus a new `HostService::findExistingHostAddresses()` helper. PHPUnit was intentionally skipped per user instruction; verification is limited to syntax checks.

Verification:

- `php -l src/Nameserver/HostService.php`
- `php -l src/Registrar.php`
- `php -l rnids.php`

All three completed with no syntax errors.

[user]
