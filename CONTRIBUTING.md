# Contributing

Thank you for helping. Bug reports, fixes, tests and documentation are all
welcome. Please read this first; it is short.

## Setup

You need PHP 8.1 or later (64-bit) and Composer.

```sh
git clone https://github.com/IndianOpinion/packed-vectors.git
cd packed-vectors
composer install
```

## Tests

```sh
composer test                                # all tests
vendor/bin/phpunit --exclude-group slow      # skip the 100,000-record memory tests
TMPDIR=/path/with/room composer test         # the slow group writes a 205 MB file
```

Every behaviour in [docs/PRD.md](docs/PRD.md) section 4 has a test; the test
docblocks name the acceptance test and the case they cover. A change in
behaviour needs a test that fails without it. Tests that need a non-root
user (unreadable files, read-only directories) skip themselves when run as
root; CI runs them.

## Style and static analysis

```sh
composer cs          # phpcs, PSR-12
composer analyse     # PHPStan level 8
php benchmarks/bench.php   # if you touch the scan loop
```

- `declare(strict_types=1);` in every PHP file.
- Code must run on PHP 8.1: no readonly classes, no DNF types, no `true`
  type, no constants in traits.
- No `@` error suppression, no `eval`, no shell, no network, no
  `unserialize`. Every failure is a typed exception.
- No runtime dependencies.
- Plain British English in docs and messages. No em dashes.

## Pull requests

- One topic per pull request, with a clear description of what and why.
- Tests, phpstan and phpcs must pass (CI runs them on PHP 8.1, 8.2 and 8.3).
- Update `CHANGELOG.md` under "Unreleased".
- Record any new design decision in `docs/ARCHITECTURE.md`.
- A format change needs a new format version (see decision D6).
- A DCO-style sign-off is not required.

By contributing you agree that your contribution is licensed under the MIT
licence of this project, and you agree to follow the
[Code of Conduct](CODE_OF_CONDUCT.md).
