import './viewer.css';
import { initAll } from './viewer';

/** Frontend bundle entry: starts every map on the page once the DOM is ready. */
export function run(doc: Document = document): void {
  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', () => initAll(doc), { once: true });
  } else {
    initAll(doc);
  }
}

run();
