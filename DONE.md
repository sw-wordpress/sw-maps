
- [x] M3-09: Accessibility-Tests: axe über Admin-Routen und Frontend-Karte inkl. Standortliste (hell/dunkel), Tastaturbedienung
  - Ergebnis: `e2e/tests/a11y.spec.ts` (axe-core, WCAG 2 A/AA): alle Admin-Routen (Dashboard, Karten, Anbieter, Einstellungen, Status), der Karteneditor, die Frontend-Karte mit Liste, Legende und Suche sowie ein geöffnetes Popup, jeweils hell und dunkel, ohne Verstöße; Tastaturbedienung der Navigation, der Legende, Suche, Liste und des Popups. Dabei behoben: Kontrast des Sprunglinks im Dunkelmodus, Kontrast der Zusatzzeilen, ARIA-Rolle der Standortlisten im Editor.
