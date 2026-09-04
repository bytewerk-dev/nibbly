# Contributing

Thank you for your interest in contributing to Nibbly!

## How to Contribute

1. **Fork** the repository
2. Create a **feature branch** (`git checkout -b feature/my-feature`)
3. **Commit** your changes (`git commit -m 'Add my feature'`)
4. **Push** to the branch (`git push origin feature/my-feature`)
5. Open a **Pull Request**

## Code Standards

- PHP 8.1+ syntax; validate on a supported PHP release.
- Use consistent indentation (4 spaces)
- Follow existing naming conventions
- Add comments for complex logic
- Ensure security: validate inputs, sanitize outputs, use CSRF tokens

## Reporting Issues

- Use GitHub Issues
- Include steps to reproduce
- Include browser/PHP version if relevant

## Feature Requests

- Open an issue with the "enhancement" label
- Describe the use case
- Discuss before implementing large changes

## Offline validation

Run `python3 tests/run-smoke.py` from the repository root. The runner needs PHP
(with mbstring, DOM, cURL and ZIP), Node.js and Python 3. It creates disposable
site copies, uses generated fixture accounts, and makes no paid AI calls or
external email deliveries. Live configuration and customer content are not copied.

The suite covers PHP/JavaScript syntax, translation JSON, permissions, sessions,
CSRF, safe HTML, routing, forms, backup/restore, concurrent file writes, and mocked
AI provider requests. Browser checks are separate from the offline runner.

With Playwright and Chromium installed locally, run `python3 tests/browser-check.py`.
This also uses an isolated fixture and reports the location of its screenshots.

For shared JSON records use `includes/json-store.php`: `nibblyJsonUpdate()` locks
the complete read/change/write transaction; `nibblyJsonAtomicWrite()` alone only
prevents partially written files. Never remove a lock file after releasing it.
