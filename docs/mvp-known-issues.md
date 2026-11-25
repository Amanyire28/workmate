# MVP known issues

A separate record of existing problems that affect MVP readiness. It is a register, not a work order: do not investigate or fix an item as part of an unrelated task. Each item needs the owner's approval and a plan first (AGENTS.md section 12).

Last updated: 2026-11-25.

## Open

| # | Issue | Impact | Status |
|---|---|---|---|
| 1 | **Background push notification not delivered.** A provider receives an in-app notification for a new service request after opening WorkMate, but receives **no push notification while the app is closed**. | Providers miss requests; directly hurts the core journey and retention. | Reported by the owner. **Not investigated.** The push and notification infrastructure must not be changed as part of an unrelated task. Note only, from the README: smart push is off until the server scheduler is confirmed, and "Queue worker and scheduler" is still unchecked under Production readiness. Whether either is related is unknown. |
| 2 | **Production `finance.pricing_enabled` read as true** on 2026-11-24 (public settings) after reading false earlier the same day. No release changed settings. | A financial switch may have been turned on without being recorded here. | Owner to confirm it was intentional; check the settings audit log. Not changed by development. |
| 3 | **Hidden admin pages still run on mock data** (bookings, analytics, content, communication, system and others; the seven finance ones now show "Service currently not available."). The Executive Dashboard still draws several charts from mock data. | Administrators could read invented figures if they open these by address. | Known. Review before any administrator relies on the dashboard. |
| 4 | **Physical-device checks outstanding** for B11/B12: keyboard behaviour in sheets, iOS focus zoom, installed-PWA layout. | Unverified on real phones. | Owner to check. |
| 5 | **Local development database `workmate` is empty** (wiped earlier by mistake). `workmate_test` holds seeded browser-test data. | Slows local work only; production unaffected. | Reseed only when the owner asks. |
| 6 | **No `.env.testing`**; `--env=testing` falls back to the development database. | Risk of wiping the wrong database. | Use `DB_DATABASE=workmate_test` and check the resolved connection first. |
| 7 | **`php artisan test` runs out of memory on PHP 7.4.** | Test workflow only. | Run PHPUnit directly with `-d memory_limit=1536M` when the full suite is approved. |
| 8 | **Email delivery** depends on mail settings in `.env`. | Confirmation and reset emails may not arrive. | Check with `php artisan workmate:mail-test` on the server. |
| 9 | **Real-time messaging is polling** (every few seconds). | Slower chat; more data on slow links. | Known limitation. |
| 10 | **A provider team member (not the owner) does not get the Received tab on My Work.** The page decides who is a provider from the account's own provider status, which is `not_applied` for a member. The server still returns the business's requests to a member (with pricing actions off), but the screen does not show them. | Team members cannot manage requests from the app. | Found 2026-11-25 while checking permissions. Pre-existing; README lists member management as in development. Not changed. |

## Resolved

- B11/B12 mobile overflow on Notifications at 320 and 360px, sheet footer overflow at 320px, and form fields under 16px on touch screens (deployed in `59a646e`).
