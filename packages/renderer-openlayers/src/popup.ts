import { safeLink } from '@sw-maps/map-contracts';
import type { FeatureProperties, ViewerStrings } from '@sw-maps/map-contracts';

/**
 * Builds the popup content from the structured fields with DOM APIs only (textContent / setAttribute); there is no
 * innerHTML path, so imported strings can never become markup.
 */
export function buildPopup(
  properties: FeatureProperties,
  strings: Pick<ViewerStrings, 'openLink' | 'closePopup'>,
  onClose: () => void,
  doc: Document = document,
): HTMLElement {
  const root = doc.createElement('div');
  root.className = 'swmap-popup';
  root.setAttribute('role', 'dialog');
  root.setAttribute('aria-label', properties.title || strings.closePopup);

  const close = doc.createElement('button');
  close.type = 'button';
  close.className = 'swmap-popup__close';
  close.setAttribute('aria-label', strings.closePopup);
  close.textContent = '×';
  close.addEventListener('click', (event) => {
    event.stopPropagation();
    onClose();
  });
  root.append(close);

  if (properties.title) {
    const h = doc.createElement('h3');
    h.className = 'swmap-popup__title';
    h.textContent = properties.title;
    root.append(h);
  }
  const imageUrl = safeImageUrl(properties.image_url);
  if (imageUrl !== null) {
    const img = doc.createElement('img');
    img.className = 'swmap-popup__image';
    img.src = imageUrl;
    img.alt = '';
    img.loading = 'lazy';
    root.append(img);
  }
  if (properties.description) {
    const p = doc.createElement('p');
    p.className = 'swmap-popup__text';
    p.textContent = properties.description;
    root.append(p);
  }
  if (properties.address) {
    const a = doc.createElement('p');
    a.className = 'swmap-popup__address';
    a.textContent = properties.address;
    root.append(a);
  }
  const href = safeLink(properties.link);
  if (href !== null) {
    const link = doc.createElement('a');
    link.className = 'swmap-popup__link';
    link.href = href;
    link.rel = 'noopener noreferrer';
    if (/^https?:/i.test(href)) link.target = '_blank';
    link.textContent = strings.openLink;
    root.append(link);
  }

  return root;
}

function safeImageUrl(url: string | null | undefined): string | null {
  return typeof url === 'string' && /^https?:\/\/[^\s"<>\\]+$/i.test(url) ? url : null;
}
