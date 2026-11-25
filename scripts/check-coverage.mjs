// Strict coverage gate: line coverage must be strictly greater than the limit (exactly 80 % fails).
// Vitest's own `thresholds` accept equality, so this check runs after `vitest run --coverage`.
// Usage: node scripts/check-coverage.mjs <path to coverage-summary.json>
import { readFileSync } from 'node:fs';
import { basename, resolve, sep } from 'node:path';

const LIMIT = 80;
const file = resolve(process.cwd(), process.argv[2] ?? 'coverage/coverage-summary.json');
if (!file.startsWith(process.cwd() + sep) || basename(file) !== 'coverage-summary.json') {
  console.error('Only a coverage-summary.json inside the current directory is accepted.');
  process.exit(1);
}
let summary;
try {
  summary = JSON.parse(readFileSync(file, 'utf8'));
} catch {
  console.error(`${file} missing: run the coverage tests first.`);
  process.exit(1);
}
const { covered, total } = summary.total.lines;
const pct = total === 0 ? 0 : (covered / total) * 100;
const msg = `Line coverage ${pct.toFixed(2)} % (${covered}/${total}), required > ${LIMIT} %`;
if (!(total > 0 && covered * 100 > LIMIT * total)) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}
console.log(`OK: ${msg}`);
