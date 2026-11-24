# Current development status (handover)

Written 2026-11-24 for a fresh AI coding session. Read `AGENTS.md` first. Nothing described as "uncommitted" below has been committed, pushed or deployed.

## 1. Current project state

- Branch `main`, HEAD `50216b5` (B9). `origin/main` is at the same commit. Commits on `main` that are **pushed and deployed to production**:
  - `e690379` B8: financial administration, reports, adjustment/exception requests, SQL aggregation of settlement cycles (migrations 000077, 000078).
  - `50216b5` B9: My Finances for providers, statements (PDF/CSV), per-member finance access (migration 000079).
- **Production deploy of those two commits succeeded.** Attempt 1 of "Deploy WorkMate to VPS" failed because the VPS ran out of memory; the owner added 2 GB of swap and attempt 2 (GitHub deployment #86, 48 seconds, commit `50216b5`) succeeded. Migrations 000077 to 000079 were applied in batch 43, and production `/api/v1/health` is OK (confirmed by the owner). Nothing about this deployment remains to be investigated.
- The owner took a backup before that deploy: `/var/backups/workmate/2026-11-24_05-54-25` (database and storage), restored and checked in `workmate_restore_20261124`.
- **All four financial switches must stay off** (`finance.pricing_enabled`, `finance.commission_shadow_enabled`, `finance.commission_assessment_enabled`, `finance.commission_settlement_enabled`) and `finance.commission_assessment_from` must stay unset. Production's public settings showed `finance.pricing_enabled` false; the others are not visible from outside.
- Adjustment ledger posting is **not implemented** and must not be (owner decision). The ledger CHECK constraint `commission_ledger_shape` is unchanged.

## 2. Completed functionality (by phase)

- B1 to B7 (earlier, in production): flexible pricing, price agreements, payment reports, shadow commission, disputes, obligations/ledger (B6), settlement cycles and payment reports (B7).
- B8 (pushed): admin Financial Administration page, reports and CSV, adjustment requests (request only), reconciliation exceptions, SQL aggregation of cycles.
- B9 (pushed): `/my-finances` for provider businesses. Owner always; other members only with `provider_members.can_view_finances`.
- **B10 (uncommitted, see section 3):** Financial Settings page, commission collection, Commission Payments workspace. Described in the README section "Financial settings and commission collection (Phase B10)".

## 3. Current uncommitted changes (B10)

Work tree is on `main` on top of `50216b5`. `git status` shows about 40 modified files, one staged deletion and 15 new files.

New files:
- `database/migrations/2026_12_04_000080_add_commission_collection.php`
- `database/migrations/2026_12_04_000081_add_financial_settings_permission.php`
- `app/Services/CommissionCollections.php` (gateway attempts, callbacks, expiry, receipts)
- `app/Services/CommissionPaymentDirectory.php` (admin directory, summary, detail, CSV)
- `app/Services/Payments/{PaymentGateway,SimulatedGateway,GatewayRegistry}.php`
- `app/Http/Controllers/Api/V1/{ProviderPaymentController,PaymentCallbackController}.php`, `.../Admin/CommissionPaymentController.php`
- `app/Console/Commands/ExpireCommissionAttempts.php` (scheduled every five minutes in `Kernel.php`)
- `resources/js/admin/pages/CommissionPaymentsPage.vue`, `resources/js/admin/utils/commissionPayments.js`
- `resources/js/components/pricing/PayCommissionSheet.vue`, `resources/js/utils/payCommission.js`
- `tests/Feature/Api/CommissionCollectionTest.php`, `tests/js/payCommission.test.mjs`

Notable edits to existing files: `PlatformSettings` (dependency rules, Kampala start moment), `CommissionSettlements` (provider-level payments, `creditPayment`, receipts, manual channel needs proof), `CommissionAssessments::startsAt` (converts to the app clock), `ProviderFinanceAccess` (`canPay`), `ProviderFinance::summary` (all-time totals and pending), `EventNotifications` (new notice kinds), `routes/api.php`, admin navigation and routes, `SettingsWorkspace.vue` (textarea, datetime, quiet mode), `adminApi.js` (financial settings mapping and ordered saves), `MyFinancesPage.vue`, `SettlementsPage.vue` (payments tab removed), outdated wording fixes in `CommissionShadowPage.vue`, `config/workmate.php` and the README. `ReportCommissionPaymentSheet.vue` was deleted (staged deletion). Several existing tests were edited, see section 7.

B8 and B9 were kept as separate commits. B10 should likewise be one clearly separate commit (or two: backend then frontend) once approved.

## 4. Remaining implementation tasks

- None are open for B10 itself. The implementation report was delivered and is **awaiting the owner's review**. Only review feedback may add work.
- Not started and deferred by the owner: see section 9.

## 5. Known errors and blockers

- None open for the deployment. (The earlier VPS memory failure is resolved by the 2 GB swap.)
- **Local development database `workmate` was wiped by mistake** (`migrate:fresh --seed --env=testing` with no `.env.testing`, so it ran on the dev database). Local only; production and `workmate_test` untouched. Sample marketplace data can be restored with the local seeder if wanted. **Before any database-related test or destructive command, verify the actual resolved connection** (see AGENTS.md section 12). `--env=testing` is allowed only when a dedicated `.env.testing` pointing at the isolated test database exists and the resolved database has been checked; today there is no `.env.testing`, so use the `DB_DATABASE=workmate_test` prefix.
- `php artisan test` on PHP 7.4 now runs out of memory (512 MB) in `PhotoWatermarkTest` because the suite grew. It passes when PHPUnit is run directly with more memory: `php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit`. `artisan test` does not pass `-d` options to its child process.
- Stale `public/hot` makes browser tests render blank; the e2e scripts move it aside.
- No real payment gateway exists. In production, mobile money says "Service currently not available." by design.

## 6. Tests already passed (final state of this work tree)

- PHPUnit, PHP 7.4.4 + MariaDB 10.4: OK, 906 tests, 10,827 assertions (run directly with 1536M memory).
- PHPUnit, PHP 8.3.37 + MariaDB 11.4.14 (isolated, port 3307): OK, 906 tests, 10,827 assertions.
- JS (`node --import ./tests/js/register.mjs --test "tests/js/*.test.mjs"`): 93 of 93.
- `npm run build`: succeeded.
- Real-process concurrency harnesses (outside the repo, `D:\WorkMate-test-temp\e2e\`): new `b10_conc.php` 6 rounds, 0 problems; older `b4_conc2`, `b6_conc`, `b7_conc`, `b8_conc` 0 problems each.
- Browser (headless Edge, `b10_all.sh`): 44 of 44 at 390px, 44 of 44 at 1280px, 5 of 5 empty account.
- The last built bundle is current except for no later source edits; the final full run followed the last code change.

## 7. Tests that failed during the work, and why (all resolved)

- Existing finance tests failed when settings were set in an order the new dependency rules refuse (assessment before start date or prerequisites). Fixed by reordering test setup, and by writing "damaged" values straight to the settings table in the two tests that deliberately test unreadable or missing start dates.
- Four overpayment tests reported more than owed; reports can no longer exceed the balance. Fixed by reporting within the balance and having finance verify a larger received amount.
- `CommissionSettlementTest` expected the manager to report payments; paying now needs `can_pay_commission`. Test updated. Channel list now starts with `manual`. Notification link is now `/admin/finance/payments?payment=ID`.
- A start-date test failed because storing the start in Kampala time was compared on the UTC database clock (a real bug I introduced). Fixed in `CommissionAssessments::startsAt()`.
- `ProviderFinanceTest` statement test depended on the hour (a Kampala day can hold more than one page of lines). It now reads every page.
- `CommissionObligationTest` asserted the old label "not collected"; it now expects "Recorded obligation" (deliberate wording fix).
- Both 7.4 and 8.3 full suites were once run at the same time and interfered (shared files and cache). Never run two suites at once.
- No known failing test remains.

## 8. Database and migration changes

- Migration `000080` (additive): tables `commission_payment_attempts`, `commission_payment_attempt_events`; columns `commission_payment_reports.source` (default `manual`), `attempt_id`, `receipt_number` (unique); `provider_members.can_pay_commission` (default false). Risk: LOW (new tables, nullable or defaulted columns). `down()` drops them.
- Migration `000081`: inserts permission `finance.settings.manage` for `super_admin`.
- Earlier, already pushed: `000077` (widens `commission_exceptions.status`, new tables), `000078` (permissions), `000079` (`provider_members.can_view_finances`). `000075` was already deployed before.
- Ledger CHECK `commission_ledger_shape` untouched. No destructive change, no data backfill.
- New permission added to both `config/permissions.php` and `resources/js/admin/permissions.js`.
- No new environment variables. `config('workmate.payments.simulator')` defaults on but is ignored outside `local` and `testing`.

## 9. Deferred functionality (owner decisions)

- Real payment gateway integration (interface and callback route are ready; see README "Before a real gateway").
- Adjustment ledger posting. Wallets, escrow, automatic collection or payouts.
- Mandatory pre-job price agreements; account monitoring and commission evasion detection; changes to the unanswered customer payment confirmation workflow; any change to commission rates or assessment policy.
- Activating any financial switch (needs separate explicit owner approval).

## 10. Important architectural decisions

- One credit path: `CommissionSettlements::creditPayment` is the only code that turns a payment into ledger lines, for admin-verified manual payments and for gateway confirmations. Credits at most `min(verified, reported)`, oldest settlement first (the report's own settlement first), and the remainder becomes an exception.
- A mobile money attempt is never a credit. It becomes exactly one verified payment report only through a signed callback. Late or mismatched confirmations open exceptions (`late_payment`, `gateway_mismatch`) and are never applied automatically.
- Amounts are checked against `payable()` (sum of cycle outstanding) minus `reserved()` (pending reports plus open, unexpired attempts).
- Settings dependencies live in `PlatformSettings::assertFinanceRules`: obligations need pricing, estimates and a start moment; those cannot be removed while obligations are on. Settlement is independent. The UI saves "off" changes dependents-first and "on" changes prerequisites-first (`adminApi.js`).
- Start moment is stored as Kampala ISO with `+03:00` and converted to the app clock when read.
- Access: owner always can pay; other members need both `can_view_finances` and `can_pay_commission`.
- The simulated gateway is registered only when `app()->environment(['local','testing'])`; the simulate route is registered under the same condition.
- The admin Commission Payments page owns verify and reject; the old payments tab on Commission Settlements was removed.
- Frontend never computes money. Receipts are plain PDFs from `SimplePdf` (no new package).

## 11. Remaining tasks, unresolved errors and tests still to pass

Remaining tasks:
- B10 is implemented and **awaiting the owner's review**. Nothing else is open. Only the owner's review feedback may add work, and it needs a plan and approval first.
- If B10 is approved for release: classify migrations `000080` and `000081` (both LOW), say no `.env` change is needed, commit B10 separately, push once, watch the deploy and run only read-only production checks (health, assets, signed-out 401s, switches still off).

Unresolved errors:
- None in the code. Known environment notes: the PHP 7.4 suite exceeds 512 MB through `artisan test` (use PHPUnit directly with more memory, only with approval to run the full suite), and the local `workmate` database was wiped earlier (local only; it has not been reseeded and must not be reseeded without being asked).

Tests that still need to pass (only after related code changes, and only the targeted ones; nothing here may be run without a reason and approval for the full suites):
- Everything listed in section 6 passed on the final state, so none need rerunning unless the code they cover changes.
- If review feedback touches them, run only the matching file: `tests/Feature/Api/CommissionCollectionTest.php` (collection, settings dependencies, directory, export), `tests/Feature/Api/CommissionSettlementTest.php` and `CommissionObligationTest.php` (settlement and obligation behaviour), `tests/Feature/Api/ProviderFinanceTest.php` (My Finances), `tests/Feature/Api/FinancialAdministrationTest.php` (admin reports), `tests/Unit/PermissionDefinitionsTest.php` (permission sync), `tests/js/payCommission.test.mjs` and `tests/js/settlement.test.mjs` (frontend helpers).
- The final full-suite run, JS suite, build and browser checks require explicit owner approval each time.

## 12. Exact next steps

1. Wait for the owner's review of the B10 report. Do not implement, test, commit, push or deploy anything before then.
2. After feedback: study the existing code, find root causes, present a plan and wait for approval (AGENTS.md section 12).
3. Implement only the approved scope, run only directly affected tests, report concisely, and stop.
4. Optional local cleanups, only if the owner asks: reseed local sample data; stop the MariaDB 11.4 process left running from `D:\WorkMate-test-temp\env`.

## 13. B10 review outcome and approval (2026-11-24)

- Owner approved B10 for deployment with all four financial switches off. Decisions: Financial Settings management stays Super Administrator only (`finance.settings.manage`); oldest-first allocation across open cycles approved; manual payment and verification workflow unchanged.
- Deferred until a real gateway is integrated, and **mandatory prerequisites before activating any real gateway** (also in README, "Before a real gateway"): late payment reconciliation; gateway amount mismatch resolution; exception traceability to payment attempts; corrected callbacks after a mismatched confirmation.
- Migrations 000080 and 000081 classified LOW. No `.env` change needed.
