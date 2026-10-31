# Autonomous editorial pipeline

## States
`detected -> source_checked -> normalized -> claims_extracted -> verified -> drafted -> media_ready -> approved_for_auto_publish -> published`.
Exceptions: `quarantined`, `editorial_review`, `retracted`. Transitions are audited and idempotent.

## Trusted classes
Completed match recaps with finalized provider data, verified changes to fixtures, official results and officially issued team statements with redistribution rights. Other classes require explicit policy approval; injuries, allegations, transfer rumors and betting predictions do not silently auto-publish.

## Claim-level evidence
Store each claim with fact type, event/team/athlete IDs, source/provider, source timestamp, data version, verification result and article span. Must not fill absent values with guesses; no synthetic quotes. Different feeds may differ: quarantine rather than choose arbitrarily.

## Model use
AI drafts language, summarization and insight selection based on structured allowed facts. Deterministic validators confirm numeric metrics, names, timestamps, team mapping and prohibited unsupported claims. Generation budget, model/prompt version and confidence/quality policy recorded.

## Cover images
Render scores and names using a deterministic font/layout engine, never as generative text. Synthetic illustrations may be generated if clearly illustrative; no invented match photo or unlicensed logos/athlete likeness. Store asset provenance, licenses and model metadata.

## Publishing safety
Atomic state transition + transactional outbox; debounce live events; per-event unique key; editorial kill switch; publish retry idempotency; revision/takedown trail; search notification and push only post-commit. Published fact corrections trigger article revision with timestamp, not silent overwrite.
