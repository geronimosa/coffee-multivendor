import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest


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


if __name__ == "__main__":
    unittest.main()
