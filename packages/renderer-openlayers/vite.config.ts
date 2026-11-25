import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

const outDir = resolve(import.meta.dirname, '../../assets/frontend');

// The frontend bundle: one IIFE (plus CSS) loaded only on pages that contain a map. No Vue/Quasar inside.
export default defineConfig({
  resolve: {
    alias: {
      '@sw-maps/map-contracts': resolve(import.meta.dirname, '../map-contracts/src/index.ts'),
    },
  },
  build: {
    outDir,
    emptyOutDir: true,
    sourcemap: false,
    target: 'es2020',
    cssCodeSplit: false,
    lib: {
      entry: resolve(import.meta.dirname, 'src/entry.ts'),
      formats: ['iife'],
      name: 'SWMapsViewer',
      fileName: () => 'swmaps-viewer.js',
      cssFileName: 'swmaps-viewer',
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    setupFiles: ['tests/setup.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      reporter: ['text-summary', 'json-summary', ['lcov', { projectRoot: '../..' }]],
      reportsDirectory: 'coverage',
    },
  },
});
