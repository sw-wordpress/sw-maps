import { vi } from 'vitest';

// jsdom has no canvas: provide the small subset OpenLayers needs so a real map can be constructed.
const context = new Proxy(
  {},
  {
    get: (_target, prop) => {
      if (prop === 'canvas') return document.createElement('canvas');
      if (prop === 'measureText') return () => ({ width: 0 });
      if (prop === 'getImageData') return () => ({ data: new Uint8ClampedArray(4) });
      return vi.fn();
    },
    set: () => true,
  },
);
HTMLCanvasElement.prototype.getContext = vi.fn(() => context) as never;

class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
// Assigned directly (not via vi.stubGlobal) so that vi.unstubAllGlobals() in tests keeps it.
globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver;
