-- Sikayet kaynagi uygunsuzluk olusturabilsin diye nonconformities.audit_id NULL yapilir
-- ve kaynagi belirten 'source' sutunu eklenir.
-- Denetime bagli uygunsuzluklar eskisi gibi CASCADE ile silinir; sikayet kaynakli
-- uygunsuzluklarin audit_id degeri NULL olur ve denetimden bagimsiz yasar.
ALTER TABLE nonconformities DROP FOREIGN KEY fk_nonconformities_audit;
