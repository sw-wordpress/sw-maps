# ADR 0002: Ein Renderer bis 1.0 (OpenLayers)

Status: angenommen
Entscheidung: OpenLayers ist der einzige Renderer (Viewer und Editor). Der Vertrag (`packages/map-contracts`: mount, update, setView, getView, fitBounds, on, destroy) bleibt minimal und wird erst mit einem zweiten Renderer erweitert.
Gründe: ein Bundle, ein Verhalten, geringere Testfläche; Provider (Tiles) sind vom Renderer getrennt. Google Maps wäre nach 1.0 ein getrennter Adapter (BACKLOG).
Folgen: Admin und Frontend teilen nur Typen; getrennte Vite-Builds inkl. CSS; Frontend-Bundle ohne Vue/Quasar.
