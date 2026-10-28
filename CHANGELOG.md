# Changelog

## 0.1.0 - 2026-10-28

### Added

- S1: Permission catalogue contract, exact key validation, wildcard expansion and census command.
- S2: Four configurable tables, profile and assignment models, tenant ownership contracts and atomic tenant cache versions.
- S3: Code-defined system templates, transactional sync, dry-run previews, versioning and soft deletion.
- S4: Profile, grant, change and revoke builders. Actor and reason tracking. Escalation, self-assignment, protected profile, tenant, duplicate and host rule guards.
- S5: Deny-first resolver, validity windows, live conditions, token intersection, scoped request memo and versioned cache.
- S6: Holder collections and queries, scope constraints with deny exclusions, and assignment history.
- S7: Events after commit for assignments, profiles and template sync. Optional access decision events, disabled by default.
- S8: Cache-clear, transactional and idempotent backfill with dry-run, and holder inspection commands.
- S9: HasAccessProfiles trait and the authenticated @canAccess Blade directive. Explicit checks preserve Laravel Gate behaviour.
- S10: Installation and API documentation, generic design examples, Inertia sharing, policy and queued job examples. Behaviour tests for each README example.
