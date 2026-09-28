# Repository Guidelines

## Project Structure & Module Organization

Frontend Studio is a TYPO3 extension. PHP source lives in `Classes/` under the `Andersundsehr\FrontendStudio\` namespace; TYPO3 services, routes, and backend modules are configured in `Configuration/`. Templates, JavaScript, CSS, icons, and translations are in `Resources/`. User documentation and screenshots are in `Documentation/`. Tests are grouped in `Tests/Unit/`, `Tests/Functional/` (with fixtures in `Tests/Functional/Fixtures/`), and `Tests/Playwright/`.

## Build, Test, and Development Commands

`Build/Scripts/runTests.sh` runs checks in Docker or Podman and supports PHP 8.4 and 8.5. From the package root, use:

```bash
./Build/Scripts/runTests.sh -p 8.5 -s unit
./Build/Scripts/runTests.sh -p 8.5 -s functional -d mysql
./Build/Scripts/runTests.sh -p 8.5 -s grumphpRun
./Build/Scripts/runTests.sh -p 8.5 -s phpstan
```

Functional tests also support `mariadb` and `postgres` via `-d`.

## Coding Style & Naming Conventions

Follow `.editorconfig`: spaces, LF endings, and a final newline; indentation is 2 spaces generally and 4 spaces for PHP. Keep PHP classes PSR-4 namespaced and align file names with class names. Name PHPUnit tests `*Test.php` and place them under the matching `Tests/Unit/` or `Tests/Functional/` area. Prefer the smallest direct solution; avoid speculative abstractions.

## Testing Guidelines

Add unit tests for isolated behavior and functional tests for TYPO3 integration. Keep functional fixtures under `Tests/Functional/Fixtures/`. Run the relevant suite and GrumPHP before submitting changes; use PHPStan when changing PHP source.

## Commit & Pull Request Guidelines

Recent commits use scoped prefixes such as `[FEATURE]`, `[BUGFIX]`, `[TASK]`, and `[DOCS]`, followed by a short imperative summary. Pull requests should describe the behavior changed, list checks run, link related issues when available, and include screenshots for backend UI changes.
