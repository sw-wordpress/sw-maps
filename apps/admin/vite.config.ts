import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swmapScope } from './tools/postcssScope.ts';

/** Ship only the woff2 icon font (all supported browsers). */
function woff2Only() {
  return {
    name: 'swmap-woff2-only',
    enforce: 'pre' as const,
    transform(code: string, id: string) {
      if (!id.includes('material-icons-round.css')) return null;
      return code.replace(/,\s*url\([^)]*\.woff\d?\??[^)]*\)\s*format\((['"])woff\1\)/g, '');
    },
  };
}

const outDir = resolve(import.meta.dirname, '../../assets/admin');
const alias = {
  '@': fileURLToPath(new URL('./src', import.meta.url)),
  '@sw-maps/map-contracts': resolve(
    import.meta.dirname,
    '../../packages/map-contracts/src/index.ts',
  ),
  '@sw-maps/renderer-openlayers': resolve(
    import.meta.dirname,
    '../../packages/renderer-openlayers/src/index.ts',
  ),
};

export default defineConfig({
  base: './',
  plugins: [woff2Only(), vue({ template: { transformAssetUrls } }), quasar()],
  resolve: { alias },
  css: { postcss: { plugins: [swmapScope()] } },
  // Dev server (mock mode): scan every view and the renderer package at startup so that no dependency is discovered late,
  // which would re-optimise and reload while a view is being loaded lazily.
  optimizeDeps: { entries: ['src/main.ts', 'src/**/*.vue', '../../packages/renderer-openlayers/src/index.ts'] },
  server: { port: 5174, host: '127.0.0.1' },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2020',
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 1200,
    rolldownOptions: {
      input: { app: resolve(import.meta.dirname, 'src/main.ts') },
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => {
          const names = info.names ?? (info.name ? [info.name] : []);
          return names.some((n) => n.endsWith('.css'))
            ? 'app.css'
            : 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    setupFiles: ['tests/setup.ts'],
    css: false,
    server: { deps: { inline: ['quasar'] } },
    coverage: {
      provider: 'v8',
      // All productive sources, including files no test imports. The strict > 80 % gate is enforced by
      // scripts/check-coverage.mjs, because Vitest thresholds accept exactly 80 %.
      include: ['src/**/*.{ts,vue}'],
      exclude: ['src/**/*.d.ts'],
      reporter: ['text-summary', 'json-summary', ['lcov', { projectRoot: '../..' }]],
      reportsDirectory: 'coverage',
    },
  },
});
