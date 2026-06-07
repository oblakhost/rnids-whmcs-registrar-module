# Agent Instructions — RNIDS RsReg Registrar Module

Canonical instructions and context for AI coding agents working on this project.
`CLAUDE.md` points here.

## Project Goal

RNIDS RsReg domain registrar module for WHMCS — register, transfer, renew and manage
`.rs` / Serbian-IDN domains directly from WHMCS. Built on the WHMCS sample-registrar
pattern and the `rnids/rsreg-epp-client` EPP SDK.

References:
- https://github.com/WHMCS/sample-registrar-module/
- https://developers.whmcs.com/domain-registrars/
- https://github.com/oblakhost/rnids-rsreg-client-php

## Architecture

Layered design — keep each layer in its lane:

- **`rnids.php`** — WHMCS entrypoint. Each `rnids_*` function is a **thin adapter**:
  accept `$params` → validate → call a `Registrar` method → map/log errors safely.
  No inline EPP/RNIDS command construction here.
- **`Oblak\WHMCS\RSREG\Registrar`** (`src/Registrar.php`) — the **single entrypoint**
  for all registrar business logic. Lazy-loads helper services via `SERVICE_MAP` +
  `__get()`; constructor takes module `$params` (+ optional `KnownHostRepository`).
- **Service layer** under `src/` (PSR-4 `Oblak\WHMCS\RSREG\` → `src/`):
  - `Domain/` — availability lookup, domain status/lock normalization.
  - `Contact/` — contact CRUD + WHMCS↔RNIDS field mapping.
  - `Nameserver/` — child-host (`HostService`) ops, domain NS updates, `KnownHostRepository`.
  - `Model/` — `ContactNormalizer`, `InfoNormalizer` (EPP↔WHMCS shape conversion).
  - `Support/` — `ClientFactory`, `ModuleLogger`, `ErrorMessageFormatter`.
  - `Validation/` — `DomainInputValidator`, `RegistrationProfileResolver`.
- **EPP transport** — `rnids/rsreg-epp-client` (`RNIDS\Client`), configured by
  `Support/ClientFactory.php`. Operations via `domain()`, `contact()`, `host()`.
  Endpoints: `epp.rnids.rs` (prod) / `epp-test.rnids.rs` (test, relaxed TLS).
- **`hooks.php`** — WHMCS hooks: `ClientAreaPageDomainContacts` (inject country list),
  `DomainTransferCompleted` (force tech contact to configured `admin_id` after a
  `.rs` transfer).
- **`rnids.additionalfields.php`** — per-TLD custom domain fields (registrant type,
  company info, country).

## Conventions

### Single entrypoint
All business logic flows through `Registrar`. Input mapping and response normalization
belong to dedicated model/mapper classes, never in `rnids.php` handlers.

**Contact-change policy:** `SaveContactDetails` must **never mutate an existing contact
object**. Create a new contact for any changed role and reassign the domain handle.

### WHMCS contract
- Keep function signatures WHMCS-compatible.
- On success return only the data WHMCS expects; on failure return `['error' => '...']`.
- Never leave a production-scope handler empty; behavior must be deterministic across
  test and production modes.

### RNIDS / EPP mapping & `.rs` constraints
- Centralize WHMCS→EPP mapping in helpers; handlers stay focused on
  validate → call → normalize.
- Respect WHMCS contact categories (Registrant, Admin, Tech, Billing). Map registry
  local fields: `reg_mb` (company no.), `reg_pib` (tax no.), `admin_id` (default tech).
- Treat `.rs` business rules as first-class validations: supported TLDs, period ranges,
  hostname/glue format. Company-only TLDs (e.g. `.co.rs`, `.org.rs`, `.edu.rs`) require
  company fields.
- Normalize raw RNIDS responses to stable WHMCS shapes (dates, statuses, locks).

### Nameserver / glue
- **One glue IP per child host** (single-IP host model).
- `RegisterNameserver` is idempotent only when the existing host already matches the
  requested single IP.
- `ModifyNameserver` replaces the host's full address set with the requested single IP.
- `DeleteNameserver` treats a missing host as success (safe WHMCS retries).
- When setting **domain** nameservers, hosts absent from `knownhosts.php` are looked up
  via `host()->check()` + `host()->info()` to reuse existing glue and avoid duplicates.

### Error handling & observability
- Wrap registry calls in focused try/catch; return concise, actionable error text.
- Never expose stack traces, raw XML, credentials, or TLS internals to users.
- Fail fast: validate WHMCS inputs (and required mapped custom fields) before any
  registry call; reject unsupported periods/transitions deterministically.
- Log diagnostics through `Support/ModuleLogger` with secrets redacted (passwords,
  auth codes, certificates). Centralize exception→WHMCS error mapping in shared helpers.

### Definition of Done
Functional completion (all in-scope registrar functions implemented), WHMCS contract
compliance, security/test-mode isolation (no hardcoded creds, TLS verification in prod),
and operational readiness (sufficient redacted logging + matching docs).

## Build & Test

- **Dependencies:** `composer install`. PHP **8.1+**, all `src/` classes use
  `declare(strict_types=1)`. PSR-4: `Oblak\WHMCS\RSREG\` → `src/`.
- **Verification:** there is **no PHPUnit/PHPStan suite wired up**. Verify changes with
  PHP syntax checks: `php -l <file>` on each modified PHP file. Functional changes are
  verified manually in a WHMCS instance.
- **Release:** automated via semantic-release (`.releaserc`) running
  `.github/release.sh <version>`, which reads `extra.whmcs` in `composer.json` to
  package the module zip. Branches: `master` (prod), `beta` (prerelease).

## Commits & Releases

Conventional Commits: `type(scope): Description` (scope optional). Capitalize the first
word, no trailing period, subject < 72 chars.

Release impact: `feat` → minor; `fix`/`perf`/`refactor`/`style` → patch;
`chore`/`docs`/`test`/`build`/`ci` → no release; `BREAKING CHANGE` footer → major.

## Task Tracking — beads (`bd`)

This project uses **bd (beads)** for ALL task tracking. Run `bd prime` for the full
command reference and session-close protocol.

```bash
bd ready                # Find available work
bd show <id>            # View issue details
bd update <id> --claim  # Claim work
bd close <id>           # Complete work
```

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, markdown TODOs,
  or the old agentkanban/clinerules systems.
- Use `bd remember "insight"` for persistent knowledge — do NOT use MEMORY.md files.

### Session Completion (MANDATORY)

Work is **not** complete until `git push` succeeds.

1. File beads issues for any remaining/follow-up work.
2. Run quality gates if code changed (`php -l` on changed files; build/package if relevant).
3. Update issue status — close finished work, update in-progress items.
4. **Push to remote** (mandatory):
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status   # MUST show "up to date with origin"
   ```
5. Clean up stashes / prune remote branches.
6. Verify everything is committed AND pushed.
7. Hand off context for the next session.

Never stop before pushing — that strands work locally. If push fails, resolve and retry.
