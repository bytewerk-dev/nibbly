# Release policy and historical records

## Release metadata

`VERSION` is the runtime source of truth. Each published release must have:

- The same version in `VERSION`, the newest numbered `CHANGELOG.md` section,
  the Git tag (`vX.Y.Z`) and the GitHub release title.
- A changelog date matching its actual publication calendar date in
  Europe/Vienna. GitHub timestamps may display a different date in UTC.
- A tag pointing to the tested commit merged into `main`.
- Upgrade notes for API, runtime, storage or authentication changes.

The `Unreleased` section holds work awaiting the next release. A merge alone
does not publish a release. Under Semantic Versioning, incompatible documented
API changes require a major version, compatible features a minor version, and
compatible fixes a patch version. Do not rename or move existing published tags.

## Release checklist

1. Review changes since the previous release and determine the version from the
   documented compatibility impact.
2. Update `VERSION`, move pending changelog entries into a dated version section,
   leave a fresh `Unreleased` section and document upgrade steps.
3. Submit a release PR. Check that PHP 8.1, PHP 8.4, PHP 8.5 and Chromium pass for its
   current commit, then merge through the protected `main` branch.
4. Verify the merge commit's CI and the version rendered by `nibblyVersion()`.
   Check that its release archive includes `VERSION` and the complete core.
5. Create an annotated `vX.Y.Z` tag on that exact merge commit and publish a
   GitHub release using explicit release notes. Include the compatibility impact,
   upgrade instructions and validation scope. If publication crosses midnight
   in Europe/Vienna, correct the date through a PR before tagging.
6. Read back the tag target, published release and archive version. Mark a stable
   release as latest only after publication succeeds.

## Historical 1.x dates

The 1.x changelog dates are preserved as originally recorded. They are not all
verified publication dates. The repository provides the following milestones:

| Version | Recorded changelog date | Verified repository milestone |
| --- | --- | --- |
| 1.6.1 | 2026-08-05 | `VERSION` changed from 1.6.0 in `7efed4d` on 2026-09-04; merged into `main` via PR #40 on 2026-09-05. |
| 1.6.0 | 2026-08-04 | `VERSION` changed from 1.5.4 in `496e09f` on 2026-08-05; merged into `main` via PR #40 on 2026-09-05. |
| 1.5.4 | 2026-06-18 | Root `VERSION` introduced as 1.5.4 in `fd3d23c` on 2026-07-25; merged via PR #39 that day. |
| 1.1.0 | 2026-04-27 | GitHub release `v1.1.0` published on 2026-04-28 at 05:59:26 UTC. |

At the 2026-09-06 audit, `v1.1.0` was the only published GitHub release and Git
tag. The recorded 1.5.4–1.6.1 dates cannot be established as public release dates
from this history. Commit and merge dates do not prove when a local build was
first used or separately deployed, so no replacement release dates or historical
tags are invented. Original 1.x version numbers are retained, including the
feature additions recorded under the patch version 1.6.1.

Version 2.0.0 starts the synchronized release process above. Its major increment
reflects the required revision parameter in the documented admin save API; it
does not imply the visual page builder or plugin roadmap shown in older demos.
