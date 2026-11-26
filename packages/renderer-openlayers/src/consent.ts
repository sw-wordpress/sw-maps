import type { Unsubscribe } from '@sw-maps/map-contracts';

type ConsentWindow = Window & { wp_has_consent?: (category: string) => boolean };

const EVENTS = ['wp_listen_for_consent_change', 'wp_consent_type_defined'];

/**
 * WP Consent API check. `null` means the map is not gated. When a category is required but the Consent API script is
 * not available, access is denied (fail closed): no request to external tile servers without a consent decision.
 */
export function hasConsent(category: string | null, win: Window = window): boolean {
  if (category === null) return true;
  const fn = (win as ConsentWindow).wp_has_consent;
  if (typeof fn !== 'function') return false;
  try {
    return fn(category) === true;
  } catch {
    return false;
  }
}

export function onConsentChange(callback: () => void, doc: Document = document): Unsubscribe {
  for (const name of EVENTS) doc.addEventListener(name, callback);
  return () => {
    for (const name of EVENTS) doc.removeEventListener(name, callback);
  };
}
