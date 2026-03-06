-- Crear tabla de logs de actividad usada por múltiples módulos PHP
CREATE TABLE IF NOT EXISTS logs_actividad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    accion VARCHAR(120) NOT NULL,
    detalles TEXT NULL,
    ip_address VARCHAR(45) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_accion (accion),
    INDEX idx_fecha (fecha),
    CONSTRAINT fk_logs_actividad_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
