-- ============================================
-- CREAR USUARIOS ADMIN - Naturalitos Acuario
-- Ejecutar en la DB de Hostinger
-- ============================================

-- Usuario: patroncitolencho
-- Email: patroncitolencho@naturalitos.com
-- Password: Patr0n_L3nch0!2026
-- Rol: admin
INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES
('Patroncito Lencho', 'patroncitolencho@naturalitos.com',
 '$2y$10$fl2OXxF6sdQtmDSSVD4LF.8MHkV0UKWAstTr11/jV2EpXXJ0JG3d6', 'admin');

-- Usuario: carlos
-- Email: carlos@naturalitos.com
-- Password: Carl0s_Natur@2026
-- Rol: admin
INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES
('Carlos', 'carlos@naturalitos.com',
 '$2y$10$P/0qQScSaF7RF1.jRZr.iO36bbNgbiZnKtC23uznQiOMFgvdUrrxW', 'admin');
