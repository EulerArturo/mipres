<?php

if (!function_exists('registrar_log_actividad')) {
    function registrar_log_actividad($pdo, $usuarioId, $accion, $detalles)
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles) VALUES (?, ?, ?)");
            $stmt->execute([$usuarioId, $accion, $detalles]);
        } catch (PDOException $e) {
            // Error al registrar log
        }
    }
}
