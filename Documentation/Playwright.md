# Component browser tests with Playwright

Playwright browser tests run from the TYPO3 project that contains the components. Frontend Studio supplies the component preview endpoint and the `getUrlForVariant()` test helper; the project supplies Playwright, its configuration, and a running TYPO3 site.

## Requirements

- Add Playwright Test to the project that owns the component tests:

  ```bash
  npm install --save-dev @playwright/test
  ```
- When running directly on the host, install its browser binaries:

  ```bash
  npx playwright install
  ```
- Run the TYPO3 project before starting Playwright. Its component collections must be registered, and each test must name a variant available in that component's fixture file.
- Set `TEST_BASE_URL` to an address the Playwright process can reach. The project configuration defaults to `http://web`, which is the web service hostname available from the DDEV Playwright container.

## Playwright configuration

The host project needs a root-level `playwright.config.ts`. This repository's config uses the following settings:

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  // Search for browser tests in the component packages.
  testDir: './packages',
  // Include TypeScript specs ending in .spec.ts.
  testMatch: '**/*.spec.ts',
  // Ignore generated templates and Storybook tests.
  testIgnore: ['**/CodeTemplates/**', '**/storybook/**'],

  // Allow tests to run in parallel.
  fullyParallel: true,
  // Reject test.only when CI is enabled.
  forbidOnly: !!process.env.CI,
  // Retry failures twice in CI; do not retry locally.
  retries: process.env.CI ? 2 : 0,
  // Use one worker in CI; use Playwright's default worker count locally.
  workers: process.env.CI ? 1 : undefined,
  // Generate an HTML report after the run.
  reporter: 'html',

  use: {
    // Emulate a browser with dark color preference.
    colorScheme: 'dark',
    // Set TEST_BASE_URL to a URL reachable by the runner; DDEV defaults to http://web.
    baseURL: process.env.TEST_BASE_URL || 'http://web',
    // Retain local failure traces; record a trace on the first CI retry.
    trace: process.env.CI ? 'on-first-retry' : 'retain-on-failure',
  },

  projects: [
    // Run with the Desktop Chrome device profile.
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // Run with the Desktop Firefox device profile.
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    // Run with the Desktop Safari device profile (WebKit).
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
```

This is a complete config file for the project layout shown in this guide; adjust `testDir`, `testMatch`, and `baseURL` if your tests or TYPO3 site use different paths or hosts.

The config does not start TYPO3 because it has no active `webServer` setting, so start the site before running tests. The default `http://web` address is reachable from the DDEV Playwright container. For a host-based runner, set `TEST_BASE_URL` to the TYPO3 site URL the host can reach. For example:

```bash
TEST_BASE_URL=https://my-project.ddev.site npx playwright test
```

`getUrlForVariant()` returns a root-relative URL at `/__frontendStudio/preview`; Playwright combines it with the configured base URL's origin. The helper supplies the endpoint path, so configure the base URL for the correct site host and port.

## Running the tests

From the project root, run the suite with the project's package manager, for example:

```bash
npx playwright test
```

The project package also defines `test:e2e` as a shortcut for `playwright test`, so `yarn test:e2e` or `npm run test:e2e` runs the same configured projects.

To run one spec in Chromium:

```bash
npx playwright test packages/content_element_text/Components/Element/Text/Text.spec.ts --project=chromium
```

### Using DDEV

If the TYPO3 project uses DDEV, [ochorocho/ddev-playwright](https://github.com/ochorocho/ddev-playwright) provides a separate container with Playwright and its browser dependencies. Install the add-on and restart DDEV:

```bash
ddev add-on get ochorocho/ddev-playwright
ddev restart
```

Then run the browser suite from the project root:

```bash
ddev playwright test
```

The add-on also supports selecting a browser, opening Playwright's interactive UI, and viewing the last HTML report:

```bash
ddev playwright test --project=chromium
ddev playwright browser
ddev playwright show-report
```

The project still needs `@playwright/test` in its Node dependencies. Keep the add-on's `PLAYWRIGHT_DOCKER_IMAGE` version aligned with the installed Playwright version; this repository sets the image in `.ddev/.env.playwright`.

## Writing a component spec

Keep a browser spec beside the component and name it `*.spec.ts`. The spec imports Playwright's `test` and `expect`, then calls `getUrlForVariant()` from Node-side test code:

```ts
import { expect, test } from '@playwright/test';
import { getUrlForVariant } from '@frontend_studio/getUrlForVariant.node.ts';

test('renders the selected Text variant', async ({ page }) => {
  await page.goto(getUrlForVariant('Simple Test'));

  await expect(page.locator('#c50')).toBeVisible();
  await expect(page.locator('#c50')).toContainText('Test');
});
```

The `@frontend_studio/*` alias in this repository's `tsconfig.node.json` points to `packages/frontend_studio/Tests/Playwright/`. A different project can configure the same alias to the helper or import the helper through a relative path.

## `getUrlForVariant()` options

The helper has this signature:

```ts
getUrlForVariant(
  variantName: string,
  componentPath?: string,
  site?: string,
  language?: string,
): string
```

It returns a URL shaped like `/__frontendStudio/preview?componentVariantName=...&componentPath=...`. It builds the query with `URLSearchParams`, so variant names, paths, site identifiers, and language values are encoded as query values.

| Argument | Required | Behavior |
| --- | --- | --- |
| `variantName: string` | Yes | The exact fixture variant name to render. It becomes the `componentVariantName` query parameter; it must match a variant defined for the component. |
| `componentPath?: string` | No | The component spec's path, used by Frontend Studio to identify the component. If omitted, the helper infers the calling file and sends its absolute filesystem path. Pass an absolute spec path when calling through a wrapper, because inference would otherwise identify the wrapper. A `file:` URL such as `import.meta.url` is converted to a filesystem path. |
| `site?: string` | No | A TYPO3 site identifier. If supplied, it becomes the `site` query parameter and selects that configured site. If omitted, Frontend Studio selects a site whose configured origin matches the request origin, then falls back to the first configured site. |
| `language?: string` | No | The selected site's configured language `hreflang`, such as `de-AT`. If supplied, it becomes the `language` query parameter. If omitted, Frontend Studio uses the first configured language for the selected site. |

The optional values are independent. Use `undefined` for an earlier optional argument when setting a later one:

```ts
// Let the helper infer the calling spec; select a site and language.
getUrlForVariant('Simple Test', undefined, 'main-site', 'de-AT');

// Select a language while resolving the site from the request origin.
getUrlForVariant('Simple Test', undefined, undefined, 'de-AT');

// Supply the spec path explicitly when a wrapper calls the helper.
getUrlForVariant('Simple Test', import.meta.url, 'main-site', 'de-AT');
```

Use identifiers and `hreflang` values configured in TYPO3. An unknown site or a language that does not belong to the selected site produces a preview error. Call the helper from the test's Node context, not from `page.evaluate()` or other browser-side code.
