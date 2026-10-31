# PostgreSQL schema blueprint

## Core sports
`sports(id, slug)`, `competitions(id,sport_id,provider_ref)`, `seasons(id,competition_id)`, `teams(id,sport_id)`, `athletes(id,sport_id)`, `team_memberships(athlete_id,team_id,valid_from,valid_to)`, `fixtures(id,competition_id,season_id,status,starts_at)`, `fixture_participants(fixture_id,team_id,side)`, `fixture_events(id,fixture_id,provider_event_key,revision)`, `statistics(fixture_id,subject_type,subject_id,metric,unit,value,source_revision)`.

## Providers & provenance
`providers`, `provider_subscriptions`, `provider_licenses`, `provider_entity_mappings`, `raw_ingestion_events`, `source_documents`, `source_snapshots`, `claims`, `claim_evidence`, `ingest_runs`.

## Editorial & distribution
`story_candidates`, `articles`, `article_versions`, `article_claims`, `article_sources`, `media_assets`, `publication_events`, `outbox_events`, `notification_subscriptions`, `notification_deliveries`.

## People & business operations
`users`, `reader_profiles`, `favorites`, `staff_memberships`, `roles`, `permissions`, `role_permissions`, `mfa_credentials`, `sessions`, `password_reset_tokens`, `audit_events`, `support_tickets`, `support_messages`, `advertisers`, `placements`, `campaigns`, `creatives`, `bookings`, `ad_impressions`, `ad_clicks`, `invoices`, `invoice_items`, `payments`, `refunds`, `ledger_accounts`, `ledger_entries`, `tax_rules`.

## Invariants
FKs and unique constraints for provider IDs, source revisions, publication idempotency, event identity, invoice/ledger integrity; transactionally enforce consent, restricted scopes and ad budgets. UTC timestamps; localized presentation; append-only audited event history; typed enumerations/check constraints. Index only observed query shapes after EXPLAIN. Use nondestructive migrations and tested rollback plans.
