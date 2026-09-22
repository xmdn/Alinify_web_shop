#!/usr/bin/env python3
"""Generate the demo placeholder images used by the seeded products.

Pure standard library (no Pillow): a minimal PNG encoder plus a few flat,
branded-looking tiles. They are deliberately abstract — no photography is taken
from any other site.

    python scripts/make_placeholders.py
    python scripts/make_placeholders.py --count 8 --size 900

Output: assets/placeholders/placeholder-1.png … (imported into the media library
by scripts/setup.ps1 and attached to the demo products).
"""

from __future__ import annotations

import argparse
import struct
import sys
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# (background, diagonal band, accent square) — a muted palette that suits a
# beauty/barber supply catalogue without imitating anyone's brand colours.
PALETTES: list[tuple[tuple[int, int, int], tuple[int, int, int], tuple[int, int, int]]] = [
    ((238, 240, 244), (222, 226, 233), (200, 16, 46)),
    ((247, 243, 238), (236, 229, 219), (22, 24, 29)),
    ((240, 242, 245), (224, 229, 238), (32, 78, 138)),
    ((244, 240, 245), (231, 224, 235), (108, 58, 138)),
    ((239, 244, 241), (223, 234, 228), (26, 108, 82)),
    ((245, 242, 236), (233, 227, 214), (176, 122, 32)),
    ((242, 242, 244), (228, 228, 234), (60, 66, 78)),
    ((245, 238, 240), (234, 220, 226), (162, 13, 37)),
]


def png_bytes(width: int, height: int, rows: list[bytearray]) -> bytes:
    """Encode 8-bit truecolour rows as a PNG."""

    def chunk(tag: bytes, payload: bytes) -> bytes:
        crc = zlib.crc32(tag + payload) & 0xFFFFFFFF
        return (
            struct.pack(">I", len(payload))
            + tag
            + payload
            + struct.pack(">I", crc)
        )

    raw = b"".join(b"\x00" + bytes(row) for row in rows)
    header = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", header)
        + chunk(b"IDAT", zlib.compress(raw, 9))
        + chunk(b"IEND", b"")
    )


def render(size: int, palette: tuple[tuple[int, int, int], ...]) -> bytes:
    background, band, accent = palette
    rows: list[bytearray] = []

    # Margins for the centred "product" plate.
    plate_from = int(size * 0.22)
    plate_to = int(size * 0.78)
    band_width = max(6, size // 60)

    for y in range(size):
        row = bytearray()
        for x in range(size):
            colour = background
            # Diagonal stripes across the whole tile.
            if (x + y) % (size // 9) < band_width:
                colour = band
            # The plate: a soft square standing in for a product photo.
            if plate_from <= x <= plate_to and plate_from <= y <= plate_to:
                colour = band if (x < plate_from + 6 or x > plate_to - 6 or y < plate_from + 6 or y > plate_to - 6) else background
            # An accent stripe near the bottom, where a caption would sit.
            if int(size * 0.86) <= y <= int(size * 0.89) and plate_from <= x <= plate_to:
                colour = accent
            row += bytes(colour)
        rows.append(row)

    return png_bytes(size, size, rows)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--count", type=int, default=len(PALETTES), help="how many tiles")
    parser.add_argument("--size", type=int, default=900, help="square edge in pixels")
    parser.add_argument("--out", default=str(ROOT / "assets" / "placeholders"))
    args = parser.parse_args(argv)

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)

    for index in range(1, max(1, args.count) + 1):
        palette = PALETTES[(index - 1) % len(PALETTES)]
        target = out_dir / f"placeholder-{index}.png"
        target.write_bytes(render(args.size, palette))
        print(f"[placeholders] {target} ({target.stat().st_size} bytes)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
