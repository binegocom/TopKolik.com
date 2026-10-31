# Proposed REST API contract (not implemented)

Version prefix: `/api/v1`. All formats JSON and UTC ISO timestamps; errors RFC 9457-like typed responses with opaque correlation IDs. Document actual contract in OpenAPI and add backward compatibility tests before implementing.

## Public endpoints
`GET /sports`, `GET /competitions`, `GET /fixtures`, `GET /fixtures/{id}`, `GET /fixtures/{id}/statistics`, `GET /teams/{id}`, `GET /athletes/{id}`, `GET /standings`, `GET /news`, `GET /news/{slug}`, `GET /search`.

## Reader endpoints
`POST /auth/register`, `POST /auth/login`, `POST /auth/password/forgot`, `POST /auth/password/reset`, `POST /auth/email/verify`, `POST /auth/logout`, `GET /me`, `PATCH /me/preferences`, `GET/POST/DELETE /me/favorites`, `POST /me/notification-subscriptions`, `DELETE /me/sessions/{id}`.

## Admin endpoints
Scoped `/admin/v1` endpoints for editorial candidates, source evidence, quarantine, article versions, publishing actions, provider quotas, bots/queues, media rights, ad inventory/campaigns, invoices/ledger, support and staff access; each operation requires explicit role+permission policy, step-up MFA for sensitive actions and audit event.

## Contracts
Never return unlicensed source data to public endpoints. Cursor pagination for feeds, ETag/read caches where safe, rate limits and event revision numbers; validation and predictable error codes. Browser CORS allowlist, signed inbound webhooks, object signed upload URLs, API key rotation.
