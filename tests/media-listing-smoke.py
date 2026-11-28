#!/usr/bin/env python3
"""Media library listing for big libraries: large JSON answers go out gzip-compressed when the
browser accepts it, items name their thumbnail (smallest responsive size), small answers and
clients without gzip get plain JSON."""
import gzip
import json
import shutil
import subprocess
import tempfile
import uuid

from support import Client, Site


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


def png(width, height):
    script = (f"$i=imagecreatetruecolor({width},{height});"
              f"for($y=0;$y<{height};$y+=8)imagefilledrectangle($i,0,$y,{width},$y+7,imagecolorallocate($i,$y%256,($y*3)%256,128));"
              "imagepng($i,'php://stdout');")
    return subprocess.check_output(["php", "-r", script])


def headers_lower(headers):
    return {k.lower(): v for k, v in headers.items()}


with tempfile.TemporaryDirectory(prefix="nibbly-media-listing-") as folder:
    site = Site(folder).start()
    try:
        images = site.root / "assets/images"
        (images / "many").mkdir()
        for i in range(400):
            shutil.copyfile(images / "favicon.svg", images / f"many/icon-{i:03}.svg")
        admin = Client(site).login("admin")

        # A photo wider than 640 px gets its responsive sizes on upload; the listing names the smallest as thumb
        body, ctype = multipart({"action": "upload-media", "csrf_token": admin.csrf, "type": "image", "folder": "photos"},
                                {"file": ("wide.png", png(1500, 900), "image/png")})
        status, _, raw = admin.request("/admin/api.php", body, "POST", {"Content-Type": ctype})
        uploaded = json.loads(raw)
        assert uploaded["success"], uploaded

        url = "/admin/api.php?action=list-media&type=image"
        status, headers, raw = admin.request(url)
        h = headers_lower(headers)
        assert status == 200 and "content-encoding" not in h, h
        plain = json.loads(raw)
        item = next(i for i in plain["data"]["items"] if i["name"] == uploaded["data"]["name"])
        assert item["sizes"] == [640, 1280], item
        assert item["thumb"] == "../assets/images-sizes/photos/wide-640.webp", item
        assert (site.root / "assets/images-sizes/photos/wide-640.webp").is_file()
        assert "thumb" not in next(i for i in plain["data"]["items"] if i["name"] == "many/icon-000.svg")

        # Accepting gzip: same JSON, compressed, with Vary
        status, headers, raw = admin.request(url, headers={"Accept-Encoding": "gzip, deflate, br"})
        h = headers_lower(headers)
        assert status == 200 and h.get("content-encoding") == "gzip" and "accept-encoding" in h.get("vary", "").lower(), h
        assert int(h["content-length"]) == len(raw) and len(raw) * 4 < len(json.dumps(plain)), (len(raw), h)
        assert json.loads(gzip.decompress(raw)) == plain
        # gzip refused, or only offered as "*;q=0": plain
        for header in ("gzip;q=0, deflate", "*;q=0", "br", "identity"):
            _, headers, raw = admin.request(url, headers={"Accept-Encoding": header})
            assert "content-encoding" not in headers_lower(headers), (header, headers)
            assert json.loads(raw)["success"]
        assert "content-encoding" in headers_lower(admin.request(url, headers={"Accept-Encoding": "*"})[1])
        # Small answers stay plain
        status, headers, raw = admin.request("/admin/api.php?action=list-media&type=audio", headers={"Accept-Encoding": "gzip"})
        assert "content-encoding" not in headers_lower(headers) and json.loads(raw)["success"], headers

        # The trash lists no thumbs
        assert admin.api("delete-media", type="image", filename=uploaded["data"]["name"])[1]["success"]
        _, _, raw = admin.request("/admin/api.php?action=list-media&type=image&trash=1")
        trashed = json.loads(raw)["data"]["items"]
        assert trashed and all("thumb" not in i for i in trashed), trashed
        print("PASS media listing: thumb from the smallest responsive size, gzip for large JSON when accepted "
              "(q=0, *, br, identity handled), small answers plain, trash without thumbs")
    finally:
        site.close()
