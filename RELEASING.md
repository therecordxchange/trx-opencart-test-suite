# Releasing

This document describes how to cut a release of `therecordxchange/opencart-test-suite` (this repo, `therecordxchange/trx-opencart-test-suite` on GitHub) so that downstream consumers (notably `trx-enterprise-php`) can pin to a versioned Composer constraint instead of `dev-main`.

## Why we tag releases

Composer can resolve a package by tag, by branch, or by SHA. Branch and SHA resolution requires hitting `api.github.com/repos/.../commits/<sha>` on every fresh install. On shared CI runners that endpoint trips GitHub's secondary rate limit (HTTP 429), which manifests as intermittent `Install test dependencies` failures (see TRX-4477 / TRX-4478).

Tag resolution uses GitHub's CDN-backed tarball endpoint, which is not subject to the same throttling. Pinning consumers to a tag therefore both stabilises CI and produces a lockfile entry that points at a human-meaningful version instead of an opaque SHA.

## Versioning convention

We follow [Semantic Versioning 2.0.0](https://semver.org):

- `MAJOR.MINOR.PATCH` (e.g. `v0.1.0`, `v0.1.1`, `v0.2.0`, `v1.0.0`)
- While the package is `0.y.z`, breaking changes may land in a minor bump; consumers should pin with `^0.y` (which under Composer's rules only accepts `>=0.y, <0.(y+1)`).
- Once we cut `v1.0.0`, the standard semver guarantees apply: breaking changes require a major bump.

Tag names are prefixed with `v` (e.g. `v0.1.0`). Composer strips the prefix when comparing versions.

### What counts as a breaking change

Anything that would force a consuming test suite to be rewritten:

- Removing or renaming a public method on `OpenCartTest`, `OpenCartSeleniumTest`, or any class under `src/` that consumers extend or call.
- Changing the signature of such a method in a non-backwards-compatible way.
- Changing the supported PHP version range in `composer.json`.
- Changing the supported PHPUnit major version.

Additions (new helper methods, new test base classes, new optional parameters with defaults) are minor bumps. Bugfixes that do not change public surface are patch bumps.

## Cutting a release

We currently release manually with `gh`. There is no automatic tagging on merge to `main` (deliberate; releases should be intentional).

### Prerequisites

- `gh` CLI installed and authenticated against `github.com/therecordxchange`.
- Push access to tags on `therecordxchange/trx-opencart-test-suite`.
- A clean working tree on `main` with the commit you intend to release already pushed.

### Steps

1. Fetch and verify you are on the exact commit you want to release:

   ```bash
   git fetch origin
   git checkout main
   git pull --ff-only origin main
   git log -1 --oneline
   ```

2. Choose the next version number per the convention above. For the first-ever release of this fork, use `v0.1.0`.

3. Create an annotated tag and push it:

   ```bash
   VERSION=v0.1.0
   git tag -a "$VERSION" -m "Release $VERSION"
   git push origin "$VERSION"
   ```

4. Create the GitHub Release (auto-generates release notes from merged PRs since the previous tag):

   ```bash
   gh release create "$VERSION" --generate-notes --title "$VERSION"
   ```

   For the first release there is no previous tag, so `--generate-notes` will summarise the full history; review and edit the notes if needed.

5. Verify the release is resolvable by Composer. From a scratch directory:

   ```bash
   composer init --no-interaction --name=trx/release-smoketest
   composer require --no-update "therecordxchange/opencart-test-suite:^0.1"
   composer update --dry-run
   ```

   The dry run should show the package resolving to the tag (e.g. `v0.1.0`) without any `api.github.com/repos/.../commits/` calls in the output.

## Updating downstream consumers

After a release is published:

1. In the consuming repo (e.g. `trx-enterprise-php`), in the relevant `composer.json` (e.g. `tests/phpunit/composer.json`), bump the constraint:

   ```json
   {
     "require-dev": {
       "therecordxchange/opencart-test-suite": "^0.1"
     }
   }
   ```

   - Use `^0.1` to accept any `0.1.x` patch.
   - Use `^0.2` (etc.) when you want to opt into a new minor line that may contain breaking changes.

2. Refresh the lockfile against the new tag:

   ```bash
   composer update therecordxchange/opencart-test-suite --lock
   ```

3. Commit the updated `composer.json` and `composer.lock` together. Open a PR; CI should pass the `Install test dependencies` step without contacting `api.github.com/repos/.../commits/`.

## Hotfixes

For a patch fix on an older minor line:

1. Branch from the tag you need to patch: `git checkout -b hotfix/v0.1.x v0.1.0`.
2. Land the fix on that branch.
3. Tag `v0.1.1` on the branch tip and push.
4. Merge the fix forward into `main` if it still applies.

## Notes

- Do **not** publish to Packagist. Consumers resolve this package via a VCS `repositories` entry in their own `composer.json` pointing at this GitHub repo.
- The package name in `composer.json` (`therecordxchange/opencart-test-suite`) intentionally differs from the repo name (`trx-opencart-test-suite`). Consumers must `require` the package name, not the repo name.
