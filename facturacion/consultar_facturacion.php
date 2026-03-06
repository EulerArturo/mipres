<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../services/MipresApiClient.php';

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    header('Location: ../login.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$mensaje = '';
$tipo_mensaje = '';

function normalizar_respuesta_api($rawResponse, $httpCode, $curlError)
{
    if ($rawResponse !== null && $rawResponse !== '') {
        json_decode($rawResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $rawResponse;
        }
    }

    return json_encode([
        'raw_response' => (string) $rawResponse,
        'http_code' => (int) $httpCode,
        'curl_error' => (string) $curlError,
    ], JSON_UNESCAPED_UNICODE);
}

function registrar_facturacion_log($pdo, $facturacionId, $usuarioId, $accion, $detalles)
{
    try {
        $stmt = $pdo->prepare('INSERT INTO facturaciones_logs (facturacion_id, usuario_id, accion, detalles, ip_address) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $facturacionId,
            $usuarioId,
            $accion,
            $detalles,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        // No romper flujo por errores de auditoria secundaria
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reenviar_api'])) {
    $facturacionId = (int) ($_POST['facturacion_id'] ?? 0);

    try {
        $stmt = $pdo->prepare('SELECT * FROM facturaciones WHERE id = ? LIMIT 1');
        $stmt->execute([$facturacionId]);
        $facturacion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$facturacion) {
            $mensaje = 'No se encontro la facturacion solicitada.';
            $tipo_mensaje = 'error';
        } else {
            $tokenTemporal = $facturacion['token_temporal'] ?: ($_SESSION['token_temporal'] ?? '');
            if ($tokenTemporal === '') {
                $mensaje = 'No hay token disponible para reenviar la facturacion #' . $facturacionId;
                $tipo_mensaje = 'error';
            } else {
                $apiClient = new MipresApiClient('https://wsmipres.sispro.gov.co/WSFACMIPRESNOPBS/api');
                $tokenProcesado = rtrim($tokenTemporal, '=') . '%3D';

                $payload = [
                    'NoEntrega' => $facturacion['no_entrega'],
                    'NoSubEntrega' => $facturacion['no_sub_entrega'],
                    'NoPrescripcion' => $facturacion['no_prescripcion'],
                    'TipoTec' => $facturacion['tipo_tec'],
                    'ConTec' => $facturacion['con_tec'],
                    'TipoIDPaciente' => $facturacion['tipo_id_paciente'],
                    'NoIDPaciente' => $facturacion['no_id_paciente'],
                    'NoFactura' => $facturacion['no_factura'],
                    'CodSerTecEntregado' => $facturacion['cod_ser_tec_entregado'],
                    'CantUnMinDis' => (float) $facturacion['cant_un_min_dis'],
                    'ValorUnitFacturado' => (float) $facturacion['valor_unit_facturado'],
                    'ValorTotFacturado' => (float) $facturacion['valor_tot_facturado'],
                    'CuotaMod' => (float) $facturacion['cuota_moderadora'],
                    'Copago' => (float) $facturacion['copago'],
                    'DirPaciente' => $facturacion['dir_paciente'],
                ];

                $apiResult = $apiClient->putJson('Facturacion/' . $facturacion['no_id_eps'] . '/' . $tokenProcesado, $payload, 30);
                $httpCode = (int) ($apiResult['http_code'] ?? 0);
                $curlError = $apiResult['curl_error'] ?? '';
                $rawResponse = $apiResult['raw_response'] ?? '';
                $estado = (in_array($httpCode, [200, 201], true) && $curlError === '') ? 'enviada' : 'error';
                $respuestaApi = normalizar_respuesta_api($rawResponse, $httpCode, $curlError);

                $pdo->prepare('UPDATE facturaciones SET estado = ?, respuesta_api = ?, http_code = ? WHERE id = ?')
                    ->execute([$estado, $respuestaApi, $httpCode, $facturacionId]);

                if ($estado === 'enviada') {
                    $mensaje = 'Facturacion #' . $facturacionId . ' reenviada exitosamente.';
                    $tipo_mensaje = 'success';
                    registrar_facturacion_log($pdo, $facturacionId, $usuario['id'], 'REENVIAR', 'Reenvio exitoso a API. HTTP ' . $httpCode);
                    registrar_log_actividad($pdo, $usuario['id'], 'FACTURACION_REENVIAR', 'Facturacion #' . $facturacionId . ' reenviada');
                } else {
                    $mensaje = 'Facturacion #' . $facturacionId . ' con error al reenviar (HTTP ' . $httpCode . ').';
                    $tipo_mensaje = 'warning';
                    registrar_facturacion_log($pdo, $facturacionId, $usuario['id'], 'ERROR', 'Reenvio con error. HTTP ' . $httpCode . '. Curl: ' . $curlError);
                    registrar_log_actividad($pdo, $usuario['id'], 'FACTURACION_REENVIAR_ERROR', 'Error reenviando facturacion #' . $facturacionId);
                }
            }
        }
    } catch (PDOException $e) {
        $mensaje = 'Error en el proceso de reenvio: ' . $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

$estado = trim($_GET['estado'] ?? '');
$noPrescripcion = trim($_GET['no_prescripcion'] ?? '');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '');
$fechaFin = trim($_GET['fecha_fin'] ?? '');

$sql = 'SELECT * FROM facturaciones WHERE 1=1';
$params = [];

if ($estado !== '') {
    $sql .= ' AND estado = ?';
    $params[] = $estado;
}
if ($noPrescripcion !== '') {
    $sql .= ' AND no_prescripcion LIKE ?';
    $params[] = '%' . $noPrescripcion . '%';
}
if ($fechaInicio !== '') {
    $sql .= ' AND DATE(fecha_registro) >= ?';
    $params[] = $fechaInicio;
}
if ($fechaFin !== '') {
    $sql .= ' AND DATE(fecha_registro) <= ?';
    $params[] = $fechaFin;
}

$sql .= ' ORDER BY id DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$facturaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

registrar_log_actividad($pdo, $usuario['id'], 'CONSULTAR_FACTURACION', 'Acceso al listado de facturaciones');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Facturacion - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="header">
        <h1>Consultar Facturacion</h1>
        <div class="user-info">
            <span class="user-badge"><?php echo htmlspecialchars($usuario['nombre_completo'] ?? 'Usuario'); ?></span>
            <a href="../logout.php" class="logout-btn">Cerrar Sesion</a>
        </div>
    </div>

    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-section">
                <a href="../dashboard.php" class="sidebar-item">Inicio</a>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('facturacion')">
                    <span>Facturacion</span>
                    <span class="arrow open" id="arrow-facturacion">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-facturacion">
                    <a href="realizar_facturacion.php" class="sidebar-item sidebar-child">Realizar Facturacion</a>
                    <a href="consultar_facturacion.php" class="sidebar-item sidebar-child active">Consultar Facturacion</a>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="card">
                <h2>Facturaciones registradas</h2>

                <?php if ($mensaje !== ''): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>

                <form method="GET" action="" style="margin-bottom:16px; display:grid; grid-template-columns: repeat(4, 1fr); gap:10px;">
                    <input type="text" name="no_prescripcion" placeholder="No prescripcion" value="<?php echo htmlspecialchars($noPrescripcion); ?>">
                    <select name="estado">
                        <option value="">Estado (todos)</option>
                        <?php foreach (['pendiente','enviada','error'] as $op): ?>
                            <option value="<?php echo $op; ?>" <?php echo $estado === $op ? 'selected' : ''; ?>><?php echo ucfirst($op); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($fechaInicio); ?>">
                    <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fechaFin); ?>">
                    <button type="submit" class="btn">Filtrar</button>
                </form>

                <div style="overflow:auto;">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Fecha</th>
                                <th>Prescripcion</th>
                                <th>Paciente</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>HTTP</th>
                                <th>Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($facturaciones)): ?>
                                <tr><td colspan="8" style="text-align:center; padding:16px;">Sin resultados</td></tr>
                            <?php else: ?>
                                <?php foreach ($facturaciones as $row): ?>
                                    <tr>
                                        <td><?php echo (int) $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['fecha_registro']); ?></td>
                                        <td><?php echo htmlspecialchars($row['no_prescripcion']); ?></td>
                                        <td><?php echo htmlspecialchars($row['tipo_id_paciente'] . ' ' . $row['no_id_paciente']); ?></td>
                                        <td><?php echo htmlspecialchars($row['valor_tot_facturado']); ?></td>
                                        <td><?php echo htmlspecialchars($row['estado']); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($row['http_code'] ?? '')); ?></td>
                                        <td>
                                            <?php if ($row['estado'] !== 'enviada'): ?>
                                                <form method="POST" action="" style="display:inline;">
                                                    <input type="hidden" name="facturacion_id" value="<?php echo (int) $row['id']; ?>">
                                                    <button type="submit" name="reenviar_api" class="btn">Reenviar API</button>
                                                </form>
                                            <?php else: ?>
                                                OK
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleMenu(menuId) {
            const menu = document.getElementById('menu-' + menuId);
            const arrow = document.getElementById('arrow-' + menuId);
            if (menu) {
                menu.classList.toggle('open');
                arrow.classList.toggle('open');
            }
        }
    </script>
</body>
</html>
