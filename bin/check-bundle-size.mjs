#!/usr/bin/env node
// Bundle size budget (docs/PLAN.md section 18): compressed (gzip) size of the shipped assets must stay below the
// budgets in bin/bundle-budget.json. Run after the builds: `node bin/check-bundle-size.mjs`.
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const budget = JSON.parse(readFileSync(join(root, 'bin/bundle-budget.json'), 'utf8'));

function files(dir, pattern) {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? files(path, pattern) : pattern.test(name) ? [path] : [];
  });
}

let failed = false;
for (const entry of budget.groups) {
  const list = files(join(root, entry.dir), new RegExp(entry.pattern));
  if (list.length === 0) {
    console.error(`FAIL ${entry.name}: no files found in ${entry.dir} (build first)`);
    failed = true;
    continue;
  }
  const bytes = list.reduce((sum, file) => sum + gzipSync(readFileSync(file), { level: 9 }).length, 0);
  const kb = bytes / 1024;
  const ok = kb <= entry.maxGzipKb;
  failed ||= !ok;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${entry.name}: ${kb.toFixed(1)} KB gzip (budget ${entry.maxGzipKb} KB, ${list.length} file(s))`);
}
process.exit(failed ? 1 : 0);
