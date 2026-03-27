# Optional Web Fonts

These fonts are not loaded by default. To use them:

1. Copy all `.woff2` files to your project's `assets/fonts/` directory:
   ```bash
   cp examples/fonts/*.woff2 assets/fonts/
   ```

2. In `includes/header.php`, uncomment the fonts.css line:
   ```html
   <link rel="stylesheet" href="css/fonts.css">
   ```

3. Update your CSS variables in `css/style.css`:
   ```css
   :root {
       --font-display: 'Quicksand', sans-serif;
       --font-body: 'Inter', sans-serif;
   }
   ```

## Included Fonts

| Font | Usage | License |
|------|-------|---------|
| Quicksand | Display / headings | OFL |
| Inter | Body text | OFL |
| Geist | UI elements | MIT |
| Geist Mono | Code blocks | MIT |
| JetBrains Mono | Code blocks (alternative) | OFL |
