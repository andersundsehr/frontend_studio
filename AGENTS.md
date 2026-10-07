# Repository Guidelines

## Project Structure & Module Organization

Frontend Studio is a TYPO3 extension. PHP source lives in `Classes/`. It uses the `Andersundsehr\FrontendStudio\` namespace.
TYPO3 services, routes, and backend modules use `Configuration/`. Templates, JavaScript,
CSS, icons, and translations use `Resources/`. User documentation and screenshots are in `Documentation/`.
PHP tests are in `Tests/Unit/` and `Tests/Functional/`. Functional fixtures are in `Tests/Functional/Fixtures/`.
The Node preview helper and its test live in `Playwright/`.

## Build, Test, and Development Commands

`Build/Scripts/runTests.sh` runs checks in Docker or Podman. It supports PHP 8.4 and 8.5.
From the package root, use:

```bash
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s unit
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s functional -d mysql
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s grumphpRun
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s phpstan
```

Functional tests also support `mariadb` and `postgres` via `-d`.

## Coding Style & Naming Conventions

Follow `.editorconfig` for spaces, LF endings, and a final newline. Use 2 spaces for indentation generally and 4 spaces for PHP.
In all Markdown files, wrap prose after reaching 80 characters at the next sentence end or comma.
Do not split at arbitrary words. Keep PHP classes PSR-4 namespaced. Align file names with class names.
Name PHPUnit tests `*Test.php`. Place them under the matching `Tests/Unit/` or `Tests/Functional/` area.
Prefer the smallest direct solution. Avoid speculative abstractions.

## Documentation Guidelines

Documentation should primarily target frontend developers using Frontend Studio.
Start each section with an introduction focused on their workflow and practical use.
Place deeper technical details relevant to frontend developers after that introduction.
Include only very important details about Frontend Studio's internal implementation.

Keep the README footer sections last and in this order:

- `Further Reading`
- `Development Notes`
- `Contributor Checks`
- `License and Author`
- `with ♥️ from anders und sehr GmbH`

Place all other README sections before this footer.

## Testing Guidelines

Add unit tests for isolated behavior. Add functional tests for TYPO3 integration.
Keep functional fixtures under `Tests/Functional/Fixtures/`. Run the relevant suite and GrumPHP before submitting changes.
Use PHPStan when changing PHP source.

## Commit & Pull Request Guidelines

Recent commits use `[FEATURE]`, `[BUGFIX]`, `[TASK]`, and `[DOCS]` prefixes. Follow the prefix with a short imperative summary.
Pull requests should describe the behavior changed and list checks run. Link related issues when available.
Include screenshots for backend UI changes.
