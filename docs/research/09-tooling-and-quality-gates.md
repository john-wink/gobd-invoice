# Paragon-Level Tooling & Quality Gates

> **Verification status (lens: current best practice, verified June 2026).** Package names, version
> constraints, config keys and tool capabilities below were checked against the actual current
> (2025/2026) releases and each project's own docs/repo. Corrections from the original draft are
> annotated inline with **[corrected]** / **[caveat]**. Nothing here was deprecated at the time of
> review except where explicitly flagged.

## Scope and posture

This note defines the **tooling and quality-gate layer** for `john-wink/gobd-invoice` — a
framework-only (no Filament, no Blade) GoBD-compliant German-invoicing engine. The target is
"paragon level": the bar set by Spatie's `package-skeleton-laravel` and high-end packages like
`spatie/laravel-data`, raised to **PHPStan max + Rector + Pest 4 mutation/type-coverage gates**,
suitable to demo on stage at Laracon in ~8 months. Everything below is copy-pasteable and
version-pinned for **mid-2026**.

Two framing decisions drive the whole config:

1. **Depend on `illuminate/contracts`, not `laravel/framework`.** A backend engine should pull the
   narrowest dependency surface so it installs into any Laravel 13 app without version friction. The
   framework itself is a *dev* dependency (pulled transitively by Testbench). This mirrors the Spatie
   skeleton, which requires `illuminate/contracts: ^11.0||^12.0||^13.0` in production and brings
   `laravel/framework` only through `orchestra/testbench` in `require-dev` [1].
2. **No Blade/JS to format.** Because the package is framework-only, Prettier's job shrinks to
   `*.json`, `*.yml`/`*.yaml`, `*.md` — there is no Blade-formatter decision to make. (If you ever
   ship a published config stub or a separate docs site, revisit this.)

---

## 1. `composer.json`

The `require` block is deliberately minimal. Add `nesbot/carbon` only if you do date math beyond what
`illuminate/support` re-exports; you usually do not need it explicitly.

```json
{
    "name": "john-wink/gobd-invoice",
    "description": "GoBD-compliant German business-document engine (Rechnung, Angebot, Storno, e-invoice) for Laravel.",
    "keywords": ["laravel", "gobd", "invoice", "xrechnung", "zugferd", "e-rechnung", "rechnung"],
    "homepage": "https://github.com/john-wink/gobd-invoice",
    "license": "MIT",
    "authors": [
        { "name": "John Wink", "role": "Developer" }
    ],
    "require": {
        "php": "^8.4",
        "illuminate/contracts": "^13.0",
        "spatie/laravel-package-tools": "^1.16"
    },
    "require-dev": {
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.18",
        "nunomaduro/collision": "^8.8",
        "orchestra/testbench": "^11.0",
        "pestphp/pest": "^4.0",
        "pestphp/pest-plugin-arch": "^4.0",
        "pestphp/pest-plugin-laravel": "^4.0",
        "pestphp/pest-plugin-mutate": "^4.0",
        "pestphp/pest-plugin-type-coverage": "^4.0",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/phpstan-phpunit": "^2.0",
        "rector/rector": "^2.5",
        "driftingly/rector-laravel": "^2.5"
    },
    "autoload": {
        "psr-4": {
            "JohnWink\\GobdInvoice\\": "src/",
            "JohnWink\\GobdInvoice\\Database\\Factories\\": "database/factories/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "JohnWink\\GobdInvoice\\Tests\\": "tests/",
            "Workbench\\App\\": "workbench/app/",
            "Workbench\\Database\\Factories\\": "workbench/database/factories/"
        }
    },
    "scripts": {
        "post-autoload-dump": "@php vendor/bin/testbench package:discover --ansi",
        "analyse": "vendor/bin/phpstan analyse",
        "test": "vendor/bin/pest",
        "test-coverage": "vendor/bin/pest --coverage",
        "test-types": "vendor/bin/pest --type-coverage --min=100",
        "test-mutate": "vendor/bin/pest --mutate --parallel --covered-only",
        "format": "vendor/bin/pint",
        "lint": "vendor/bin/pint --test",
        "rector": "vendor/bin/rector process",
        "rector-check": "vendor/bin/rector process --dry-run"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "phpstan/extension-installer": true
        }
    },
    "extra": {
        "laravel": {
            "providers": ["JohnWink\\GobdInvoice\\GobdInvoiceServiceProvider"]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Version notes (verified June 2026):**

- `rector/rector` latest stable is **2.5.2** (`rector-src` tagged 2026-06-22; the `rector/rector`
  distribution mirror was updated 2026-06-24) and bundles the dead-code, code-quality and
  type-declaration prepared sets in core [2].
- `driftingly/rector-laravel` is at **2.5.0** (2026-06-02) [3]. **The canonical package name is
  `driftingly/rector-laravel`.** The old `rector/rector-laravel` package was **abandoned** and now
  redirects on Packagist to `driftingly/rector-laravel` [3]; do not require the `rector/`-namespaced
  one.
- `larastan/larastan ^3.0` targets PHPStan **2.x** (`phpstan/phpstan: ^2.2.0`) and requires
  **PHP 8.2+** and **Laravel 11.44.2+** (`illuminate/console: ^11.44.2 || ^12.4.1 || ^13`). Latest 3.x
  at review time was **v3.10.0** (2026-05-28) [4]. **[corrected]** The original draft said "Laravel
  11.15+"; the real floor is **11.44.2**. (This only matters if you widen `illuminate/contracts`
  below `^13`; on a Laravel-13 baseline it is moot.)
- `pestphp/pest-plugin-mutate` is **4.0.1** (released **2025-08-21**) [5] and
  `pestphp/pest-plugin-type-coverage` is **4.0.4** (2026-04-06) [6] — both are **separate plugins**,
  not bundled into Pest core. **[caveat]** `pest-plugin-mutate` has not had a stable release since
  2025-08; confirm there isn't a newer patch when you pin, but `^4.0` is correct.
- The Spatie skeleton itself now ships `php: ^8.4`, Pest 4 plugins, and Testbench
  `^11.0.0||^10.0.0||^9.0.0` [1]. (Note: the skeleton does **not** include the mutate or
  type-coverage plugins or Rector by default — those are paragon-level additions; see Section 11.)

**One deviation from the skeleton you should make on purpose:** the Spatie skeleton keeps
`illuminate/contracts: ^11||^12||^13` to support three majors. Since this is a *new* package, you may
pin `^13.0` only (Laravel 13 + PHP 8.4 baseline) to keep the support matrix small, or widen to
`^12.0||^13.0` if you want one extra major. Whatever you choose, the CI matrix below must match it
exactly. **[caveat]** If you do widen below `^13`, remember Larastan's real floor is Laravel 11.44.2,
so a `prefer-lowest` leg will resolve Laravel to at least that.

---

## 2. PHPStan + Larastan at MAX

The skeleton ships `level: 5` as a gentle default and **includes a `phpstan-baseline.neon`** [7]; for
paragon level you go to **max** (currently level 10) with no baseline. A *new* package has no legacy
debt, so commit to a clean baseline-free `phpstan.neon.dist`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: max
    paths:
        - src
        - config
        - database
    treatPhpDocTypesAsCertain: false
    checkModelProperties: true
    checkOctaneCompatibility: false
    tmpDir: build/phpstan
    # No baseline. Fix every error; do not suppress.
```

- **`level: max`** is the documented alias to the highest level. In PHPStan 2.x the levels run **0–10
  (11 levels total)** and `max` currently maps to **level 10** [8]; using the alias means you
  auto-adopt new strictness as PHPStan ships levels. Level 10 specifically tightens handling of the
  implicit `mixed` type [8].
- **`treatPhpDocTypesAsCertain: false`** is the Spatie convention (used in e.g.
  `spatie/laravel-query-builder`'s `phpstan.neon.dist`) [9]: it stops PHPStan treating your own PHPDoc
  as ground truth, which avoids the anti-pattern of "writing a docblock to silence a real type
  error." Best practice is to *not* flip it back to `true` to mask annotations. **[caveat]** That
  Spatie file runs `level: 6` (not max) — the *flag convention* is what we are borrowing, not the
  level.
- **`larastan/larastan`** is pulled via the `extension.neon` include [4]. With
  `phpstan/extension-installer` in `allow-plugins`, the `phpstan-deprecation-rules` and
  `phpstan-phpunit` extensions are **auto-registered at composer install/update time**, so they need
  no manual `includes` entry [10]. (The explicit Larastan `include` line above is still the
  documented, deterministic way to load Larastan; you may also rely on the extension-installer for it,
  but keeping the line is harmless and clearer.)
- **Baseline strategy:** none for greenfield. If you ever inherit debt, generate
  `phpstan-baseline.neon` via `phpstan analyse --generate-baseline`, include it, and treat shrinking it
  as a CI-visible metric — never grow it.
- **`checkModelProperties: true`** is valuable here because you will have many Eloquent models
  (Invoice, Position, Storno, etc.) with casts; it forces correct `@property` annotations.

**Software requirement implication:** GoBD immutability (*Unveränderbarkeit*) means audited documents
must be append-only. PHPStan max + `checkModelProperties` helps enforce that your immutable value
objects and read-only model accessors are correctly typed, but the immutability *enforcement* itself
is a runtime/DB concern (hash chains, no `UPDATE` on finalized rows) — static analysis only guards the
type contracts around it.

---

## 3. Rector

Use the modern `withPhpSets()` + `withPreparedSets()` API. Per the Rector docs, the best practice is
**empty `withPhpSets()`** so Rector reads the PHP version from `composer.json` (`^8.4`) automatically
("Rector will automatically pick it up with empty `->withPhpSets()` method") [10a]. Bundle Laravel
rules via `driftingly/rector-laravel`.

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelLevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()                 // auto-detects PHP 8.4 from composer.json
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
    ])
    ->withImportNames(removeUnusedImports: true);
```

- `withPreparedSets()` parameters confirmed in the Rector docs include `deadCode`, `codeQuality`,
  `codingStyle`, `naming`, `privatization`, `typeDeclarations`, and `rectorPreset` [10a]. **[caveat]**
  `earlyReturn` is a real prepared set and is accepted by `withPreparedSets()`, but it is **not shown
  in the docs' example snippet** — the docs say "Try autocomplete in your IDE to see all available
  prepared sets." Verify it against your installed Rector version's signature; drop it if your version
  doesn't expose it. These are the high-value ones for a clean library.
- **Pint/Rector overlap:** `codingStyle` and `naming` can fight Pint. Either drop `codingStyle` from
  Rector and let Pint own formatting (simplest), or run Rector first then Pint second in the `format`
  script. The conservative paragon choice is: **Rector owns semantics/types, Pint owns
  whitespace/style** — so you may omit `codingStyle: true` if you see churn.
- `LaravelLevelSetList::UP_TO_LARAVEL_130` applies all version-upgrade rules through Laravel 13; the
  per-version `LARAVEL_130` set is only the 12→13 delta [12]. For a greenfield 13 package,
  `UP_TO_LARAVEL_130` is fine and future-proofs against accidental old idioms.
- An alternative auto-detecting config exists:
  `->withSetProviders(LaravelSetProvider::class)->withComposerBased(laravel: true)` — confirmed in the
  `driftingly/rector-laravel` README [3]. It picks Laravel sets from your installed version. Prefer the
  explicit sets above for a library so the behavior is deterministic in CI across the matrix.
- CI runs `rector process --dry-run` (fails if changes would be made).

---

## 4. Laravel Pint

Pint with the `laravel` preset plus a few strictness rules. `pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "strict_comparison": true,
        "ordered_imports": { "sort_algorithm": "alpha" },
        "no_unused_imports": true,
        "fully_qualified_strict_types": true,
        "global_namespace_import": {
            "import_classes": true,
            "import_constants": true,
            "import_functions": false
        }
    }
}
```

- `vendor/bin/pint` fixes; `vendor/bin/pint --test` is the CI gate (does not modify) — the skeleton
  exposes `format` as `vendor/bin/pint` [7].
- `declare_strict_types` + `strict_comparison` are the two rules that most cleanly push the codebase
  toward what PHPStan max wants, reducing Rector/PHPStan friction.
- **CI choice:** The Spatie skeleton uses an *auto-fix-and-commit* workflow
  (`aglipanci/laravel-pint-action` + `git-auto-commit-action`) [13]. For a package you intend to keep
  pristine, the stronger paragon stance is a **`pint --test` gate that fails the PR** (developers run
  `composer format` locally) rather than bot commits in history. Pick one; do not run both.

---

## 5. Prettier + EditorConfig

Because there is **no Blade and no JS** in a framework-only engine, Prettier only normalizes
config/docs files. Keep it tiny.

`.prettierrc.json`:
```json
{
    "printWidth": 100,
    "tabWidth": 2,
    "overrides": [
        { "files": "*.md", "options": { "proseWrap": "preserve" } }
    ]
}
```

`.prettierignore`: `vendor`, `build`, `composer.lock`, `CHANGELOG.md` (let your changelog tool own its
formatting).

`.editorconfig` (PHP uses 4 spaces, YAML/JSON/MD use 2):
```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = space

[*.php]
indent_size = 4

[*.{json,yml,yaml,md,neon}]
indent_size = 2

[*.md]
trim_trailing_whitespace = false
```

If you ever add Blade (e.g. a docs/demo app), the mid-2026 best-in-class plugin is
**`prettier-plugin-laravel-blade`** — a Dart parser compiled to JS producing a typed AST, ~160 KB,
zero dependencies, 1450+ tests, with full Blade/Alpine/Livewire support — preferred over the older
`@shufo/prettier-plugin-blade` (lexer-based, can break indentation on complex Blade) and the
`stillat`/Chisel `prettier-plugin-blade` line [14]. But it is out of scope for the engine itself.

---

## 6. Pest 4 quality gates

Four layers, escalating in strictness:

**Unit/feature tests** — standard Pest 4 on PHPUnit 12, **PHP 8.3+** (Pest 4 requires `php: ^8.3.0`
and `phpunit/phpunit: ^12.5.29`) [15]. Note: this package's own baseline is PHP 8.4 (Section 1); Pest
merely *tolerates* 8.3, so 8.4 is fine.

**Architecture tests** (`pestphp/pest-plugin-arch`) — encode GoBD-relevant invariants as enforceable
rules. Example `tests/Arch/ArchTest.php`:
```php
arch('no debug leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('JohnWink\GobdInvoice')
    ->toUseStrictTypes();

arch('value objects are immutable')
    ->expect('JohnWink\GobdInvoice\ValueObjects')
    ->toBeReadonly();

arch('enums for document types')
    ->expect('JohnWink\GobdInvoice\Enums')
    ->toBeEnums();

arch()->preset()->php();   // built-in PHP best-practice preset
```
Arch tests are the natural place to *prove* the immutability/typing discipline that GoBD demands.

**Type coverage** (`pestphp/pest-plugin-type-coverage`) — `vendor/bin/pest --type-coverage --min=100`
[16]. A greenfield library should hold **100%**: every parameter, return, and property typed. Error
codes like `rt31` (missing return type, line 31) / `pa31` (missing param type, line 31) point you
straight to the gap; `--compact` shows only files below 100% [16].

**Mutation testing** (`pestphp/pest-plugin-mutate`) —
`vendor/bin/pest --mutate --parallel --covered-only --min=90` [17]. Requires **Xdebug 3.0+ or PCOV**.
Mark covered code with `covers()`/`mutates()` in test files (they are identical for mutation purposes;
`covers()` additionally affects the coverage report) [17]. Start the `--min` threshold around 80–90 and
ratchet up; a tax/invoicing engine should aim very high because a surviving mutation often means an
un-asserted monetary or rounding edge. Run `--covered-only` in CI to keep it fast, and
`--bail`/`--profile` locally when hunting.

**Line coverage** — `vendor/bin/pest --coverage --min=90` (or `--ci`). Aim ≥90%.

Per the project convention, run the suite in parallel: `vendor/bin/pest --parallel` (and the skeleton
CI uses `vendor/bin/pest --ci` [1]).

---

## 7. Testbench + Workbench

`orchestra/testbench ^11.0` is the package test harness for Laravel 13 (Testbench 11 ↔ Laravel 13;
Testbench 10 ↔ Laravel 12 — confirmed in the skeleton's CI `include` block) [1]. Workbench is the
modern replacement for hand-rolled test apps:

- `post-autoload-dump` runs `testbench package:discover` so your service provider is registered in the
  test app [7]. (The Spatie skeleton wraps this in a `prepare` script; calling
  `@php vendor/bin/testbench package:discover --ansi` directly, as above, is equivalent.)
- Put a throwaway app skeleton in `workbench/app`, migrations in `workbench/database`, registered via
  `autoload-dev` PSR-4 (`Workbench\App\`, `Workbench\Database\Factories\`).
- Your base `TestCase` extends `Orchestra\Testbench\TestCase`; register the provider and use an
  in-memory sqlite connection for speed in CI.

For a document engine you will want a `TestCase` that runs the package migrations and seeds a minimal
"Unternehmen" (company) + sequence counter so each test gets a fresh, gap-free `Rechnungsnummer`
(invoice-number) sequence — important because GoBD requires unbroken sequential numbering
(*lückenlose, fortlaufende Nummerierung*), which is itself a thing your tests must assert.

---

## 8. GitHub Actions — matrix CI + quality-gate workflows

### 8a. `run-tests.yml` (matrix)

This is the Spatie skeleton matrix [1], trimmed to your support window. The skeleton runs PHP
**8.5/8.4/8.3 × Laravel 13/12 × prefer-lowest/prefer-stable × ubuntu/windows** (with Testbench 11↔L13,
10↔L12 in the `include`) [1]. For a PHP-8.4-baseline package, drop 8.3 and (optionally) Windows to
control cost. Keep both stability modes — `prefer-lowest` is what catches too-loose `composer.json`
constraints.

```yaml
name: run-tests

on:
  push:
    paths: ['**.php', '.github/workflows/run-tests.yml', 'phpunit.xml.dist', 'composer.json']
  pull_request:
    paths: ['**.php', '.github/workflows/run-tests.yml', 'phpunit.xml.dist', 'composer.json']

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  test:
    runs-on: ${{ matrix.os }}
    timeout-minutes: 5
    strategy:
      fail-fast: true
      matrix:
        os: [ubuntu-latest]
        php: ['8.5', '8.4']
        laravel: ['13.*']
        stability: [prefer-lowest, prefer-stable]
        include:
          - laravel: 13.*
            testbench: 11.*
    name: P${{ matrix.php }} - L${{ matrix.laravel }} - ${{ matrix.stability }}
    steps:
      - uses: actions/checkout@v6
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, intl, fileinfo, simplexml
          coverage: pcov
      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
          composer update --${{ matrix.stability }} --prefer-dist --no-interaction
      - name: List installed dependencies
        run: composer show -D
      - name: Execute tests
        run: vendor/bin/pest --ci
```

Extension notes for *this* package: keep `bcmath` (decimal money math), `intl` (German
number/date/locale formatting), `simplexml`/`dom`/`libxml` (XRechnung/ZUGFeRD XML generation and
validation). The skeleton's full extension list also includes `soap, gd, exif, iconv, imagick` [1];
drop those unless you actually render PDFs or call SOAP. If you generate PDF/A-3 (ZUGFeRD), add
whatever your PDF lib needs.

### 8b. `phpstan.yml`

```yaml
name: PHPStan
on:
  push: { paths: ['**.php', 'phpstan.neon.dist', '.github/workflows/phpstan.yml'] }
  pull_request: { paths: ['**.php', 'phpstan.neon.dist'] }
jobs:
  phpstan:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@v6
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', coverage: none }
      - uses: ramsey/composer-install@v4
      - run: vendor/bin/phpstan analyse --error-format=github
```
Shape verified against the skeleton's PHPStan workflow (PHP 8.5, `actions/checkout@v6`,
`ramsey/composer-install@v4`, `--error-format=github`) [18]. **[caveat]** The skeleton's run line is
`./vendor/bin/phpstan --error-format=github` (no explicit `analyse`; it defaults to `analyse`) — the
form above is functionally identical.

### 8c. `code-style.yml` (Pint as a gate, not auto-commit)

```yaml
name: code-style
on: [push, pull_request]
jobs:
  pint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', coverage: none }
      - uses: ramsey/composer-install@v4
      - run: vendor/bin/pint --test
```

### 8d. `rector.yml`

```yaml
name: rector
on: [push, pull_request]
jobs:
  rector:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', coverage: none }
      - uses: ramsey/composer-install@v4
      - run: vendor/bin/rector process --dry-run
```

### 8e. `type-coverage.yml`

```yaml
name: type-coverage
on: [push, pull_request]
jobs:
  type-coverage:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', coverage: none }
      - uses: ramsey/composer-install@v4
      - run: vendor/bin/pest --type-coverage --min=100
```

### 8f. `mutation.yml` (heavier — run on PRs / nightly, not every push)

```yaml
name: mutation
on:
  pull_request: { paths: ['**.php'] }
  schedule: [{ cron: '0 3 * * 1' }]   # weekly safety net
jobs:
  mutation:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', coverage: pcov }
      - uses: ramsey/composer-install@v4
      - run: vendor/bin/pest --mutate --parallel --covered-only --min=90 --ci
```

### 8g. Coverage reporting

In `run-tests.yml`, one matrix leg already runs `coverage: pcov` (above). Add a Codecov upload step
(`codecov/codecov-action@v5` with `vendor/bin/pest --coverage --coverage-clover=coverage.xml`). Keep
coverage on a single representative leg (PHP 8.5, prefer-stable) — running it across the whole matrix
wastes minutes.

---

## 9. Release hygiene: SemVer + Conventional Commits + Keep-a-Changelog

- **SemVer mapping (per the Conventional Commits 1.0.0 spec):** "`fix` type commits should be
  translated to PATCH releases. `feat` type commits should be translated to MINOR releases. Commits
  with `BREAKING CHANGE` ... regardless of type, should be translated to MAJOR releases" — and a `!`
  after the type/scope (e.g. `feat!:`) also signals a breaking change [19]. Document your commit
  scopes (e.g. `rechnung`, `xrechnung`, `storno`, `gobd`) in `CONTRIBUTING.md` so the vocabulary is
  shared.
- **Conventional Commits** drive automated bumps; enforce in CI with a commitlint-style check or just
  convention + review.
- **CHANGELOG.md** in *Keep a Changelog* format. Either maintain by hand (Spatie style: an
  `Unreleased` section, dated releases) or automate with **`release-please`** (a Google-maintained
  GitHub Action that maintains a Release PR with the changelog + version bump, merged when you ship)
  or the PHP-native **`marcocesarato/php-conventional-changelog`** run as a composer script [19].

---

## 10. `.github` community files & LICENSE

The skeleton ships `.github/{workflows, ISSUE_TEMPLATE/, FUNDING.yml, dependabot.yml}` [20]. The full
paragon set:

| File | Purpose |
|---|---|
| `LICENSE.md` | **MIT** (matches `"license": "MIT"`). |
| `README.md` | Badges: Packagist version, total downloads, **run-tests** status, **PHPStan** status, license. Quick install + usage. |
| `CHANGELOG.md` | Keep a Changelog. |
| `CONTRIBUTING.md` | Setup (`composer install`), how to run `composer test`/`analyse`/`lint`/`rector-check`, commit-message convention, scopes. |
| `CODE_OF_CONDUCT.md` | Contributor Covenant 2.1. |
| `SECURITY.md` | Private vuln disclosure contact + supported-versions table — extra important for an *invoicing/tax* package handling financial data. |
| `.github/PULL_REQUEST_TEMPLATE.md` | Checklist: tests added, `pint --test` clean, PHPStan clean, rector dry-run clean, changelog updated. |
| `.github/ISSUE_TEMPLATE/{bug_report.yml,feature_request.yml,config.yml}` | Structured issue forms. |
| `.github/FUNDING.yml` | GitHub Sponsors / etc. |

`dependabot.yml` (shape verified against skeleton [20]) monitoring both `composer` and
`github-actions` weekly:
```yaml
version: 2
updates:
  - package-ecosystem: "composer"
    directory: "/"
    schedule: { interval: "weekly" }
    labels: ["dependencies"]
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule: { interval: "weekly" }
    labels: ["dependencies"]
```
**[caveat]** The current skeleton `dependabot.yml` additionally sets a `cooldown:` block
(`default-days: 1`) on each ecosystem. It is optional; add it if you want to debounce noisy releases.

---

## 11. Summary of deliberate upgrades over the stock Spatie skeleton

1. **PHPStan `level: max`** (currently level 10), baseline-free — vs the skeleton's `level: 5` with a
   committed `phpstan-baseline.neon` [7][8].
2. **Rector** (`rector/rector ^2.5` + `driftingly/rector-laravel ^2.5`) added with a dry-run CI gate —
   the skeleton ships no Rector [2][3].
3. **Pest type-coverage `--min=100`** and **mutation `--min=90`** added as separate plugins and CI
   jobs — the skeleton ships neither plugin [16][17].
4. **Pint as a failing `--test` gate** instead of the skeleton's auto-commit workflow (cleaner
   history) [13].
5. **Narrowed dependency/CI matrix** to PHP 8.4/8.5 × Laravel 13 (drop 8.3 / extra majors / Windows to
   taste) while keeping `prefer-lowest`+`prefer-stable` to catch loose constraints.
6. Extensions trimmed to what a German-invoice XML/decimal engine actually needs (`bcmath`, `intl`,
   `simplexml`/`dom`/`libxml`), dropping `soap`/`gd`/`exif`/`iconv`/`imagick` from the skeleton's list.

---

## Sources

[1] spatie/package-skeleton-laravel — composer.json & run-tests.yml (PHP 8.5/8.4/8.3, Laravel 13/12, Testbench 11/10, Pest 4 plugins, ubuntu/windows, prefer-lowest/prefer-stable, `pest --ci`) - https://github.com/spatie/package-skeleton-laravel/blob/main/composer.json
[2] rector/rector on Packagist (rector-src 2.5.2 tagged 2026-06-22, dist updated 2026-06-24; prepared sets in core) - https://packagist.org/packages/rector/rector
[3] driftingly/rector-laravel (2.5.0, 2026-06-02; canonical name — old rector/rector-laravel abandoned & redirects here; LaravelSetList / LaravelLevelSetList / LaravelSetProvider + withComposerBased) - https://github.com/driftingly/rector-laravel
[4] larastan/larastan on Packagist (v3.10.0 2026-05-28; PHPStan ^2.2.0; PHP ^8.2; illuminate ^11.44.2 || ^12.4.1 || ^13; extension.neon include) - https://packagist.org/packages/larastan/larastan
[5] pestphp/pest-plugin-mutate on Packagist (v4.0.1, released 2025-08-21; requires pest-plugin ^4.0.0) - https://packagist.org/packages/pestphp/pest-plugin-mutate
[6] pestphp/pest-plugin-type-coverage on Packagist (v4.0.4, 2026-04-06; separate plugin) - https://packagist.org/packages/pestphp/pest-plugin-type-coverage
[7] spatie/package-skeleton-laravel — phpstan.neon.dist (level: 5, includes phpstan-baseline.neon, paths src/config/database, tmpDir build/phpstan) & composer scripts - https://github.com/spatie/package-skeleton-laravel/blob/main/phpstan.neon.dist
[8] PHPStan Rule Levels (levels 0–10; `max` alias = current highest = level 10; level 10 tightens implicit mixed) - https://phpstan.org/user-guide/rule-levels
[9] spatie/laravel-query-builder phpstan.neon.dist (treatPhpDocTypesAsCertain: false, checkModelProperties: true; level 6) - https://github.com/spatie/laravel-query-builder/blob/main/phpstan.neon.dist
[10] phpstan/extension-installer — auto-registers supported extensions (phpstan-phpunit, phpstan-deprecation-rules) at composer install/update; no manual includes needed - https://github.com/phpstan/extension-installer
[10a] Rector Set Lists docs — empty `withPhpSets()` auto-detects PHP from composer.json; `withPreparedSets()` params (deadCode, codeQuality, codingStyle, naming, privatization, typeDeclarations, rectorPreset) - https://getrector.com/documentation/set-lists
[11] Laravel News — Rector rules for Laravel and prepared sets (background) - https://laravel-news.com/rector-rules-for-laravel
[12] Mastering Laravel code upgrades with Rector — LaravelLevelSetList::UP_TO_LARAVEL_130 (cumulative) vs per-version LARAVEL_130 delta set - https://masteryoflaravel.medium.com/mastering-laravel-code-upgrades-and-refactoring-with-rector-c2f23a8ce427
[13] spatie/package-skeleton-laravel — fix-php-code-style-issues.yml (aglipanci/laravel-pint-action + git-auto-commit-action) - https://github.com/spatie/package-skeleton-laravel/blob/main/.github/workflows/fix-php-code-style-issues.yml
[14] prettier-plugin-laravel-blade — fastest AST-based Blade formatter (Dart parser → JS, typed AST, ~160 KB, 1450+ tests) vs @shufo/prettier-plugin-blade & stillat/Chisel prettier-plugin-blade - https://blade-formatter.vercel.app/
[15] Pest 4 requirements (php: ^8.3.0, phpunit/phpunit: ^12.5.29) - https://pestphp.com/docs/installation
[16] Pest — Type Coverage docs (`pest --type-coverage --min=100`; error codes rt31/pa31; --compact) - https://pestphp.com/docs/type-coverage
[17] Pest — Mutation Testing docs (`--mutate --parallel --covered-only --min`; requires XDebug 3.0+ or PCOV; covers()/mutates(); --bail/--profile) - https://pestphp.com/docs/mutation-testing
[18] spatie/package-skeleton-laravel — phpstan.yml workflow (PHP 8.5, actions/checkout@v6, ramsey/composer-install@v4, --error-format=github) - https://github.com/spatie/package-skeleton-laravel/blob/main/.github/workflows/phpstan.yml
[19] Conventional Commits 1.0.0 (fix→PATCH, feat→MINOR, BREAKING CHANGE / `!`→MAJOR) + SemVer + Keep-a-Changelog tooling (release-please, marcocesarato/php-conventional-changelog) - https://www.conventionalcommits.org/en/v1.0.0/
[20] spatie/package-skeleton-laravel — .github/dependabot.yml (composer + github-actions, weekly, labels: [dependencies], optional cooldown default-days: 1) - https://github.com/spatie/package-skeleton-laravel/blob/main/.github/dependabot.yml

---

## Open Questions

1. **Support window:** pin `illuminate/contracts` to `^13.0` only, or widen to `^12.0||^13.0`? The CI
   matrix and `require` constraint must match exactly. If widened below `^13`, note Larastan's real
   Laravel floor is **11.44.2** (not 11.15), so any `prefer-lowest` leg resolves to at least that.
2. **Pint policy:** failing `pint --test` gate (recommended here, cleaner history) vs the Spatie-style
   auto-fix-and-commit bot. Pick one; do not run both.
3. **Rector `codingStyle: true`** can churn against Pint formatting. Confirm whether to disable it and
   let Pint own all whitespace/style, or to order Rector-then-Pint in the `format` script. Also
   confirm `earlyReturn:` is exposed by your installed Rector version's `withPreparedSets()` signature
   (it is a real set but is not shown in the docs' example snippet).
4. **PDF/A-3 (ZUGFeRD) generation** may require extra PHP extensions (e.g. `gd`/`imagick` or a specific
   PDF lib) not in the trimmed CI extension list — finalize once the PDF/XML stack is chosen.
5. **Mutation-score `--min` target:** 90 is a starting suggestion; a tax/money engine may justify
   ratcheting toward 95–100 on core calculation/numbering modules. Final threshold TBD after the suite
   stabilizes.
6. **Conventional Commits enforcement:** mechanically (commitlint/CI check + release-please) or rely on
   convention + review — affects whether you add a Node toolchain to an otherwise PHP-only repo.
7. **`pest-plugin-mutate` cadence:** the latest stable (4.0.1) dates to 2025-08; before release,
   re-check Packagist for a newer patch and confirm it still tracks Pest 4.x rather than a 5.x line.
