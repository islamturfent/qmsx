-- Denetci calisma yuzeyi: denetim yasam dongusu tarihleri.
ALTER TABLE audits ADD COLUMN IF NOT EXISTS started_at DATETIME NULL DEFAULT NULL;
ALTER TABLE audits ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL DEFAULT NULL;
