import importlib.util
import hashlib
import hmac
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch


AGENT_PATH = Path(__file__).resolve().parents[1] / "agent.py"
SPEC = importlib.util.spec_from_file_location("qrkiosk_edge_agent", AGENT_PATH)
agent = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(agent)


class SnapshotTests(unittest.TestCase):
    def test_snapshot_is_applied_atomically(self):
        snapshot = {
            "schema_version": 1,
            "generated_at": "2026-09-02T20:00:00Z",
            "snapshot_hash": "a" * 64,
            "vendor": {
                "id": 7,
                "name": "Test Coffee",
                "slug": "test-coffee",
                "status": "active",
                "contact_email": None,
                "contact_phone": None,
                "theme_primary": "#112233",
                "theme_accent": "#445566",
                "theme_background": "#ffffff",
                "theme_surface": "#ffffff",
                "theme_text": "#111111",
                "logo_path": None,
                "hero_path": None,
                "storefront_message": "Order here",
                "vendor_description": "<p>Hello</p>",
                "updated_at": "2026-09-02 20:00:00",
            },
            "menu_items": [
                {"id": 11, "name": "Flat White", "category": "Coffee", "price": "32.00", "variants": []}
            ],
        }
        with tempfile.TemporaryDirectory() as directory:
            connection = agent.initialize_database(Path(directory))
            agent.apply_snapshot(connection, snapshot, "test-coffee")
            vendor = connection.execute("SELECT remote_id, slug FROM restaurants").fetchone()
            item = connection.execute("SELECT id, name, price, variant_options FROM menu_items").fetchone()
            state = connection.execute("SELECT value FROM edge_state WHERE key='snapshot_hash'").fetchone()
            connection.close()
        self.assertEqual(vendor, (7, "test-coffee"))
        self.assertEqual(item, (11, "Flat White", "32.00", json.dumps([], separators=(",", ":"))))
        self.assertEqual(state, ("a" * 64,))

    def test_wrong_vendor_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            connection = agent.initialize_database(Path(directory))
            with self.assertRaises(agent.EdgeError):
                agent.apply_snapshot(
                    connection,
                    {"schema_version": 1, "vendor": {"slug": "other"}, "menu_items": []},
                    "expected",
                )
            count = connection.execute("SELECT COUNT(*) FROM restaurants").fetchone()[0]
            connection.close()
        self.assertEqual(count, 0)

    def test_order_upload_acknowledgement_is_idempotent_locally(self):
        with tempfile.TemporaryDirectory() as directory:
            connection = agent.initialize_database(Path(directory))
            connection.row_factory = sqlite3.Row
            agent.apply_snapshot(connection, {
                "schema_version": 1,
                "vendor": {
                    "id": 7, "name": "Test", "slug": "test", "status": "active",
                    "theme_primary": "#111111", "theme_accent": "#222222", "theme_background": "#ffffff",
                    "theme_surface": "#ffffff", "theme_text": "#111111",
                },
                "menu_items": [{"id": 11, "name": "Coffee", "category": "Coffee", "price": "20.00", "variants": []}],
            }, "test")
            connection.execute(
                "INSERT INTO orders (order_uuid,origin_device_id,customer_name,customer_phone,total,status,payment_method,payment_status,status_token,created_at,updated_at) "
                "VALUES (?,?,?,?,?,'pending','manual','unpaid',?,?,?)",
                ("11111111-1111-4111-8111-111111111111", "a" * 32, "Customer", "0700000000", "20.00", "token", "2026-09-02T20:00:00+00:00", "2026-09-02T20:00:00+00:00"),
            )
            connection.execute(
                "INSERT INTO order_items (order_id,remote_menu_item_id,item_name,variant_label,quantity,unit_price,subtotal) VALUES (1,11,'Coffee','Standard',1,'20.00','20.00')"
            )
            connection.commit()
            config = {"server": "https://example.test", "device_id": "a" * 32, "device_secret": "secret"}

            def accepted_request(_url, method="GET", payload=None, headers=None):
                self.assertEqual(method, "POST")
                self.assertEqual(len(payload["orders"]), 1)
                response = json.dumps({"accepted": {"orders": [payload["orders"][0]["order_uuid"]], "events": []}}, separators=(",", ":")).encode()
                return 200, {"X-QRKiosk-Signature": hmac.new(b"secret", response, hashlib.sha256).hexdigest()}, response

            with patch.object(agent, "api_request", side_effect=accepted_request) as request:
                self.assertEqual(agent.upload_orders(connection, config), (1, 0))
                self.assertEqual(agent.upload_orders(connection, config), (0, 0))
                self.assertEqual(request.call_count, 1)
            self.assertIsNotNone(connection.execute("SELECT synced_at FROM orders WHERE id=1").fetchone()[0])
            connection.close()


if __name__ == "__main__":
    unittest.main()
