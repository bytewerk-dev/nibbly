#!/usr/bin/env python3
"""cli/media-originals.php: originals that older versions left in the root of images-originals/
move to the folder of their web version; unclear cases are only reported."""
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def run(images, *args):
    result = subprocess.run(["php", "cli/media-originals.php", f"--images={images}", *args],
                            cwd=ROOT, text=True, capture_output=True)
    return result.returncode, result.stdout + result.stderr


with tempfile.TemporaryDirectory(prefix="nibbly-originals-") as folder:
    base = Path(folder) / "assets"
    images, originals = base / "images", base / "images-originals"
    trash, originals_trash = base / "images-trash", base / "images-originals-trash"
    files = {
        images / "root.webp": b"w", originals / "root.png": b"o",                                    # in place
        images / "2026/live/stage.webp": b"w", originals / "stage.jpg": b"o",                        # misplaced
        images / "a/twin.webp": b"w", images / "b/twin.webp": b"w", originals / "twin.png": b"o",     # ambiguous
        originals / "lost.jpg": b"o",                                                                # orphan
        images / "c/taken.webp": b"w", originals / "taken.png": b"new", originals / "c/taken.png": b"old",  # conflict
        trash / "x/gone.webp": b"w", originals_trash / "gone.png": b"o",                             # trash
    }
    for path, data in files.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)

    code, out = run(images, "--action=status")
    assert code == 0 and "1 in place" in out and "2 to move" in out and "1 orphaned" in out and "1 ambiguous" in out and "1 conflicts" in out, out
    assert (originals / "stage.jpg").is_file(), "status must not change anything"

    code, out = run(images, "--action=repair", "--dry-run")
    assert code == 0 and "would move images-originals/stage.jpg -> images-originals/2026/live/stage.jpg" in out, out
    assert (originals / "stage.jpg").is_file()

    code, out = run(images, "--action=repair")
    assert code == 0 and "Repair: 2 moved" in out, out
    assert (originals / "2026/live/stage.jpg").read_bytes() == b"o" and not (originals / "stage.jpg").exists()
    assert (originals_trash / "x/gone.png").is_file() and not (originals_trash / "gone.png").exists()
    assert (originals / "root.png").is_file() and (originals / "twin.png").is_file() and (originals / "lost.jpg").is_file()
    assert (originals / "c/taken.png").read_bytes() == b"old" and (originals / "taken.png").read_bytes() == b"new", "nothing overwritten"

    code, out = run(images, "--action=repair")
    assert code == 0 and "Repair: 0 moved" in out, out
    code, out = run(images, "--action=wipe")
    assert code == 2, out
    print("PASS media originals repair: misplaced originals move to their folder (library and trash), "
          "status and dry run change nothing, ambiguous, orphaned and conflicting files stay")
