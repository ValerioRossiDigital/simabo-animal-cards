# Simabo · Animal Cards

Printable one-page (A4) cards for the animals in the Simabo gallery
(https://simabo.org/our-animals/). Replaces the old Google Sheet template.

The page reads animal data **live** from the Simabo website — pick an animal,
click print. No installation, no dependencies, nothing to maintain on the
team's computers: they just open the URL.

## Architecture

```
GitHub Pages (index.html)  --fetch-->  simabo.org/wp-json/simabo/v1/animals
        |                                         ^
   pick + print (A4 PDF)                  wp-endpoint.php (one-time install)
```

- **index.html** — the whole tool. A static page hosted on GitHub Pages that
  fetches the animals live from the WordPress REST API and renders the card.
- **wp-endpoint.php** — a small read-only REST endpoint added once to the
  WordPress site, exposing each animal's name, photo, species, size, sex,
  birth, status and bio as clean JSON (the browser cannot read these from the
  rendered pages directly because of CORS). It changes nothing on the public
  site.

## For the Simabo team

Open the page, choose an animal from the dropdown, click **Print / Save PDF** ->
"Save as PDF", A4. In the print dialog set **Margins: None** and enable
**Background graphics** so the teal/green areas print. Data is always current —
new animals and photos appear automatically.

## One-time setup (developer)

1. **Install the endpoint** on WordPress: paste `wp-endpoint.php` via the
   *Code Snippets* plugin (Snippets -> Add New -> *Run everywhere* -> Save &
   Activate) or append it to the child theme's `functions.php`.
2. **Verify** it responds:
   - https://simabo.org/wp-json/simabo/v1/animals
   - https://simabo.org/wp-json/simabo/v1/animals?slug=nancy

   Each record carries a temporary `_keys` array (the available custom-field
   names). If `sex` / `birth` / `size` / `status` / `bio` come back empty,
   match the real key from `_keys` into the alias lists in `wp-endpoint.php`
   (function `simabo_pick(...)`), then remove the `_keys` line.
3. The GitHub Pages site (`index.html`) points at the endpoint via the
   `ENDPOINT` constant — no other configuration needed.

## Notes

- Empty fields (e.g. missing birth date or status) are hidden automatically.
- **Photo framing**: defaults to `object-fit: cover` (fills the frame, may crop
  edges). For the whole photo with white borders instead, change that one line
  in `index.html` to `object-fit: contain`.

## Files

| File | Purpose |
|------|---------|
| `index.html` | The card viewer / printer (hosted on GitHub Pages) |
| `wp-endpoint.php` | WordPress REST endpoint, installed once on simabo.org |
