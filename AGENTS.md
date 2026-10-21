# Repository Guidelines

## Project Structure & Module Organization

Frontend Studio is a TYPO3 extension. PHP source lives in `Classes/`. It uses the `Andersundsehr\FrontendStudio\` namespace.
TYPO3 services, routes, and backend modules use `Configuration/`. Templates, JavaScript,
CSS, icons, and translations use `Resources/`. User documentation and screenshots are in `Documentation/`.
PHP tests are in `Tests/Unit/` and `Tests/Functional/`. Functional fixtures are in `Tests/Functional/Fixtures/`.
The public `@frontend_studio/test` consumer package lives in `Playwright/`.
It supplies TypeScript helpers for testing Fluid components in a user's TYPO3 project.
Extension JavaScript and browser regression tests live in `Tests/browser/`.

## JavaScript Package and Test Boundaries

- Put new tests for extension JavaScript, backend UI, documentation editing and rendering in `Tests/browser/`,
  including tests that launch Playwright or use a DOM implementation.
- Put tests for the public consumer helpers beside their implementation as `Playwright/src/*.test.ts`.
  Existing files and tests in `Playwright/src/` stay in place, including legacy extension tests.
- When changing contributor tooling or extension UI, preserve `Playwright/package.json`,
  `Playwright/tsconfig.json` and `Playwright/src/`.
  Change these only when the task calls for changes to the consumer package or its existing tests.
- The public package exports TypeScript directly. Do not generate or commit `Playwright/dist/`,
  add compilation or freshness checks for it, or recreate `Playwright/browser/`.
- Keep contributor build and test dependencies in the private root `package.json` and `package-lock.json`.
  `Playwright/` is the only workspace. Do not add separate manifests or lockfiles in `Tests/browser/`
  or `Build/InlineDocumentationEditor/`.
- Keep JavaScript tests and no-emit TypeScript checks covering both `Playwright/src/` and `Tests/browser/` in CI.
  Keep `javascriptBuildCheck` as a separate mandatory pull-request check for the three committed bundles.

See [test placement and JavaScript checks](Tests/browser/README.md) for examples,
and [consumer usage](Playwright/README.md) for testing a user's components.

## Build, Test, and Development Commands

`Build/Scripts/runTests.sh` runs checks in Docker or Podman. It supports PHP 8.4 and 8.5.
From the package root, use:

```bash
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s unit
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s functional -d mysql
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s grumphpRun
CI=true ./Build/Scripts/runTests.sh -p 8.5 -s phpstan
CI=true ./Build/Scripts/runTests.sh -s javascript
CI=true ./Build/Scripts/runTests.sh -s javascriptBuildCheck
```

Functional tests also support `mariadb` and `postgres` via `-d`.
JavaScript suites use pinned Node.js 24.19.0. Use the runner for contributor npm commands:

```bash
./Build/Scripts/runTests.sh -s npm -- ci
./Build/Scripts/runTests.sh -s npm -- run typecheck
./Build/Scripts/runTests.sh -s javascriptBuild
```

Present runner commands first in contributor documentation, with direct npm/npx commands as secondary local options.
PHP test suites must not automatically install or rebuild JavaScript.

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
