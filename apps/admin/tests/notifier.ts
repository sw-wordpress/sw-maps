import { vi } from 'vitest';

/** Shared spy for the notification composable (mocked per test file with vi.mock). */
export const notifier = { success: vi.fn(), error: vi.fn() };
