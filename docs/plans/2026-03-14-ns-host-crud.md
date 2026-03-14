# NS Host CRUD Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add WHMCS child nameserver register, modify, and delete support with a one-host-one-IP model.

**Architecture:** Keep `rnids.php` as thin WHMCS wrappers, add host CRUD orchestration to `Registrar`, and extend `HostService` with explicit single-IP host operations. Validation and safe error translation stay centralized in the existing module layers.

**Tech Stack:** PHP 8+, WHMCS registrar module entrypoints, RNIDS EPP client library

---

### Task 1: Document and queue the work

**Files:**
- Modify: `.agentkanban/tasks/doing/task_20260314_022644484_glrzzx_implement_ns_host_crud.md`
- Create: `.agentkanban/tasks/doing/todo_20260314_022644484_glrzzx_implement_ns_host_crud.md`
- Create: `docs/plans/2026-03-14-ns-host-crud-design.md`

**Step 1: Record the approved design**

Save the approved behavior, architecture, data flow, error handling, and manual verification notes.

**Step 2: Create the TODO checklist**

Add checklist items for registrar changes, host service changes, WHMCS wrappers, verification, and docs updates.

### Task 2: Add host CRUD behavior in the service layer

**Files:**
- Modify: `src/Nameserver/HostService.php`

**Step 1: Add single-IP host helpers**

Implement methods to:
- create a host with one validated IP
- replace an existing host's full address set with one requested IP
- delete a host while treating missing hosts as success

**Step 2: Keep existing sync behavior intact**

Do not regress `ensureHostExistsAndSynced`, because it is used by domain registration and nameserver save flows.

### Task 3: Add registrar workflows

**Files:**
- Modify: `src/Registrar.php`

**Step 1: Add input normalization and validation helpers**

Normalize host names and validate the single `ipaddress` input for register/modify operations.

**Step 2: Add public registrar methods**

Implement:
- `registerNameserver(array $params): array`
- `modifyNameserver(array $params): array`
- `deleteNameserver(array $params): array`

Each method should call the new `HostService` operations and return `['success' => true]` when the target state is satisfied.

### Task 4: Wire WHMCS entrypoints

**Files:**
- Modify: `rnids.php`

**Step 1: Add shared log context builder**

Capture parent domain, child host, and requested IP for module logs.

**Step 2: Implement WHMCS wrapper functions**

Update:
- `rnids_RegisterNameserver`
- `rnids_ModifyNameserver`
- `rnids_DeleteNameserver`

Follow the existing module pattern: call `Registrar`, log success or error, and return WHMCS-compatible arrays.

### Task 5: Verify and document

**Files:**
- Modify: `TECHNICAL.md`
- Modify: `.agentkanban/tasks/doing/task_20260314_022644484_glrzzx_implement_ns_host_crud.md`
- Modify: `.agentkanban/tasks/doing/todo_20260314_022644484_glrzzx_implement_ns_host_crud.md`

**Step 1: Run syntax checks**

Run:
- `php -l rnids.php`
- `php -l src/Registrar.php`
- `php -l src/Nameserver/HostService.php`

Expected: `No syntax errors detected` for each file.

**Step 2: Update docs**

Add a short note to `TECHNICAL.md` describing the nameserver host CRUD flow and the one-IP host assumption.

**Step 3: Record implementation summary**

Append a concise summary to the task conversation and mark TODO items complete.
