import { build } from 'esbuild';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const directory = fileURLToPath(new URL('.', import.meta.url));
const root = resolve(directory, '../..');
const check = process.argv.includes('--check');
if (process.argv.slice(2).some(argument => argument !== '--check')) throw new Error('Use build.mjs with no arguments or --check.');
const bundles = [
  ['index.js', 'inline-documentation-editor.js'],
  ['code-highlighting.js', 'code-highlighting.js'],
  ['markdown-converter.js', 'markdown-converter.js'],
];

for (const [entry, filename] of bundles) {
  const path = `Resources/Public/Contrib/${filename}`;
  const result = await build({
    absWorkingDir: directory,
    entryPoints: [resolve(directory, entry)],
    outfile: resolve(root, path),
    plugins: entry === 'index.js' ? [{ name: 'shared-highlighter', setup(builder) {
      builder.onResolve({ filter: /^highlight\.js\/lib\/core$/ }, () => ({
        path: '@andersundsehr/frontend-studio/vendor/code-highlighting', external: true,
      }));
    } }] : [],
    bundle: true,
    format: 'esm',
    target: 'es2022',
    minify: true,
    legalComments: 'inline',
    metafile: true,
    write: false,
  });

  const packages = new Set();
  for (const input of Object.keys(result.metafile.inputs)) {
    if (!input.includes('node_modules/')) continue;
    let packageDirectory = dirname(resolve(directory, input));
    while (packageDirectory.includes('node_modules')) {
      try {
        const pkg = JSON.parse(await readFile(`${packageDirectory}/package.json`, 'utf8'));
        if (pkg.name) { packages.add(packageDirectory); break; }
        packageDirectory = dirname(packageDirectory);
      } catch { packageDirectory = dirname(packageDirectory); }
    }
  }
  const notices = [];
  for (const directory of [...packages].sort()) {
    const pkg = JSON.parse(await readFile(`${directory}/package.json`, 'utf8'));
    const license = (await readdir(directory)).find(name => /^licen[cs]e(?:-MIT)?(?:\.(?:md|txt))?$/i.test(name));
    if (!license) throw new Error(`Missing license notice for ${pkg.name}`);
    notices.push(`${pkg.name} ${pkg.version}\n${await readFile(`${directory}/${license}`, 'utf8')}`);
  }
  const notice = `/*!\n${notices.join('\n\n').replaceAll('*/', '* /')}\n*/\n`;
  const generated = notice + result.outputFiles[0].text;
  if (!check) {
    await writeFile(result.outputFiles[0].path, generated);
    console.log(`Built ${path}`);
    continue;
  }

  try {
    execFileSync('git', ['ls-files', '--error-unmatch', '--', path], { cwd: root, stdio: 'pipe' });
    const committed = execFileSync('git', ['show', `HEAD:${path}`], { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
    const current = await readFile(resolve(root, path), 'utf8');
    if (current !== committed) throw new Error('has local changes');
    if (generated !== committed) throw new Error('is outdated or not reproducible');
    console.log(`Verified ${path}`);
  } catch (error) {
    const reason = error.code === 'ENOENT' ? 'is missing' : error.status !== undefined ? 'must be tracked and committed in Git' : error.message;
    console.error(`${path} ${reason}. Run ./Build/Scripts/runTests.sh -s javascriptBuild and commit all generated bundles.`);
    process.exitCode = 1;
  }
}
