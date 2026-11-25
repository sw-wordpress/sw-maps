import type { Unsubscribe } from '@sw-maps/map-contracts';

/** Minimal typed event emitter. A failing listener never prevents the others from running. */
export class Emitter<Events extends object> {
  private readonly handlers = new Map<keyof Events, Set<(payload: never) => void>>();

  on<K extends keyof Events>(event: K, handler: (payload: Events[K]) => void): Unsubscribe {
    let set = this.handlers.get(event);
    if (!set) {
      set = new Set();
      this.handlers.set(event, set);
    }
    set.add(handler as (payload: never) => void);
    return () => {
      set.delete(handler as (payload: never) => void);
    };
  }

  emit<K extends keyof Events>(event: K, payload: Events[K]): void {
    for (const handler of [...(this.handlers.get(event) ?? [])]) {
      try {
        (handler as (payload: Events[K]) => void)(payload);
      } catch (error) {
        console.error('sw-maps: listener failed', error);
      }
    }
  }

  clear(): void {
    this.handlers.clear();
  }
}
