# JavaScript and browser checks

Run Frontend Studio's JavaScript checks from the repository root.
The suite includes the existing tests in `Playwright/src/` and extension tests in `Tests/browser/`.

## Where tests belong

Choose the location by the code or behavior being tested:

| What you are testing | Location | Example |
| --- | --- | --- |
| Public `@frontend_studio/test` helpers | `Playwright/src/*.test.ts`, beside the helper | `fetchVariants.test.ts` |
| Extension JavaScript, backend UI, documentation and browser rendering | `Tests/browser/` | `documentationRequests.test.ts`, `documentationEditor.mjs` |
| TYPO3 integration, controllers and rendered Fluid templates | `Tests/Functional/` | `Fluid/OwnComponentsTest.php` |
| Isolated PHP behavior | `Tests/Unit/` | `Service/HtmlSourceHighlighterTest.php` |
| A user's own Fluid components | Beside the component in the user's TYPO3 project, as `*.spec.ts` | `Text.spec.ts` |

`Playwright/` is the installable public `@frontend_studio/test` package.
Its TypeScript helpers let users discover fixture variants, build preview URLs and run component snapshot checks.
See its [consumer README](../../Playwright/README.md) for installation and project-level specs.
Extension tests that launch Playwright also belong in `Tests/browser/`.
Existing tests in `Playwright/src/` stay in place, including legacy extension regressions;
add new extension regressions in `Tests/browser/`.

`componentTreeFiltering.mjs` runs Chromium against the unmodified TYPO3 13.4.35 and 14.3.7
Tree and TreeToolbar, including each release's Lit, Ajax, debounce and storage implementations.
It downloads pinned public JavaScript from `TYPO3-CMS/backend` and `TYPO3-CMS/core` on GitHub
on the first run and caches it in `var/.cache/typo3-tree/`.
The initial run needs network access to `raw.githubusercontent.com`; cached runs work offline.
Only unrelated backend chrome and HTTP endpoint responses are stubbed.
The tests click real Overview links in a content iframe and edit the actual documentation editor.
Controlled request gates exercise pending and overlapping filters, stale responses,
deleted or renamed variants, failed reloads and rejected navigation.
After unsuccessful navigation, the tests attempt an unrelated browser navigation,
dismiss its native unload warning and verify that the same edited document remains mounted.

The consumer package exports TypeScript directly and has no separate compilation step.
Keep its manifest, TypeScript configuration and source unchanged when working on extension UI or contributor tooling.
Do not generate `Playwright/dist/` or recreate `Playwright/browser/`.

## Shared tooling and checks

The private root npm package owns test and browser-bundle tooling with one root `package-lock.json`.
It supplies pinned dependencies and a command that runs tests from both locations.
`Tests/browser/tsconfig.json` extends `Playwright/tsconfig.json` and includes both folders without emitting JavaScript.
Add contributor dependencies to the root manifest and commit its lockfile.
Keep build sources in `Build/InlineDocumentationEditor/` and generated bundles in `Resources/Public/Contrib/`.
Neither `Tests/browser/` nor `Build/InlineDocumentationEditor/` needs its own npm manifest or lockfile.

Use Docker or Podman to run the complete suite with pinned Node.js 24.19.0 and system Chromium:

```sh
./Build/Scripts/runTests.sh -s javascript
```

The runner follows [TYPO3's JavaScript handling](https://github.com/TYPO3/typo3/blob/main/Build/Scripts/runTests.sh).
It prepares a cached Chromium image from the pinned Node base and runs npm as the calling user.
TYPO3's suite name `unitJavascript` is also supported as an alias:

```sh
./Build/Scripts/runTests.sh -s unitJavascript
```

This installs root dependencies with `npm ci`, runs JavaScript and real-browser tests from both locations,
and checks TypeScript for the Playwright implementation and all test sources.
The GitHub Actions JavaScript job runs the same command for pull requests.
Bundle reproducibility remains a separate check:

```sh
./Build/Scripts/runTests.sh -s javascriptBuildCheck
```

To install dependencies or run TypeScript checking separately:

```sh
./Build/Scripts/runTests.sh -s npm -- ci
./Build/Scripts/runTests.sh -s npm -- run typecheck
```

The shared TypeScript check skips third-party declarations because LinkeDOM's declarations conflict with `lib.dom`;
the Playwright implementation and all test source remain checked with strict settings.
Commit the root `package.json` and `package-lock.json` when updating contributor dependencies.

As a secondary option with Node.js 24.19.0 and Chromium installed locally:

```sh
npm ci
CHROMIUM_PATH=/usr/bin/chromium npm test
npm run typecheck
```
