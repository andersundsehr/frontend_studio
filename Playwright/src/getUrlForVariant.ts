import { fileURLToPath } from 'node:url';

/**
 * Creates the Frontend Studio preview URL for a component variant.
 *
 * Call this directly from a Node based component spec, for example:
 * `await page.goto(getUrlForVariant('Special Variant'))`. By default, the
 * caller's file is sent as the absolute `componentPath`, so the preview request
 * can find the registered component automatically. Pass `componentPath` only
 * when a wrapper needs to provide the component spec's absolute filesystem path
 * or `import.meta.url` explicitly; file URLs are converted before sending. This
 * helper is for test setup code, not browser side code.
 *
 * @param variantName The component variant name to render.
 * @param componentPath Optional absolute filesystem path or `import.meta.url` for the component spec.
 * @param site Optional TYPO3 site identifier; when omitted, the backend site default is used.
 * @param language Optional TYPO3 language hreflang value; when omitted, the selected site's first language is used.
 * @returns A root-relative URL for the Frontend Studio component preview endpoint.
 */
export function getUrlForVariant(variantName: string, componentPath?: string, site?: string, language?: string): string {
  const parameters = new URLSearchParams({
    componentVariantName: variantName,
    componentPath: componentPath?.startsWith('file:') ? fileURLToPath(componentPath) : componentPath || getCallerFile(),
  });

  if (site !== undefined) {
    parameters.set('site', site);
  }
  if (language !== undefined) {
    parameters.set('language', language);
  }

  return `/__frontendStudio/preview?${parameters.toString()}`;
}

function getCallerFile(): string {
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const originalPrepareStackTrace = Error.prepareStackTrace;

  try {
    Error.prepareStackTrace = (_, stack) => stack;

    const error = new Error();
    const stack = error.stack as unknown as { getFileName(): string | null }[];

    if (stack.length > 2) {
      const callerFile = stack[2].getFileName();
      if (callerFile) {
        return callerFile.startsWith('file:') ? fileURLToPath(callerFile) : callerFile;
      }
    }

    throw new Error('Could not determine the caller file');
  } finally {
    Error.prepareStackTrace = originalPrepareStackTrace;
  }
}
