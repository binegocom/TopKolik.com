# Screen catalog and UX workflows

## Flutter mobile
Splash -> optional onboarding sport/favorite selection -> Home -> news detail; Matches -> fixture detail (overview, timeline, statistics, lineups, articles); Discover -> sports, teams, competitions, athlete profiles and search; Following -> favorite teams, saved stories and followed competitions. Include notification center, settings, sign-in, sign-up, email verification, forgotten password, reset password, manage sessions, consent/privacy and accessible support.

## Next.js public web
Home; all news, category and sport feeds; article and author/editorial disclosure; live/fixtures/results; match center; standings; leagues, teams and athletes; transfers; advanced search; reader sign-in/settings/favorites; advertising disclosure; about/publisher, contact, terms, privacy and corrections. Search-visible SSR article pages only; no indexation for internal auth/settings.

## Next.js admin
Dashboard; AI Bots; Source Providers; Sports Catalog; Match Monitor; Editorial Candidate Queue; Fact Checking; Articles/Versions; Media Studio; Publishing Policies; Corrections/Takedowns; SEO; Notification Campaigns; Users/Roles/Staff MFA; Advertisers; Sales & Inventory; Creatives & Delivery; Billing/Payments/Ledger; Support Tickets; Analytics; Infrastructure/Costs; Audit Trail; Settings. Admin robots noindex and protected server authentication.

## Design sequencing
Design tokens -> responsive layout primitives -> authenticated access shells -> headline/news cards -> fixture/score components -> article detail -> match detail -> public web page templates -> staff dashboard -> editorial review -> ads/finance/support -> empty/loading/error/permission states -> a11y verification -> automated interaction tests.
