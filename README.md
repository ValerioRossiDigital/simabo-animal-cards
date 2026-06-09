# Simabo · Animal Cards

Printable one-page (A4) cards for the Simabo shelter animals
(https://simabo.org/our-animals/). Replaces the old Google Sheet template.

Open the page, filter by status, pick an animal, print to PDF. Data is read
**live** from WordPress — nothing to install or maintain on the team's
computers.

## Where it lives (two identical surfaces)

| Surface | URL | Data | Updates |
|---------|-----|------|---------|
| **WordPress page** (primary) | `https://simabo.org/animal-cards/` | inline, server-side | re-paste snippet |
| **GitHub Pages** (backup) | `https://valeriorossidigital.github.io/simabo-animal-cards/` | fetched from REST | automatic on push |

Both depend on the WordPress snippet for data. Give the **team** the
`simabo.org/animal-cards/` link.

## Architecture

```
WordPress page  /animal-cards         GitHub Pages  index.html
        │  (data inlined)                   │  (fetch)
        └──────────► wp-animal-cards.php ◄───┘
                            │  reads server-side
                     animal CPT — ACF + taxonomies
                            │
              also exposes: /wp-json/simabo/v1/animals (JSON)
```

- **wp-animal-cards.php** — the deliverable, installed once on WordPress. It
  (1) serves the print page at `/animal-cards` reading the data server-side
  (no API call, no CORS), and (2) exposes a read-only REST endpoint used by the
  GitHub Pages copy. It changes nothing on the public site.
- **index.html** — standalone copy of the same card for GitHub Pages; fetches
  the REST endpoint. Kept in sync with the snippet's template.

## The card

A4 portrait, one animal per page. Teal frame, white logo, gold accents.
Brand fonts: **Lato** (name) + **Roboto** (body). Shows photo (`contain`),
species pill, name, a meta grid (Sex · Born · Size · Status), the bio, and a
teal "Sponsor {name}" footer with the animal's page URL.

- The toolbar has a **status filter** (e.g. *Ready for EU*, *Adopted abroad*,
  *(no status)*) that scopes the animal dropdown. Animals with several status
  terms appear under each.
- JS **auto-fit** shrinks the bio text so every card stays on **one page**.

## One-time setup / updating (developer)

1. **Install** `wp-animal-cards.php` via the *Code Snippets* plugin
   (Snippets → Add New → *Run everywhere* → Save & Activate), or append it to
   the child theme's `functions.php`. Keep **only one** copy active (the
   functions would otherwise be redeclared → fatal error).
2. Open `https://simabo.org/animal-cards/` (with the trailing slash). If it
   404s, go to **Settings → Permalinks** and click **Save** once.
3. **To deploy a change**: replace the snippet's content with the latest from
   `https://raw.githubusercontent.com/ValerioRossiDigital/simabo-animal-cards/main/wp-animal-cards.php`,
   then hard-refresh (`Cmd+Shift+R`). GitHub Pages updates automatically.

## Data mapping (Simabo `animal` CPT)

- `species` and `status` are **taxonomies**.
- `sex`, `size`, birth (ACF field **`age`**) and bio (ACF field **`story`**)
  are custom fields. Adjust the alias lists in `simabo_pick(...)` if renamed.
- The site is multilingual (**WPML**); `simabo_all_animals()` keeps only the
  **English** version of each animal.
- Birth is reduced to the **year**; bios are stripped of stray URLs (e.g. video
  links); HTML entities are decoded.

## Notes

- Empty fields are hidden automatically.
- Print dialog: **Margins: None** + **Background graphics** ON.
- Photo framing is `object-fit: contain`; switch to `cover` to fill (may crop).
- The site uses **LiteSpeed** cache — if data looks stale, purge it for
  `/animal-cards/`.

## Files

| File | Purpose |
|------|---------|
| `wp-animal-cards.php` | WordPress snippet — print page + REST endpoint |
| `index.html` | GitHub Pages copy (fetches the endpoint) |
