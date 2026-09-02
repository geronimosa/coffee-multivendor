ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS vendor_description MEDIUMTEXT NULL AFTER storefront_message;
