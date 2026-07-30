# formemockup
mymockup

## Allegro export

The WordPress admin contains an **Export & Feedek → Allegro Export** tab. It
creates a UTF-8, semicolon-separated CSV that can be imported into the
companion `allegro-sync` application.

- Every product type + colour + size combination receives a deterministic SKU.
- The original Woo colour is exported as the manufacturer colour, while the
  Allegro core colour comes from Allegro's accepted clothing dictionary.
- Standard sizes are normalized (`2XL` → `XXL`, `XXXL` → `3XL`). Unknown and
  child age-range sizes must be mapped explicitly in the admin page.
- Woo stock is used when stock management is enabled; otherwise the saved
  template stock is used.
- Missing SKU, description, image, price, colour mapping or size mapping stops
  the download and produces an actionable validation list.

The first mapping screen is organized into three Allegro profiles: child,
men's and women's T-shirts. Each Allegro category is assigned to one virtual
product type, then that type's own colours and sizes are mapped from dropdowns
containing the exact Allegro dictionary values. Collared shirts are not part of
the initial profiles.
