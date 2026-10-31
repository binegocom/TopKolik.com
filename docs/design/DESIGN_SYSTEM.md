# Topkolik design system

## Brand source
Approved presentation reference (see downloadable project package); a production vector logo and icon export are pending. Name TOPKOLİK, slogan Sporun Tutkusu. Do not invent replacement colors or treat a marketing mockup as final production SVG.

## Palette
Primary `#0B5D3B`, Accent `#7CFF4D`, Graphite `#1F2933`, White `#FFFFFF`. Supporting light surface `#F4F7F5`, dark surface `#111A17`. Never use white copy on lime; dark foreground for accent buttons. Maintain text contrast targets per WCAG 2.2 AA.

## Platform-specific contracts
- iOS: Apple HIG, safe area, native back/navigation/sheets, dynamic text, VoiceOver, reduced motion.
- Android: Material 3/You adaptive layout, edge-to-edge, predictive back where supported, system font, TalkBack, large touch targets.
- Web: Next.js SSR, semantic landmarks, keyboard and screen-reader compatibility, responsive 320px+ to desktop, article reading column and strong media hierarchy.
- Admin: dense but readable tables, permission-sensitive actions, explicit loading/errors/empty states, audit trails and keyboard-first controls.

## Shared components
Brand header/logo, story card, featured story, ticker/live badge, fixture summary, score card, team badge (licensed artwork only), statistic comparison, source citation, article version stamp, category/filter chips, notifications, controlled ad slot, feedback and empty state.

## Layout & typography
Spacing 4/8/12/16/24/32. 12–16px card corner radius. Montserrat for brand headings; SF Pro native iOS system font; Roboto Android; system-ui or licensed Montserrat web. Scores use tabular numerals. Mobile bottom nav: Home, Matches, Discover, Following. Admin left navigation. Light/dark/system theme options.

## No fake data
No hardcoded fixtures, fake article previews, pretend real-time statuses or fabricated badges in production runtime. Skeletons and documented design references are permitted in documentation only.
