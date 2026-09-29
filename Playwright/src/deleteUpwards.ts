import { lstat, readdir, rmdir, unlink } from 'node:fs/promises';
import { dirname } from 'node:path';

export async function deleteUpwards(dirOrFileName: string) {
  if (dirOrFileName === '.') return;
  if (dirOrFileName === '/') return;
  if (dirOrFileName === '') return;

  try {
    // if directory and empty remove directory
    // and recursive call with parent directory
    const element = await lstat(dirOrFileName);
    if (element.isDirectory()) {
      const dir = await readdir(dirOrFileName);
      if (dir.length > 0) {
        return; // stop recursion
      }
      await rmdir(dirOrFileName);
    }

    // if file and not directory unlink file
    // and recursive call with parent directory
    if (element.isFile()) {
      await unlink(dirOrFileName);
    }
  } catch (e: unknown) {
    // file not found: ignore
    if (e && typeof e === 'object' && 'code' in e && e.code === 'ENOENT') {
      return;
    }
    throw e;
  }

  return await deleteUpwards(dirname(dirOrFileName));
}
