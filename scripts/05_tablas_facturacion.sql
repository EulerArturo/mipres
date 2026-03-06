-- Script de soporte para modulo de facturacion (MVP)
-- Ejecutar sobre mipres_db

USE mipres_db;

CREATE TABLE IF NOT EXISTS facturaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    nombre_usuario VARCHAR(255) DEFAULT NULL,
    no_prescripcion VARCHAR(20) NOT NULL,
    tipo_tec VARCHAR(1) NOT NULL,
    con_tec VARCHAR(2) NOT NULL,
    tipo_id_paciente VARCHAR(2) NOT NULL,
    no_id_paciente VARCHAR(17) NOT NULL,
    no_entrega VARCHAR(4) NOT NULL,
    no_sub_entrega VARCHAR(2) NOT NULL,
    no_factura VARCHAR(96) NOT NULL,
    no_id_eps VARCHAR(17) NOT NULL,
    cod_eps VARCHAR(6) NOT NULL,
    cod_ser_tec_entregado VARCHAR(20) NOT NULL,
    cant_un_min_dis DECIMAL(16,4) NOT NULL,
    valor_unit_facturado DECIMAL(16,2) NOT NULL,
    valor_tot_facturado DECIMAL(16,2) NOT NULL,
    cuota_moderadora DECIMAL(16,2) DEFAULT 0.00,
    copago DECIMAL(16,2) DEFAULT 0.00,
    dir_paciente VARCHAR(80) DEFAULT NULL,
    token_temporal VARCHAR(255) NOT NULL,
    estado VARCHAR(50) DEFAULT 'pendiente',
    respuesta_api LONGTEXT DEFAULT NULL,
    http_code INT DEFAULT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fact_usuario (usuario_id),
    INDEX idx_fact_prescripcion (no_prescripcion),
    INDEX idx_fact_eps (no_id_eps),
    INDEX idx_fact_paciente (no_id_paciente),
    INDEX idx_fact_fecha (fecha_registro),
    INDEX idx_fact_estado (estado),
    CONSTRAINT fk_facturacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facturaciones_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facturacion_id INT NULL,
    usuario_id INT NOT NULL,
    accion VARCHAR(100) NOT NULL,
    detalles TEXT,
    ip_address VARCHAR(45),
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_factlog_facturacion (facturacion_id),
    INDEX idx_factlog_usuario (usuario_id),
    INDEX idx_factlog_fecha (fecha),
    CONSTRAINT fk_factlog_facturacion FOREIGN KEY (facturacion_id) REFERENCES facturaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_factlog_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
