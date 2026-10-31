# Threat model baseline

Assets: provider licenses/API credentials, private source documents, admin accounts, personal reader data, published article truth, ad budgets and payment records. Trust boundaries: client<->API, provider<->ingestor, AI prompt<->source content, worker<->DB, advertiser<->ad admin, storage pending<->public.

Main risks: IDOR, CSRF, broken RBAC, account takeover, session/MFA bypass, webhook spoofing, queue replay, duplicate publishing, prompt injection from scraped/licensed source text, fabricated AI claims, SSRF in image fetching, unsafe file upload, unlicensed photos/logos, cross-advertiser report leakage, invoice fraud, malicious ad creatives and analytics PII overcollection.

Mandatory verification: policy tests for every role/resource, source allowlists, signed hooks, non-executable media pipeline, sandboxed renderers, bounded provider polling, immutable audit trails, production secret rotation, independent financial authorization and abuse throttling. See security task gates in ROADMAP.json.
