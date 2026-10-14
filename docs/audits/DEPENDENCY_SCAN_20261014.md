# Executed dependency scan

Baseline lockfiles: `3b41102291757cf5ab1537ed5f46ee8d31cdcc9a`; 2026-10-14. PHP CLI8.3.6; Composer executed with plugins disabled. These are advisory-database findings, not proven application exploits. No dependency versions were changed in this candidate.

Commands from akaiv-saas:

```
/workspace/scratch/8fb89b0f219e/runtime/bin/php /workspace/scratch/8fb89b0f219e/runtime/composer.phar audit --locked --format=json
npm audit --json
npm audit --package-lock-only --json
```

Initial direct scans exited nonzero (advisories); summary reruns piped to a JSON parser exited0 for successful parsing, NOT a clean audit. npm installed and locked scans both report14 affected dependency nodes:10 high,4 moderate,0 critical. This includes propagated findings, not14 independent vulnerabilities. They are frontend development/build dependencies; no exploitability assessment of untrusted build inputs was completed.

| Composer package | Advisory | Scanner severity |
|---|---|---|
| laravel/framework | GHSA-jh5r-qr3c-85q8 — debug information XSS, CVE-2026-102279 | Low |
| laravel/framework | GHSA-crmm-hgp2-wgrp — temporary signed URL path confusion | Medium |
| laravel/framework | GHSA-5vg9-5847-vvmq — default email CRLF, CVE-2026-48019 | High; duplicate upstream record has no severity |
| league/commonmark | GHSA-97jj-33gv-5xf9 — disallowed raw HTML bypass | Medium |
| league/commonmark | GHSA-3q6v-r5mr-hxv8 — GFM table scan denial of service | High |

Composer returns6 advisory records across2 packages; email advisory duplicated across databases, so5 distinct GHSA identifiers. Abandoned list empty.

npm primary advisory identifiers: braces GHSA-vfj7-8cjw-p6xm (high), fast-uri GHSA-hrr3-gc8f-f4qj (moderate), postcss-selector-parser GHSA-rj75-hqrm-r3gf (moderate), source-map-js GHSA-68fv-2mgg-jv7q (high). Affected propagated nodes additionally include @tailwindcss/typography, chokidar, fast-glob, globby, micromatch, postcss-nested, stylelint and its two configs, tailwindcss. Do not apply suggested major upgrades/downgrades blindly.

Official Laravel support table (checked 2026-10-14): https://laravel.com/docs/11.x/releases lists Laravel11 security support ending2026-03-12. Existing DEPENDENCY_AUDIT.md claims Laravel11 is current/LTS and contains a simulated scan; those claims are superseded, not fresh evidence.

Follow-up: supported Laravel upgrade in an isolated focused branch, compatible patch upgrades for advisory-bearing dependencies, runtime reachability assessment, signed URL/email/Markdown regressions and clean repeat audits. APP_DEBUG=false mitigates debug-page exposure only; it is not a dependency patch or resolution of signed-route/email findings. Release gate remains BLOCKED. No live configuration changed.

## Additional scoped scans

`npm audit --package-lock-only --json` from workers/agents-service returned19 affected nodes:3 critical,10 high,6 moderate. Critical nodes: vitest (direct development test dependency), tinypool and proxy-addr (transitive). Other affected nodes include agents, MCP client/SDK, esbuild, fast-uri, hono, ip-address, miniflare, sharp, undici, vite/vite-node, wrangler, ws and worker test-pool. The reserved worker returns404 and Phase1 imports no AI consumer, but toolchain advisories still require remediation. This scan does not prove any deployed service is exploitable. No dev/test server should be publicly exposed.

`composer audit --locked --format=json` from myarchivesonline.com returned34 advisory records across13 packages: facade/ignition, laravel/framework, league/flysystem, nesbot/carbon, phpunit/phpunit, psy/psysh, swiftmailer/swiftmailer, symfony/error-handler, symfony/http-foundation, symfony/http-kernel, symfony/mime, symfony/process, symfony/routing. Legacy source was not modified or executed; actual live versions/configuration are UNKNOWN. Keep it read-only/offline here, and have the owner assess the old live system separately. Parsed summaries exited0 only because JSON parsing succeeded; findings remain failed security acceptance.
