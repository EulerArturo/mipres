-- Crear tabla para guardar estado de direccionamientos
CREATE TABLE IF NOT EXISTS direccionamientos_estado (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_direccionamiento INT NOT NULL,
    no_prescripcion VARCHAR(100) NOT NULL,
    direccionado TINYINT(1) DEFAULT 0,
    usuario_id INT NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_direccionamiento (id_direccionamiento),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_prescripcion (no_prescripcion),
    INDEX idx_direccionado (direccionado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
