import type { MapRenderData, PublicFeature, ViewerStrings } from '@sw-maps/map-contracts';

export const strings: ViewerStrings = {
  zoomIn: 'Zoom in',
  zoomOut: 'Zoom out',
  fullscreen: 'Fullscreen',
  exitFullscreen: 'Exit fullscreen',
  loadMap: 'Load map',
  consentRequired: 'Consent required',
  clickToLoad: 'Click to load',
  error: 'Map error',
  attributionLabel: 'Map attribution',
  openLink: 'Open link',
  closePopup: 'Close',
};

export function feature(id: number, lon = 8.4, lat = 49, title = `Place ${id}`): PublicFeature {
  return {
    id,
    geometry: { type: 'Point', coordinates: [lon, lat] },
    properties: {
      title,
      description: 'Text',
      address: 'Street 1',
      image_id: null,
      link: 'https://example.org',
      icon: 'builtin:pin',
    },
    style: {},
  };
}

export function makeData(overrides: Partial<MapRenderData> = {}): MapRenderData {
  return {
    id: 1,
    title: 'Test',
    viewport: {
      center: [8.4, 49],
      zoom: 10,
      minZoom: 2,
      maxZoom: 18,
      bounds: null,
      autoBounds: false,
    },
    settings: {
      height: 400,
      aspectRatio: null,
      scrollZoom: false,
      touch: true,
      drag: true,
      zoomControls: true,
      fullscreen: true,
      scale: true,
      showList: true,
      clickToLoad: false,
      providerId: 1,
    },
    provider: {
      type: 'xyz',
      urlTemplate: 'https://tiles.example.org/{z}/{x}/{y}.png',
      attribution: '© [Tiles](https://tiles.example.org/copyright) contributors',
      minZoom: 0,
      maxZoom: 19,
      tileSize: 256,
    },
    layers: [{ id: 1, title: 'Layer', style: {}, features: [feature(1), feature(2, 8.5, 49.1)] }],
    consentCategory: null,
    strings,
    ...overrides,
  };
}
