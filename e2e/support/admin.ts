import type { Locator, Page } from '@playwright/test';
import { PLUGIN_PAGE } from './env';

/**
 * Opens an admin route. On a cold stack the very first admin page can stay blank for a while (the app chunks are
 * still being served): wait briefly for the given element and reload once when it did not appear.
 */
export async function openAdmin(
  page: Page,
  hash: string,
  ready: (page: Page) => Locator,
): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}${hash}`);
  const shown = await ready(page)
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(
      () => true,
      () => false,
    );
  if (!shown) await page.reload();
}
