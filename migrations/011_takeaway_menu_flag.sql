-- Products appear on the takeaway menu by default and can be disabled individually.
ALTER TABLE menu_items
    ADD COLUMN IF NOT EXISTS takeaway_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER variant_options;

-- Bricklane's alcoholic drinks remain available for table service, not public takeaway.
UPDATE menu_items m
JOIN restaurants r ON r.id=m.restaurant_id
SET m.takeaway_enabled=0
WHERE r.slug='bricklane-eatery'
  AND (
    m.category IN ('Beers & Ciders','Cocktails','Signature Shooters','Classic Shooters','Spirits','Liqueurs','Bubbly')
    OR m.category LIKE 'Wine - %'
  );
