-- Teslimat reddinden uygunsuzluk baglantisi: nonconformities.delivery_id
ALTER TABLE nonconformities
    ADD COLUMN IF NOT EXISTS delivery_id INT NULL,
    ADD INDEX IF NOT EXISTS idx_nc_delivery (delivery_id);
