# Registrar testing

Run these commands from the WHMCS DDEV project directory. The test scripts accept
CLI execution only. The old module `test.php` is a scratch script containing
existing domain and invoice identifiers; it is not a test suite.

## Module contracts

```sh
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/contracts.php
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/validation-regressions.php
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/diagnostics.php
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/idn-nameservers.php
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/contact-events.php
```

The contract harness checks module behavior without registry access. A nonzero
exit indicates failed expectations; passing contract checks do not establish
that RNIDS accepts the corresponding operation.
The focused scripts cover strict input validation, secret-safe diagnostics, and
actual SDK XML using an in-memory transport. Run against the locked Composer
dependency; an SDK source override is not release verification.

## Dev connection prerequisites

```sh
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/dev-preflight.php
```

This script reads the saved local RNIDS settings through WHMCS. It refuses to
connect unless test mode is enabled and the configured target is exactly
`epp-test.rnids.rs:700`. It checks credentials are configured, certificate
readability, validity, private-key match and CA chain, then attempts TCP and EPP
login/logout. It prints a JSON report without credential values or raw EPP/TLS
exceptions. It does not register or modify registry objects.

Exit codes: `0` means login/logout succeeded, `1` means the EPP check failed,
and `2` means a prerequisite blocked the check. A blocked check is not a pass.

## WHMCS dev lifecycle

```sh
ddev exec --raw -- php /var/www/html/public_html/modules/registrars/rnids/tests/dev-integration.php --allow-mutations
```

Run only with permission to create disposable objects in the test registry. It
uses the saved WHMCS settings and invokes actual registrar handlers for `.rs`,
company `.co.rs`, and Cyrillic `.срб` profiles. Coverage includes availability,
registration, domain/contact/nameserver reads, synchronization, locks, immutable
contact reassignment, contact no-ops, child-host retries and replacement,
domain nameserver changes, and renewal.

The CLI refuses production settings and checks shared fixture nameserver glue
before registration. It never changes the configured technical contact. Its
private JSON report under `/tmp` records checks and owned object handles for
cleanup; an interrupted or incomplete report must be inspected before rerunning.
WHMCS module logging must be enabled: the runner verifies a harmless log probe
before mutation and reads only new `ContactCreated` events, then confirms each
handle's unique synthetic email before claiming ownership. Those events contain
only the created handle and role, with no contact details or credentials.
Accepted registrant changes can retain the old handle while `pendingUpdate` is
visible. The runner records the new target under `workflow_pending` and retains
that contact until the approval workflow resolves.
RNIDS can accept deletion while retaining a domain in `pendingDelete`; linked
contacts can remain until deletion completes. Retained resources must be reported
as pending, never as confirmed cleanup.

Lifecycle exit codes: `0` means the scoped checks passed and cleanup is complete;
`3` means submitted operations passed with registry approval or deletion pending;
approval completion is still unexecuted. `1` means a
failed check or unresolved resource; `2` means a CLI usage/report-path rejection.

Full cross-registrar transfer, WHMCS UI ordering/billing and hook/notification
delivery require separate fixtures or final application testing. The script
explicitly reports these exclusions and does not acknowledge arbitrary poll
messages or send EPP-code/IRTP emails.

## Client-area IRTP banners

Log into the account that owns the test domain, or use the admin client's
Login as Client action. Open Domains → My Domains → Manage Domain → Contact
Information. The direct route is
`clientarea.php?action=domaincontacts&domainid=<WHMCS-domain-id>`.

The active Lagom template displays an informational **Contact Change Pending**
banner above the contact form when the domain is manageable, IRTP is enabled,
and the module reports a pending contact-verification process. If the module also
reports pending suspension, the template displays **Verification Required**
instead. Check again after registry approval to verify that the pending flag and
banner clear. Registry pending flags alone do not confirm completed approval or
delivered email.

The current Resend Verification Email handler sends an admin notification for
manual handling in RNIDS. It does not automatically resend the registry email.

## SDK live lifecycle coverage

The SDK source repository supplies a separate PHPUnit integration suite:

```sh
php vendor/bin/phpunit --testsuite integration --fail-on-skipped --display-skipped
```

Run it from an SDK checkout with the version under test recorded. The module's
Composer distribution does not include those test sources. The SDK checkout's
passing results must not be presented as results for a different installed SDK
revision or as end-to-end WHMCS handler coverage.

Supply test credentials and certificate paths through `RNIDS_EPP_USERNAME`,
`RNIDS_EPP_PASSWORD`, `RNIDS_EPP_CLIENT_CERT_PATH`, `RNIDS_EPP_CA_CERT_PATH`, and
optional `RNIDS_EPP_CLIENT_CERT_PASSWORD`. Set `RNIDS_EPP_HOST=epp-test.rnids.rs`
and `RNIDS_EPP_PORT=700`. Keep credentials out of committed files and shell
history. Set `RNIDS_EPP_TEST_HOST_IPV4` and
`RNIDS_EPP_REGISTER_NAMESERVERS` to approved test nameserver values.

The current SDK suite exercises greeting, contact create/update/info/delete,
disposable domain register/update/renew/delete, child-host lifecycle, domain
info, and poll. Stable domain info requires `RNIDS_EPP_TEST_DOMAIN`. Poll
acknowledgement requires an explicitly approved `RNIDS_EPP_POLL_ACK_MESSAGE_ID`;
do not acknowledge unrelated queue messages to eliminate a skipped test.

Full transfer completion needs an independent donor registrar and an approved
transfer fixture. Report unexecuted operations explicitly.
