import importlib.util
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import tempfile
import unittest

try:
    import flask  # noqa: F401
    FLASK_AVAILABLE = True
except ImportError:
    FLASK_AVAILABLE = False

EDGE_DIR = Path(__file__).resolve().parents[1]
STAFF_CODE = "staff_test-access-code"


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@unittest.skipUnless(FLASK_AVAILABLE, "Flask is not installed in this test environment")
class StorefrontTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.data_dir = Path(self.temporary.name)
        (self.data_dir / "device.json").write_text(
            json.dumps(
                {
                    "server": "https://coffee.tatu.co.za",
                    "device_id": "a" * 32,
                    "device_secret": "test-secret",
                    "vendor_slug": "test-coffee",
                }
            ),
            encoding="utf-8",
        )
        os.environ["QRKIOSK_EDGE_DATA_DIR"] = str(self.data_dir)
        self.agent = load_module("edge_agent_web_test", EDGE_DIR / "agent.py")
        connection = self.agent.initialize_database(self.data_dir)
        self.agent.apply_snapshot(
            connection,
            {
                "schema_version": 1,
                "generated_at": "2026-09-02T20:00:00Z",
                "snapshot_hash": "b" * 64,
                "staff_access_keys": [{
                    "id": 91,
                    "username": "test.staff",
                    "key_hash": hashlib.sha256(STAFF_CODE.encode("utf-8")).hexdigest(),
                    "expires_at_epoch": 2_000_000_000,
                }],
                "vendor": {
                    "id": 7, "name": "Test Coffee", "slug": "test-coffee", "status": "active",
                    "contact_email": None, "contact_phone": None, "theme_primary": "#112233",
                    "theme_accent": "#445566", "theme_background": "#ffffff",
                    "theme_surface": "#ffffff", "theme_text": "#111111", "logo_path": None,
                    "hero_path": None, "storefront_message": "Order here",
                    "vendor_description": "<p>Hello</p>", "updated_at": "2026-09-02 20:00:00",
                },
                "menu_items": [
                    {"id": 11, "name": "Flat White", "category": "Coffee", "price": "32.00", "variants": []}
                ],
            },
            "test-coffee",
        )
        connection.close()
        self.web = load_module("edge_web_test", EDGE_DIR / "web.py")
        self.web.app.config.update(TESTING=True)
        self.client = self.web.app.test_client()

    def tearDown(self):
        os.environ.pop("QRKIOSK_EDGE_DATA_DIR", None)
        self.temporary.cleanup()

    def csrf(self, response) -> str:
        match = re.search(rb'name="csrf_token" value="([^"]+)"', response.data)
        self.assertIsNotNone(match)
        return match.group(1).decode()

    def create_order(self):
        shop = self.client.get("/shop/test-coffee")
        self.client.post(
            "/cart.php",
            data={
                "csrf_token": self.csrf(shop), "slug": "test-coffee",
                "variant_11": "Standard", "quantity_11": "2", "note_11": "Extra hot",
            },
        )
        checkout = self.client.get("/checkout.php")
        return self.client.post(
            "/confirm_order.php",
            data={"csrf_token": self.csrf(checkout), "name": "Test Customer", "phone": "0700000000"},
            follow_redirects=True,
        )

    def test_local_manual_order_is_recorded_with_edge_ownership(self):
        order = self.create_order()
        self.assertEqual(order.status_code, 200)
        self.assertIn(b"Pay at counter", order.data)
        connection = sqlite3.connect(self.data_dir / "edge.db")
        saved = connection.execute(
            "SELECT origin_type,origin_device_id,total,payment_method,payment_status FROM orders"
        ).fetchone()
        connection.close()
        self.assertEqual(saved, ("edge", "a" * 32, "64.00", "manual", "unpaid"))
        note = sqlite3.connect(self.data_dir / "edge.db").execute("SELECT item_note FROM order_items").fetchone()[0]
        self.assertEqual(note, "Extra hot")

    def test_staff_can_fulfil_manual_order_and_key_revocation_ends_session(self):
        self.create_order()
        login = self.client.get("/vendor/test-coffee")
        self.assertIn(b"Staff key", login.data)
        rejected = self.client.post(
            "/vendor/test-coffee",
            data={"csrf_token": self.csrf(login), "access_code": "wrong"},
        )
        self.assertIn(b"incorrect", rejected.data)
        accepted = self.client.post(
            "/vendor/test-coffee",
            data={"csrf_token": self.csrf(rejected), "access_code": STAFF_CODE},
            follow_redirects=True,
        )
        self.assertIn(b"Fulfilment queue", accepted.data)
        self.assertIn(b"Test Customer", accepted.data)
        self.assertIn(b"Tap order for full till slip", accepted.data)
        self.assertIn(b"2 \xc3\x97 Flat White", accepted.data)
        self.assertIn(b"R64.00", accepted.data)
        self.assertIn(b"Special instruction: Extra hot", accepted.data)
        self.assertIn(b'receipt-dialog', accepted.data)
        self.assertNotIn(b"Sync now", accepted.data)
        admin = self.client.get("/vendor/test-coffee?tab=admin")
        self.assertIn(b"Central sync", admin.data)
        self.assertIn(b"Sync now", admin.data)

        for action in ("preparing", "confirm_payment", "complete", "collected", "archived"):
            page = self.client.get("/vendor/test-coffee")
            response = self.client.post(
                "/vendor/test-coffee/order",
                data={"csrf_token": self.csrf(page), "order_id": "1", "action": action},
            )
            self.assertEqual(response.status_code, 303)

        connection = sqlite3.connect(self.data_dir / "edge.db")
        saved = connection.execute("SELECT status,payment_status,paid_at FROM orders WHERE id=1").fetchone()
        events = connection.execute(
            "SELECT actor,remote_staff_key_id FROM edge_order_events WHERE order_id=1 ORDER BY id"
        ).fetchall()
        connection.execute("DELETE FROM edge_staff_access_keys WHERE id=91")
        connection.commit()
        connection.close()
        self.assertEqual(saved[:2], ("archived", "paid"))
        self.assertIsNotNone(saved[2])
        self.assertEqual(events, [("test.staff", 91)] * 5)

        revoked = self.client.get("/vendor/test-coffee")
        self.assertIn(b"Staff access has not been configured", revoked.data)
        self.assertNotIn(b"Fulfilment queue", revoked.data)

    def test_other_vendor_slug_is_rejected(self):
        self.assertEqual(self.client.get("/shop/another-vendor").status_code, 404)


if __name__ == "__main__":
    unittest.main()
