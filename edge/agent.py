#!/usr/bin/env python3
"""QRKiosk Edge enrollment and signed snapshot synchronization."""

from __future__ import annotations

import argparse
import getpass
import hashlib
import hmac
import json
import os
from pathlib import Path
import socket
import sqlite3
import sys
import tempfile
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

VERSION = "0.1.0"
DEFAULT_DATA_DIR = Path("/var/lib/qrkiosk-edge")
MAX_RESPONSE_BYTES = 5 * 1024 * 1024


class EdgeError(RuntimeError):
    pass


def api_request(url: str, method: str = "GET", payload: dict | None = None, headers: dict | None = None):
    body = None if payload is None else json.dumps(payload, separators=(",", ":")).encode("utf-8")
    request_headers = {"Accept": "application/json", "User-Agent": f"QRKiosk-Edge/{VERSION}"}
    if body is not None:
        request_headers["Content-Type"] = "application/json"
    request_headers.update(headers or {})
    request = Request(url, data=body, headers=request_headers, method=method)
    try:
        with urlopen(request, timeout=20) as response:
            raw = response.read(MAX_RESPONSE_BYTES + 1)
            if len(raw) > MAX_RESPONSE_BYTES:
                raise EdgeError("Server response was too large.")
            return response.status, response.headers, raw
    except HTTPError as exception:
        detail = ""
        try:
            error_payload = json.loads(exception.read(8192))
            detail = str(error_payload.get("error", ""))
        except Exception:
            pass
        raise EdgeError(f"Server rejected the request ({exception.code}{': ' + detail if detail else ''}).") from None
    except URLError as exception:
        raise EdgeError(f"Unable to reach the QRKiosk server: {exception.reason}") from None


def require_secure_server(server: str, allow_http: bool) -> str:
    server = server.rstrip("/") + "/"
    parsed = urlparse(server)
    if parsed.scheme != "https" and not (allow_http and parsed.scheme == "http"):
        raise EdgeError("The server URL must use HTTPS.")
    if not parsed.netloc:
        raise EdgeError("The server URL is invalid.")
    return server


def write_private_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    file_descriptor, temporary_name = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        os.fchmod(file_descriptor, 0o600)
        with os.fdopen(file_descriptor, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_name, path)
    finally:
        if os.path.exists(temporary_name):
            os.unlink(temporary_name)


def load_config(data_dir: Path) -> dict:
    path = data_dir / "device.json"
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise EdgeError("This device is not enrolled.") from None
    except (OSError, json.JSONDecodeError):
        raise EdgeError("The device configuration is unreadable.") from None
    for key in ("server", "device_id", "device_secret", "vendor_slug"):
        if not isinstance(config.get(key), str) or not config[key]:
            raise EdgeError("The device configuration is incomplete.")
    return config


def enroll(args: argparse.Namespace) -> None:
    server = require_secure_server(args.server, args.allow_http)
    if args.key_file:
        try:
            enrollment_key = args.key_file.read_text(encoding="utf-8").strip()
        except OSError as exception:
            raise EdgeError(f"Unable to read the enrollment key file: {exception}") from None
    else:
        enrollment_key = args.key or getpass.getpass("One-time enrollment key: ")
    status, _, raw = api_request(
        urljoin(server, "api/edge/enroll.php"),
        method="POST",
        payload={
            "enrollment_key": enrollment_key.strip(),
            "device_name": args.device_name or socket.gethostname(),
            "software_version": VERSION,
        },
    )
    if status != 201:
        raise EdgeError("Enrollment did not complete.")
    response = json.loads(raw)
    edge = response.get("edge", {})
    config = {
        "server": server.rstrip("/"),
        "device_id": edge.get("device_id"),
        "device_secret": edge.get("device_secret"),
        "vendor_slug": edge.get("vendor_slug"),
    }
    if not all(isinstance(value, str) and value for value in config.values()):
        raise EdgeError("Enrollment returned incomplete credentials.")
    write_private_json(args.data_dir / "device.json", config)
    if args.key_file:
        args.key_file.unlink(missing_ok=True)
    print(f"Enrolled for vendor {config['vendor_slug']} as device {config['device_id']}.")


def initialize_database(data_dir: Path) -> sqlite3.Connection:
    data_dir.mkdir(parents=True, exist_ok=True)
    database_path = data_dir / "edge.db"
    connection = sqlite3.connect(database_path)
    os.chmod(database_path, 0o600)
    connection.execute("PRAGMA foreign_keys = ON")
    schema_path = Path(__file__).with_name("schema.sql")
    connection.executescript(schema_path.read_text(encoding="utf-8"))
    return connection


def apply_snapshot(connection: sqlite3.Connection, snapshot: dict, expected_slug: str) -> None:
    if snapshot.get("schema_version") != 1:
        raise EdgeError("The server returned an unsupported snapshot schema.")
    vendor = snapshot.get("vendor")
    items = snapshot.get("menu_items")
    if not isinstance(vendor, dict) or not isinstance(items, list) or vendor.get("slug") != expected_slug:
        raise EdgeError("The snapshot does not match this device's vendor.")

    snapshot_vendor_columns = (
        "id", "name", "slug", "status", "contact_email", "contact_phone", "theme_primary",
        "theme_accent", "theme_background", "theme_surface", "theme_text", "logo_path", "hero_path",
        "storefront_message", "vendor_description", "updated_at",
    )
    values = [vendor.get(column) for column in snapshot_vendor_columns]
    with connection:
        connection.execute(
            "INSERT INTO restaurants (id, remote_id, name, slug, status, contact_email, contact_phone, "
            "theme_primary, theme_accent, theme_background, theme_surface, theme_text, logo_path, hero_path, "
            "storefront_message, vendor_description, remote_updated_at) VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) "
            "ON CONFLICT(id) DO UPDATE SET remote_id=excluded.remote_id, name=excluded.name, slug=excluded.slug, "
            "status=excluded.status, contact_email=excluded.contact_email, contact_phone=excluded.contact_phone, "
            "theme_primary=excluded.theme_primary, theme_accent=excluded.theme_accent, "
            "theme_background=excluded.theme_background, theme_surface=excluded.theme_surface, "
            "theme_text=excluded.theme_text, logo_path=excluded.logo_path, hero_path=excluded.hero_path, "
            "storefront_message=excluded.storefront_message, vendor_description=excluded.vendor_description, "
            "remote_updated_at=excluded.remote_updated_at",
            values,
        )
        connection.execute("DELETE FROM menu_items")
        for item in items:
            connection.execute(
                "INSERT INTO menu_items (id, restaurant_id, name, category, price, variant_options) VALUES (?,1,?,?,?,?)",
                (
                    int(item["id"]), str(item["name"]), str(item.get("category", "")),
                    str(item["price"]), json.dumps(item.get("variants", []), separators=(",", ":")),
                ),
            )
        connection.execute(
            "INSERT INTO edge_state (key,value) VALUES ('snapshot_hash',?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (str(snapshot.get("snapshot_hash", "")),),
        )
        connection.execute(
            "INSERT INTO edge_state (key,value) VALUES ('generated_at',?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (str(snapshot.get("generated_at", "")),),
        )


def sync(args: argparse.Namespace) -> None:
    config = load_config(args.data_dir)
    status, headers, raw = api_request(
        urljoin(config["server"] + "/", "api/edge/snapshot.php"),
        headers={
            "Authorization": "Bearer " + config["device_secret"],
            "X-QRKiosk-Device": config["device_id"],
            "X-QRKiosk-Secret": config["device_secret"],
        },
    )
    signature = headers.get("X-QRKiosk-Signature", "")
    expected = hmac.new(config["device_secret"].encode(), raw, hashlib.sha256).hexdigest()
    if not signature or not hmac.compare_digest(signature, expected):
        raise EdgeError("The snapshot signature is invalid.")
    if status != 200:
        raise EdgeError("Snapshot synchronization failed.")
    payload = json.loads(raw)
    snapshot = payload.get("snapshot", {})
    connection = initialize_database(args.data_dir)
    try:
        apply_snapshot(connection, snapshot, config["vendor_slug"])
    finally:
        connection.close()
    print(f"Synchronized vendor {config['vendor_slug']} ({len(snapshot.get('menu_items', []))} menu items).")


def status(args: argparse.Namespace) -> None:
    config = load_config(args.data_dir)
    database = args.data_dir / "edge.db"
    snapshot_hash = "never"
    if database.exists():
        connection = sqlite3.connect(database)
        try:
            row = connection.execute("SELECT value FROM edge_state WHERE key='snapshot_hash'").fetchone()
            if row:
                snapshot_hash = row[0]
        except sqlite3.Error:
            snapshot_hash = "unreadable"
        finally:
            connection.close()
    print(f"Vendor: {config['vendor_slug']}")
    print(f"Device: {config['device_id']}")
    print(f"Snapshot: {snapshot_hash}")


def parser() -> argparse.ArgumentParser:
    command_parser = argparse.ArgumentParser(description=__doc__)
    command_parser.add_argument("--data-dir", type=Path, default=DEFAULT_DATA_DIR)
    subcommands = command_parser.add_subparsers(dest="command", required=True)

    enroll_parser = subcommands.add_parser("enroll", help="Enroll this Pi with a one-time key.")
    enroll_parser.add_argument("--server", required=True)
    key_input = enroll_parser.add_mutually_exclusive_group()
    key_input.add_argument("--key", help="Prefer the secure prompt to avoid shell history.")
    key_input.add_argument("--key-file", type=Path, help="Read and remove a protected key file after successful enrollment.")
    enroll_parser.add_argument("--device-name")
    enroll_parser.add_argument("--allow-http", action="store_true", help=argparse.SUPPRESS)
    enroll_parser.set_defaults(handler=enroll)

    sync_parser = subcommands.add_parser("sync", help="Download and apply a signed vendor snapshot.")
    sync_parser.set_defaults(handler=sync)
    status_parser = subcommands.add_parser("status", help="Show enrollment and snapshot status.")
    status_parser.set_defaults(handler=status)
    return command_parser


def main() -> int:
    args = parser().parse_args()
    try:
        args.handler(args)
        return 0
    except (EdgeError, OSError, ValueError, sqlite3.Error, json.JSONDecodeError) as exception:
        print(f"Error: {exception}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
