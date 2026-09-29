import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { deleteUpwards } from './deleteUpwards.ts';

void test('removes a file and its empty parent directories', async (context) => {
  const root = await mkdtemp(join(tmpdir(), 'frontend-studio-delete-'));
  context.after(() => rm(root, { recursive: true, force: true }));
  await writeFile(join(root, 'keep'), '');
  const nestedDirectory = join(root, 'one', 'two');
  await mkdir(nestedDirectory, { recursive: true });
  await writeFile(join(nestedDirectory, 'ignore'), 'entry');

  await deleteUpwards(join(nestedDirectory, 'ignore'));

  assert.deepEqual(await readdir(root), ['keep']);
});

void test('preserves a nonempty directory', async (context) => {
  const root = await mkdtemp(join(tmpdir(), 'frontend-studio-delete-'));
  context.after(() => rm(root, { recursive: true, force: true }));
  const directory = join(root, 'one');
  await mkdir(directory);
  await writeFile(join(directory, 'keep'), '');

  await deleteUpwards(directory);

  assert.deepEqual(await readdir(directory), ['keep']);
});

void test('ignores a missing file and removes an empty directory', async (context) => {
  const root = await mkdtemp(join(tmpdir(), 'frontend-studio-delete-'));
  context.after(() => rm(root, { recursive: true, force: true }));
  await writeFile(join(root, 'keep'), '');
  const directory = join(root, 'one');
  await mkdir(directory);

  await deleteUpwards(join(directory, 'missing'));
  assert.deepEqual(await readdir(directory), []);

  await deleteUpwards(directory);
  assert.deepEqual(await readdir(root), ['keep']);
});
