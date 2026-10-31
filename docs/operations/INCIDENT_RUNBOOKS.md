# Incident runbooks

1. **Unlicensed or erroneous story published:** disable auto-publish policy, quarantine affected source, retract or correct article with visible history, stop push, preserve evidence, alert publisher/legal.
2. **Provider outage/drift:** monitor freshness and schema changes, disable dependent publishing, alert data ops, retry rate-limited, backfill idempotently.
3. **Account or advertiser data leak:** freeze affected credentials and staff sessions, rotate secrets, audit cross-scope access, notify legal/security, preserve logs; comply with applicable reporting law.
4. **AI cost spike:** provider budget kill switch, inspect loop/duplication and job concurrency, cap model/image production, restore after root cause verified.
5. **Payment/ledger inconsistency:** freeze finance postings and disputed invoices, reconcile immutable ledger and processor evidence, dual approval remediation.
6. **Cloud provider failure:** fail closed for writes with uncertain integrity; use documented backup/restore policy and validate replica consistency.

For every incident record timeline, severity, affected resources, mitigation, decision owner, rollback and retest evidence.
