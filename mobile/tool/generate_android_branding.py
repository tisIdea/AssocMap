"""Regenerate mobile branding from the repository's original AssocMap artwork."""

from pathlib import Path

from PIL import Image


def main():
    mobile = Path(__file__).resolve().parents[1]
    source = mobile.parent / "web/public/images/brand/assocmap-logo.png"
    # Keep the original map symbol; omit the wordmark at launcher-icon sizes.
    symbol = Image.open(source).convert("RGB").crop((96, 32, 978, 898))
    canvas = Image.new("RGB", (1024, 1024), "white")
    canvas.paste(symbol, ((1024 - symbol.width) // 2, (1024 - symbol.height) // 2))
    brand = mobile / "assets/brand"
    brand.mkdir(parents=True, exist_ok=True)
    canvas.save(brand / "assocmap-symbol.png", optimize=True)

    resources = mobile / "android/app/src/main/res"
    for density, size in [("mdpi", 48), ("hdpi", 72), ("xhdpi", 96),
                          ("xxhdpi", 144), ("xxxhdpi", 192)]:
        folder = resources / f"mipmap-{density}"
        folder.mkdir(parents=True, exist_ok=True)
        icon = folder / "ic_launcher.png"
        canvas.resize((size, size), Image.Resampling.LANCZOS).save(icon)
        (folder / "ic_launcher_round.png").write_bytes(icon.read_bytes())
        foreground = Image.new("RGBA", (size * 108 // 48,) * 2, (0, 0, 0, 0))
        artwork = symbol.copy()
        artwork.thumbnail((size * 66 // 48,) * 2, Image.Resampling.LANCZOS)
        foreground.paste(artwork, ((foreground.width - artwork.width) // 2,
                                 (foreground.height - artwork.height) // 2))
        foreground.save(folder / "ic_launcher_foreground.png", optimize=True)

    adaptive = resources / "mipmap-anydpi-v26"
    adaptive.mkdir(exist_ok=True)
    xml = '''<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background" />
    <foreground android:drawable="@mipmap/ic_launcher_foreground" />
</adaptive-icon>
'''
    for name in ["ic_launcher", "ic_launcher_round"]:
        (adaptive / f"{name}.xml").write_text(xml, encoding="utf-8")
    (resources / "values/colors.xml").write_text('''<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">#FFFFFF</color>
</resources>
''', encoding="utf-8")


if __name__ == "__main__":
    main()
