#!/usr/bin/env python3
"""Large image uploads: a web version (WebP, longest side capped) replaces the upload, the
original is kept and travels with rename/trash/restore; small files stay as they are; the
limits reach the library; a file above the PHP limit gets a message naming that limit."""
import json
import subprocess
import tempfile
import uuid

from support import Client, Site, write_json


def multipart(fields, files):
    boundary = uuid.uuid4().hex
    body = b""
    for key, value in fields.items():
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n").encode()
    for key, (name, data, ctype) in files.items():
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"; filename=\"{name}\"\r\n"
                 f"Content-Type: {ctype}\r\n\r\n").encode() + data + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    return body, f"multipart/form-data; boundary={boundary}"


def upload(client, name, data, extra=None):
    fields = {"action": "upload-media", "csrf_token": client.csrf, "type": "image"}
    fields.update(extra or {})
    body, ctype = multipart(fields, {"file": (name, data, "application/octet-stream")})
    status, _, raw = client.request("/admin/api.php", body, "POST", {"Content-Type": ctype})
    return json.loads(raw)


def post(client, action, **fields):
    data = {"action": action, "csrf_token": client.csrf, **fields}
    _, _, raw = client.request("/admin/api.php", data, "POST")
    return json.loads(raw)


def make_png(width, height, noise=False):
    """A PNG from GD: a gradient compresses small, noise makes it big."""
    script = (f"$i=imagecreatetruecolor({width},{height});"
              + ("mt_srand(1);for($y=0;$y<{h};$y++)for($x=0;$x<{w};$x++)imagesetpixel($i,$x,$y,mt_rand(0,0xffffff));".format(w=width, h=height) if noise
                 else f"for($y=0;$y<{height};$y+=8)imagefilledrectangle($i,0,$y,{width},$y+7,imagecolorallocate($i,$y%256,($y*3)%256,128));")
              + "imagepng($i,'php://stdout');")
    return subprocess.check_output(["php", "-r", script])


with tempfile.TemporaryDirectory(prefix="nibbly-web-version-") as folder:
    site = Site(folder)
    write_json(site.root / "content/settings.json", {"modules": {"ai": False}, "privacy": {"analyticsEnabled": False},
                                                     "media": {"maxUploadMb": 20, "webMaxSide": 1200, "webQuality": 80}})
    site.start(ini={"upload_max_filesize": "3M", "post_max_size": "4M"})
    try:
        admin = Client(site).login()

        # 1. The library reports both limits.
        _, _, raw = admin.request("/admin/api.php?action=list-media&type=image")
        limits = json.loads(raw)["data"]["limits"]
        assert limits["imageMaxBytes"] == 20 * 1024 * 1024 and limits["serverMaxBytes"] == 3 * 1024 * 1024, limits
        assert limits["imageEffectiveBytes"] == 3 * 1024 * 1024 and limits["webMaxSide"] == 1200, limits

        # 2. A big photo (3000 px wide) becomes a 1200 px WebP; the original is kept.
        big = make_png(3000, 2000)
        result = upload(admin, "Sunset Photo.png", big)
        assert result["success"], result
        name = result["data"]["name"]
        assert name == "Sunset-Photo.webp", name
        wv = result["data"]["webVersion"]
        assert wv and wv["width"] == 1200 and wv["height"] == 800 and wv["originalKept"], wv
        assert (site.root / "assets/images" / name).is_file()
        original = site.root / "assets/images-originals/Sunset-Photo.png"
        assert original.is_file() and original.stat().st_size == len(big), "original not stored unchanged"
        _, _, raw = admin.request("/admin/api.php?action=list-media&type=image")
        item = next(i for i in json.loads(raw)["data"]["items"] if i["name"] == name)
        assert item["original"]["name"] == "Sunset-Photo.png" and item["original"]["width"] == 3000, item["original"]
        assert item["original"]["path"] == "../assets/images-originals/Sunset-Photo.png"
        status, _, _ = Client(site).request("/assets/images-originals/Sunset-Photo.png")
        assert status == 200, "the original must be downloadable"

        # 3. A small image stays as uploaded, with no original.
        small = make_png(640, 400)
        result = upload(admin, "icon.png", small)
        assert result["success"] and result["data"]["name"] == "icon.png" and result["data"]["webVersion"] is None, result
        assert not (site.root / "assets/images-originals/icon.png").exists()

        # 4. Rename, trash and restore carry the original along.
        result = post(admin, "rename-media", type="image", filename=name, newName="evening.webp")
        assert result["success"], result
        assert (site.root / "assets/images-originals/evening.png").is_file()
        assert post(admin, "delete-media", type="image", filename="evening.webp")["success"]
        assert (site.root / "assets/images-originals-trash/evening.png").is_file()
        assert not (site.root / "assets/images-originals/evening.png").exists()
        assert post(admin, "restore-media", type="image", filename="evening.webp")["success"]
        assert (site.root / "assets/images-originals/evening.png").is_file()
        assert post(admin, "delete-media", type="image", filename="evening.webp")["success"]
        assert post(admin, "delete-media-trash", type="image", filename="evening.webp")["success"]
        assert not (site.root / "assets/images-originals-trash/evening.png").exists(), "original must go with the file"

        # 5. Above the PHP limit: the message names the server limit.
        huge = make_png(1400, 1400, noise=True)
        assert len(huge) > 3 * 1024 * 1024, len(huge)
        result = upload(admin, "noise.png", huge)
        assert not result["success"] and "3 MB" in result["message"] and "upload_max_filesize" in result["message"], result

        # 6. Web versions off: the big photo is stored as uploaded.
        write_json(site.root / "content/settings.json", {"modules": {"ai": False}, "media": {"webVersion": False}})
        result = upload(admin, "plain.png", big)
        assert result["success"] and result["data"]["name"] == "plain.png" and result["data"]["webVersion"] is None, result
    finally:
        site.close()

print("PASS media web version: limits reported, big upload -> WebP + original, small kept, original follows rename/trash/restore, server limit named, switch off respected")
