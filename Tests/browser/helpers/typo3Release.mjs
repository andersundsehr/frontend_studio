import { mkdir, readFile, writeFile } from 'node:fs/promises';

const pending = new Map();

// Read released JavaScript verbatim, including TYPO3's own Lit, Ajax and storage
// implementations. Cache it in the ignored var directory for subsequent offline runs.
export function typo3ReleaseModule(version, extension, path) {
  const key = `${version}/${extension}/${path}`;
  if (!pending.has(key)) pending.set(key, load());
  return pending.get(key);

  async function load() {
    const file = new URL(`../../../var/.cache/typo3-tree/${key}`, import.meta.url);
    try {
      return await readFile(file, 'utf8');
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    const url = `https://raw.githubusercontent.com/TYPO3-CMS/${extension}/v${version}/Resources/Public/JavaScript/${path}`;
    const response = await fetch(url, { signal: AbortSignal.timeout(30000) });
    if (!response.ok) throw new Error(`TYPO3 fixture download failed: ${response.status} ${url}`);
    const source = await response.text();
    await mkdir(new URL('.', file), { recursive: true });
    await writeFile(file, source);
    return source;
  }
}
