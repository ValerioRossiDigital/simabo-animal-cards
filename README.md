# Simabo · Animal Cards

Printable one-page (A4) cards for the Simabo shelter animals
(https://simabo.org/our-animals/). Replaces the old Google Sheet template.

The team opens a page on the Simabo site, picks an animal, and prints it to PDF.
Data is read **live** from WordPress — no installation, no dependencies,
nothing to maintain on the team's computers.

## Architecture

Everything is served from one small WordPress snippet (`wp-animal-cards.php`).

```
Browser  ──▶  https://simabo.org/animal-cards   (pick an animal → print A4 PDF)
                       │
              wp-animal-cards.php  ──reads──▶  animal CPT (ACF + taxonomies)
                       │
              also exposes (backup):  /wp-json/simabo/v1/animals  (JSON)
```

- **wp-animal-cards.php** — the deliverable. A single snippet installed once on
  WordPress that (1) serves the print page at `/animal-cards`, reading the
  animal data server-side (no API call, no CORS), and (2) also exposes an
  optional REST endpoint. It changes nothing on the public site.
- **index.html** — a standalone copy of the same card that runs on GitHub Pages
  and fetches the REST endpoint. Kept as a backup / offline preview; not needed
  once the WordPress page is live.

## For the Simabo team

Open **https://simabo.org/animal-cards**, choose an animal from the dropdown,
click **Print / Save PDF** → "Save as PDF", A4. In the print dialog set
**Margins: None** and enable **Background graphics** so the teal/green areas
print. Data is always current — new animals and photos appear automatically.

## One-time setup (developer)

1. **Install** `wp-animal-cards.php` via the *Code Snippets* plugin
   (Snippets → Add New → *Run everywhere* → Save & Activate), or append it to
   the child theme's `functions.php`. Remove any previous Simabo cards snippet
   first.
2. Open **https://simabo.org/animal-cards**. If it 404s, go to
   **Settings → Permalinks** and click **Save** once (registers the pretty URL).
3. Field mapping for the `animal` CPT: `species` and `status` are taxonomies;
   `sex`, `size`, birth (ACF field `age`) and bio (ACF field `story`) are custom
   fields. If a field is ever renamed, adjust the alias lists in
   `wp-animal-cards.php` (function `simabo_pick(...)`).

## Notes

- The tool lists **all** published animals (including adopted and past ones).
  To limit it, add a `tax_query` on the `status` taxonomy in
  `simabo_all_animals()`.
- Empty fields (e.g. missing birth date or status) are hidden automatically.
- **Photo framing**: defaults to `object-fit: cover` (fills the frame, may crop
  edges). For the whole photo with white borders, change that line to
  `object-fit: contain`.
- If a caching plugin serves stale data, purge the cache for `/animal-cards`.

## Files

| File | Purpose |
|------|---------|
| `wp-animal-cards.php` | The WordPress snippet — print page + REST endpoint |
| `index.html` | Standalone GitHub Pages copy (backup / preview) |
