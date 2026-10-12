#!/opt/pf-venv/bin/python
"""Entrypoint de pf-app: levanta PostgreSQL como servicio Celaut hijo y luego Laravel.

Usa celaut-service-libraries (node_controller) para pedir al Gateway del nodo las
instancias de las dependencias empaquetadas en __services__/ (hashes en .dependencies).

Si el ejecutor ya aporta DB_HOST, ese hijo no se lanza y se usa lo indicado.

El servicio OCR no va empaquetado: el usuario sube un .celaut.bee desde la app y Laravel
pide a un controlador local (CONTROL_HOST:CONTROL_PORT, solo loopback) que lo lance. Este
proceso es el dueño de la instancia porque el DependencyManager vive aquí.
"""
import base64
import json
import os
import secrets
import signal
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

APP_DIR = "/app"
CONFIG_FILE = "/__config__"
DEPENDENCIES_FILE = os.path.join(APP_DIR, ".dependencies")

DB_READY_TIMEOUT_S = 300
OCR_READY_TIMEOUT_S = 900
CONTROL_HOST = "127.0.0.1"
CONTROL_PORT = 8081
MIGRATE_ATTEMPTS = 60
LAUNCH_ATTEMPTS = 40
LAUNCH_RETRY_S = 15

# bee_rpc resuelve los bloques de las dependencias en ./__block__/ relativo al cwd.
os.chdir(APP_DIR)


def log(message: str) -> None:
    print(f"[pf] {message}", file=sys.stderr, flush=True)


def read_dependencies() -> dict:
    deps = {}
    if os.path.exists(DEPENDENCIES_FILE):
        with open(DEPENDENCIES_FILE) as f:
            for line in f:
                key, sep, value = line.strip().partition("=")
                if sep and value:
                    deps[key] = value
    return deps


class Children:
    """Instancias Celaut lanzadas por este servicio, para pararlas al salir."""

    def __init__(self):
        self.controller = None
        self.launched = []  # [(nombre, ServiceInterface, ServiceInstance)]

    def connect_once(self) -> bool:
        return self.controller is not None or self.connect()

    def connect(self) -> bool:
        if not os.path.exists(CONFIG_FILE):
            log(f"{CONFIG_FILE} no existe: fuera de un nodo Celaut, no se lanzan dependencias.")
            return False
        from node_controller.controller.controller import Controller

        self.controller = Controller(
            debug=lambda m: log(f"controller: {m}"),
            default_resource_manager=False,
            app_dir=APP_DIR,
        )
        return True

    def launch(self, name: str, service_hash: str, env: dict):
        interface = self.controller.add_service(service_hash=service_hash, config=self._config(env))
        return self._start(name, interface)

    def launch_bee(self, name: str, bee_path: str, env: dict):
        """Registra un paquete .celaut.bee en el nodo y lanza su instancia."""
        interface = self.controller.add_bee_file(file_path=bee_path, config=self._config(env))
        if interface is None:
            raise RuntimeError(f"{bee_path} no contiene un servicio válido")
        return interface.sc.service_hash, *self._start(name, interface)

    @staticmethod
    def _config(env: dict):
        from node_controller.gateway.protos import celaut_pb2

        return celaut_pb2.Configuration(environment_variables=[
            celaut_pb2.BytesKeyValue(key=k, value=v.encode()) for k, v in env.items()
        ])

    def _start(self, name: str, interface):
        # Los hijos los paga el saldo de ESTA instancia. Si no alcanza, el nodo rechaza el
        # lanzamiento: se reintenta con paciencia para dar tiempo a ingresar fondos.
        instance = None
        for attempt in range(1, LAUNCH_ATTEMPTS + 1):
            try:
                instance = interface.get_instance(max_attempts=2)
                break
            except Exception as e:  # noqa: BLE001
                log(f"no se pudo lanzar {name} ({attempt}/{LAUNCH_ATTEMPTS}): {e}")
                log("Si es por saldo insuficiente: nodo increase_deposit <id de pf-app> <ERG>")
                time.sleep(LAUNCH_RETRY_S)
        if instance is None:
            raise RuntimeError(f"no se pudo lanzar {name}")

        self.launched.append((name, interface, instance))
        host, _, port = instance.uri.rpartition(":")
        log(f"{name} lanzado en {instance.uri}")
        return host, int(port)

    def stop(self, name: str) -> None:
        for entry in [e for e in self.launched if e[0] == name]:
            _, interface, instance = entry
            log(f"parando {name}")
            instance.try_stop(interface.gateway_stub)
            self.launched.remove(entry)

    def stop_all(self) -> None:
        for name, interface, instance in reversed(self.launched):
            log(f"parando {name}")
            instance.try_stop(interface.gateway_stub)
        self.launched.clear()


def wait_open(name: str, host: str, port: int, timeout_s: int) -> bool:
    from node_controller.utils.network import is_open

    deadline = time.monotonic() + timeout_s
    while time.monotonic() < deadline:
        if is_open(timeout=2, ip=host, port=port):
            log(f"{name} acepta conexiones en {host}:{port}")
            return True
        time.sleep(2)
    log(f"{name} no respondió en {host}:{port} tras {timeout_s}s")
    return False


def artisan(*args: str, check: bool = True) -> int:
    result = subprocess.run(["php", "artisan", *args], check=False)
    if check and result.returncode != 0:
        raise subprocess.CalledProcessError(result.returncode, args)
    return result.returncode


def prepare_dependencies(children: Children) -> None:
    deps = read_dependencies()
    if os.environ.get("DB_HOST"):
        return

    if not children.connect():
        raise SystemExit("[pf] Sin DB_HOST y sin nodo Celaut: no hay base de datos disponible.")

    if "PF_POSTGRES" not in deps:
        raise SystemExit("[pf] Falta PF_POSTGRES en .dependencies.")
    os.environ.setdefault("DB_DATABASE", "personal_finance")
    os.environ.setdefault("DB_USERNAME", "laravel")
    os.environ.setdefault("DB_PASSWORD", secrets.token_hex(24))
    host, port = children.launch("pf-postgres", deps["PF_POSTGRES"], {
        "DB_DATABASE": os.environ["DB_DATABASE"],
        "DB_USERNAME": os.environ["DB_USERNAME"],
        "DB_PASSWORD": os.environ["DB_PASSWORD"],
    })
    if not wait_open("pf-postgres", host, port, DB_READY_TIMEOUT_S):
        raise SystemExit(1)
    os.environ["DB_HOST"], os.environ["DB_PORT"] = host, str(port)


def start_control_server(children: Children) -> ThreadingHTTPServer:
    """API local para que Laravel lance/pare el servicio OCR subido como .celaut.bee.

    POST /ocr/start {"path": "<.celaut.bee>"} -> {"service_hash", "uri"}; para antes el anterior.
    POST /ocr/stop -> {}
    Solo escucha en loopback.
    """
    lock = threading.Lock()  # Un solo lanzamiento/parada a la vez.

    class Handler(BaseHTTPRequestHandler):
        def _reply(self, code: int, body: dict) -> None:
            data = json.dumps(body).encode()
            self.send_response(code)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def do_POST(self):  # noqa: N802
            length = int(self.headers.get("Content-Length") or 0)
            try:
                payload = json.loads(self.rfile.read(length) or b"{}")
            except ValueError:
                return self._reply(400, {"message": "JSON inválido"})

            with lock:
                try:
                    if self.path == "/ocr/start":
                        path = payload.get("path")
                        if not path or not os.path.isfile(path):
                            return self._reply(400, {"message": f"no existe {path}"})
                        if not children.connect_once():
                            return self._reply(503, {"message": "sin nodo Celaut"})
                        children.stop("pf-ocr")
                        service_hash, host, port = children.launch_bee("pf-ocr", path, {})
                        if not wait_open("pf-ocr", host, port, OCR_READY_TIMEOUT_S):
                            children.stop("pf-ocr")
                            return self._reply(504, {"message": "el servicio OCR no responde"})
                        return self._reply(200, {"service_hash": service_hash, "uri": f"{host}:{port}"})
                    if self.path == "/ocr/stop":
                        children.stop("pf-ocr")
                        return self._reply(200, {})
                    return self._reply(404, {"message": "no encontrado"})
                except Exception as e:  # noqa: BLE001
                    log(f"control {self.path}: {e!r}")
                    return self._reply(500, {"message": str(e)})

        def log_message(self, fmt, *args):  # silencia el log por petición
            pass

    server = ThreadingHTTPServer((CONTROL_HOST, CONTROL_PORT), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    os.environ["PF_CONTROL_URL"] = f"http://{CONTROL_HOST}:{CONTROL_PORT}"
    return server


def ensure_admin_user() -> None:
    """Sin usuario no se puede entrar al panel: lo crea a partir de PF_ADMIN_EMAIL/PF_ADMIN_PASSWORD."""
    email = os.environ.get("PF_ADMIN_EMAIL")
    if not email:
        log("AVISO: sin PF_ADMIN_EMAIL no se crea ningún usuario; si la base está vacía no podrás iniciar sesión.")
        return
    if not os.environ.get("PF_ADMIN_PASSWORD"):
        log("AVISO: PF_ADMIN_EMAIL sin PF_ADMIN_PASSWORD; no se crea el usuario.")
        return
    # El comando no toca un usuario que ya exista.
    artisan("app:ensure-user", email, "--no-interaction", check=False)


def main() -> int:
    os.environ.setdefault("DB_CONNECTION", "pgsql")

    if not os.environ.get("APP_KEY"):
        log("APP_KEY no fue proporcionada (nodo execute -e APP_KEY ...).")
        log("Generando una clave solo para este arranque; las sesiones no sobrevivirán a un reinicio.")
        os.environ["APP_KEY"] = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()

    children = Children()
    procs = []

    def shutdown(code: int):
        for p in procs:
            if p.poll() is None:
                p.terminate()
        for p in procs:
            try:
                p.wait(timeout=20)
            except subprocess.TimeoutExpired:
                p.kill()
        children.stop_all()
        # El DependencyManager deja un hilo de mantenimiento no-daemon: salida explícita.
        os._exit(code)

    signal.signal(signal.SIGTERM, lambda *_: shutdown(143))
    signal.signal(signal.SIGINT, lambda *_: shutdown(130))

    try:
        prepare_dependencies(children)
        start_control_server(children)

        artisan("config:clear", check=False)
        artisan("package:discover", "--ansi")
        for attempt in range(1, MIGRATE_ATTEMPTS + 1):
            if artisan("migrate", "--force", check=False) == 0:
                break
            log(f"esperando a la base de datos ({attempt}/{MIGRATE_ATTEMPTS}, DB_HOST={os.environ.get('DB_HOST')})")
            time.sleep(3)
        else:
            raise SystemExit("[pf] La base de datos no aceptó las migraciones.")

        ensure_admin_user()

        # artisan serve atiende una petición a la vez si no se le dan workers.
        os.environ.setdefault("PHP_CLI_SERVER_WORKERS", "4")
        procs.append(subprocess.Popen(["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]))
        procs.append(subprocess.Popen(["php", "artisan", "queue:work", "--sleep=3", "--tries=3"]))
        # Backups diarios de la base de datos (routes/console.php).
        procs.append(subprocess.Popen(["php", "artisan", "schedule:work", "--no-interaction"]))
        # Relanza el servicio OCR que estaba activo antes del reinicio (no bloquea el arranque).
        threading.Thread(target=artisan, args=("ocr:restore",), kwargs={"check": False}, daemon=True).start()
    except SystemExit as e:
        log(str(e.code))
        shutdown(1)
    except Exception as e:  # noqa: BLE001
        log(f"error de arranque: {e!r}")
        shutdown(1)

    # Si cualquiera de los procesos de Laravel muere, se cae todo (el nodo decide si relanzar).
    while True:
        for p in procs:
            if p.poll() is not None:
                log(f"{p.args[2]} terminó con código {p.returncode}")
                shutdown(p.returncode or 1)
        time.sleep(2)


if __name__ == "__main__":
    main()
