PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS edge_state (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS restaurants (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    remote_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL,
    contact_email TEXT,
    contact_phone TEXT,
    theme_primary TEXT NOT NULL,
    theme_accent TEXT NOT NULL,
    theme_background TEXT NOT NULL,
    theme_surface TEXT NOT NULL,
    theme_text TEXT NOT NULL,
    logo_path TEXT,
    hero_path TEXT,
    storefront_message TEXT,
    vendor_description TEXT,
    remote_updated_at TEXT
);

CREATE TABLE IF NOT EXISTS menu_items (
    id INTEGER PRIMARY KEY,
    restaurant_id INTEGER NOT NULL DEFAULT 1 REFERENCES restaurants(id),
    name TEXT NOT NULL,
    category TEXT,
    price TEXT NOT NULL,
    variant_options TEXT NOT NULL DEFAULT '[]'
);

CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_uuid TEXT NOT NULL UNIQUE,
    restaurant_id INTEGER NOT NULL DEFAULT 1 REFERENCES restaurants(id),
    origin_type TEXT NOT NULL DEFAULT 'edge' CHECK (origin_type = 'edge'),
    origin_device_id TEXT NOT NULL,
    customer_name TEXT,
    customer_phone TEXT,
    total TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    payment_method TEXT,
    payment_status TEXT NOT NULL DEFAULT 'unpaid',
    payment_reference TEXT,
    paid_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    synced_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_edge_orders_sync ON orders(synced_at, updated_at);

CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    remote_menu_item_id INTEGER NOT NULL,
    item_name TEXT NOT NULL,
    variant_label TEXT,
    quantity INTEGER NOT NULL,
    unit_price TEXT NOT NULL,
    subtotal TEXT NOT NULL
);
