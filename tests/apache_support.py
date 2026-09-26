"""Disposable, unprivileged Apache + PHP-FPM using the real project .htaccess."""
import grp
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import time
from support import Site


def executable(variable, names):
    candidates = [os.environ.get(variable, ""), *(shutil.which(name) for name in names),
                  *(["/opt/homebrew/opt/php/sbin/php-fpm"] if variable == "PHP_FPM" else ["/usr/sbin/httpd", "/usr/sbin/apache2"])]
    for candidate in candidates:
        if candidate and Path(candidate).is_file():
            return str(Path(candidate).resolve())
    raise RuntimeError(f"Set {variable} to an installed executable ({', '.join(names)}).")


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


class ApacheSite(Site):
    def start(self):
        httpd = executable("HTTPD", ["httpd", "apache2"])
        fpm = executable("PHP_FPM", ["php-fpm", "php-fpm8.5", "php-fpm8.4", "php-fpm8.3", "php-fpm8.1"])
        modules = next((Path(p) for p in [os.environ.get("HTTPD_MODULE_DIR", ""),
                       "/usr/libexec/apache2", "/usr/lib/apache2/modules"] if p and Path(p).is_dir()), None)
        if modules is None:
            raise RuntimeError("Set HTTPD_MODULE_DIR to Apache's module directory.")
        compiled = subprocess.check_output([httpd, "-l"], text=True)
        self.port, fpm_port = free_port(), free_port()
        user = pwd.getpwuid(os.getuid()).pw_name
        group = grp.getgrgid(os.getgid()).gr_name
        runtime = self.root / "apache-runtime"
        runtime.mkdir()
        (runtime / "sessions").mkdir()
        self.fpm_process = None
        self.log = open(runtime / "startup.log", "w+")
        (runtime / "mime.types").write_text("text/css css\ntext/javascript js\nimage/svg+xml svg\napplication/json json\n")
        fpm_conf = runtime / "php-fpm.conf"
        fpm_conf.write_text(f"""[global]
daemonize = no
error_log = {runtime}/fpm-error.log
[fixture]
listen = 127.0.0.1:{fpm_port}
user = {user}
group = {group}
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
catch_workers_output = yes
php_admin_value[session.save_path] = {runtime}/sessions
""")
        loads = []
        for name in ["mpm_event", "unixd", "authz_core", "authz_host", "access_compat", "dir", "mime", "rewrite", "proxy", "proxy_fcgi"]:
            if f"mod_{name}.c" not in compiled and not (name == "mpm_event" and any(x in compiled for x in ["prefork.c", "event.c", "worker.c"])):
                loads.append(f'LoadModule {name}_module "{modules}/mod_{name}.so"')
        apache_conf = runtime / "httpd.conf"
        apache_conf.write_text("\n".join(loads) + f"""
ServerRoot "{runtime}"
DefaultRuntimeDir "{runtime}"
Mutex file:{runtime} default
ServerName 127.0.0.1
Listen 127.0.0.1:{self.port}
PidFile "{runtime}/httpd.pid"
ErrorLog "{runtime}/httpd-error.log"
User {user}
Group {group}
TypesConfig "{runtime}/mime.types"
DocumentRoot "{self.root}"
<Directory "{self.root}">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php index.html
</Directory>
<FilesMatch "\\.php$">
    SetHandler "proxy:fcgi://127.0.0.1:{fpm_port}"
</FilesMatch>
""")
        try:
            self.fpm_process = subprocess.Popen([fpm, "-F", "-y", str(fpm_conf)], stdout=self.log, stderr=self.log)
            self._wait(self.fpm_process, fpm_port)
            subprocess.run([httpd, "-t", "-f", str(apache_conf)], check=True, stdout=self.log, stderr=self.log)
            self.process = subprocess.Popen([httpd, "-f", str(apache_conf), "-DFOREGROUND"], stdout=self.log, stderr=self.log)
            self._wait(self.process, self.port)
            self.server_version = subprocess.check_output([httpd, "-v"], text=True).splitlines()[0]
            self.php_version = subprocess.check_output([fpm, "-v"], text=True).splitlines()[0]
            return self
        except Exception:
            self.log.seek(0)
            detail = self.log.read()
            for error_log in [runtime / "httpd-error.log", runtime / "fpm-error.log"]:
                if error_log.exists():
                    detail += "\n" + error_log.read_text()
            self.close()
            raise RuntimeError("Apache fixture failed to start:\n" + detail)

    def _wait(self, process, port):
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError("Fixture server exited during startup")
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=.1):
                    return
            except OSError:
                time.sleep(.05)
        raise RuntimeError("Fixture server startup timed out")

    def close(self):
        for process in [self.process, getattr(self, "fpm_process", None)]:
            if process and process.poll() is None:
                process.terminate()
                process.wait(timeout=10)
        if getattr(self, "log", None) and not self.log.closed:
            self.log.close()
