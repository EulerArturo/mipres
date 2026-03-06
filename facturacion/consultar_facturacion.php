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

function ensure_facturacion_candidatos_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS facturacion_candidatos (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function to_decimal($value)
{
    if ($value === null) {
        return 0.0;
    }
    $normalized = str_replace(',', '.', trim((string) $value));
    return is_numeric($normalized) ? (float) $normalized : 0.0;
}

function calcular_completitud_candidato($data)
{
    $required = [
        'no_prescripcion' => 'text',
        'tipo_tec' => 'text',
        'con_tec' => 'text',
        'tipo_id_paciente' => 'text',
        'no_id_paciente' => 'text',
        'no_entrega' => 'text',
        'no_sub_entrega' => 'text',
        'no_factura' => 'text',
        'no_id_eps' => 'text',
        'cod_eps' => 'text',
        'cod_ser_tec_entregado' => 'text',
        'cant_un_min_dis' => 'numeric',
        'valor_unit_facturado' => 'numeric',
        'valor_tot_facturado' => 'numeric',
    ];

    $faltantes = [];
    $completos = 0;

    foreach ($required as $campo => $tipo) {
        $valor = $data[$campo] ?? null;
        if ($tipo === 'numeric') {
            if ((float) $valor > 0) {
                $completos++;
            } else {
                $faltantes[] = $campo;
            }
        } else {
            if (trim((string) $valor) !== '') {
                $completos++;
            } else {
                $faltantes[] = $campo;
            }
        }
    }

    $porcentaje = (int) round(($completos / count($required)) * 100);
    if ($porcentaje >= 80) {
        $semaforo = 'VERDE';
    } elseif ($porcentaje >= 50) {
        $semaforo = 'AMARILLO';
    } else {
        $semaforo = 'ROJO';
    }

    return [
        'faltantes' => $faltantes,
        'porcentaje' => $porcentaje,
        'semaforo' => $semaforo,
    ];
}

function sincronizar_candidatos_facturacion($pdo)
{
    ensure_facturacion_candidatos_table($pdo);

    $stmt = $pdo->query("SELECT * FROM entregas_reportes_exitosos WHERE tipo_registro IN ('ENTREGA','REPORTE_ENTREGA') ORDER BY id DESC LIMIT 5000");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upsert = $pdo->prepare(
        "INSERT INTO facturacion_candidatos (
            source_tipo, source_id, source_fecha,
            no_prescripcion, tipo_tec, con_tec, tipo_id_paciente, no_id_paciente,
            no_entrega, no_sub_entrega, no_factura, no_id_eps, cod_eps,
            cod_ser_tec_entregado, cant_un_min_dis, valor_unit_facturado, valor_tot_facturado,
            cuota_moderadora, copago, dir_paciente,
            campos_faltantes, porcentaje_completitud, semaforo, estado
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, 'pendiente'
        )
        ON DUPLICATE KEY UPDATE
            source_fecha = VALUES(source_fecha),
            no_prescripcion = VALUES(no_prescripcion),
            tipo_tec = VALUES(tipo_tec),
            con_tec = VALUES(con_tec),
            tipo_id_paciente = VALUES(tipo_id_paciente),
            no_id_paciente = VALUES(no_id_paciente),
            no_entrega = VALUES(no_entrega),
            no_sub_entrega = VALUES(no_sub_entrega),
            no_factura = VALUES(no_factura),
            no_id_eps = VALUES(no_id_eps),
            cod_eps = VALUES(cod_eps),
            cod_ser_tec_entregado = VALUES(cod_ser_tec_entregado),
            cant_un_min_dis = VALUES(cant_un_min_dis),
            valor_unit_facturado = VALUES(valor_unit_facturado),
            valor_tot_facturado = VALUES(valor_tot_facturado),
            cuota_moderadora = VALUES(cuota_moderadora),
            copago = VALUES(copago),
            dir_paciente = VALUES(dir_paciente),
            campos_faltantes = VALUES(campos_faltantes),
            porcentaje_completitud = VALUES(porcentaje_completitud),
            semaforo = VALUES(semaforo),
            estado = CASE WHEN estado = 'convertida' THEN estado ELSE 'pendiente' END,
            facturacion_id = CASE WHEN estado = 'convertida' THEN facturacion_id ELSE NULL END"
    );

    $procesados = 0;

    foreach ($rows as $r) {
        $tipo = $r['tipo_registro'];
        $cantidad = to_decimal($r['cantidad_entregada']);
        $valorTotal = to_decimal($r['valor_entregado']);
        $valorUnit = ($cantidad > 0 && $valorTotal > 0) ? round($valorTotal / $cantidad, 2) : 0.0;

        $noEntrega = '';
        if ($tipo === 'REPORTE_ENTREGA') {
            $idEntrega = trim((string) ($r['id_entrega'] ?? ''));
            if ($idEntrega !== '' && strlen($idEntrega) <= 4) {
                $noEntrega = $idEntrega;
            }
        }

        $data = [
            'no_prescripcion' => trim((string) ($r['no_prescripcion'] ?? '')),
            'tipo_tec' => trim((string) (!empty($r['cod_servicio']) ? 'M' : '')),
            'con_tec' => '',
            'tipo_id_paciente' => trim((string) ($r['tipo_id_recibe'] ?? '')),
            'no_id_paciente' => trim((string) ($r['numero_id_recibe'] ?? '')),
            'no_entrega' => $noEntrega,
            'no_sub_entrega' => '',
            'no_factura' => '',
            'no_id_eps' => trim((string) ($r['nit'] ?? '')),
            'cod_eps' => '',
            'cod_ser_tec_entregado' => trim((string) ($r['cod_servicio'] ?? '')),
            'cant_un_min_dis' => $cantidad,
            'valor_unit_facturado' => $valorUnit,
            'valor_tot_facturado' => $valorTotal,
            'cuota_moderadora' => 0,
            'copago' => 0,
            'dir_paciente' => '',
        ];

        $completitud = calcular_completitud_candidato($data);

        $upsert->execute([
            $tipo,
            (int) $r['id'],
            $r['fecha_registro'] ?? null,
            $data['no_prescripcion'],
            $data['tipo_tec'],
            $data['con_tec'],
            $data['tipo_id_paciente'],
            $data['no_id_paciente'],
            $data['no_entrega'],
            $data['no_sub_entrega'],
            $data['no_factura'],
            $data['no_id_eps'],
            $data['cod_eps'],
            $data['cod_ser_tec_entregado'],
            $data['cant_un_min_dis'],
            $data['valor_unit_facturado'],
            $data['valor_tot_facturado'],
            $data['cuota_moderadora'],
            $data['copago'],
            $data['dir_paciente'],
            implode(', ', $completitud['faltantes']),
            $completitud['porcentaje'],
            $completitud['semaforo'],
        ]);

        $procesados++;
    }

    return $procesados;
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

$syncProcesados = 0;
try {
    $syncProcesados = sincronizar_candidatos_facturacion($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sincronizar_candidatos'])) {
        $mensaje = 'Sincronizacion completada. Registros revisados: ' . $syncProcesados;
        $tipo_mensaje = 'success';
    }
} catch (PDOException $e) {
    if ($mensaje === '') {
        $mensaje = 'No fue posible sincronizar candidatos automaticos.';
        $tipo_mensaje = 'warning';
    }
}

$estado = trim($_GET['estado'] ?? '');
$noPrescripcion = trim($_GET['no_prescripcion'] ?? '');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '');
$fechaFin = trim($_GET['fecha_fin'] ?? '');
$semaforo = trim($_GET['semaforo'] ?? '');
$estadoCandidato = trim($_GET['estado_candidato'] ?? 'pendiente');

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

$sqlCand = 'SELECT * FROM facturacion_candidatos WHERE 1=1';
$paramsCand = [];
if ($estadoCandidato !== '') {
    $sqlCand .= ' AND estado = ?';
    $paramsCand[] = $estadoCandidato;
}
if ($semaforo !== '') {
    $sqlCand .= ' AND semaforo = ?';
    $paramsCand[] = $semaforo;
}
if ($noPrescripcion !== '') {
    $sqlCand .= ' AND no_prescripcion LIKE ?';
    $paramsCand[] = '%' . $noPrescripcion . '%';
}
$sqlCand .= " ORDER BY CASE semaforo WHEN 'ROJO' THEN 1 WHEN 'AMARILLO' THEN 2 ELSE 3 END, porcentaje_completitud DESC, id DESC LIMIT 500";
$stmtCand = $pdo->prepare($sqlCand);
$stmtCand->execute($paramsCand);
$candidatos = $stmtCand->fetchAll(PDO::FETCH_ASSOC);

$resumenSemaforo = ['ROJO' => 0, 'AMARILLO' => 0, 'VERDE' => 0];
foreach ($candidatos as $cand) {
    $key = strtoupper((string) ($cand['semaforo'] ?? 'ROJO'));
    if (isset($resumenSemaforo[$key])) {
        $resumenSemaforo[$key]++;
    }
}

registrar_log_actividad($pdo, $usuario['id'], 'CONSULTAR_FACTURACION', 'Acceso al listado de facturaciones y candidatos automaticos');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Facturacion - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 12px; font-weight: 600; font-size: 12px; display: inline-block; }
        .badge-rojo { background: #f8d7da; color: #842029; }
        .badge-amarillo { background: #fff3cd; color: #664d03; }
        .badge-verde { background: #d1e7dd; color: #0f5132; }
    </style>
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
                <h2>Candidatos automaticos de facturacion</h2>
                <p class="card-subtitle">Sincronizados desde entregas/reportes exitosos. Revisados en esta carga: <?php echo (int) $syncProcesados; ?></p>

                <?php if ($mensaje !== ''): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>

                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <span class="badge badge-rojo">ROJO: <?php echo (int) $resumenSemaforo['ROJO']; ?></span>
                    <span class="badge badge-amarillo">AMARILLO: <?php echo (int) $resumenSemaforo['AMARILLO']; ?></span>
                    <span class="badge badge-verde">VERDE: <?php echo (int) $resumenSemaforo['VERDE']; ?></span>
                </div>

                <form method="POST" action="" style="margin-bottom:12px;">
                    <button type="submit" name="sincronizar_candidatos" class="btn">Sincronizar ahora</button>
                </form>

                <form method="GET" action="" style="margin-bottom:16px; display:grid; grid-template-columns: repeat(5, 1fr); gap:10px;">
                    <input type="text" name="no_prescripcion" placeholder="No prescripcion" value="<?php echo htmlspecialchars($noPrescripcion); ?>">
                    <select name="semaforo">
                        <option value="">Semaforo (todos)</option>
                        <?php foreach (['ROJO','AMARILLO','VERDE'] as $sm): ?>
                            <option value="<?php echo $sm; ?>" <?php echo $semaforo === $sm ? 'selected' : ''; ?>><?php echo $sm; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="estado_candidato">
                        <option value="">Estado cand. (todos)</option>
                        <?php foreach (['pendiente','convertida'] as $ec): ?>
                            <option value="<?php echo $ec; ?>" <?php echo $estadoCandidato === $ec ? 'selected' : ''; ?>><?php echo ucfirst($ec); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($fechaInicio); ?>">
                    <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fechaFin); ?>">
                    <button type="submit" class="btn">Filtrar</button>
                </form>

                <div style="overflow:auto; margin-bottom:24px;">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Fuente</th>
                                <th>Fecha fuente</th>
                                <th>Prescripcion</th>
                                <th>Paciente</th>
                                <th>Servicio/Cant.</th>
                                <th>Total</th>
                                <th>Completitud</th>
                                <th>Faltantes</th>
                                <th>Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($candidatos)): ?>
                                <tr><td colspan="10" style="text-align:center; padding:16px;">Sin candidatos para los filtros actuales</td></tr>
                            <?php else: ?>
                                <?php foreach ($candidatos as $cand): ?>
                                    <?php
                                        $sem = strtoupper((string) $cand['semaforo']);
                                        $badgeClass = $sem === 'VERDE' ? 'badge-verde' : ($sem === 'AMARILLO' ? 'badge-amarillo' : 'badge-rojo');
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $cand['id']; ?></td>
                                        <td><?php echo htmlspecialchars($cand['source_tipo'] . ' #' . $cand['source_id']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $cand['source_fecha']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $cand['no_prescripcion']); ?></td>
                                        <td><?php echo htmlspecialchars(trim((string) $cand['tipo_id_paciente'] . ' ' . (string) $cand['no_id_paciente'])); ?></td>
                                        <td><?php echo htmlspecialchars((string) $cand['cod_ser_tec_entregado'] . ' / ' . (string) $cand['cant_un_min_dis']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $cand['valor_tot_facturado']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($sem . ' ' . (int) $cand['porcentaje_completitud'] . '%'); ?></span>
                                        </td>
                                        <td style="max-width:240px; white-space:normal;"><?php echo htmlspecialchars((string) $cand['campos_faltantes']); ?></td>
                                        <td>
                                            <a class="btn" href="realizar_facturacion.php?candidate_id=<?php echo (int) $cand['id']; ?>">Prellenar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2>Facturaciones registradas</h2>

                <form method="GET" action="" style="margin-bottom:16px; display:grid; grid-template-columns: repeat(5, 1fr); gap:10px;">
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
