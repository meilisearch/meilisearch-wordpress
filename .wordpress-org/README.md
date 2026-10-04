# wordpress.org assets

Files in this directory are published to the plugin's SVN `assets/` directory by the release workflow
(10up/action-wordpress-plugin-deploy, `ASSETS_DIR: .wordpress-org`). They are not part of the plugin zip.
The images are supplied by the design team; they are the only release input not produced from this repository.

| File | Size (px) | Use |
|---|---|---|
| `banner-772x250.png` | 772 × 250 | Plugin page header |
| `banner-1544x500.png` | 1544 × 500 | Plugin page header (high-DPI) |
| `icon-128x128.png` | 128 × 128 | Search results and plugin cards |
| `icon-256x256.png` | 256 × 256 | Same, high-DPI |
| `screenshot-1.png` | ≥ 1200 wide | Connection tab: Meilisearch URL, admin key and connection status |
| `screenshot-2.png` | ≥ 1200 wide | Content tab: post types, taxonomies and custom fields |
| `screenshot-3.png` | ≥ 1200 wide | Status tab: document counts, reindex progress and recent errors |
| `screenshot-4.png` | ≥ 1200 wide | Theme search results served by Meilisearch (typo query) |
| `screenshot-5.png` | ≥ 1200 wide | Autocomplete dropdown on a search form |
| `screenshot-6.png` | ≥ 1200 wide | WooCommerce product search sorted by price |

Rules:

- PNG or JPG, sRGB, no transparency in banners, file names exactly as above (lowercase).
- Screenshot numbers and captions must match `== Screenshots ==` in `readme.txt`.
- Take screenshots on the E2E site (`bin/e2e-setup.sh`) with a default theme; no personal data or real keys on screen.
- No other Meilisearch-unrelated trademarks in the images.
