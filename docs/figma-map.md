# Figma implementation references

File key: 8Lj1mwqXcx9DO8aQ4iRla8.

- Login Manager: 255:175132; 1040×620 workspace, 536 px identity + 24 px gap + 480 px form. Barlow typography, local official artwork.
- Brand/ShopLogo: component 53:4037, exact 224 px login instance 255:175136 exported to public/assets/brand/borgs-logo.png. The instance preserves the transparent cream keyline; the component default export had a white backing and was replaced after screenshot comparison.
- Login visibility icon: 19:68; exact SVG in public/assets/icons/eye.svg.
- Manager sidebar: 106:40241, component 55:6437; 80 px collapsed / 240 px expanded. Local exported navigation SVGs.
- Manager topbar: 106:40879; 64 px high; local toggle and chevron SVGs.
- Dashboard overview: 106:40240; sparse parent context inspected, screenshot requested and child contexts obtained for the shell. Dashboard content is implemented separately in Task 9.
- Fonts: Barlow 400, 500, 600 and ExtraBold Italic 800 from local @fontsource/barlow 5.3.0; no external runtime font calls.

Prototype example names are demonstration data, not authentication credentials. Pages use account-derived role and identity.
