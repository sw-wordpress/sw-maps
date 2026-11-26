import type { ViewerStrings } from '@sw-maps/map-contracts';

/** Viewer strings for the editor canvas (the public viewer receives server translated strings). */
export function viewerStrings(t: (key: string) => string): ViewerStrings {
  return {
    zoomIn: t('viewer.zoomIn'),
    zoomOut: t('viewer.zoomOut'),
    fullscreen: t('viewer.fullscreen'),
    exitFullscreen: t('viewer.exitFullscreen'),
    loadMap: t('viewer.loadMap'),
    consentRequired: t('viewer.consentRequired'),
    clickToLoad: t('viewer.clickToLoad'),
    error: t('viewer.error'),
    attributionLabel: t('viewer.attributionLabel'),
    openLink: t('viewer.openLink'),
    closePopup: t('viewer.closePopup'),
    search: t('viewer.search'),
    filterByCategory: t('viewer.filterByCategory'),
    noResults: t('viewer.noResults'),
  };
}
