-- Keep fulfilment staff and vendor administrators as separate key roles.
ALTER TABLE edge_staff_access_keys
    ADD COLUMN IF NOT EXISTS portal_role ENUM('staff','vendor_admin') NOT NULL DEFAULT 'staff' AFTER username;
