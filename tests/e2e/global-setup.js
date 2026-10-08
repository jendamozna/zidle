import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { ENV } from '../../playwright.config.js';

// Wipes and seeds the test database; the tests read the seeded values from .seed.json.
export default function globalSetup() {
  const out = execFileSync('php', ['tests/e2e/seed.php'], { env: ENV, encoding: 'utf8' });
  writeFileSync(new URL('./.seed.json', import.meta.url), out);
}
