---
sessionId: session-261012-163408-1x07
---

# Requirements

### Overview & Goals
The fork branch `zs2.0` (remote `origin` = zangra-dev/AdyenPlugin) is **5 commits ahead / 28 commits behind** `sylius/2.0` (Sylius/AdyenPlugin). Goal: bring in the upstream 2.0 changes **without breaking** the Zangra fork features or the host project `~/Dev/prj/zangra-sylius`, and decide whether the upstream `2.1` branch is worth adopting.

### Scope
**In scope**
- Merge `sylius/2.0` into `zs2.0` and resolve/verify.
- Run the plugin test suites (phpunit unit/functional, phpstan, ecs) after merge.
- Dry-run the dependency resolution in `zangra-sylius` (no changes to that repo's working tree).
- Evaluate `sylius/2.1` and recommend adopt / cherry-pick / skip.

**Out of scope**
- Modifying `zangra-sylius` files (it has uncommitted `composer.json` / `docker-compose.yml` changes).
- Upgrading `zangra-sylius` to Sylius 2.3 / Symfony 7.4+/8.
- Pushing branches (user does that).

### Findings (investigation results)
| Item | Result |
|---|---|
| Fork-only commits (5) | Bancontact pending-payment fallback (`AuthorizePendingPayment*`, `PaymentNotificationResolver`, `PaymentCurrencyResolver`), currency config, Log `token` column + migration `Version20241119094104`, dropin.js update, `DoctrineHandler` fix |
| `git merge-tree` HEAD + `sylius/2.0` | **Clean, zero conflicts** |
| `git merge-tree` HEAD + `sylius/2.1` | **4 conflicts** (modify/delete): `config/services/{adyen,bus,logging,resolvers}.xml` — upstream converted all service XML to PHP |
| Upstream 2.0 content (28 commits) | CI/Behat setup, `.gitattributes`, `HttpClientStub` moved to `tests/Behat/Service`, liip-imagine `conflict`, branch-alias bump, `sylius/telemetry` + `TelemetryCompilerPass` + `TELEMETRY_POLICY.md`, migration `Version20260105150445` (utf8mb4 fix for `sylius_adyen_payment_detail`), migration charset fix, OpenApi PHPStan fixes, workflow-transition fix (#190) |
| Host project | PHP ^8.2, **Symfony 6.4.46**, Sylius **2.2.9**, `sylius/refund-plugin` v2.1.0, `sylius/telemetry` v1.0.1 (already installed), requires `sylius/adyen-plugin: dev-zs2.0` from the zangra-dev VCS repo, uses **winzou** state machine (`config/packages/zangra_state_machine.yaml`) with custom payment callbacks |
| Host `composer.lock` | Out of sync: locks `dev-ZNG-43` from `pbalcerzak/AdyenPlugin` @ `9176628` while `composer.json` asks `dev-zs2.0` |

### Assessment
**Upstream 2.0 → worth merging, low risk.**
- Conflict-free; compatible with Symfony 6.4 / Sylius 2.2.9 (`symfony/messenger ^6.4 || ^7.2` unchanged; telemetry already in host).
- #190 only drops the duplicate `request_payment authorized→awaiting_payment` transition when Sylius >= 2.2.7 (host is 2.2.9, so it uses the native one) — neutral/beneficial.
- New migration is idempotent-safe (`CONVERT TO CHARACTER SET`), no schema change; our `Version20241119094104` keeps its `skipIf` guard, so migration order (older timestamp, newer in file list) is harmless.

**Upstream 2.1 → do NOT merge wholesale now.**
- Requires `sylius/refund-plugin ^2.2` (host has 2.1.0), targets Sylius 2.3 / Symfony 8 / Behat 4, converts services XML→PHP (4 conflicts with our edited XML files), and its largest churn (87 files) is test/CI/PHPStan-2 related.
- **Cherry-pick candidates worth taking** (they are 2.2-compatible and do not need new deps):
  - `9756912` winzou fix: `config/winzou_state_machine/sylius_payment.yaml` gets `priority: -200` for `update_order_payment_state` on `cancel`/`refund`. **Relevant to the host** because it uses winzou on Sylius 2.2 (Sylius' own -100 callbacks otherwise overwrite the reversal outcome).
  - `fc58eaf` / `PaymentCheckoutOrderResolver` and `RemoveCartAction`: replace `Request::get()` (removed in Symfony 8, deprecated in 6.4/7.x) — optional, safe.
  - `8f34fad` PaymentLink `createdAt` type alignment — optional, needs a look at whether it needs a migration in the host.
  - `eaad9ab` admin twig hook suggesting Adyen — optional, cosmetic, requires twig hooks (Sylius 2.x) — skip by default.
- Re-evaluate full 2.1 adoption when `zangra-sylius` moves to Sylius 2.3 / refund-plugin 2.2.

### Functional Requirements
- After merge, all fork features (Bancontact fallback, currency config, log token) keep working: existing `PaymentNotificationResolverPendingPaymentTest` passes.
- `composer validate` passes and `composer update sylius/adyen-plugin --dry-run` in a **temp copy** of the host's `composer.json`/`composer.lock` resolves with Sylius 2.2.9 / Symfony 6.4.
- No change to the public service IDs / config keys used by the host (`bitbag_sylius_adyen.yaml`, `messenger.yaml`, `zangra_state_machine.yaml`).

# Technical Design

### Current Implementation
- Branch `zs2.0` tracks `origin/zs2.0`; merge-base with `sylius/2.0` is `2d99ca5` (`[UPMERGE] 1.0 -> 2.0 (#173)`).
- Fork commits touch: `config/services/{adyen,bus,logging,resolvers}.xml`, `src/Bus/Command/AuthorizePendingPayment.php`, `src/Bus/Handler/AuthorizePendingPaymentHandler.php`, `src/Resolver/PaymentNotification/PaymentNotificationResolver.php` (+`NotificationRetryRequiredException`), `src/Resolver/Currency/PaymentCurrencyResolver.php`, `src/DependencyInjection/{Configuration,SyliusAdyenExtension}.php`, `src/Client/ClientPayloadFactory.php`, `src/Entity/Log*`, `src/Factory/LogFactory*`, `src/Logging/Monolog/DoctrineHandler.php`, `config/doctrine/Log.orm.xml`, `config/grids/admin/log.yaml`, `src/Migrations/Version20241119094104.php`, `assets/shop/js/dropin.js`, plus docs under `docs/`.
- Upstream 2.0 touched different files except `composer.json`, `src/SyliusAdyenPlugin.php`, `tests/Behat/Resources/services.xml`, which do not overlap with fork edits.

### Key Decisions
1. **Merge (not rebase) `sylius/2.0` into `zs2.0`.** The host consumes `dev-zs2.0` and other clones (and `piotr`/`ZNG-43` remotes) may track it; a merge commit avoids rewriting shared history. Clean merge confirmed via `git merge-tree`.
2. **Do not adopt `sylius/2.1` wholesale.** Hard blockers: `refund-plugin ^2.2`, Sylius 2.3/Symfony 8 test matrix, XML→PHP service conversion conflicting with fork XML. Revisit after host upgrade.
3. **Cherry-pick only the winzou priority fix (`9756912`)** from 2.1 because the host uses winzou on Sylius >= 2.2; other 2.1 items stay out to keep the diff to upstream small.
4. **Keep `composer.json` constraints as upstream 2.0** (`sylius/sylius ^2.0`, `symfony/* ^6.4 || ^7.2`, add `sylius/telemetry ^1.0`) — satisfied by host.

### Proposed Changes
- `git switch zs2.0 && git merge sylius/2.0` (no-ff, message `Merge sylius/2.0 into zs2.0`). Expected auto-merges: `composer.json`, `src/SyliusAdyenPlugin.php`.
- After merge, verify `composer.json` `extra.branch-alias` stays `dev-2.0` (upstream) — if the host relies on `dev-zs2.0` no alias is needed.
- `git cherry-pick 9756912` (only `config/winzou_state_machine/sylius_payment.yaml`, +4 lines, no conflict expected).
- Re-run quality gates: `vendor/bin/phpunit --testsuite=unit`, `--testsuite=functional` (incl. `AddOrderPaymentWorkflowTransitionPassTest` updated upstream and our `PaymentNotificationResolverPendingPaymentTest`), `vendor/bin/phpstan analyse`, `vendor/bin/ecs check`. Fix any fork-code failures (e.g. PHPStan complaints from upstream's stricter OpenApi typing, `tests/Functional/AdyenTestCase.php` changes).
- Confirm `HttpClientStub` move (`tests/Behat/Service/HttpClientStub.php`) doesn't break any fork test referencing the old namespace (`grep` for `Tests\\Sylius\\AdyenPlugin\\HttpClientStub`).
- Host compatibility dry-run (read-only for the host): copy `zangra-sylius/composer.json` + `composer.lock` to `$JUNIE_TMPDIR`, point the plugin VCS entry at the local merged branch and run `composer update sylius/adyen-plugin --dry-run --no-scripts` to check resolution; inspect that the host's winzou `sylius_payment` callbacks and `process` transition (`new, cancelled, failed → processing`) do not collide with the plugin's `config/winzou_state_machine/sylius_payment.yaml`.
- Write a short upgrade note in the final answer: host should run `composer update sylius/adyen-plugin` (lock currently pins `dev-ZNG-43` from `pbalcerzak`) and `doctrine:migrations:migrate` (new `Version20260105150445`).

### Risks
- **Migration ordering/duplicates:** `Version20241119094104` (token) vs upstream newer ones — guarded by `skipIf`; host also has legacy BitBag tables (`bitbag_sylius_adyen.yaml`), upstream migration `Version20250825063005` was adapted for that.
- **Host lock mismatch:** host lock points at `pbalcerzak/AdyenPlugin` `dev-ZNG-43`; updating will switch to `zangra-dev` `dev-zs2.0` — confirm with the user before running it for real.
- **Winzou priority callback:** changes callback order in the host for `cancel`/`refund`; behavior is intended upstream but should be exercised in the host's payment tests.
- **Upstream CI workflow rewrite** (`.github/workflows/build.yaml`) uses a `BuildTestAppAction` of Sylius — may not work in the zangra-dev fork CI; acceptable/informational.

### Architecture Diagram
``` mermaid
graph LR
  U20[sylius/2.0] -->|merge clean| ZS[zs2.0 fork]
  U21[sylius/2.1] -.->|cherry-pick 9756912 only| ZS
  ZS -->|dev-zs2.0| HOST[zangra-sylius Sylius 2.2.9 Symfony 6.4]
  U21 -.->|needs refund-plugin 2.2, Sylius 2.3| HOST
```

# Testing

### Validation Approach
Run the plugin's own suites after the merge and cherry-pick, then do a read-only dependency dry-run against the host.

### Key Scenarios
- `vendor/bin/phpunit --testsuite=unit` and `--testsuite=functional` pass (including `AddOrderPaymentWorkflowTransitionPassTest` and `PaymentNotificationResolverPendingPaymentTest`).
- `vendor/bin/phpstan analyse` and `vendor/bin/ecs check` pass.
- `composer validate --strict` passes.
- Temp-copy `composer update sylius/adyen-plugin --dry-run` resolves with Sylius 2.2.9 / Symfony 6.4.46 / refund-plugin 2.1.0.

### Edge Cases
- `git diff --stat sylius/2.0..HEAD` shows only the intended fork changes + the single winzou YAML cherry-pick.
- Migrations: `bin/console doctrine:migrations:list` (test app) shows both `Version20241119094104` and `Version20260105150445` without errors.
- No remaining references to the moved `HttpClientStub` namespace.

### Test Changes
No new tests unless the merge reveals a fork-code regression; if the winzou ordering needs coverage, add a functional assertion that `update_order_payment_state` callback priority is -200.

# Delivery Steps

###   Step 1: Merge sylius/2.0 into zs2.0 and fix fallout
`zs2.0` contains all 28 upstream 2.0 commits plus our 5 fork commits, and builds cleanly.

- Check `git status` is clean, then `git merge --no-ff sylius/2.0` on `zs2.0` (dry-run via `git merge-tree` already shows zero conflicts).
- Review auto-merged `composer.json` (adds `sylius/telemetry`, `conflict` on liip/imagine 2.17.0, branch-alias) and `src/SyliusAdyenPlugin.php` (`TelemetryCompilerPass`).
- Run `composer install`/`validate` and fix any dependency issue.
- Check for stale references to the old `HttpClientStub` namespace in fork tests and adjust.

###   Step 2: Cherry-pick winzou fix from sylius/2.1
The winzou `update_order_payment_state` callback runs at priority -200 so it is not overwritten by Sylius 2.2 callbacks.

- `git cherry-pick 9756912` (changes only `config/winzou_state_machine/sylius_payment.yaml`).
- Leave all other 2.1 commits (XML→PHP conversion, Symfony 8, refund-plugin ^2.2, admin twig hook) out; document why in the final summary.
- Optionally evaluate `Request::get()` replacement commit as a follow-up.

###   Step 3: Run quality gates on the merged branch
phpunit (unit + functional), phpstan and ecs all pass on the merged branch.

- Run `vendor/bin/phpunit --testsuite=unit` and `--testsuite=functional`, with focus on `AddOrderPaymentWorkflowTransitionPassTest` and `PaymentNotificationResolverPendingPaymentTest`.
- Run `vendor/bin/phpstan analyse` and `vendor/bin/ecs check`; fix fork code violations only.
- Verify migrations listing includes `Version20241119094104` and `Version20260105150445`.

###   Step 4: Dry-run compatibility with zangra-sylius and report
A verified go/no-go report for the host project and for upstream 2.1, with no change to the host working tree.

- Copy host `composer.json`/`composer.lock` to the scratch dir and run `composer update sylius/adyen-plugin --dry-run --no-scripts` against the merged branch.
- Compare host `config/packages/zangra_state_machine.yaml` and `bitbag_sylius_adyen.yaml` with the plugin's winzou/config for collisions.
- Summarize: merge result, 2.1 blockers (refund-plugin ^2.2, Sylius 2.3, Symfony 8, XML→PHP conflicts), host upgrade steps (`composer update sylius/adyen-plugin`, lock currently pins `dev-ZNG-43` from `pbalcerzak`, run migrations) and recommendation to revisit 2.1 after the host upgrades.