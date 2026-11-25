/** Configuration object printed by PHP (packages/wordpress/src/Admin/AdminPage.php). */
export interface SwmapConfig {
  restRoot: string;
  nonce: string;
  locale: string;
  version: string;
  adminUrl: string;
  siteUrl: string;
  capabilities: string[];
}

declare global {
  interface Window {
    swmapsConfig?: Partial<SwmapConfig>;
    wp?: {
      media?: (options: Record<string, unknown>) => WpMediaFrame;
    };
  }
}

export interface WpMediaFrame {
  on(event: 'select', callback: () => void): void;
  open(): void;
  state(): { get(name: 'selection'): { first(): { toJSON(): WpAttachment } } };
}

export interface WpAttachment {
  id: number;
  url: string;
  mime?: string;
  sizes?: Record<string, { url: string }>;
}
