import type { WpAttachment } from '@/types/config';

/** Opens the WordPress media library and resolves with the chosen image (null when cancelled or unavailable). */
export function pickImage(win: Window = window): Promise<WpAttachment | null> {
  const factory = win.wp?.media;
  if (typeof factory !== 'function') return Promise.resolve(null);
  return new Promise((resolve) => {
    const frame = factory({
      library: { type: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] },
      multiple: false,
    });
    frame.on('select', () => resolve(frame.state().get('selection').first().toJSON()));
    frame.open();
  });
}
