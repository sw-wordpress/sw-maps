import { vi } from 'vitest';

type Handler = (payload: never) => void;

/** Stand-in for EditableOpenLayersRenderer: records calls and lets tests fire renderer events. */
export class FakeRenderer {
  static instances: FakeRenderer[] = [];
  static failOnMount = false;
  handlers = new Map<string, Handler>();
  mount = vi.fn(() => {
    if (FakeRenderer.failOnMount) throw new Error('webgl lost');
  });
  update = vi.fn();
  destroy = vi.fn();
  setTool = vi.fn();
  setView = vi.fn();
  selectFeature = vi.fn();
  fitBounds = vi.fn();
  getView = vi.fn(() => ({ center: [8.4, 49] as [number, number], zoom: 10 }));
  on = vi.fn(
    (event: string, handler: Handler) => (
      this.handlers.set(`on:${event}`, handler),
      () => undefined
    ),
  );
  onEdit = vi.fn(
    (event: string, handler: Handler) => (
      this.handlers.set(`edit:${event}`, handler),
      () => undefined
    ),
  );

  constructor() {
    FakeRenderer.instances.push(this);
  }

  fire(key: string, payload: unknown): void {
    this.handlers.get(key)?.(payload as never);
  }

  static reset(): void {
    FakeRenderer.instances = [];
    FakeRenderer.failOnMount = false;
  }
}
