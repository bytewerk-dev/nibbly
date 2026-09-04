#!/usr/bin/env python3
"""Concurrent account changes and one-time password reset tokens."""
from concurrent.futures import ThreadPoolExecutor
import json
import subprocess
import tempfile
from support import copy_core, write_json

with tempfile.TemporaryDirectory(prefix="nibbly-users-smoke-") as folder:
    root = copy_core(folder)
    users_path = root / "content/users.json"
    write_json(users_path, {"users": [
        {"id": "first", "username": "first", "email": "", "role": "admin", "passwordHash": "old", "resetToken": None},
        {"id": "second", "username": "second", "email": "", "role": "admin", "passwordHash": "old", "resetToken": None},
    ]})

    def php(code):
        return subprocess.check_output(["php", "-r", "require 'admin/users.php'; " + code], cwd=root, text=True).strip()

    with ThreadPoolExecutor(max_workers=12) as workers:
        results = list(workers.map(lambda n: php("echo (int)" + ("updateUserPassword('first','new-hash');" if n == 0 else "updateUserLastLogin('first');")), range(24)))
    assert all(result == "1" for result in results)
    assert json.loads(users_path.read_text())["users"][0]["passwordHash"] == "new-hash", "Login overwrote a new password"
    token = php("echo generateResetToken('first');")
    with ThreadPoolExecutor(max_workers=12) as workers:
        results = list(workers.map(lambda _: php(f"echo (int)completePasswordReset('first','{token}','reset-hash');"), range(24)))
    assert results.count("1") == 1, "Reset token accepted multiple times"
    with ThreadPoolExecutor(max_workers=2) as workers:
        results = list(workers.map(lambda name: php(f"echo (int)updateUser('{name}', ['role'=>'editor']);"), ["first", "second"]))
    assert results.count("1") == 1, "Concurrent demotions removed the last admin"
    remaining_admin = next(user["id"] for user in json.loads(users_path.read_text())["users"] if user["role"] == "admin")
    assert php(f"echo (int)deleteUser('{remaining_admin}');") == "0", "Deleted the last administrator"
    users_path.write_text("{broken-users")
    assert php("echo (int)updateUserPassword('first','must-not-save');") == "0"
    assert users_path.read_text() == "{broken-users", "Overwrote corrupt user store"
print("PASS concurrent account updates, single-use password reset, last-admin protection and corrupt-store preservation")
