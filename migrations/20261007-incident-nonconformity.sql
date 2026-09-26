-- Olaydan uygunsuzluk baglantisi: nonconformities.incident_id
ALTER TABLE nonconformities
    ADD COLUMN IF NOT EXISTS incident_id INT NULL,
    ADD INDEX IF NOT EXISTS idx_nc_incident (incident_id);
