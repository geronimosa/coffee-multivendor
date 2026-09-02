#!/usr/bin/env python3
"""Local QRKiosk Edge storefront backed by the signed snapshot database."""

from __future__ import annotations

import json
import hashlib
import hmac
import os
from pathlib import Path
import secrets
import sqlite3
from datetime import datetime, timezone
from decimal import Decimal
import uuid

from flask import Flask, abort, g, jsonify, redirect, render_template, request, session, url_for


DATA_DIR = Path(os.environ.get("QRKIOSK_EDGE_DATA_DIR", "/var/lib/qrkiosk-edge"))
DATABASE = DATA_DIR / "edge.db"

EDGE_APP_DIR = Path(__file__).resolve().parent
app = Flask(
    __name__,
    template_folder=str(EDGE_APP_DIR / "templates"),
    static_folder=str(EDGE_APP_DIR / "static"),
)
app.config.update(
    SESSION_COOKIE_NAME="qrkiosk_edge_session",
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",
)


def device_config() -> dict:
    try:
        config = json.loads((DATA_DIR / "device.json").read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exception:
        raise RuntimeError("Edge device credentials are unavailable.") from exception
    for key in ("device_id", "device_secret", "vendor_slug"):
        if not isinstance(config.get(key), str) or not config[key]:
            raise RuntimeError("Edge device credentials are incomplete.")
    return config


DEVICE = device_config()
app.secret_key = hmac.new(
    DEVICE["device_secret"].encode("utf-8"), b"qrkiosk-edge-web-session-v1", hashlib.sha256
).digest()


def database() -> sqlite3.Connection:
    if "database" not in g:
        if not DATABASE.is_file():
            raise RuntimeError("Edge snapshot database is unavailable.")
        connection = sqlite3.connect(DATABASE, timeout=10)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute("PRAGMA busy_timeout = 10000")
        g.database = connection
    return g.database


@app.teardown_appcontext
def close_database(_exception=None) -> None:
    connection = g.pop("database", None)
    if connection is not None:
        connection.close()


@app.after_request
def edge_headers(response):
    response.headers["X-QRKiosk-Mode"] = "edge"
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "same-origin"
    return response


def assigned_vendor() -> sqlite3.Row:
    vendor = database().execute("SELECT * FROM restaurants WHERE id=1 AND status='active'").fetchone()
    if vendor is None:
        abort(503, "The local vendor snapshot is unavailable.")
    return vendor


def csrf_token() -> str:
    if "csrf_token" not in session:
        session["csrf_token"] = secrets.token_urlsafe(32)
    return session["csrf_token"]


def require_csrf() -> None:
    submitted = request.form.get("csrf_token", "")
    if not submitted or not hmac.compare_digest(submitted, csrf_token()):
        abort(400, "Your session expired. Please go back and try again.")


app.jinja_env.globals["csrf_token"] = csrf_token


def menu_item(item_id: int) -> sqlite3.Row | None:
    return database().execute(
        "SELECT id,name,category,price,variant_options FROM menu_items WHERE id=?", (item_id,)
    ).fetchone()


def selected_price(item: sqlite3.Row, variant_label: str) -> tuple[str, Decimal] | None:
    try:
        variants = json.loads(item["variant_options"] or "[]")
    except json.JSONDecodeError:
        variants = []
    if not variants:
        return "Standard", Decimal(str(item["price"]))
    for variant in variants:
        if str(variant.get("label", "")) == variant_label:
            return variant_label, Decimal(str(variant.get("price", "0")))
    return None


def verified_cart() -> list[dict]:
    verified = []
    for entry in session.get("cart", {}).values():
        item = menu_item(int(entry.get("item_id", 0)))
        if item is None:
            continue
        selection = selected_price(item, str(entry.get("variant", "")))
        quantity = max(1, min(20, int(entry.get("quantity", 1))))
        if selection is None:
            continue
        label, price = selection
        verified.append(
            {
                "key": f"{item['id']}:{label}", "item_id": item["id"], "name": item["name"],
                "variant": label, "quantity": quantity, "unit_price": price,
                "subtotal": price * quantity,
            }
        )
    return verified


@app.get("/")
def index():
    return redirect(f"/shop/{assigned_vendor()['slug']}", code=302)


@app.get("/shop/<slug>")
def storefront(slug: str):
    vendor = assigned_vendor()
    if slug != vendor["slug"]:
        abort(404)

    rows = database().execute(
        "SELECT id,name,category,price,variant_options FROM menu_items ORDER BY category,name,id"
    ).fetchall()
    groups: dict[str, list[dict]] = {}
    for row in rows:
        try:
            variants = json.loads(row["variant_options"] or "[]")
        except json.JSONDecodeError:
            variants = []
        groups.setdefault(row["category"] or "Menu", []).append({"row": row, "variants": variants})

    state = dict(database().execute("SELECT key,value FROM edge_state").fetchall())
    return render_template("storefront.html", vendor=vendor, groups=groups, state=state)


@app.post("/cart.php")
def add_to_cart():
    require_csrf()
    vendor = assigned_vendor()
    if request.form.get("slug") != vendor["slug"]:
        abort(404)
    cart = session.get("cart", {})
    for field, value in request.form.items():
        if not field.startswith("quantity_"):
            continue
        try:
            item_id = int(field.removeprefix("quantity_"))
            quantity = max(0, min(20, int(value)))
        except ValueError:
            continue
        if quantity == 0:
            continue
        item = menu_item(item_id)
        if item is None:
            continue
        selection = selected_price(item, request.form.get(f"variant_{item_id}", "Standard"))
        if selection is None:
            continue
        label, _price = selection
        key = f"{item_id}:{label}"
        cart[key] = {
            "item_id": item_id,
            "variant": label,
            "quantity": min(20, int(cart.get(key, {}).get("quantity", 0)) + quantity),
        }
    session["cart"] = cart
    session.modified = True
    return redirect(url_for("cart"), code=303)


@app.get("/cart.php")
def cart():
    vendor = assigned_vendor()
    items = verified_cart()
    total = sum((item["subtotal"] for item in items), Decimal("0"))
    return render_template("cart.html", vendor=vendor, items=items, total=total)


@app.post("/cart/remove")
def remove_from_cart():
    require_csrf()
    key = request.form.get("key", "")
    cart_data = session.get("cart", {})
    cart_data.pop(key, None)
    session["cart"] = cart_data
    session.modified = True
    return redirect(url_for("cart"), code=303)


@app.get("/checkout.php")
def checkout():
    vendor = assigned_vendor()
    items = verified_cart()
    if not items:
        return redirect(url_for("storefront", slug=vendor["slug"]), code=302)
    total = sum((item["subtotal"] for item in items), Decimal("0"))
    return render_template("checkout.html", vendor=vendor, items=items, total=total)


@app.post("/confirm_order.php")
def confirm_order():
    require_csrf()
    vendor = assigned_vendor()
    items = verified_cart()
    if not items:
        abort(400, "The cart is empty.")
    customer_name = request.form.get("name", "").strip()[:120]
    customer_phone = request.form.get("phone", "").strip()[:32]
    if not customer_name or not customer_phone:
        abort(400, "Name and phone number are required.")

    total = sum((item["subtotal"] for item in items), Decimal("0"))
    order_uuid = str(uuid.uuid4())
    status_token = secrets.token_urlsafe(24)
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    connection = database()
    try:
        connection.execute("BEGIN IMMEDIATE")
        cursor = connection.execute(
            "INSERT INTO orders (order_uuid,restaurant_id,origin_type,origin_device_id,customer_name,customer_phone,"
            "total,status,payment_method,payment_status,status_token,created_at,updated_at) "
            "VALUES (?,1,'edge',?,?,?,?,'pending','manual','unpaid',?,?,?)",
            (
                order_uuid, DEVICE["device_id"], customer_name, customer_phone, str(total),
                status_token, now, now,
            ),
        )
        order_id = cursor.lastrowid
        for item in items:
            connection.execute(
                "INSERT INTO order_items (order_id,remote_menu_item_id,item_name,variant_label,quantity,unit_price,subtotal) "
                "VALUES (?,?,?,?,?,?,?)",
                (
                    order_id, item["item_id"], item["name"], item["variant"], item["quantity"],
                    str(item["unit_price"]), str(item["subtotal"]),
                ),
            )
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    session.pop("cart", None)
    return redirect(url_for("order_status", token=status_token), code=303)


@app.get("/order_status.php")
def order_status():
    token = request.args.get("token", "")
    order = database().execute(
        "SELECT id,order_uuid,customer_name,total,status,payment_method,payment_status,created_at "
        "FROM orders WHERE status_token=? LIMIT 1", (token,)
    ).fetchone()
    if order is None:
        abort(404)
    items = database().execute(
        "SELECT item_name,variant_label,quantity,unit_price,subtotal FROM order_items WHERE order_id=? ORDER BY id",
        (order["id"],),
    ).fetchall()
    return render_template("order_status.html", vendor=assigned_vendor(), order=order, items=items)


@app.get("/health")
def health():
    try:
        vendor = assigned_vendor()
        state = dict(database().execute("SELECT key,value FROM edge_state").fetchall())
        return jsonify(
            status="ok",
            mode="edge",
            vendor_slug=vendor["slug"],
            snapshot_hash=state.get("snapshot_hash"),
            generated_at=state.get("generated_at"),
        )
    except (RuntimeError, sqlite3.Error):
        return jsonify(status="unavailable", mode="edge"), 503


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8090, debug=False)
