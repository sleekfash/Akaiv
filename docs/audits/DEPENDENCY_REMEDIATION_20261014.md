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

Candidate PHP/PostgreSQL/browser verification pending. Exact commit/run IDs and executed results will be recorded here. Current classification: partial remediation READY FOR REVIEW; full dependency-security acceptance BLOCKED by unresolved npm findings/access. No production readiness claim.
