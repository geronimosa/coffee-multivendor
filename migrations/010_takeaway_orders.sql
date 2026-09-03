-- Standalone restaurant collection orders do not belong to an accumulating table tab.
ALTER TABLE orders
    MODIFY COLUMN service_type ENUM('kiosk','table','takeaway') NOT NULL DEFAULT 'kiosk';
