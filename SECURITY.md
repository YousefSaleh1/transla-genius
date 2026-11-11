# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 4.x | Yes |
| 3.x | No |
| < 3.0 | No |

Security fixes are released for the latest 4.x minor version.

## Reporting a vulnerability

Please do not report security issues in public GitHub issues, discussions or pull requests.

Report them privately through GitHub's private vulnerability reporting: open the repository's [Security tab](https://github.com/YousefSaleh1/transla-genius/security) and choose **Report a vulnerability**, or go directly to [the new advisory form](https://github.com/YousefSaleh1/transla-genius/security/advisories/new).

Please include:

- the package version and your PHP and Laravel versions
- a description of the issue and its impact
- steps to reproduce it, or a proof of concept

You will get an acknowledgement after the report is reviewed. Once a fix is ready, it is released and the issue is disclosed in a GitHub security advisory, crediting you unless you prefer otherwise.

## Scope

In scope: the code in this repository. Issues in a translation provider's API, or in Laravel or spatie/laravel-translatable themselves, should be reported to those projects.

API keys belong in your environment, never in committed config files. Note that with the translation cache on, translated field content is stored in your cache store (see the README's "Translation cache" section).
