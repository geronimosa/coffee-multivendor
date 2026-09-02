import importlib.util
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

    def test_local_manual_order_is_recorded_with_edge_ownership(self):
        shop = self.client.get("/shop/test-coffee")
        self.assertEqual(shop.status_code, 200)
        self.assertIn(b"quantity_11", shop.data)
        cart = self.client.post(
            "/cart.php",
            data={
                "csrf_token": self.csrf(shop), "slug": "test-coffee",
                "variant_11": "Standard", "quantity_11": "2",
            },
            follow_redirects=True,
        )
        self.assertIn(b"R64.00", cart.data)
        checkout = self.client.get("/checkout.php")
        order = self.client.post(
            "/confirm_order.php",
            data={"csrf_token": self.csrf(checkout), "name": "Test Customer", "phone": "0700000000"},
            follow_redirects=True,
        )
        self.assertEqual(order.status_code, 200)
        self.assertIn(b"Pay at counter", order.data)
        connection = sqlite3.connect(self.data_dir / "edge.db")
        saved = connection.execute(
            "SELECT origin_type,origin_device_id,total,payment_method,payment_status FROM orders"
        ).fetchone()
        connection.close()
        self.assertEqual(saved, ("edge", "a" * 32, "64.00", "manual", "unpaid"))

    def test_other_vendor_slug_is_rejected(self):
        self.assertEqual(self.client.get("/shop/another-vendor").status_code, 404)


if __name__ == "__main__":
    unittest.main()
