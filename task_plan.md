# Task Plan

## Goal
Lay the first real foundation for broader offline parity without pretending the app has full conflict-safe reconciliation yet.

## Constraints
- Keep the continuous front-desk keyboard-entry flow intact.
- Preserve auth/privacy/data integrity.
- Do not silently auto-resolve destructive conflicts.
- Do not fake parity for payments/admin mutations.

## Phase 1
1. [completed] Confirm the safest foundation scope.
2. [completed] Add a shared offline state/storage layer and renewal tracking.
3. [completed] Wire the shared model into PWA status, attendance, auth, and member snapshots.
4. [completed] Verify baseline behavior and document remaining gaps.

## Phase 2
1. [completed] Inspect current outbox/replay/error handling for attendance, member writes, and payments.
2. [completed] Implement the safest narrow batch toward unified retry/reconciliation UX.
3. [completed] Verify and document exact remaining limitations.

## Current decision
- The next safe boundary is a read-only unified outbox view backed by the existing queues, plus per-item failure metadata and latest-issue summaries.
- Member conflict review now includes changed-field summaries and a manual current-record base option; payments remain compare-only.
- Existing mutation/replay paths remain unchanged in behavior; no destructive conflict auto-resolution was added.
- The sync screen now becomes the first place to inspect and retry queued writes across attendance, members, and payments.
