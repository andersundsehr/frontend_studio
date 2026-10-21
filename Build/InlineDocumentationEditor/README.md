# Documentation JavaScript bundles

Use this tooling when contributing changes to documentation rendering, the inline editor or syntax highlighting.
All three browser bundles are committed in `Resources/Public/Contrib/`; TYPO3 needs no Node.js, npm or CDN at runtime.

Run the commands below from the repository root.
The test runner uses Docker or Podman and pins Node.js **24.19.0** through `docker.io/node:24.19.0-bookworm`,
both locally and in GitHub Actions.
Following [TYPO3's test runner](https://github.com/TYPO3/typo3/blob/main/Build/Scripts/runTests.sh),
npm and bundle builds use a Node image; browser tests use a separate Chromium image.
`Build/Scripts/Dockerfile.NodejsChrome` builds that image from the same pinned Node.js version.
Docker/Podman caches its browser-installation layer between test runs.
Use `-b docker` or `-b podman` to select a runtime explicitly.
PHP test suites do not install or rebuild JavaScript.
Use `Build/Scripts/runTests.sh` for dependency commands, builds and checks.
The `npm` suite forwards arguments after `--` to npm in the same pinned container.

## Install dependencies

Install dependencies using the test runner:

```sh
./Build/Scripts/runTests.sh -s npm -- ci
```

As a secondary option, with Node.js 24.19.0 installed locally:

```sh
npm ci
```

`npm ci` installs the versions recorded in the root `package-lock.json`.
The containerized build and test commands below install these dependencies automatically,
so local Node.js is optional.
The private root `package.json` owns both browser-bundle and test dependencies,
with one root `package-lock.json`.
The Playwright source, existing tests and TypeScript configuration stay in `Playwright/`.
Browser and documentation tests run from `Tests/browser/` using the root package's pinned test tools.
Build sources remain in `Build/InlineDocumentationEditor/`; they use the same root installation.
See [internal JavaScript checks](../../Tests/browser/README.md) for running tests from both locations.

## Update a dependency

Update an existing build dependency and save its exact version using the runner:

```sh
./Build/Scripts/runTests.sh -s npm -- install --save-dev --save-exact markdown-it@latest
```

The secondary local npm equivalent is:

```sh
npm install --save-dev --save-exact markdown-it@latest
```

Replace `markdown-it` with the package you want to update.
You can use a specific version instead of `latest`.
This updates both `package.json` and `package-lock.json`.
Update test dependencies in the same root package through the runner:

```sh
./Build/Scripts/runTests.sh -s npm -- install --save-dev --save-exact @playwright/test@latest
```

The secondary local npm equivalent is:

```sh
npm install --save-dev --save-exact @playwright/test@latest
```

Regenerate the bundles and run the tests after updating build dependencies.

## Regenerate and test

Rebuild all three bundles with the pinned container environment:

```sh
./Build/Scripts/runTests.sh -s javascriptBuild
```

- `inline-documentation-editor.js` provides the editor and toolbar icons.
- `code-highlighting.js` provides shared syntax highlighting.
- `markdown-converter.js` exports `renderMarkdown` and `toMarkdown`.

The build embeds third-party license notices in each generated bundle.
See `code-highlighting.js` for supported languages and their labels.
Unspecified or unregistered languages render as plain text.
Fluid is a separate language from HTML/XML and uses the PHP highlighter's token classes and existing theme colors.
Its shared PHP/JavaScript regression fixtures are in `Tests/Unit/Service/Fixtures/fluid-highlighting.json`.

As a secondary option with local dependencies installed:

```sh
npm run build
```

Run JavaScript tests, browser tests and TypeScript checks from both test locations:

```sh
./Build/Scripts/runTests.sh -s javascript
```

This installs locked root dependencies and runs tests in the prepared Chromium container.
As a secondary option with local dependencies and Chromium already installed,
the equivalent test commands are:

```sh
CHROMIUM_PATH=/usr/bin/chromium npm test
npm run typecheck
```

## Commit generated files and verify reproducibility

Commit changed build sources, the root manifest and lockfile,
and all three generated bundles together:

```sh
git add Build/InlineDocumentationEditor package.json package-lock.json \
  Resources/Public/Contrib/inline-documentation-editor.js \
  Resources/Public/Contrib/code-highlighting.js \
  Resources/Public/Contrib/markdown-converter.js
git commit -m "[TASK] Rebuild documentation JavaScript bundles"
./Build/Scripts/runTests.sh -s javascriptBuildCheck
```

`javascriptBuildCheck` starts with a clean dependency installation using `npm ci`.
It rebuilds all three bundles in memory without overwriting them.
Each expected file must exist, be tracked and be committed.
The check compares both the rebuilt output and the working copy with Git `HEAD`.
Commit regenerated bundles before running this check.

GitHub Actions automatically runs this same check for pull requests.
Missing, untracked, modified or outdated bundles fail CI.

As a secondary option with local dependencies installed, run the same build check directly:

```sh
npm run build:check
```
