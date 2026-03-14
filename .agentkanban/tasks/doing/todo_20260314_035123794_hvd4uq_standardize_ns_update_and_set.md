---
task: task_20260314_035123794_hvd4uq_standardize_ns_update_and_set
---

## TODO

### Iteration 1

- [x] Review the current domain nameserver save flow and local known-host usage.
- [x] Add RNIDS host lookup fallback for requested nameservers missing from known hosts.
- [x] Update task and technical documentation for the new fallback behavior.
- [x] Run syntax verification for the changed PHP files.

### Iteration 2

- [x] Investigate the save-nameservers regression for new in-bailiwick hosts without glue.
- [x] Prevent empty host sync attempts for unknown child hosts and return a clearer validation error.
- [x] Re-run syntax verification for the adjusted PHP files.
