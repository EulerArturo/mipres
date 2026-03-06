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
$isAdmin = $auth->isAdmin();
$mensaje = '';
$tipo_mensaje = '';
$datosFormulario = [
    'no_prescripcion' => '',
    'tipo_tec' => 'M',
    'con_tec' => '',
    'tipo_id_paciente' => '',
    'no_id_paciente' => '',
    'no_entrega' => '',
    'no_sub_entrega' => '',
    'no_factura' => '',
    'no_id_eps' => $_SESSION['token_nit'] ?? '',
    'cod_eps' => '',
    'cod_ser_tec_entregado' => '',
    'cant_un_min_dis' => '0',
    'valor_unit_facturado' => '0',
    'valor_tot_facturado' => '0',
    'cuota_moderadora' => '0',
    'copago' => '0',
    'dir_paciente' => ''
];

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
        // No interrumpir flujo por fallo de logging secundario
    }
}

if (isset($_GET['source_id']) && is_numeric($_GET['source_id'])) {
    $sourceId = (int) $_GET['source_id'];

    try {
        $stmt = $pdo->prepare('SELECT * FROM entregas_reportes_exitosos WHERE id = ? LIMIT 1');
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($source) {
            $datosFormulario['no_prescripcion'] = $source['no_prescripcion'] ?? '';
            $datosFormulario['tipo_id_paciente'] = $source['tipo_id_recibe'] ?? '';
            $datosFormulario['no_id_paciente'] = $source['numero_id_recibe'] ?? '';
            $datosFormulario['no_id_eps'] = $source['nit'] ?? $datosFormulario['no_id_eps'];
            $datosFormulario['cod_ser_tec_entregado'] = $source['cod_servicio'] ?? '';
            $datosFormulario['cant_un_min_dis'] = $source['cantidad_entregada'] ?? '0';

            if (!empty($_GET['tipo_tec'])) {
                $datosFormulario['tipo_tec'] = trim($_GET['tipo_tec']);
            }

            $mensaje = 'Se cargaron datos base desde entregas/reportes. Complete los campos faltantes para facturar.';
            $tipo_mensaje = 'warning';
        }
    } catch (PDOException $e) {
        $mensaje = 'No fue posible cargar la fuente para prellenado.';
        $tipo_mensaje = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_facturacion'])) {
    foreach ($datosFormulario as $campo => $valor) {
        $datosFormulario[$campo] = trim($_POST[$campo] ?? $valor);
    }

    $enviarApi = (($_POST['enviar_api'] ?? '0') === '1');
    $tokenTemporal = trim($_POST['token_temporal'] ?? ($_SESSION['token_temporal'] ?? ''));

    $camposObligatorios = [
        'no_prescripcion',
        'tipo_tec',
        'con_tec',
        'tipo_id_paciente',
        'no_id_paciente',
        'no_entrega',
        'no_sub_entrega',
        'no_factura',
        'no_id_eps',
        'cod_eps',
        'cod_ser_tec_entregado',
    ];

    $faltantes = [];
    foreach ($camposObligatorios as $campo) {
        if ($datosFormulario[$campo] === '') {
            $faltantes[] = $campo;
        }
    }

    if ((float) $datosFormulario['valor_tot_facturado'] <= 0) {
        $faltantes[] = 'valor_tot_facturado';
    }

    if ((float) $datosFormulario['valor_unit_facturado'] < 0 || (float) $datosFormulario['cant_un_min_dis'] <= 0) {
        $faltantes[] = 'cant_un_min_dis/valor_unit_facturado';
    }

    if (!empty($faltantes)) {
        $mensaje = 'Faltan campos obligatorios o hay valores invalidos: ' . implode(', ', array_unique($faltantes));
        $tipo_mensaje = 'error';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO facturaciones (
                    usuario_id, nombre_usuario, no_prescripcion, tipo_tec, con_tec,
                    tipo_id_paciente, no_id_paciente, no_entrega, no_sub_entrega,
                    no_factura, no_id_eps, cod_eps, cod_ser_tec_entregado,
                    cant_un_min_dis, valor_unit_facturado, valor_tot_facturado,
                    cuota_moderadora, copago, dir_paciente, token_temporal, estado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $usuario['id'],
                $usuario['nombre_completo'] ?? '',
                $datosFormulario['no_prescripcion'],
                $datosFormulario['tipo_tec'],
                $datosFormulario['con_tec'],
                $datosFormulario['tipo_id_paciente'],
                $datosFormulario['no_id_paciente'],
                $datosFormulario['no_entrega'],
                $datosFormulario['no_sub_entrega'],
                $datosFormulario['no_factura'],
                $datosFormulario['no_id_eps'],
                $datosFormulario['cod_eps'],
                $datosFormulario['cod_ser_tec_entregado'],
                (float) $datosFormulario['cant_un_min_dis'],
                (float) $datosFormulario['valor_unit_facturado'],
                (float) $datosFormulario['valor_tot_facturado'],
                (float) $datosFormulario['cuota_moderadora'],
                (float) $datosFormulario['copago'],
                $datosFormulario['dir_paciente'],
                $tokenTemporal,
                'pendiente',
            ]);

            $facturacionId = (int) $pdo->lastInsertId();
            registrar_facturacion_log($pdo, $facturacionId, $usuario['id'], 'CREAR', 'Facturacion creada en estado pendiente');
            registrar_log_actividad($pdo, $usuario['id'], 'FACTURACION_CREAR', 'Facturacion #' . $facturacionId . ' creada');

            if ($enviarApi) {
                if ($tokenTemporal === '') {
                    $mensaje = 'Facturacion guardada en BD, pero no se envio: no hay token temporal activo.';
                    $tipo_mensaje = 'warning';
                } else {
                    $apiClient = new MipresApiClient('https://wsmipres.sispro.gov.co/WSFACMIPRESNOPBS/api');
                    $tokenProcesado = rtrim($tokenTemporal, '=') . '%3D';

                    $payload = [
                        'NoEntrega' => $datosFormulario['no_entrega'],
                        'NoSubEntrega' => $datosFormulario['no_sub_entrega'],
                        'NoPrescripcion' => $datosFormulario['no_prescripcion'],
                        'TipoTec' => $datosFormulario['tipo_tec'],
                        'ConTec' => $datosFormulario['con_tec'],
                        'TipoIDPaciente' => $datosFormulario['tipo_id_paciente'],
                        'NoIDPaciente' => $datosFormulario['no_id_paciente'],
                        'NoFactura' => $datosFormulario['no_factura'],
                        'CodSerTecEntregado' => $datosFormulario['cod_ser_tec_entregado'],
                        'CantUnMinDis' => (float) $datosFormulario['cant_un_min_dis'],
                        'ValorUnitFacturado' => (float) $datosFormulario['valor_unit_facturado'],
                        'ValorTotFacturado' => (float) $datosFormulario['valor_tot_facturado'],
                        'CuotaMod' => (float) $datosFormulario['cuota_moderadora'],
                        'Copago' => (float) $datosFormulario['copago'],
                        'DirPaciente' => $datosFormulario['dir_paciente'],
                    ];

                    $apiResult = $apiClient->putJson('Facturacion/' . $datosFormulario['no_id_eps'] . '/' . $tokenProcesado, $payload, 30);
                    $httpCode = (int) ($apiResult['http_code'] ?? 0);
                    $curlError = $apiResult['curl_error'] ?? '';
                    $rawResponse = $apiResult['raw_response'] ?? '';
                    $estado = (in_array($httpCode, [200, 201], true) && $curlError === '') ? 'enviada' : 'error';
                    $respuestaApi = normalizar_respuesta_api($rawResponse, $httpCode, $curlError);

                    $pdo->prepare('UPDATE facturaciones SET estado = ?, respuesta_api = ?, http_code = ? WHERE id = ?')
                        ->execute([$estado, $respuestaApi, $httpCode, $facturacionId]);

                    if ($estado === 'enviada') {
                        $mensaje = 'Facturacion enviada a MIPRES exitosamente.';
                        $tipo_mensaje = 'success';
                        registrar_facturacion_log($pdo, $facturacionId, $usuario['id'], 'ENVIAR', 'Envio exitoso a API. HTTP ' . $httpCode);
                        registrar_log_actividad($pdo, $usuario['id'], 'FACTURACION_ENVIAR', 'Facturacion #' . $facturacionId . ' enviada correctamente');
                    } else {
                        $mensaje = 'Facturacion guardada, pero con error de envio a API (HTTP ' . $httpCode . ').';
                        $tipo_mensaje = 'warning';
                        registrar_facturacion_log($pdo, $facturacionId, $usuario['id'], 'ERROR', 'Fallo envio API. HTTP ' . $httpCode . '. Curl: ' . $curlError);
                        registrar_log_actividad($pdo, $usuario['id'], 'FACTURACION_ERROR', 'Facturacion #' . $facturacionId . ' con error de API');
                    }
                }
            } else {
                $mensaje = 'Facturacion registrada en base de datos (pendiente de envio).';
                $tipo_mensaje = 'success';
            }
        } catch (PDOException $e) {
            $mensaje = 'Error de base de datos al registrar facturacion: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

$tokenTemporalVista = $_SESSION['token_temporal'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar Facturacion - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="header">
        <h1>Realizar Facturacion</h1>
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
                    <a href="realizar_facturacion.php" class="sidebar-item sidebar-child active">Realizar Facturacion</a>
                    <a href="consultar_facturacion.php" class="sidebar-item sidebar-child">Consultar Facturacion</a>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="card">
                <h2>Formulario de Facturacion</h2>
                <p class="card-subtitle">MVP: registro en BD y envio opcional a API de MIPRES</p>

                <?php if ($mensaje !== ''): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="enviar_api" id="enviar-api" value="0">

                    <div class="grid-2">
                        <div class="form-group">
                            <label>Token Temporal (sesion)</label>
                            <input type="text" name="token_temporal" value="<?php echo htmlspecialchars(trim($tokenTemporalVista, '"')); ?>">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group"><label>1. No Prescripcion *</label><input type="text" name="no_prescripcion" required maxlength="20" value="<?php echo htmlspecialchars($datosFormulario['no_prescripcion']); ?>"></div>
                        <div class="form-group"><label>2. Tipo Tecnologia *</label>
                            <select name="tipo_tec" required>
                                <?php foreach (['M','P','D','N','S'] as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $datosFormulario['tipo_tec'] === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>3. ConTec *</label><input type="text" name="con_tec" required maxlength="2" value="<?php echo htmlspecialchars($datosFormulario['con_tec']); ?>"></div>
                        <div class="form-group"><label>4. Tipo ID Paciente *</label><input type="text" name="tipo_id_paciente" required maxlength="2" value="<?php echo htmlspecialchars($datosFormulario['tipo_id_paciente']); ?>"></div>
                        <div class="form-group"><label>5. No ID Paciente *</label><input type="text" name="no_id_paciente" required maxlength="17" value="<?php echo htmlspecialchars($datosFormulario['no_id_paciente']); ?>"></div>
                        <div class="form-group"><label>6. No Entrega *</label><input type="text" name="no_entrega" required maxlength="4" value="<?php echo htmlspecialchars($datosFormulario['no_entrega']); ?>"></div>
                        <div class="form-group"><label>7. No SubEntrega *</label><input type="text" name="no_sub_entrega" required maxlength="2" value="<?php echo htmlspecialchars($datosFormulario['no_sub_entrega']); ?>"></div>
                        <div class="form-group"><label>8. No Factura *</label><input type="text" name="no_factura" required maxlength="96" value="<?php echo htmlspecialchars($datosFormulario['no_factura']); ?>"></div>
                        <div class="form-group"><label>9. NIT EPS *</label><input type="text" name="no_id_eps" required maxlength="17" value="<?php echo htmlspecialchars($datosFormulario['no_id_eps']); ?>"></div>
                        <div class="form-group"><label>10. Cod EPS *</label><input type="text" name="cod_eps" required maxlength="6" value="<?php echo htmlspecialchars($datosFormulario['cod_eps']); ?>"></div>
                        <div class="form-group"><label>11. Cod Serv/Tec Entregado *</label><input type="text" name="cod_ser_tec_entregado" required maxlength="20" value="<?php echo htmlspecialchars($datosFormulario['cod_ser_tec_entregado']); ?>"></div>
                        <div class="form-group"><label>12. Cantidad *</label><input type="number" name="cant_un_min_dis" required step="0.0001" value="<?php echo htmlspecialchars($datosFormulario['cant_un_min_dis']); ?>"></div>
                        <div class="form-group"><label>13. Valor Unitario *</label><input type="number" name="valor_unit_facturado" required step="0.01" value="<?php echo htmlspecialchars($datosFormulario['valor_unit_facturado']); ?>"></div>
                        <div class="form-group"><label>14. Valor Total *</label><input type="number" name="valor_tot_facturado" required step="0.01" value="<?php echo htmlspecialchars($datosFormulario['valor_tot_facturado']); ?>"></div>
                        <div class="form-group"><label>15. Cuota Moderadora</label><input type="number" name="cuota_moderadora" step="0.01" value="<?php echo htmlspecialchars($datosFormulario['cuota_moderadora']); ?>"></div>
                        <div class="form-group"><label>16. Copago</label><input type="number" name="copago" step="0.01" value="<?php echo htmlspecialchars($datosFormulario['copago']); ?>"></div>
                    </div>

                    <div class="form-group">
                        <label>Direccion Paciente</label>
                        <input type="text" name="dir_paciente" maxlength="80" value="<?php echo htmlspecialchars($datosFormulario['dir_paciente']); ?>">
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="submit" name="enviar_facturacion" class="btn" onclick="document.getElementById('enviar-api').value='0'">Guardar en BD</button>
                        <button type="submit" name="enviar_facturacion" class="btn" style="background:#0d6efd;" onclick="document.getElementById('enviar-api').value='1'">Guardar y Enviar API</button>
                    </div>
                </form>
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
