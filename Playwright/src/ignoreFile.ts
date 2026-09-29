import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { test } from '@playwright/test';
import { deleteUpwards } from './deleteUpwards.ts';

function getIgnoreFileName(fileEnding: string) {
  return test
    .info()
    .snapshotPath()
    .replace(/\.txt$/, fileEnding);
}

async function getIgnoreFileContent(fileEnding: string) {
  const consoleIgnoreFilename = getIgnoreFileName(fileEnding);
  await mkdir(dirname(consoleIgnoreFilename), { recursive: true });
  let fileContent = '';
  try {
    fileContent = await readFile(consoleIgnoreFilename, 'utf-8');
  } catch {
    // Ignore error if file does not exist
  }

  return fileContent
    .split('\n')
    .map((line: string) => line.trim())
    .filter((line: string) => line && !line.startsWith('#'));
}

interface IgnoreEntriesMetadata {
  ignoreEntries?: Record<string, (string | RegExp)[]>;
}

function getGlobalIgnores(fileEnding: string): (string | RegExp)[] {
  const metadata = (test.info().project.metadata ?? {}) as IgnoreEntriesMetadata;
  return metadata.ignoreEntries?.[fileEnding] || [];
}

export async function createFilterFunction(fileEnding: string, withLocal = true): Promise<(value: string) => boolean> {
  const globalIgnores = getGlobalIgnores(fileEnding);
  let ignores: string[] = [];
  if (withLocal) {
    ignores = await getIgnoreFileContent(fileEnding);
  }

  const ignoresSet = new Set([...globalIgnores, ...ignores]);
  const allIgnores = [...ignoresSet].map((ignore) => {
    if (typeof ignore !== 'string') {
      return ignore;
    }
    if (!ignore.startsWith('^') && !ignore.endsWith('$')) {
      return ignore;
    }
    return new RegExp(ignore);
  });

  // return (value: string) => !ignoresSet.has(value);

  // For partial match use this:
  return (value: string) => {
    return !allIgnores.some((ignore) => {
      if (ignore instanceof RegExp) {
        // Global and sticky expressions retain lastIndex between messages.
        ignore.lastIndex = 0;
        return ignore.test(value);
      }
      return value === ignore;
    });
  };
}

export async function writeIgnoreFile(ignores: string[], fileEnding = '.consoleignore') {
  ignores = ignores.filter(await createFilterFunction(fileEnding, false));

  const ignoreFilename = getIgnoreFileName(fileEnding);
  if (ignores.length === 0) {
    await deleteUpwards(ignoreFilename);
    console.log('nothing to ignore, removed ' + ignoreFilename);
    return;
  }
  const set = new Set(ignores);
  const fileIgnores = new Set(await getIgnoreFileContent(fileEnding));
  // if ignores are exactly the same as before, do nothing
  if (fileIgnores.size === set.size && [...fileIgnores].every((v: string) => set.has(v))) {
    return;
  }
  const content: string = [...set].sort().join('\n') + '\n';
  await mkdir(dirname(ignoreFilename), { recursive: true });
  await writeFile(ignoreFilename, content);

  console.warn('Wrote ' + ignoreFilename + ' with ' + ignores.length + ' entries');
}
