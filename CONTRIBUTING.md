# Contributing

Contributions are **welcome** and will be fully **credited**.

We accept contributions via Pull Requests on [GitHub](https://github.com/john-wink/gobd-invoice).

## Pull Requests

- **[PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)** — the easiest
  way to apply the conventions is to run `composer format` (Laravel Pint).

- **Add tests!** — Your patch won't be accepted if it doesn't have tests. Every
  change must be programmatically tested with Pest.

- **Document any change in behaviour** — Make sure the `README.md`, the docs in
  `docs/`, and any other relevant documentation are kept up-to-date.

- **Consider our release cycle** — We try to follow [SemVer v2.0.0](https://semver.org/).
  Randomly breaking public APIs is not an option.

- **One pull request per feature** — If you want to do more than one thing, send
  multiple pull requests.

- **Send coherent history** — Make sure each individual commit in your pull
  request is meaningful. We follow
  [Conventional Commits](https://www.conventionalcommits.org/).

## Legal accuracy

This package implements German tax and bookkeeping law (GoBD, UStG, e-invoicing).
Any change that touches a legal rule (a mandatory field, a deadline, a rate, a
retention period, a numbering rule, a rounding rule) **must** cite an
authoritative primary source (e.g. `gesetze-im-internet.de`,
`bundesfinanzministerium.de`, KoSIT) in the PR description and update the
relevant document under `docs/research/`. We do not merge legal claims that are
not backed by a citation. This package provides tooling; it is **not** legal or
tax advice.

## Running the quality gates locally

```bash
composer install
composer test          # Pest test suite
composer test:coverage # with coverage threshold
composer analyse       # PHPStan (max level) + Larastan
composer rector        # Rector dry-run
composer format        # Laravel Pint (fixes style)
```

All of the above must be green before a PR can be merged. CI enforces the same
matrix across PHP 8.4 / 8.5 and the supported Laravel versions.

## Reporting security issues

Please review [our security policy](.github/SECURITY.md) on how to report
security vulnerabilities. **Do not** report security issues publicly.

**Happy coding**!
