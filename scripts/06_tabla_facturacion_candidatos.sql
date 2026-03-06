-- Script de candidatos automaticos para facturacion (MVP)
-- Ejecutar sobre mipres_db

USE mipres_db;

CREATE TABLE IF NOT EXISTS facturacion_candidatos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_tipo ENUM('ENTREGA','REPORTE_ENTREGA') NOT NULL,
    source_id INT NOT NULL,
    source_fecha DATETIME NULL,
    no_prescripcion VARCHAR(20) DEFAULT NULL,
    tipo_tec VARCHAR(1) DEFAULT NULL,
    con_tec VARCHAR(2) DEFAULT NULL,
    tipo_id_paciente VARCHAR(2) DEFAULT NULL,
    no_id_paciente VARCHAR(17) DEFAULT NULL,
    no_entrega VARCHAR(4) DEFAULT NULL,
    no_sub_entrega VARCHAR(2) DEFAULT NULL,
    no_factura VARCHAR(96) DEFAULT NULL,
    no_id_eps VARCHAR(17) DEFAULT NULL,
    cod_eps VARCHAR(6) DEFAULT NULL,
    cod_ser_tec_entregado VARCHAR(20) DEFAULT NULL,
    cant_un_min_dis DECIMAL(16,4) DEFAULT NULL,
    valor_unit_facturado DECIMAL(16,2) DEFAULT NULL,
    valor_tot_facturado DECIMAL(16,2) DEFAULT NULL,
    cuota_moderadora DECIMAL(16,2) DEFAULT 0.00,
    copago DECIMAL(16,2) DEFAULT 0.00,
    dir_paciente VARCHAR(80) DEFAULT NULL,
    campos_faltantes TEXT,
    porcentaje_completitud INT DEFAULT 0,
    semaforo VARCHAR(10) DEFAULT 'ROJO',
    estado VARCHAR(20) DEFAULT 'pendiente',
    facturacion_id INT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source (source_tipo, source_id),
    INDEX idx_estado (estado),
    INDEX idx_semaforo (semaforo),
    INDEX idx_completitud (porcentaje_completitud)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
