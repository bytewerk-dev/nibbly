#!/usr/bin/env python3
"""Publish a customer site, back up the serving installation, recover to a fresh target."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile
from support import Site, Client, write_json


def put(root, path, data):
    file = root / path
    file.parent.mkdir(parents=True, exist_ok=True)
    file.write_bytes(data)


def package(site, *args, ok=True):
    result = subprocess.run(["php", "cli/package.php", *map(str, args)], cwd=site.root,
                            capture_output=True, text=True)
    assert (result.returncode == 0) == ok, result.stderr
    return result.stdout + result.stderr


with tempfile.TemporaryDirectory(prefix="nibbly-publication-") as tmp:
    base = Path(tmp)
    for name in ["source", "live", "recovery", "private"]:
        (base / name).mkdir()
    source, live, recovery = [Site(base / name) for name in ("source", "live", "recovery")]
    rules = {"paths": {"downloads": "publish", "customer": "publish", "models": "local",
                       "customer-data": "server", "assets/design-source": "local"}, "restorePhp": ["customer/start.php"]}
    module = b'<?php echo "Customer module";'
    put(source.root, "downloads/prices.csv", b"sku,price\nA,1")
    put(source.root, "customer/start.php", module)
    put(source.root, "assets/images-originals/photo.jpg", b"original image")
    put(source.root, "assets/images-originals-trash/old.jpg", b"discarded image")
    (source.root / "downloads/empty").mkdir()
    put(source.root, "llms.txt", b"internal agent references")
    put(source.root, "local-only/work.md", b"draft")
    put(source.root, "not-for-git/draft.php", b"internal")
    put(source.root, "assets/design-source/proof.psd", b"working file")
    put(source.root, "models/cache.bin", b"local model")
    put(source.root, "deploy.sh", b"private credentials")
    put(source.root, "content/ai-secrets.json", b'{"secret":"development"}')
    result = package(source, "build", base / "blocked", ok=False)
    assert "customer" in result and not (base / "blocked").exists()
    write_json(source.root / "content/project-files.json", rules)
    (source.root / "downloads/link").symlink_to(base / "private")
    assert "downloads/link" in package(source, "preview", ok=False)
    (source.root / "downloads/link").unlink()
    package(source, "preview")
    bundle = base / "package"
    package(source, "build", bundle)
    package(source, "build", bundle, ok=False)
    for tree in (bundle / "webroot", bundle / "seed"):
        for name in ("AGENTS.md", "llms.txt", "examples", "models", "local-only", "not-for-git", "admin/config.php",
                     "content/ai-secrets.json", "content/settings.json", "content/users.json", "deploy.sh", "assets/design-source"):
            assert not (tree / name).exists(), name
    assert (bundle / "webroot/customer/start.php").read_bytes() == module
    assert (bundle / "webroot/downloads/empty").is_dir()
    assert (bundle / "seed/assets/images-originals/photo.jpg").is_file()
    for tree in (bundle / "seed", bundle / "webroot"):
        assert not (tree / "assets/images-originals-trash/old.jpg").exists()
    assert (bundle / "seed/content/pages/en_home.json").is_file()
    assert not (bundle / "webroot/content/pages/en_home.json").exists()

    put(live.root, "assets/documents/live-only.pdf", b"server upload")
    put(live.root, "customer-data/order.csv", b"live order")
    page = live.root / "content/pages/en_home.json"
    write_json(page, {"title": "Live revision", "sections": []})
    # Simulate a manual upload using the documented two package destinations.
    shutil.copytree(bundle / "webroot", live.root, dirs_exist_ok=True)
    for file in (bundle / "seed").rglob("*"):
        target = live.root / file.relative_to(bundle / "seed")
        if file.is_dir():
            target.mkdir(exist_ok=True, parents=True)
        elif not target.exists():
            shutil.copyfile(file, target)
    assert json.loads(page.read_text())["title"] == "Live revision"
    # An existing installation may still contain old artifacts and unclassified live files.
    put(live.root, "local-only/report.csv", b"draft")
    put(live.root, "not-for-git/old.md", b"draft")
    put(live.root, ".env", b"host-secret")
    put(live.root, "deploy.sh", b"local credentials")
    put(live.root, "misc/extra.dat", b"customer binary")
    put(live.root, "misc/readme.txt", b"unknown but live")
    put(live.root, "vendor/library.txt", b"runtime dependency")
    (live.root / "misc/linked").symlink_to(base / "private")
    with (live.root / "admin/config.php").open("a") as config:
        config.write("\ndefine('NIBBLY_PRIVATE_DIR', dirname(__DIR__, 2) . '/private');\n")
    subprocess.run(["php", "-r", """require 'admin/config.php'; require 'includes/ai/settings-store.php';
        if (!nibblyAiWriteStoredSettings(['provider' => 'openrouter', 'apiKey' => 'fixture-token'])) exit(1);
        if (nibblyAiReadStoredSettings()['providerCredentials']['openrouter']['apiKey'] !== 'fixture-token') exit(1);"""],
                   cwd=live.root, check=True)
    private = base / "private/ai-secrets.json"
    assert private.is_file() and not (live.root / "content/ai-secrets.json").exists()
    # A leftover key file after opt-in is outside both transfer sets.
    put(live.root, "content/ai-secrets.json", b'{"legacy":"fixture"}')
    web_link = live.root / "private-link"
    web_link.symlink_to(base / "private")
    try:
        subprocess.run(["php", "-r", """define('NIBBLY_PRIVATE_DIR', $argv[1]); require 'includes/private-files.php';
            try { nibblyPrivateDirectory(); } catch (RuntimeException $e) { exit(0); } exit(1);""", str(web_link)],
            cwd=live.root, check=True)
    finally:
        web_link.unlink()
    # A missing private directory on a recovery host leaves keys unavailable and does not
    # recreate them inside the webroot or silently create a less restricted private directory.
    subprocess.run(["php", "-r", """define('NIBBLY_PRIVATE_DIR', dirname(getcwd()) . '/missing/private');
        require 'includes/ai/settings-store.php';
        if (nibblyAiReadStoredSettings() || nibblyAiWriteSecrets(['test' => 'key'])) exit(1);"""],
                   cwd=recovery.root, check=True)
    assert not (base / "missing").exists()
    try:
        live.start()
        recovery.start()
        result = Client(live).login().api("create-site-backup")[1]
        assert result["success"], result
        response = result["data"]
        assert any("misc/extra.dat" in w for w in response["warnings"])
        assert any("ai-secrets.json" in w for w in response["warnings"])
        name = response["filename"]
        archive = live.root / "backups" / name
        with zipfile.ZipFile(archive) as zipfile_obj:
            entries = zipfile_obj.namelist()
            assert {"downloads/prices.csv", "customer/start.php", "misc/extra.dat", "misc/readme.txt",
                    "vendor/library.txt", "customer-data/order.csv", "assets/documents/live-only.pdf"} <= set(entries)
            assert not any(p.startswith(("local-only/", "not-for-git/", "misc/linked")) for p in entries)
            assert "deploy.sh" not in entries and "content/ai-secrets.json" not in entries and ".env" not in entries
            notes = json.loads(zipfile_obj.read(".nibbly-backup.json"))
            assert set(notes["privateFiles"]) == {"ai-secrets.json", ".env"}
        shutil.copy(archive, recovery.root / "backups" / name)
        admin = Client(recovery).login()
        result = admin.api("restore-site-backup", pool_file=name, restore_mode="full")[1]
        assert not result["success"] and "customer/start.php" in result["message"], result
        assert not (recovery.root / "content/project-files.json").exists()
        write_json(recovery.root / "content/project-files.json", dict(rules, privateFiles=["operator-key"]))
        result = admin.api("restore-site-backup", pool_file=name, restore_mode="full")[1]
        assert result["success"] and any("ai-secrets.json" in w for w in result["data"]["warnings"]), result
        assert json.loads((recovery.root / "content/project-files.json").read_text())["privateFiles"] == ["operator-key"]
        assert not (recovery.root / "deploy.sh").exists()
        assert not (recovery.root / "local-only").exists() and not (recovery.root / ".nibbly-backup.json").exists()
        assert (recovery.root / "downloads/empty").is_dir()
        for path in ["downloads/prices.csv", "assets/documents/live-only.pdf", "customer-data/order.csv", "misc/readme.txt"]:
            assert (recovery.root / path).read_bytes() == (live.root / path).read_bytes()
        assert Client(recovery).request("/customer/start.php")[2] == b"Customer module"

        # Older ZIPs lack recovery metadata and may carry once-excluded work files.
        legacy_name = name.replace("-manual.zip", "-daily.zip")
        with zipfile.ZipFile(archive) as original, zipfile.ZipFile(recovery.root / "backups" / legacy_name, "w") as legacy:
            for entry in original.infolist():
                if entry.filename not in (".nibbly-backup.json", "content/project-files.json"):
                    legacy.writestr(entry, original.read(entry))
            legacy.writestr("not-for-git/notes.md", "internal")
            legacy.writestr("examples/demo.php", "<?php // old reference template")
        legacy_admin = Client(recovery).login(password=live.password)
        result = legacy_admin.api("restore-site-backup", pool_file=legacy_name, restore_mode="full")[1]
        assert result["success"] and not (recovery.root / "not-for-git").exists(), result
        assert not (recovery.root / "examples/demo.php").exists()
    finally:
        recovery.close()
        live.close()
print("PASS publication, runtime ownership, trusted restore, custom files and private recovery notes")
