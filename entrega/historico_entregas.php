<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Validar sesión
$auth = new Auth($pdo);
$usuario = $auth->getCurrentUser();
if (!$usuario) {
    header('Location: ../login.php');
    exit;
}

$isAdmin = $usuario['rol'] === 'admin';

// Consultar entregas exitosas
try {
    $stmt = $pdo->prepare("
        SELECT e.*, u.nombre_completo as nombre_usuario_tabla
        FROM entregas_reportes_exitosos e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        WHERE e.tipo_registro = 'ENTREGA'
        ORDER BY e.fecha_registro DESC
        LIMIT 200
    ");
    $stmt->execute();
    $entregas = $stmt->fetchAll();
} catch (PDOException $e) {
    $entregas = [];
    $error_msg = "Error al consultar: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historico Entregas - <?php echo APP_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif; background-color: #ffffff; color: #000000; }
        .header { background-color: #000000; color: #ffffff; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 20px; font-weight: 600; }
        .user-info { display: flex; align-items: center; gap: 16px; }
        .user-badge { background-color: #ffffff; color: #000000; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; }
        .logout-btn { background-color: #ffffff; color: #000000; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; text-decoration: none; }
        .layout { display: flex; min-height: calc(100vh - 73px); }
        .sidebar { width: 280px; background-color: #f8f9fa; border-right: 1px solid #e5e5e5; padding: 24px 0; }
        .sidebar-item { padding: 12px 24px; color: #000000; text-decoration: none; display: block; font-size: 14px; font-weight: 500; }
        .sidebar-item:hover { background-color: #e5e5e5; }
        .sidebar-item.active { background-color: #000000; color: #ffffff; }
        .sidebar-parent { display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .sidebar-children { display: none; background-color: #ffffff; }
        .sidebar-children.open { display: block; }
        .sidebar-child { padding-left: 48px; font-size: 13px; font-weight: 400; }
        .arrow { transition: transform 0.2s ease; font-size: 12px; }
        .arrow.open { transform: rotate(90deg); }
        .main-content { flex: 1; padding: 32px; background-color: #ffffff; }
        .card { background: #ffffff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 32px; margin-bottom: 24px; }
        .card h2 { color: #000000; margin-bottom: 8px; font-size: 24px; font-weight: 600; }
        .card-subtitle { color: #6c757d; font-size: 14px; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #000000; color: #ffffff; padding: 12px 8px; text-align: left; font-weight: 500; }
        td { padding: 10px 8px; border-bottom: 1px solid #e5e5e5; }
        tr:hover { background-color: #f8f9fa; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background-color: #d4edda; color: #155724; }
        .empty-state { text-align: center; padding: 48px; color: #6c757d; }
        .alert-error { background-color: #fff5f5; color: #000; border: 1px solid #000; padding: 16px; border-radius: 6px; margin-bottom: 24px; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo APP_NAME; ?></h1>
        <div class="user-info">
            <span class="user-badge"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></span>
            <a href="../logout.php" class="logout-btn">Cerrar Sesion</a>
        </div>
    </div>
    
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-section"><a href="../dashboard.php" class="sidebar-item">Inicio</a></div>
            <div class="sidebar-section"><a href="../token/generar_token.php" class="sidebar-item">Generacion de Token</a></div>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('direccionamiento')"><span>Direccionamiento</span><span class="arrow" id="arrow-direccionamiento">&#9654;</span></div>
                <div class="sidebar-children" id="menu-direccionamiento">
                    <a href="../direccionamiento/por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripcion</a>
                    <a href="../direccionamiento/por_fecha.php" class="sidebar-item sidebar-child">Por Fecha</a>
                    <a href="../direccionamiento/por_paciente.php" class="sidebar-item sidebar-child">Por paciente</a>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')"><span>Programacion</span><span class="arrow" id="arrow-programacion">&#9654;</span></div>
                <div class="sidebar-children" id="menu-programacion">
                    <a href="../programacion/realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programacion</a>
                    <a href="../programacion/consultar_programacion.php" class="sidebar-item sidebar-child">Consultar por Prescripcion</a>
                    <a href="../programacion/consultar_por_fecha.php" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="../programacion/consultar_por_paciente.php" class="sidebar-item sidebar-child">Consultar por Paciente</a>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('entrega')"><span>Entrega</span><span class="arrow open" id="arrow-entrega">&#9654;</span></div>
                <div class="sidebar-children open" id="menu-entrega">
                    <a href="registrar_entrega.php" class="sidebar-item sidebar-child">Registrar Entrega</a>
                    <a href="consultar_entrega.php" class="sidebar-item sidebar-child">Consultar por No. Prescripcion</a>
                    <a href="historico_entregas.php" class="sidebar-item sidebar-child active">Historico Entregas</a>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('reporte_entrega')"><span>Reporte de Entrega</span><span class="arrow" id="arrow-reporte_entrega">&#9654;</span></div>
                <div class="sidebar-children" id="menu-reporte_entrega">
                    <a href="../reporte_entrega/generar_reporte.php" class="sidebar-item sidebar-child">Generar Reporte</a>
                    <a href="../reporte_entrega/consultar_reporte.php" class="sidebar-item sidebar-child">Consultar Reporte</a>
                    <a href="../reporte_entrega/historico_reportes.php" class="sidebar-item sidebar-child">Historico Reportes</a>
                </div>
            </div>
            <?php if ($isAdmin): ?>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('admin')"><span>Administracion</span><span class="arrow" id="arrow-admin">&#9654;</span></div>
                <div class="sidebar-children" id="menu-admin">
                    <a href="../admin/crear_usuario.php" class="sidebar-item sidebar-child">Crear Usuarios</a>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        
        <main class="main-content">
            <div class="card">
                <h2>Historico de Entregas Exitosas</h2>
                <p class="card-subtitle">Registro de todas las entregas realizadas exitosamente en la API MIPRES</p>
                
                <?php if (isset($error_msg)): ?>
                    <div class="alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php endif; ?>
                
                <?php if (count($entregas) > 0): ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Usuario</th>
                                <th>No. Prescripcion</th>
                                <th>NIT</th>
                                <th>Cod. Servicio</th>
                                <th>Cantidad</th>
                                <th>Causa No Entrega</th>
                                <th>Tipo ID Recibe</th>
                                <th>No. ID Recibe</th>
                                <th>Lote</th>
                                <th>Fecha Registro</th>
                                <th>HTTP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entregas as $index => $e): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($e['nombre_usuario'] ?: $e['nombre_usuario_tabla'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($e['no_prescripcion'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($e['nit']); ?></td>
                                <td><?php echo htmlspecialchars($e['cod_servicio'] ?: ''); ?></td>
                                <td><?php echo htmlspecialchars($e['cantidad_entregada'] ?: ''); ?></td>
                                <td><?php echo htmlspecialchars($e['causa_no_entrega']); ?></td>
                                <td><?php echo htmlspecialchars($e['tipo_id_recibe'] ?: ''); ?></td>
                                <td><?php echo htmlspecialchars($e['numero_id_recibe'] ?: ''); ?></td>
                                <td><?php echo htmlspecialchars($e['lote'] ?: ''); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($e['fecha_registro'])); ?></td>
                                <td><span class="badge badge-success"><?php echo $e['http_code']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>No hay entregas exitosas registradas aun.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        function toggleMenu(menuId) {
            const menu = document.getElementById('menu-' + menuId);
            const arrow = document.getElementById('arrow-' + menuId);
            if (menu) { menu.classList.toggle('open'); arrow.classList.toggle('open'); }
        }
    </script>
</body>
</html>
