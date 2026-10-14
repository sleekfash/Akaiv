# Dependency-security remediation — review candidate

2026-10-14, Africa/Lagos. Base: f575fba5c408c661cda028f7c7159f7ae64f4860. Branch: security/dependency-remediation-20261014. No merge/deployment/production database change, key rotation, real data import or OCR/AI activation. Legacy Laravel6 source remains untouched.

## Verified baseline / implementation

Active PHP lockfile previously6 advisory records across Laravel/framework and league/commonmark. Frontend14 affected nodes. Fresh worker audit now22 affected nodes (7 critical/8 high/7 moderate), rather than prior19; advisory databases change and counts are not proven exploits.

Minimal-change Composer resolution upgrades Laravel11.56.1→12.70.0, CommonMark2.10.0→2.10.3, Pest2→3 and PHPUnit10→11 with compatible testing dependencies. Filament3 and production domain/service APIs are retained. Carbon3 was already locked before the upgrade. No custom HasUuids, Carbon diffIn*, schema constructors or password-token constructor overrides were found in active app source. Local disk roots are explicit. Official upgrade reference: https://laravel.com/framework/docs/12.x/upgrade. Laravel12 is an incremental security-supported bridge, not the latest major; plan a supported-major successor before its2027-02-24 security-support deadline.

Commands in akaiv-saas (PHP executable supplied by isolated scratch runtime):

```
composer update laravel/framework 'pestphp/*' league/commonmark --with-all-dependencies --minimal-changes --no-scripts --no-plugins --no-install --no-interaction
composer audit --locked --format=json
npm update --ignore-scripts --no-audit --no-fund
npm audit --package-lock-only --json
npm run test:frontend
npm run build
```

Composer lockfile audit executed successfully: advisories[], abandoned[], exit0. Resolution:3 new/32 updated packages; unrestricted dry-run would have updated69, so minimal-change resolution was selected. No local application bootstrap or database migration ran during dependency resolution.

Frontend compatible update changed22 packages; fresh scan12 affected nodes (9 high/3 moderate), down from14. fast-uri/source-map-js findings no longer reported. Frontend3 tests and production build passed locally (Vite6.4.4). Remaining findings include braces, postcss-selector-parser and propagated Tailwind3/Stylelint dependencies. No advisories ignored, no dev dependencies excluded from security acceptance, no forced incompatible downgrade/major migration applied.

New DependencySecurityTest covers email CR/LF header-injection rejection and ordinary validation, archive signature expiry and resource substitution. These are application-facing regressions, not proof of every upstream exploit: the signed-path advisory concerns Laravel local-filesystem temporary URLs whereas Akaiv serves through signed application controllers. CommonMark patch is verified by dependency audit; no claim of a reproduced Markdown exploit.

New dependency-security workflow audits PHP and both JavaScript lockfiles separately, includes tooling, fails on any severity and has no continue-on-error. This is an additional visible gate; enforcement on protected branches remains an owner-controlled repository setting.

## Access limits and unresolved security acceptance

`npm view wrangler`, `npm view vite@6` and `npm view tailwindcss@3` received E403 registry access-policy responses here. No alternate registry, proxy or alternate environment was used to bypass them. Compatible frontend update completed through the ordinary package manager, but denied package lookup operations are not retried. Worker upgrades requiring that access are BLOCKED pending guidance. @cloudflare/vitest-plugin migration documentation inspected only; old worker test runtime/SDK remains unchanged and inactive. Do not call worker advisories remediated.

`npm view braces version` returned3.0.3, still in scanner affected range<=3.0.3. PostCSS selector parser latest7.1.6 was visible but existing consumers require older majors; major overrides need compatibility verification. A clean frontend scan has NOT been obtained. Do not blindly force Tailwind4 into the Filament3 theme or accept npm's proposed Stylelint downgrade to7.

Legacy prior scan34 advisory records remains a separate owner-controlled operational risk; no legacy upgrade authorized here. Historical credentials, actual-provider security, real legacy mapping, judicial policies and encrypted off-site recovery remain open and are not addressed by dependency upgrades.

## Verification checkpoint

Candidate execution evidence is recorded below. Current classification: partial remediation READY FOR REVIEW; full dependency-security acceptance BLOCKED by unresolved npm findings/access. No production readiness claim.

## Executed candidate verification

Tested code/runtime commit:489d813afdf2a4d8462793b8983abae421095025.
[CI38605664193](https://github.com/sleekfash/Akaiv/actions/runs/38605664193) passed: Laravel job115995757233 and worker job115995757879. PHP8.3, Node22, disposable PostgreSQL16/Redis7;46 application tests/145 assertions,3 frontend tests, production build/Pint, worker typecheck and5 containment tests. New email and signed-route regressions all executed successfully. CI runs `composer install --no-interaction --prefer-dist --no-progress`, `npm ci --no-audit --no-fund`, `npm run test:frontend`, `npm run build`, `vendor/bin/pint --test`, isolated `php artisan migrate --force`, `vendor/bin/pest --colors=never`, worker `npm run typecheck` and `npm test`. Existing three repeated signed-serving cases each passed2 assertions; not additional unique coverage.

[Dependency security38605664174](https://github.com/sleekfash/Akaiv/actions/runs/38605664174): PHP audit job115995757577 PASSED; frontend115995756970 and worker115995757555 FAILED. No failure suppressed, no advisory allowlist, no production-only exclusion. Independent logs confirm PHP advisories[], abandoned[]. Full security workflow is FAILED despite functional CI success.

[Node22 pilot38605664171](https://github.com/sleekfash/Akaiv/actions/runs/38605664171), job115995757277, tested commit489d813afdf2a4d8462793b8983abae421095025, independently passed:23 browser checks with no page errors; actual isolated S3 immutable/private bytes, ClamAV clean/EICAR/outage, separate publication review, PostgreSQL tenant constraints, four audit writers, Redis retry exhaustion with retained failed job, and coordinated isolated database/object restore with historical/current hashes and independent audit anchor. Login/reset/logout, metadata persistence, read-only/sealed checks and protected serving executed successfully. Node version in CI logs22.23.3. No encrypted off-site production-recovery claim. Earlier Node20 pilot38605109561 also passed but is retained as historical evidence, not substituted for this run. Final report follow-up is documentation-only; run identifiers belong to the tested code/runtime SHA above.

## Milestone progress report

| Required item | Evidence / disposition |
|---|---|
| Phase/objective | Dependency-security remediation and isolated compatibility verification. |
| Verified completed | PHP advisories resolved in supported Laravel12.70/CommonMark2.10.3; compatible frontend updates; Node22 CI compatibility; security regressions and explicit audit gates. |
| Unsuccessful | Denied npm metadata lookups403; both npm audit jobs still fail. First connector blob upload hit a transient response error; retried same ordinary operation successfully. |
| Risks | Remaining frontend and worker advisories, unverified Docker/system-package/image security, legacy deployment and credential exposure; no exploitability assumptions. |
| Blockers | Approved npm registry/workspace access for denied lookups; compatible strategy for advisory-bearing Tailwind3/Stylelint dependencies. Judicial and actual-provider release gates remain unchanged. |
| Components | Active Composer manifest/lock, frontend lock, DependencySecurityTest, dependency-security/CI/pilot workflows, prepared Docker Node reference, developer runtime version, AGENTS/runbook and this report. No domain services/policies/migrations, worker source or legacy files changed. |
| Tests |46/145 plus frontend3/worker5,23 browser checks, real synthetic infrastructure/restore and clean PHP audit; exact SHA/run/job IDs above. npm audit FAILED. Docker build/scans and deployed runtime verification NOT executed. |
| Compliance | No OCR/AI/sharing activation, main merge/deployment/real-data import/production mutation/rotation/history rewrite. |
| Next safe action | Provide approved registry/runtime access; resolve remaining tooling dependencies with compatibility tests, then repeat full audits. Never infer full completion from clean PHP results. |
| Approvals | Access direction needed for affected npm work; owner controls any risk acceptance, protected-branch enforcement, live-system changes and separate deployment/import/merge approvals. |

Status: partial technical remediation READY FOR REVIEW; full dependency-security milestone BLOCKED/NOT COMPLETE. Earlier Phase1 reports remain historical evidence rather than silently rewritten acceptance. This report supersedes their PHP dependency status only for this unmerged candidate. Production and migration readiness remain BLOCKED.

## Runtime lifecycle and first CI security gate

Node20 is end-of-life per https://nodejs.org/en/about/eol and the official release schedule. Prepared CI/pilot/Docker build references now use supported Node22, with root .node-version for developers. No Docker build was executed here (Docker unavailable); Docker base-image/system-package vulnerability scanning remains unverified. PHP8.3 runtime is retained. Local frontend tests/build used Node24.19.0; updated Node22 CI compatibility must execute before claiming it passed.

Commit a21251f6146e178cceb985a677996f3a4b5b12b4: dependency-security run38605109581 PHP audit job115993866636 passed (strict manifest validation, advisories[], abandoned[]). Frontend job115993867178 failed with12 nodes (9 high/3 moderate); worker job115993867171 failed with19 nodes (3 critical/10 high/6 moderate). This worker response differs from the22-node local response on unchanged lockfile; no clean result can be inferred from either and no cause of registry/advisory response variance is established. Failed npm acceptance is preserved explicitly, not downgraded or ignored. Standard CI38605109473 and pilot38605109561 initially ran on Node20; revised runtime candidate requires a fresh run.
