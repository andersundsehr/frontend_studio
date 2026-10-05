import { readFileSync } from 'node:fs';
import { createContext, SourceTextModule, SyntheticModule, type Module } from 'node:vm';

const backend = new URL('../../Resources/Public/JavaScript/Backend/', import.meta.url);

// Evaluate real ES modules, including conditional imports, with explicit TYPO3/DOM substitutes.
export function backendModules(globals: Record<string, unknown> = {}, substitutes: Record<string, unknown> = {}) {
  const context = createContext({ Event, EventTarget, CustomEvent, AbortController, URL, setTimeout, clearTimeout, ...globals });
  const modules = new Map<string, Promise<Module>>();
  const loaded = new Set<string>();
  const defaults: Record<string, unknown> = {
    '@typo3/core/ajax/ajax-request.js': class {
      post() { throw new Error('Unexpected AJAX request in this test'); }
    },
    '@typo3/core/document-service.js': { ready: () => ({ then: () => {} }) },
    '@typo3/backend/storage/persistent.js': { set: async () => {} },
    '@typo3/backend/notification.js': { success: () => {}, error: () => {} },
    ...substitutes,
  };
  function load(identifier: string): Promise<Module> {
    if (!modules.has(identifier)) {
      const promise = (async () => {
        if (identifier in defaults) {
          const module = new SyntheticModule(['default'], function () {
            this.setExport('default', defaults[identifier]);
          }, { context, identifier });
          await module.link(() => { throw new Error('Unexpected synthetic dependency'); });
          return module;
        }
        loaded.add(identifier);
        const module = new SourceTextModule(readFileSync(new URL(identifier, backend), 'utf8'), {
          context,
          identifier,
          importModuleDynamically: async (specifier, parent) => {
            const dependency = await load(resolve(specifier, parent.identifier));
            await dependency.evaluate();
            return dependency;
          },
        });
        await module.link((specifier, parent) => load(resolve(specifier, parent.identifier)));
        return module;
      })();
      modules.set(identifier, promise);
    }
    return modules.get(identifier)!;
  }
  function resolve(specifier: string, parent: string): string {
    if (specifier.startsWith('@andersundsehr/frontend-studio/backend/')) {
      return new URL(specifier.slice('@andersundsehr/frontend-studio/backend/'.length), backend).href;
    }
    return specifier.startsWith('.') ? new URL(specifier, new URL(parent, backend)).href : specifier;
  }
  return {
    loaded,
    async import(name: string): Promise<any> {
      const module = await load(new URL(name, backend).href);
      await module.evaluate();
      return module.namespace;
    },
  };
}
