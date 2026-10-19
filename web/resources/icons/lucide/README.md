# Lucide interface icons

The SVG geometry in `../../ui/icons.php` comes from the official
[lucide-icons/lucide](https://github.com/lucide-icons/lucide) repository at commit
[`1ef60af944de207e0d5176905a0759ba12e6ee8d`](https://github.com/lucide-icons/lucide/tree/1ef60af944de207e0d5176905a0759ba12e6ee8d/icons).
The upstream ISC and Feather-derived MIT notices are retained in `LICENSE`.

This is a verified subset of 42 icons, not a package installation. The Blade
`x-icon` component renders only the requested SVG inline. There is no runtime
download, CDN, icon font, JavaScript initialization, or full-library bundle.
Existing application aliases remain supported. In this upstream revision,
`building-complex` and `trash` replace the example names `building-2` and `trash-2`.

To add an icon, copy its SVG child elements from the pinned official revision,
verify its name and license, and add it to the registry. Retain the 24 by 24
viewBox, currentColor, stroke width 2, and round joins/caps. Never insert SVG
provided by a user. The icon tests validate the registry and existing call sites.

Navigation and action labels provide the accessible names; SVGs are decorative
and nonfocusable. The original AssocMap brand mark lives separately in
`../../views/components/assocmap-mark.blade.php` and is not a Lucide asset. Leaflet,
Geoman controls, GIS markers, and public map artwork retain their existing design.
