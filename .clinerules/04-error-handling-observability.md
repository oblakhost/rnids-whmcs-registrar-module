# Error Handling and Observability Rules

This file standardizes runtime error behavior and logging in the registrar module.

## Error Response Rules

- Wrap RNIDS/EPP calls in focused `try/catch` blocks.
- Return user-facing failures as `['error' => '...']` with concise, actionable text.
- Do not expose stack traces, raw XML/EPP frames, credentials, certificate paths, or TLS internals.
- Distinguish validation failures (input/config issues) from remote/system failures (registry/network issues).

## Validation and Fail-Fast Behavior

- Validate required WHMCS inputs before any registry call.
- Validate mapped custom fields (`reg_mb`, `reg_pib`, `admin_id`) when required by flow.
- Reject unsupported periods/status transitions with deterministic error messages.

## Logging Rules

- Log technical diagnostics only through module-safe logging facilities.
- Redact/omit secrets: passwords, auth codes, TLS passphrases, private key content.
- Include enough context to troubleshoot: operation, domain, high-level registry error class/code.
- Keep customer-visible responses minimal while preserving rich internal logs.

## Deterministic Error Mapping

- Centralize exception-to-WHMCS error mapping in shared helpers.
- Normalize repeated RNIDS failure patterns into stable messages.
- Avoid silent catches; every failure path must return or throw intentionally.
