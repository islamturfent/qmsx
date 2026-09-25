-- CAPA faaliyet türü: duzeltici (corrective) ve onleyici (preventive).
-- Mevcut kayitlar 'corrective' olarak kalir.
ALTER TABLE corrective_actions
    ADD COLUMN action_type VARCHAR(20) NOT NULL DEFAULT 'corrective' AFTER nonconformity_id;
