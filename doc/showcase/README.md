# KreaProducts screenshot frames

These export frames present current KreaProducts interface captures without altering the interface. They are documentation assets, not application pages. The design uses the existing module hand mark, the stock PWA's teal (`#087f72`) and pale background, system fonts and a large flat application image.

## Reproduce

1. Use current native Dolibarr/KreaProducts pages with approved synthetic or anonymized demo content. Preserve the application's real controls and geometry. Never include real customer, supplier, user, stock or confidential price data.
2. Save the captures beside `index.html` as `capture-workspace.jpg` and `capture-labels.jpg`. A capture around **1440 × 760 px** fits the frame well. Crop to the relevant application workspace before using it; the frame itself never crops or distorts a capture.
3. Serve the repository root locally so that the existing module icon resolves. No dependency installation or build is required.
4. Open `doc/showcase/index.html?view=workspace` and `doc/showcase/index.html?view=labels` in a browser at **1600 × 1160 px**, device scale 1, and browser zoom 100%.
5. Wait for both the capture and icon to load. Capture the visible page at that exact size. Export the results to the image paths used by the root README.
6. Inspect each exported image at full size and at the README's rendered width. Confirm readable text, complete image bounds, correct aspect ratio and no private data, browser errors, focus rings or floating menus.

Both frames disclose that their values are illustrative demo data. Keep the real interface content readable; do not replace it with an invented interface or reduce it to a decorative thumbnail. Screenshot refreshes do not independently change the module version.
