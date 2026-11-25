import { parseAttribution } from '@sw-maps/map-contracts';

/** Permanently visible attribution (also in fullscreen: it lives inside the fullscreen element). */
export function buildAttribution(
  text: string,
  label: string,
  doc: Document = document,
): HTMLElement {
  const el = doc.createElement('div');
  el.className = 'swmap__attribution';
  el.setAttribute('aria-label', label);
  for (const part of parseAttribution(text)) {
    if (part.href !== undefined) {
      const a = doc.createElement('a');
      a.href = part.href;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = part.text;
      el.append(a);
    } else {
      el.append(doc.createTextNode(part.text));
    }
  }
  return el;
}
