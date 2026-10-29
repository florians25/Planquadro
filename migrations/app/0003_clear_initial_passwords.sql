-- Planquadro 0003 – Klartext-Einmalpasswörter entfernen (Befund S-08)
-- Das Einmalpasswort bleibt über password_hash gültig, der Zwang zum
-- Passwortwechsel (must_change_password) bleibt unverändert. Die Spalte
-- selbst bleibt bestehen (Kompatibilität mit v6.9.0), wird aber nicht mehr
-- befüllt.
UPDATE `users` SET `initial_password` = NULL WHERE `initial_password` IS NOT NULL;
