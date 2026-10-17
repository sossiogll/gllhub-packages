# gllHub packages — guide for package authors

The rules of the whole system (the golden rule, the boundary, how code is written, tests, versions) are in the
core's guide: [`sossiogll/laravel-blog` → `CLAUDE.md`](https://github.com/sossiogll/laravel-blog/blob/next/CLAUDE.md).
They hold here in full; this file only adds what is specific to packages, and never repeats them.

## A package

- One folder per package at the root of this repository, with its own `composer.json`, `README.md`, `src/`, `tests/`.
- It depends on `gllhub/contracts` and, for the content types its sources produce, on the package that defines
  them (`gllhub/portfolio`). Never on the core, never on another source package.
- Its entry point is named in `composer.json`: `"extra": {"gllhub": {"package": "Vendor\\Name\\XPackage"}}`.
- It declares everything (manifest, `definition()` of each class) and returns data. It reaches its outside service
  only through `Context::http()`.

## Before writing code

- **Look in `gllhub/contracts` first** (`Support\`, `Testing\`, the HTTP client, `BodyFormat`). If two packages need
  the same pure helper, it goes there, not in both.
- **Look in the other packages.** A content type is defined once (`portfolio`); a source that needs a new field
  adds it to the type, not to itself.
- Split by job: a parser reads the outside format, a mapper builds entries, the source orchestrates. See `medium/`.

## Tests

Each package's suite runs, at least:

```php
Conformance::check(new XPackage());
Conformance::checkEntries(XSource::definition(), [Type::definition()], $entriesFromAFixture);
```

with `StaticContext` and `FakeHttpClient`, on fixtures in `tests/fixtures/` that use invented data, never a real
account's content.

## Working on this repository

Clone it next to the core, so the workspace finds the contract:

```
~/Workspace/
  laravel-blog/        (the core, branch next: contracts/ is here)
  gllhub-packages/     (this repository)
```

```sh
composer install
vendor/bin/phpunit
```
