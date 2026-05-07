# Findings

## Existing outbox handling
- Attendance had the most mature UX: queued items were visible in the front-desk panel, replay was manual/automatic, and flush events already drove dashboard refreshes.
- Member writes and payments already queued offline mutations and replayed automatically when online, but they did not expose a unified retry/reconciliation surface.
- Queue items already carried `attempts` and `lastError` fields, but replay paths did not update them during transient failures.

## Implemented batch
- Added `lastOutboxIssue*` module metadata to shared offline state so the latest queue failure can survive reloads without storing payloads.
- Added a unified offline outbox card to the admin sync screen for attendance, member writes, and payments.
- The outbox card shows queue counts, item age, attempt count, and latest error, plus per-module retry and retry-all actions.
- Replay loops now persist transient failures back onto the queued item and record the latest issue in shared offline state.

## Remaining limits
- Hard 4xx/conflict drops are still not auto-reconciled; they are only surfaced as the latest issue and removed from the queue.
- Member conflicts now expose a clearer field-diff review and a safe "start from current record" path, but the save remains manual.
- There is still no server-side conflict resolution UI or destructive auto-merge.
