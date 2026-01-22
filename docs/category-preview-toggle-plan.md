# Category Page Mockup/Pattern Toggle Plan

## Goal
Allow shoppers on category/archive pages to switch between the existing mockup thumbnails and a large pattern-focused preview for each product card. Because archive thumbnails are small and lower value, no watermark is required.

## UX behavior
- Add a compact "Nézet" toggle on each product card with two modes: **Mockup** (default) and **Minta**.
- Remember the last chosen mode in `localStorage` and apply it to all cards on page load so browsing stays consistent.
- When switching modes, swap visibility between the current mockup image and the pattern preview container without reflow jumps.

## Data needs
- Archive context must expose default preview data per product:
  - Pattern URL (smaller/lower-res asset is acceptable).
  - Default background RGB color (e.g., from the default variation).
- If data is missing, show a clear placeholder ("Minta nem érhető el ehhez a termékhez").

## Rendering approach
- Prefer a canvas-based renderer (similar to the PDP modal) to paint the pattern on a solid background color.
- Include a short timeout fallback to a simple `<img>` if canvas is slow/unsupported; no watermark needed on archive.
- Keep the preview square via CSS aspect ratio and center the rendered content.

## Interaction and performance
- Debounce any re-rendering if cards lazy-load or if multiple cards initialize simultaneously.
- Disable right-click/drag on the pattern container for consistency, but focus on fast rendering rather than heavy protection.
- Use lightweight assets to avoid slowing the category grid; do not preload full-size patterns.

## Styling highlights
- Place the toggle control near the product title/price block; ensure it is keyboard accessible.
- Active state should be clearly indicated; align colors/spacing with existing theme buttons.
- Pattern container should match mockup tile dimensions and be responsive on mobile.

## Implementation steps
1) Expose archive product pattern/color defaults via a localized script (e.g., `variantDisplayArchiveConfig`).
2) Add a JS module (e.g., `assets/js/archive-view-toggle.js`) to:
   - Initialize toggles, apply stored preference, and bind click events.
   - Render pattern previews onto canvas or fallback image.
3) Add CSS for toggle states and the pattern container in the archive stylesheet.
4) Provide a graceful placeholder when pattern data is absent.

## Edge considerations
- If a product lacks pattern data, keep the toggle but disable the "Minta" option with a tooltip.
- For variable products with multiple looks, default to the primary variation used on archive pages.
- Ensure lazy-loaded images (if used) do not conflict with canvas rendering; render after card becomes visible.
