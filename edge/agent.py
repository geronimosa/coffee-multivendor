#!/usr/bin/env python3
"""QRKiosk Edge enrollment and signed snapshot synchronization."""

from __future__ import annotations

import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
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
ORDER_BATCH_SIZE = 50


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


@contextmanager
def sync_lock(data_dir: Path):
    data_dir.mkdir(parents=True, exist_ok=True)
    lock_path = data_dir / ".sync.lock"
    with lock_path.open("a+", encoding="utf-8") as handle:
        try:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise EdgeError("Another Edge synchronization is already running.") from None
        yield


def authenticated_headers(config: dict, body: bytes | None = None) -> dict:
    headers = {
        "Authorization": "Bearer " + config["device_secret"],
        "X-QRKiosk-Device": config["device_id"],
        "X-QRKiosk-Secret": config["device_secret"],
    }
    if body is not None:
        headers["X-QRKiosk-Payload-Signature"] = hmac.new(
            config["device_secret"].encode("utf-8"), body, hashlib.sha256
        ).hexdigest()
    return headers


def verify_signed_response(headers, raw: bytes, secret: str) -> None:
    signature = headers.get("X-QRKiosk-Signature", "")
    expected = hmac.new(secret.encode("utf-8"), raw, hashlib.sha256).hexdigest()
    if not signature or not hmac.compare_digest(signature, expected):
        raise EdgeError("The server response signature is invalid.")


def set_edge_state(connection: sqlite3.Connection, key: str, value: str) -> None:
    connection.execute(
        "INSERT INTO edge_state (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (key, value),
    )


def order_payload(connection: sqlite3.Connection, force_all: bool, after_id: int) -> list[dict]:
    condition = "" if force_all else "AND synced_at IS NULL"
    orders = connection.execute(
        f"SELECT * FROM orders WHERE id>? {condition} ORDER BY id LIMIT ?",
        (after_id, ORDER_BATCH_SIZE),
    ).fetchall()
    payload = []
    for order in orders:
        items = connection.execute(
            "SELECT id,remote_menu_item_id,item_name,variant_label,quantity,unit_price,subtotal "
            "FROM order_items WHERE order_id=? ORDER BY id",
            (order["id"],),
        ).fetchall()
        event_condition = "" if force_all else "AND synced_at IS NULL"
        events = connection.execute(
            f"SELECT event_uuid,action,from_status,to_status,payment_status,actor,remote_staff_key_id,created_at "
            f"FROM edge_order_events WHERE order_id=? {event_condition} ORDER BY id",
            (order["id"],),
        ).fetchall()
        payload.append({
            "local_id": order["id"],
            "order_uuid": order["order_uuid"],
            "customer_name": order["customer_name"],
            "customer_phone": order["customer_phone"],
            "total": order["total"],
            "status": order["status"],
            "payment_method": order["payment_method"],
            "payment_status": order["payment_status"],
            "payment_reference": order["payment_reference"],
            "status_token": order["status_token"],
            "paid_at": order["paid_at"],
            "created_at": order["created_at"],
            "updated_at": order["updated_at"],
            "items": [{
                "origin_line_id": item["id"],
                "remote_menu_item_id": item["remote_menu_item_id"],
                "item_name": item["item_name"],
                "variant_label": item["variant_label"],
                "quantity": item["quantity"],
                "unit_price": item["unit_price"],
                "subtotal": item["subtotal"],
            } for item in items],
            "events": [{
                "event_uuid": event["event_uuid"],
                "action": event["action"],
                "from_status": event["from_status"],
                "to_status": event["to_status"],
                "payment_status": event["payment_status"],
                "actor_username": event["actor"],
                "staff_key_id": event["remote_staff_key_id"],
                "created_at": event["created_at"],
            } for event in events],
        })
    return payload


def upload_orders(connection: sqlite3.Connection, config: dict, reconcile: bool = False) -> tuple[int, int]:
    uploaded_orders = 0
    uploaded_events = 0
    after_id = 0
    sent_request = False
    while True:
        orders = order_payload(connection, reconcile, after_id)
        if not orders and (sent_request or not reconcile):
            break
        payload = {"reconcile": reconcile, "orders": orders}
        encoded = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        status, headers, raw = api_request(
            urljoin(config["server"] + "/", "api/edge/orders.php"),
            method="POST",
            payload=payload,
            headers=authenticated_headers(config, encoded),
        )
        verify_signed_response(headers, raw, config["device_secret"])
        if status != 200:
            raise EdgeError("Order synchronization failed.")
        response = json.loads(raw)
        accepted = response.get("accepted", {})
        accepted_orders = set(accepted.get("orders", []))
        accepted_events = set(accepted.get("events", []))
        now = datetime.now(timezone.utc).isoformat(timespec="seconds")
        with connection:
            for order in orders:
                if order["order_uuid"] in accepted_orders:
                    connection.execute(
                        "UPDATE orders SET synced_at=? WHERE order_uuid=? AND updated_at=?",
                        (now, order["order_uuid"], order["updated_at"]),
                    )
                    uploaded_orders += 1
                for event in order["events"]:
                    if event["event_uuid"] in accepted_events:
                        connection.execute(
                            "UPDATE edge_order_events SET synced_at=? WHERE event_uuid=?",
                            (now, event["event_uuid"]),
                        )
                        uploaded_events += 1
            set_edge_state(connection, "last_order_sync_at", now)
            if reconcile:
                set_edge_state(connection, "last_reconciliation_at", now)
        sent_request = True
        if not orders:
            break
        after_id = int(orders[-1]["local_id"])
        if len(orders) < ORDER_BATCH_SIZE:
            break
    return uploaded_orders, uploaded_events


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
    order_columns = {row[1] for row in connection.execute("PRAGMA table_info(orders)")}
    if "status_token" not in order_columns:
        connection.execute("ALTER TABLE orders ADD COLUMN status_token TEXT")
    connection.execute("CREATE UNIQUE INDEX IF NOT EXISTS uq_edge_orders_status_token ON orders(status_token)")
    event_columns = {row[1] for row in connection.execute("PRAGMA table_info(edge_order_events)")}
    if "remote_staff_key_id" not in event_columns:
        connection.execute("ALTER TABLE edge_order_events ADD COLUMN remote_staff_key_id INTEGER")
    return connection


def apply_snapshot(connection: sqlite3.Connection, snapshot: dict, expected_slug: str) -> None:
    if snapshot.get("schema_version") != 1:
        raise EdgeError("The server returned an unsupported snapshot schema.")
    vendor = snapshot.get("vendor")
    items = snapshot.get("menu_items")
    staff_access_keys = snapshot.get("staff_access_keys", [])
    if (
        not isinstance(vendor, dict)
        or not isinstance(items, list)
        or not isinstance(staff_access_keys, list)
        or vendor.get("slug") != expected_slug
    ):
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
        connection.execute("DELETE FROM edge_staff_access_keys")
        for staff_key in staff_access_keys:
            try:
                key_id = int(staff_key["id"])
                username = str(staff_key["username"])
                key_hash = str(staff_key["key_hash"])
            except (KeyError, TypeError, ValueError):
                raise EdgeError("The snapshot contains an invalid staff access key.") from None
            if key_id < 1 or not username or len(key_hash) != 64:
                raise EdgeError("The snapshot contains an invalid staff access key.")
            connection.execute(
                "INSERT INTO edge_staff_access_keys (id,username,key_hash,expires_at_epoch) VALUES (?,?,?,?)",
                (key_id, username, key_hash, staff_key.get("expires_at_epoch")),
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
    with sync_lock(args.data_dir):
        connection = initialize_database(args.data_dir)
        try:
            order_count, event_count = upload_orders(connection, config)
            status, headers, raw = api_request(
                urljoin(config["server"] + "/", "api/edge/snapshot.php"),
                headers=authenticated_headers(config),
            )
            verify_signed_response(headers, raw, config["device_secret"])
            if status != 200:
                raise EdgeError("Snapshot synchronization failed.")
            payload = json.loads(raw)
            snapshot = payload.get("snapshot", {})
            apply_snapshot(connection, snapshot, config["vendor_slug"])
            with connection:
                set_edge_state(connection, "last_order_sync_at", datetime.now(timezone.utc).isoformat(timespec="seconds"))
        finally:
            connection.close()
    print(
        f"Synchronized vendor {config['vendor_slug']} ({len(snapshot.get('menu_items', []))} menu items, "
        f"{order_count} orders, {event_count} events uploaded)."
    )


def reconcile(args: argparse.Namespace) -> None:
    config = load_config(args.data_dir)
    with sync_lock(args.data_dir):
        connection = initialize_database(args.data_dir)
        try:
            order_count, event_count = upload_orders(connection, config, reconcile=True)
        finally:
            connection.close()
    print(f"Reconciled {order_count} orders and {event_count} staff events.")


def status(args: argparse.Namespace) -> None:
    config = load_config(args.data_dir)
    database = args.data_dir / "edge.db"
    snapshot_hash = "never"
    order_sync = "never"
    reconciliation = "never"
    pending_orders = 0
    pending_events = 0
    if database.exists():
        connection = sqlite3.connect(database)
        try:
            row = connection.execute("SELECT value FROM edge_state WHERE key='snapshot_hash'").fetchone()
            if row:
                snapshot_hash = row[0]
            state = dict(connection.execute(
                "SELECT key,value FROM edge_state WHERE key IN ('last_order_sync_at','last_reconciliation_at')"
            ).fetchall())
            order_sync = state.get("last_order_sync_at", "never")
            reconciliation = state.get("last_reconciliation_at", "never")
            pending_orders = connection.execute("SELECT COUNT(*) FROM orders WHERE synced_at IS NULL").fetchone()[0]
            pending_events = connection.execute("SELECT COUNT(*) FROM edge_order_events WHERE synced_at IS NULL").fetchone()[0]
        except sqlite3.Error:
            snapshot_hash = "unreadable"
        finally:
            connection.close()
    print(f"Vendor: {config['vendor_slug']}")
    print(f"Device: {config['device_id']}")
    print(f"Snapshot: {snapshot_hash}")
    print(f"Last order sync: {order_sync}")
    print(f"Last reconciliation: {reconciliation}")
    print(f"Pending: {pending_orders} orders, {pending_events} events")


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
    reconcile_parser = subcommands.add_parser("reconcile", help="Resend and verify all local orders and staff events.")
    reconcile_parser.set_defaults(handler=reconcile)
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
