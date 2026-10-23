# Releasing

## Once, before the first release

- [x] Make the five repositories public: `framework`, `crab`, `sample`, `clockwerk`, `redis`.
- [x] Remove the `CRAB_REPOS_TOKEN` lines from the sibling workflows; public checkouts need no token. (`sample` is the owner's local app and keeps its workflow as it is.)
- [ ] In each repository: protect `main` so the CI checks must pass, and turn on private vulnerability reporting (Settings, Security).
- [ ] Register `crabphp/framework`, `crabphp/redis`, `crabphp/clockwerk` and `crabphp/crab` on Packagist, with the GitHub hook so tags appear on their own.
- [x] Decide whether `crabphp/engine` is published now or later: later (2026-10-23). It ships inside the framework, so keep `replace` in `composer.json`; a split repository fed from `src/Engine/` comes when someone needs the engine alone.
- [ ] Replace the `../framework` path repositories and `@dev` constraints with the release (`^0.1`) in `crab`, `clockwerk`, `redis` and `sample`, and drop "until there is a release" from AGENTS.md and the READMEs.
- [ ] In `crab`, remove `composer.lock` from `.gitignore` and tell application authors to commit theirs, so a deploy installs the versions that were tested.
- [ ] Write the install step in the README and `docs/installation.md` as `composer create-project crabphp/crab app`.
- [ ] Add `keywords`, `homepage` and `support` (issues, source, docs) to each package's `composer.json`.
- [ ] Decide where the docs are served.

## Every release

### Check

- [ ] CI is green on `main` in all five repositories.
- [ ] On your machine, from `framework/`:
  - [ ] `composer test`
  - [ ] `CRAB_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=crab_test' composer test`
  - [ ] `composer test:linux --mysql`
  - [ ] `composer soak`, when the engine, server or process control changed.
  - [ ] the request-path benchmark against the last release, when HTTP or routing changed; record it in BENCHMARKS.md.
- [ ] `redis/` tests against a throwaway Redis.
- [ ] Nothing in `composer audit` for any repository.

### Write

- [ ] `CHANGELOG.md`: move Unreleased under the new version and date. Breaking changes first, each with what an application changes.
- [ ] The skeleton (`crab`) and `sample` work with the new version and their READMEs match it.

### Tag, in this order

Each depends on the one before it.

1. [ ] `framework`: `git tag -a v0.1.0 -m v0.1.0 && git push origin v0.1.0`
2. [ ] `redis` and `clockwerk`: raise their `crabphp/framework` constraint if needed, commit, tag, push.
3. [ ] `crab`: raise its constraints, commit, tag, push.
4. [ ] `sample`: update, commit, push.
5. [ ] A GitHub release for each tag, with that version's changelog.

### Verify

- [ ] Packagist lists the new versions.
- [ ] From an empty folder: `composer create-project crabphp/crab app`, then in `app/`: `php cua dev`, `curl http://127.0.0.1:8080/up` answers, `vendor/bin/phpunit` passes.
