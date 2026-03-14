# Agent Memory

## 2026-03-14

- RNIDS child nameserver host CRUD assumes one glue IP per child host.
- `RegisterNameserver` is idempotent only when the existing host already matches the requested single IP.
- `ModifyNameserver` replaces the host's full address set with the requested single IP.
- `DeleteNameserver` treats missing hosts as success to support safe retries from WHMCS.
