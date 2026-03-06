-- Script para actualizar la contraseña del administrador
-- Ejecuta este script DESPUÉS de ejecutar generar_hash.php
-- Reemplaza 'HASH_GENERADO' con el hash que obtuviste del script PHP

-- Primero, elimina el usuario admin si existe
DELETE FROM usuarios WHERE username = 'admin';

-- Luego inserta el usuario con el hash correcto
-- IMPORTANTE: Reemplaza el hash con el que generaste en generar_hash.php
INSERT INTO usuarios (username, password, nombre_completo, email, rol) 
VALUES ('admin', 'HASH_GENERADO_AQUI', 'Administrador', 'admin@mipres.com', 'administrador');
