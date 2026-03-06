<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

// Verificar autenticación
if (!$auth->isAuthenticated()) {
    header('Location: ../login.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

// Obtener histórico de reportes exitosos
$reportes_exitosos = [];
try {
    $query = "SELECT * FROM entregas_reportes_exitosos WHERE tipo_registro = 'REPORTE_ENTREGA' ORDER BY fecha_registro DESC LIMIT 100";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $reportes_exitosos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener reportes exitosos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Reportes Exitosos - MIPRES</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid #e5e5e5;
        }
        
        .table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 13px;
        }
        
        .table tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .no-data {
            text-align: center;
            padding: 32px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container-layout">
        <?php include '../includes/auth.php'; ?>
        
        <div class="main-content">
            <div class="card">
                <h2>Histórico de Reportes Exitosos</h2>
                <p class="card-subtitle">Registro de todos los reportes de entrega exitosos registrados en el sistema</p>
                
                <?php if (empty($reportes_exitosos)): ?>
                    <div class="no-data">
                        <p>No hay reportes exitosos registrados aún.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                    <th>NIT</th>
                                    <th>No. Prescripción</th>
                                    <th>Estado Entrega</th>
                                    <th>Valor Entregado</th>
                                    <th>ID Entrega</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportes_exitosos as $reporte): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i', strtotime($reporte['fecha_registro'])); ?></td>
                                        <td><?php echo htmlspecialchars($reporte['nombre_usuario'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($reporte['nit'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($reporte['no_prescripcion'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($reporte['estado_entrega'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($reporte['valor_entregado'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($reporte['id_entrega'] ?? 'N/A'); ?></td>
                                        <td><span class="badge badge-success">Exitoso</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
