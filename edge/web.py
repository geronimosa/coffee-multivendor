#!/usr/bin/env python3
"""Local QRKiosk Edge storefront backed by the signed snapshot database."""

from __future__ import annotations

import json
import os
from pathlib import Path
import sqlite3

from flask import Flask, abort, g, jsonify, redirect, render_template


DATA_DIR = Path(os.environ.get("QRKIOSK_EDGE_DATA_DIR", "/var/lib/qrkiosk-edge"))
DATABASE = DATA_DIR / "edge.db"

app = Flask(__name__)


def database() -> sqlite3.Connection:
    if "database" not in g:
        if not DATABASE.is_file():
            raise RuntimeError("Edge snapshot database is unavailable.")
        connection = sqlite3.connect(f"file:{DATABASE}?mode=ro", uri=True)
        connection.row_factory = sqlite3.Row
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
