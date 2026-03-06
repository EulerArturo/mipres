<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

$auth = new Auth();

// Verificar autenticación
if (!$auth->isAuthenticated()) {
    header('Location: ../login.php');
    exit;
}

// Verificar que sea administrador
if (!$auth->isAdmin()) {
    header('Location: ../dashboard.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$mensaje = '';
$tipo_mensaje = '';
$entregas_exitosas = [];
$reportes_exitosos = [];
$total_entregas = 0;
$total_reportes = 0;

// Procesar filtros
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$tipo_reporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : 'todos';

// Obtener entregas exitosas
try {
    $query = "SELECT * FROM entregas_reportes_exitosos WHERE tipo_registro = 'ENTREGA'";
    $params = [];
    
    if (!empty($fecha_inicio)) {
        $query .= " AND DATE(fecha_registro) >= ?";
        $params[] = $fecha_inicio;
    }
    if (!empty($fecha_fin)) {
        $query .= " AND DATE(fecha_registro) <= ?";
        $params[] = $fecha_fin;
    }
    
    $query .= " ORDER BY fecha_registro DESC LIMIT 10000";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $entregas_exitosas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_entregas = count($entregas_exitosas);
} catch (PDOException $e) {
    $mensaje = "Error al obtener entregas: " . $e->getMessage();
    $tipo_mensaje = 'error';
}

// Obtener reportes exitosos
try {
    $query = "SELECT * FROM entregas_reportes_exitosos WHERE tipo_registro = 'REPORTE_ENTREGA'";
    $params = [];
    
    if (!empty($fecha_inicio)) {
        $query .= " AND DATE(fecha_registro) >= ?";
        $params[] = $fecha_inicio;
    }
    if (!empty($fecha_fin)) {
        $query .= " AND DATE(fecha_registro) <= ?";
        $params[] = $fecha_fin;
    }
    
    $query .= " ORDER BY fecha_registro DESC LIMIT 10000";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $reportes_exitosos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_reportes = count($reportes_exitosos);
} catch (PDOException $e) {
    $mensaje = "Error al obtener reportes: " . $e->getMessage();
    $tipo_mensaje = 'error';
}

// Procesar descarga CSV
if (isset($_GET['descargar']) && $_GET['descargar'] === 'entregas') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="entregas_exitosas_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para UTF-8
    
    // Encabezados
    fputcsv($output, [
        'ID',
        'Usuario ID',
        'NIT',
        'Código Servicio',
        'Cantidad Entregada',
        'Causa No Entrega',
        'Tipo ID Recibe',
        'Número ID Recibe',
        'Lote',
        'Fecha y Hora Registro',
        'HTTP Code'
    ], ',');
    
    // Datos
    foreach ($entregas_exitosas as $entrega) {
        fputcsv($output, [
            $entrega['id'],
            $entrega['usuario_id'],
            $entrega['nit'],
            $entrega['cod_servicio'],
            $entrega['cantidad_entregada'],
            $entrega['causa_no_entrega'],
            $entrega['tipo_id_recibe'],
            $entrega['numero_id_recibe'],
            $entrega['lote'],
            $entrega['fecha_registro'],
            $entrega['http_code']
        ], ',');
    }
    
    fclose($output);
    exit;
}

// Procesar descarga CSV reportes
if (isset($_GET['descargar']) && $_GET['descargar'] === 'reportes') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reportes_exitosos_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para UTF-8
    
    // Encabezados
    fputcsv($output, [
        'ID',
        'Usuario ID',
        'NIT',
        'ID Entrega',
        'Estado Entrega',
        'Causa No Entrega',
        'Valor Entregado',
        'Fecha y Hora Registro',
        'HTTP Code'
    ], ',');
    
    // Datos
    foreach ($reportes_exitosos as $reporte) {
        fputcsv($output, [
            $reporte['id'],
            $reporte['usuario_id'],
            $reporte['nit'],
            $reporte['id_entrega'],
            $reporte['estado_entrega'],
            $reporte['causa_no_entrega'],
            $reporte['valor_entregado'],
            $reporte['fecha_registro'],
            $reporte['http_code']
        ], ',');
    }
    
    fclose($output);
    exit;
}

registrar_log_actividad($pdo, $usuario['id'], 'CONSULTAR_INFORMES', 'Acceso a módulo de informes');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informes - Sistema MIPRES</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .informes-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .filtros-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filtro-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 15px;
        }
        
        .filtro-grupo {
            flex: 1;
            min-width: 200px;
        }
        
        .filtro-grupo label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .filtro-grupo input,
        .filtro-grupo select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .btn-filtrar {
            background: #0066cc;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
        }
        
        .btn-filtrar:hover {
            background: #0052a3;
        }
        
        .btn-limpiar {
            background: #ccc;
            color: #333;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-limpiar:hover {
            background: #999;
        }
        
        .reportes-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .reportes-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .reporte-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .reporte-card h3 {
            margin-top: 0;
            color: #0066cc;
            border-bottom: 2px solid #0066cc;
            padding-bottom: 10px;
        }
        
        .reporte-stats {
            margin: 15px 0;
            font-size: 24px;
            font-weight: bold;
            color: #27ae60;
        }
        
        .reporte-fecha {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .btn-descargar {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
            transition: background 0.3s;
        }
        
        .btn-descargar:hover {
            background: #229954;
        }
        
        .btn-descargar:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .tabla-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        table thead {
            background: #f5f5f5;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        table th {
            font-weight: bold;
            color: #333;
        }
        
        table tbody tr:hover {
            background: #f9f9f9;
        }
        
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #1976d2;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #388e3c;
            border: 1px solid #388e3c;
        }
        
        .header-info {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .header-info h2 {
            margin-top: 0;
            color: #333;
        }
    </style>
</head>
<body>
    <header>
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>Sistema MIPRES - Informes</h1>
            </div>
            <ul class="navbar-menu">
                <li><a href="../dashboard.php">Volver al Dashboard</a></li>
                <li><a href="../logout.php">Cerrar Sesión</a></li>
            </ul>
        </nav>
    </header>

    <main class="informes-container">
        <div class="header-info">
            <h2>Consulta de Informes de Entregas y Reportes</h2>
            <p>Usuario: <strong><?php echo htmlspecialchars($usuario['correo']); ?></strong></p>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>

        <!-- Sección de Filtros -->
        <div class="filtros-section">
            <h3>Filtros de Búsqueda</h3>
            <form method="GET" class="filtro-form">
                <div class="filtro-row">
                    <div class="filtro-grupo">
                        <label for="fecha_inicio">Fecha Inicio:</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                    </div>
                    <div class="filtro-grupo">
                        <label for="fecha_fin">Fecha Fin:</label>
                        <input type="date" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                    </div>
                    <div style="flex: 1; min-width: 200px; display: flex; gap: 10px;">
                        <button type="submit" class="btn-filtrar">Filtrar</button>
                        <a href="consultar_informes.php" class="btn-limpiar" style="text-decoration: none; display: inline-block;">Limpiar</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Cards de Resumen -->
        <div class="reportes-grid">
            <div class="reporte-card">
                <h3>📦 Entregas Exitosas</h3>
                <div class="reporte-fecha">Registradas en el período seleccionado</div>
                <div class="reporte-stats"><?php echo $total_entregas; ?></div>
                <button class="btn-descargar" onclick="window.location.href='?descargar=entregas<?php echo !empty($fecha_inicio) ? '&fecha_inicio=' . urlencode($fecha_inicio) : ''; ?><?php echo !empty($fecha_fin) ? '&fecha_fin=' . urlencode($fecha_fin) : ''; ?>';" <?php echo $total_entregas == 0 ? 'disabled' : ''; ?>>
                    Descargar CSV
                </button>
            </div>

            <div class="reporte-card">
                <h3>📋 Reportes Exitosos</h3>
                <div class="reporte-fecha">Registrados en el período seleccionado</div>
                <div class="reporte-stats"><?php echo $total_reportes; ?></div>
                <button class="btn-descargar" onclick="window.location.href='?descargar=reportes<?php echo !empty($fecha_inicio) ? '&fecha_inicio=' . urlencode($fecha_inicio) : ''; ?><?php echo !empty($fecha_fin) ? '&fecha_fin=' . urlencode($fecha_fin) : ''; ?>';" <?php echo $total_reportes == 0 ? 'disabled' : ''; ?>>
                    Descargar CSV
                </button>
            </div>
        </div>

        <!-- Tabla de Entregas Exitosas -->
        <div class="tabla-container" style="margin-bottom: 30px;">
            <h3>Entregas Exitosas - Últimos Registros</h3>
            <?php if ($total_entregas > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>NIT</th>
                            <th>Código Servicio</th>
                            <th>Cantidad</th>
                            <th>Lote</th>
                            <th>Fecha y Hora</th>
                            <th>Facturación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($entregas_exitosas, 0, 50) as $entrega): ?>
                            <tr>
                                <td><?php echo $entrega['id']; ?></td>
                                <td><?php echo htmlspecialchars($entrega['nit']); ?></td>
                                <td><?php echo htmlspecialchars($entrega['cod_servicio']); ?></td>
                                <td><?php echo htmlspecialchars($entrega['cantidad_entregada']); ?></td>
                                <td><?php echo htmlspecialchars($entrega['lote'] ?? '-'); ?></td>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($entrega['fecha_registro'])); ?></td>
                                <td>
                                    <a href="../facturacion/realizar_facturacion.php?source_id=<?php echo urlencode($entrega['id']); ?>&tipo_tec=M">Prellenar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_entregas > 50): ?>
                    <div class="alert-info" style="margin-top: 15px;">
                        Mostrando 50 de <?php echo $total_entregas; ?> entregas. Descarga el CSV para ver todos los registros.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    No hay entregas exitosas registradas en el período seleccionado.
                </div>
            <?php endif; ?>
        </div>

        <!-- Tabla de Reportes Exitosos -->
        <div class="tabla-container">
            <h3>Reportes Exitosos - Últimos Registros</h3>
            <?php if ($total_reportes > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>NIT</th>
                            <th>ID Entrega</th>
                            <th>Estado Entrega</th>
                            <th>Valor Entregado</th>
                            <th>Fecha y Hora</th>
                            <th>Facturación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($reportes_exitosos, 0, 50) as $reporte): ?>
                            <tr>
                                <td><?php echo $reporte['id']; ?></td>
                                <td><?php echo htmlspecialchars($reporte['nit']); ?></td>
                                <td><?php echo htmlspecialchars($reporte['id_entrega']); ?></td>
                                <td><?php echo htmlspecialchars($reporte['estado_entrega']); ?></td>
                                <td><?php echo htmlspecialchars($reporte['valor_entregado'] ?? '-'); ?></td>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($reporte['fecha_registro'])); ?></td>
                                <td>
                                    <a href="../facturacion/realizar_facturacion.php?source_id=<?php echo urlencode($reporte['id']); ?>&tipo_tec=M">Prellenar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_reportes > 50): ?>
                    <div class="alert-info" style="margin-top: 15px;">
                        Mostrando 50 de <?php echo $total_reportes; ?> reportes. Descarga el CSV para ver todos los registros.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    No hay reportes exitosos registrados en el período seleccionado.
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer style="text-align: center; padding: 20px; margin-top: 40px; color: #666; border-top: 1px solid #ddd;">
        <p>&copy; 2024 Sistema MIPRES. Todos los derechos reservados.</p>
    </footer>
</body>
</html>
