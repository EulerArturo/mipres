<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../services/MipresApiClient.php';

$auth = new Auth();

// Verificar autenticación
if (!$auth->isAuthenticated()) {
    header('Location: ../login.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

// Variables para mensajes y resultados
$mensaje = '';
$tipo_mensaje = '';
$datos_precargados = [];

// Procesar entregas individuales desde programación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['from_programacion'])) {
    $datos_precargados = [
        'id' => $_POST['id_prog'] ?? '',
        'cod_ser_tec' => $_POST['cod_ser_tec_prog'] ?? '',
        'cant_total' => $_POST['cant_total_prog'] ?? '',
        'nit' => $_POST['nit'] ?? '',
        'token_temporal' => $_POST['token_temporal'] ?? '',
        'tipo_id_paciente' => $_POST['tipo_id_paciente'] ?? '',
        'no_id_paciente' => $_POST['no_id_paciente'] ?? '',
        'fec_direccionamiento' => $_POST['fec_direccionamiento'] ?? ''
    ];
}

// Procesar entregas masivas desde programación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['from_programacion_masiva'])) {
    $programaciones_json = $_POST['programaciones'] ?? '[]';
    $programaciones = json_decode($programaciones_json, true);
    
    if (is_array($programaciones) && count($programaciones) > 0) {
        // Guardar en sesión para usar en el formulario
        $_SESSION['programaciones_masivas'] = $programaciones;
    }
}

// Procesar envío de entrega
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_entrega'])) {
    $nit = trim($_POST['nit'] ?? '');
    $token_temporal = trim($_POST['token_temporal'] ?? '');
    $mipresApiClient = new MipresApiClient();
    
    // Construir array de entregas desde los datos del formulario
    $entregas = [];
    $num_items = count($_POST['id'] ?? []);
    
    for ($i = 0; $i < $num_items; $i++) {
        $entrega = [
    'ID' => (int)($_POST['id'][$i] ?? 0),
    'NoPrescripcion' => $_POST['no_prescripcion'][$i] ?? '',
    'CodSerTecEntregado' => $_POST['cod_ser_tec_entregado'][$i] ?? '',
    'CantTotEntregada' => $_POST['cant_tot_entregada'][$i] ?? '',
    'EntTotal' => (int)($_POST['ent_total'][$i] ?? 0),
    'CausaNoEntrega' => (int)($_POST['causa_no_entrega'][$i] ?? 0),
    'FecEntrega' => $_POST['fec_entrega'][$i] ?? '',
    'NoLote' => $_POST['no_lote'][$i] ?? '',
    'TipoIDRecibe' => $_POST['tipo_id_recibe'][$i] ?? '',
    'NoIDRecibe' => $_POST['no_id_recibe'][$i] ?? ''
];

        $entregas[] = $entrega;
    }
    
    // Validaciones
    if (empty($nit) || empty($token_temporal)) {
        $mensaje = 'Por favor complete los campos de NIT y Token Temporal para continuar con el registro de entrega';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'REGISTRAR_ENTREGA_ERROR', 'Validación fallida: NIT o token temporal vacío en registro de entrega');
    } elseif (empty($entregas)) {
        $mensaje = 'Debe agregar al menos un medicamento o producto para registrar la entrega';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'REGISTRAR_ENTREGA_ERROR', 'Validación fallida: intento de registro de entrega sin items');
    } else {
        $token_procesado = rtrim($token_temporal, '=');
        
        $resultados = [];
        $errores = [];
        $exitosos = 0;
        
        foreach ($entregas as $index => $entrega) {
            // Construir URL de la API con token sin "=" y %3D al final
            $apiResult = $mipresApiClient->putJson(
                "Entrega/{$nit}/{$token_procesado}%3D",
                $entrega,
                30
            );

            // Ejecutar petición
            $response = $apiResult['raw_response'];
            $http_code = $apiResult['http_code'];
            $curl_error = $apiResult['curl_error'];
            
            // Procesar respuesta de cada item
            if ($curl_error) {
                $errores[] = "Item " . ($index + 1) . ": Error de conexión - " . $curl_error;
            } elseif ($http_code === 200 || $http_code === 201) {
                $exitosos++;
                $resultados[] = "Item " . ($index + 1) . ": Registrado exitosamente";
                
                // Guardar en BD cuando es exitoso
                try {
                    // Buscar el número de prescripción REAL desde direccionamientos_estado
                    $stmt_prescripcion = $pdo->prepare("
                        SELECT no_prescripcion FROM direccionamientos_estado 
                        WHERE id_direccionamiento = ? LIMIT 1
                    ");
                    $stmt_prescripcion->execute([$entrega['ID']]);
                    $result_prescripcion = $stmt_prescripcion->fetch(PDO::FETCH_ASSOC);
                    $no_prescripcion_real = $entrega['NoPrescripcion'] ?? '';

                    
                    $stmt = $pdo->prepare("
                        INSERT INTO entregas_reportes_exitosos 
                        (tipo_registro, usuario_id, nombre_usuario, nit, no_prescripcion, cod_servicio, cantidad_entregada, 
                         causa_no_entrega, tipo_id_recibe, numero_id_recibe, lote, 
                         fecha_registro, http_code) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([
                        'ENTREGA',
                        $usuario['id'],
                        $usuario['nombre_completo'] ?? '',
                        $nit,
                        $no_prescripcion_real,
                        $entrega['CodSerTecEntregado'] ?? '',
                        $entrega['CantTotEntregada'] ?? '',
                        $entrega['CausaNoEntrega'] ?? 0,
                        $entrega['TipoIDRecibe'] ?? '',
                        $entrega['NoIDRecibe'] ?? '',
                        $entrega['NoLote'] ?? '',
                        200
                    ]);
                } catch (PDOException $e) {
                    // Log silencioso para no interrumpir el flujo
                    error_log("Error al guardar entrega exitosa: " . $e->getMessage());
                }
            } elseif ($http_code === 401) {
                $errores[] = "Item " . ($index + 1) . ": Token inválido o expirado";
            } elseif ($http_code >= 500) {
                $errores[] = "Item " . ($index + 1) . ": Error en el servidor de MIPRES";
            } else {
                $errores[] = "Item " . ($index + 1) . ": Error HTTP {$http_code} - {$response}";
            }
        }
        
        // Construir mensaje final
        if ($exitosos > 0 && empty($errores)) {
            $mensaje = "¡ENTREGA REGISTRADA EXITOSAMENTE!";
            $mensaje_detalle = "Se registraron correctamente {$exitosos} producto(s) en el sistema MIPRES. La información ha sido guardada satisfactoriamente";
            $tipo_mensaje = 'success';
            $limpiar_formulario = true;
        } elseif ($exitosos > 0 && !empty($errores)) {
            $mensaje = "ENTREGA PARCIALMENTE COMPLETADA";
            $mensaje_detalle = "Se registraron {$exitosos} producto(s) correctamente, pero algunos presentaron errores. Revise los detalles e intente nuevamente con los productos fallidos";
            $tipo_mensaje = 'error';
        } else {
            $mensaje = "NO SE PUDO COMPLETAR EL REGISTRO";
            $mensaje_detalle = "No se logró registrar ninguna entrega. Por favor verifique los datos ingresados y su conexión, luego intente nuevamente";
            $tipo_mensaje = 'error';
        }
        
        // Registrar en logs
        if ($exitosos > 0) {
            registrar_log_actividad($pdo, $usuario['id'], 'REGISTRAR_ENTREGA', "Entregas registradas: {$exitosos} de " . count($entregas));
        }

        if (!empty($errores)) {
            registrar_log_actividad($pdo, $usuario['id'], 'REGISTRAR_ENTREGA_ERROR', "Entregas con error: " . count($errores) . ' de ' . count($entregas) . ", Primer error: " . ($errores[0] ?? 'N/A'));
        }
        
        // Limpiar formulario si la entrega fue exitosa
        if (isset($limpiar_formulario) && $limpiar_formulario) {
            $_SESSION['token_temporal'] = '';
            $_SESSION['token_nit'] = '';
            $datos_precargados = [];
        }
    }
}

// Obtener token de sesión si existe
$token_sesion = $_SESSION['token_temporal'] ?? '';
$nit_sesion = $_SESSION['token_nit'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Entrega - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif;
            background-color: #ffffff;
            color: #000000;
        }
        
        .header {
            background-color: #000000;
            color: #ffffff;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e5e5;
        }
        
        .header h1 {
            font-size: 20px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .user-badge {
            background-color: #ffffff;
            color: #000000;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .logout-btn {
            background-color: #ffffff;
            color: #000000;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background-color: #f8f9fa;
        }
        
        .layout {
            display: flex;
            min-height: calc(100vh - 73px);
        }
        
        .sidebar {
            width: 280px;
            background-color: #f8f9fa;
            border-right: 1px solid #e5e5e5;
            padding: 24px 0;
        }
        
        .sidebar-item {
            padding: 12px 24px;
            color: #000000;
            text-decoration: none;
            display: block;
            transition: all 0.2s ease;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        
        .sidebar-item:hover {
            background-color: #e5e5e5;
        }
        
        .sidebar-item.active {
            background-color: #000000;
            color: #ffffff;
        }
        
        .sidebar-parent {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sidebar-children {
            display: none;
            background-color: #ffffff;
        }
        
        .sidebar-children.open {
            display: block;
        }
        
        .sidebar-child {
            padding-left: 48px;
            font-size: 13px;
            font-weight: 400;
        }
        
        .arrow {
            transition: transform 0.2s ease;
            font-size: 12px;
        }
        
        .arrow.open {
            transform: rotate(90deg);
        }
        
        .main-content {
            flex: 1;
            padding: 32px;
            background-color: #ffffff;
        }
        
        .card {
            background: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 32px;
            margin-bottom: 24px;
        }
        
        .card h2 {
            color: #000000;
            margin-bottom: 8px;
            font-size: 24px;
            font-weight: 600;
        }
        
        .card-subtitle {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .alert {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: 14px;
            border: 1px solid;
        }
        
        .alert-success {
            background-color: #f8f9fa;
            color: #000000;
            border-color: #000000;
        }
        
        .alert-error {
            background-color: #fff5f5;
            color: #000000;
            border-color: #000000;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #000000;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #000000;
        }
        
        .btn {
            background-color: #000000;
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn:hover {
            background-color: #333333;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: #ffffff;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .items-container {
            margin-top: 32px;
        }
        
        .item-card {
            background-color: #f8f9fa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #000000;
        }
        
        .item-number {
            font-size: 16px;
            font-weight: 600;
            color: #000000;
        }
        
        .btn-remove {
            background: #000000;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-remove:hover {
            background: #333333;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        
        .btn-add {
            background-color: #ffffff;
            color: #000000;
            border: 2px solid #000000;
            margin-bottom: 24px;
        }
        
        .btn-add:hover {
            background-color: #000000;
            color: #ffffff;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background-color: white;
            padding: 32px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #000000;
        }
        
        .modal-body {
            margin-bottom: 24px;
            color: #000000;
            line-height: 1.6;
            font-size: 14px;
        }
        
        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        /* Added highlight style for pre-filled editable fields */
        .form-group input.prefilled {
            background-color: #fffbea;
            border-color: #000000;
            font-weight: 500;
        }
        
        /* Added popup notification styles */
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .popup-overlay.show {
            display: flex;
        }
        
        .popup-content {
            background-color: white;
            padding: 32px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: popupSlideIn 0.3s ease-out;
        }
        
        @keyframes popupSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .popup-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        
        .popup-icon.success {
            color: #28a745;
        }
        
        .popup-icon.error {
            color: #dc3545;
        }
        
        .popup-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #000000;
        }
        
        .popup-message {
            font-size: 16px;
            color: #333333;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        
        .popup-btn {
            background-color: #000000;
            color: #ffffff;
            border: none;
            padding: 12px 32px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .popup-btn:hover {
            background-color: #333333;
        }
        
        .popup-btn.success {
            background-color: #28a745;
        }
        
        .popup-btn.success:hover {
            background-color: #218838;
        }
        
        .popup-btn.error {
            background-color: #dc3545;
        }
        
        .popup-btn.error:hover {
            background-color: #c82333;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo APP_NAME; ?></h1>
        <div class="user-info">
            <span class="user-badge">
                <?php echo htmlspecialchars($usuario['nombre_completo']); ?>
            </span>
            <a href="../logout.php" class="logout-btn">Cerrar Sesión</a>
        </div>
    </div>
    
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-section">
                <a href="../dashboard.php" class="sidebar-item">
                    Inicio
                </a>
            </div>
            
            <div class="sidebar-section">
                <a href="../token/generar_token.php" class="sidebar-item">
                    Generación de Token
                </a>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('direccionamiento')">
                    <span>Direccionamiento</span>
                    <span class="arrow" id="arrow-direccionamiento">▶</span>
                </div>
                <div class="sidebar-children" id="menu-direccionamiento">
                    <a href="../direccionamiento/por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripción</a>
                    <a href="../direccionamiento/por_fecha.php" class="sidebar-item sidebar-child">Por Fecha</a>
                    <a href="../direccionamiento/por_paciente.php" class="sidebar-item sidebar-child">Por paciente</a>
                    
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children" id="menu-programacion">
                     <a href="../programacion/realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programación</a>
                    <a href="../programacion/consultar_programacion.php" class="sidebar-item sidebar-child">Consultar por Prescripción</a>
                    <a href="../programacion/consultar_por_fecha.php" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="../programacion/consultar_por_paciente.php" class="sidebar-item sidebar-child">Consultar por Paciente</a>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('entrega')">
                    <span>Entrega</span>
                    <span class="arrow open" id="arrow-entrega">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-entrega">
                    <a href="registrar_entrega.php" class="sidebar-item sidebar-child active">Registrar Entrega</a>
                    <a href="consultar_entrega.php" class="sidebar-item sidebar-child">Consultar por No. Prescripción</a>
		 <a href="historico_entregas.php" class="sidebar-item sidebar-child">Historico Entregas</a>
                </div>
            </div>

	   <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('reporte_entrega')">
                    <span>Reporte de Entrega</span>
                    <span class="arrow" id="arrow-reporte_entrega">▶</span>
                </div>
                <div class="sidebar-children" id="menu-reporte_entrega">
                    <a href="../reporte_entrega/generar_reporte.php" class="sidebar-item sidebar-child">Generar Reporte</a>
                    <a href="../reporte_entrega/consultar_reporte.php" class="sidebar-item sidebar-child">Consultar Reporte</a>
                </div>
            </div>
            
            <?php if ($isAdmin): ?>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('admin')">
                    <span>Administración</span>
                    <span class="arrow" id="arrow-admin">▶</span>
                </div>
                <div class="sidebar-children" id="menu-admin">
                    <a href="../admin/crear_usuario.php" class="sidebar-item sidebar-child">Crear Usuarios</a>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        
        <main class="main-content">
            <?php 
            $token_display_path = __DIR__ . '/../includes/token_display.php';
            if (file_exists($token_display_path)) {
                include $token_display_path;
            }
            ?>
            
            <div class="card">
                <h2>Registrar Entrega</h2>
                <p class="card-subtitle">Registra la entrega de medicamentos en la API de MIPRES</p>
                
                <?php if ($mensaje && !isset($mensaje_detalle)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="entregaForm">
                    <input type="hidden" name="enviar_entrega" value="1">
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="nit">NIT *</label>
                            <input 
                                type="text" 
                                id="nit" 
                                name="nit" 
                                required
                                value="<?php echo htmlspecialchars($datos_precargados['nit'] ?? $nit_sesion); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="token_temporal">Token Temporal *</label>
                            <input 
                                type="text" 
                                id="token_temporal" 
                                name="token_temporal" 
                                required
                                value="<?php echo htmlspecialchars(trim($datos_precargados['token_temporal'] ?? $token_sesion, '"')); ?>"
                            >
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-add" onclick="addItem()">+ Agregar Item</button>
                    
                    <div class="items-container" id="itemsContainer">
                        <!-- Items will be added here dynamically -->
                    </div>
                    
                    <button type="button" class="btn" onclick="showConfirmation()">ENTREGA</button>
                </form>
            </div>
        </main>
    </div>
    
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Confirmar Entrega</div>
            <div class="modal-body">
                ¿ESTA SEGURO DE LA INFORMACION SUMINISTRADA?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideConfirmation()">NO</button>
                <button type="button" class="btn" onclick="submitForm()">SI</button>
            </div>
        </div>
    </div>
    
    <?php if (isset($mensaje_detalle)): ?>
    <div id="popupNotification" class="popup-overlay show">
        <div class="popup-content">
            <div class="popup-icon <?php echo $tipo_mensaje; ?>">
                <?php echo $tipo_mensaje === 'success' ? '✓' : '✗'; ?>
            </div>
            <div class="popup-title"><?php echo htmlspecialchars($mensaje); ?></div>
            <div class="popup-message"><?php echo htmlspecialchars($mensaje_detalle); ?></div>
            <button type="button" class="popup-btn <?php echo $tipo_mensaje; ?>" onclick="closePopup()">
                Aceptar
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        let itemCount = 0;
        const prefilledData = {
            id: '<?php echo addslashes($datos_precargados['id'] ?? ''); ?>',
            codSerTec: '<?php echo addslashes($datos_precargados['cod_ser_tec'] ?? ''); ?>',
            cantTotal: '<?php echo addslashes($datos_precargados['cant_total'] ?? ''); ?>',
            tipoIdPaciente: '<?php echo addslashes($datos_precargados['tipo_id_paciente'] ?? ''); ?>',
            noIdPaciente: '<?php echo addslashes($datos_precargados['no_id_paciente'] ?? ''); ?>',
            fecDireccionamiento: '<?php echo addslashes($datos_precargados['fec_direccionamiento'] ?? ''); ?>'
        };
        
        // Datos de programaciones masivas desde sesión
        const programacionesMasivas = <?php 
            $prog_masivas = $_SESSION['programaciones_masivas'] ?? [];
            echo json_encode($prog_masivas);
        ?>;
        
        // Cargar items de programaciones masivas cuando se carga la página
        window.addEventListener('load', function() {
            // Solo si hay programaciones masivas y no hay datos precargados individuales
            if (programacionesMasivas.length > 0 && !prefilledData.id) {
                const itemsContainer = document.getElementById('itemsContainer');
                
                // LIMPIAR todos los items existentes
                itemsContainer.innerHTML = '';
                itemCount = 0;
                
                // Crear un item por cada programación
                programacionesMasivas.forEach((prog, idx) => {
                    addItem();
                    // Obtener el último item agregado
                    const allItems = itemsContainer.querySelectorAll('.item-card');
                    const lastItem = allItems[allItems.length - 1];
                    
                    if (lastItem) {
                        lastItem.querySelector('input[name="id[]"]').value = prog.id || '';
                        lastItem.querySelector('input[name="cod_ser_tec_entregado[]"]').value = prog.codSerTec || '';
                        lastItem.querySelector('input[name="cant_tot_entregada[]"]').value = prog.cantTotal || '';
                    }
                });
                
                // Guardar datos iniciales en localStorage
                guardarDatos();
            }
        });
        
        // Función para guardar los datos del formulario en localStorage
        function guardarDatos() {
            const nit = document.getElementById('nit').value;
            const token = document.getElementById('token_temporal').value;
            const items = [];
            
            // Obtener todos los items del formulario
            const itemsContainer = document.getElementById('itemsContainer');
            const itemDivs = itemsContainer.querySelectorAll('.item-field');
            
            itemDivs.forEach((item, idx) => {
                const itemData = {
                    id: item.querySelector('input[name="id[]"]')?.value || '',
                    codSerTecEntregado: item.querySelector('input[name="cod_ser_tec_entregado[]"]')?.value || '',
                    cantTotEntregada: item.querySelector('input[name="cant_tot_entregada[]"]')?.value || '',
                    entTotal: item.querySelector('input[name="ent_total[]"]')?.value || '',
                    causaNoEntrega: item.querySelector('select[name="causa_no_entrega[]"]')?.value || '',
                    fecEntrega: item.querySelector('input[name="fec_entrega[]"]')?.value || '',
                    noLote: item.querySelector('input[name="no_lote[]"]')?.value || '',
                    tipoIdRecibe: item.querySelector('select[name="tipo_id_recibe[]"]')?.value || '',
                    noIdRecibe: item.querySelector('input[name="no_id_recibe[]"]')?.value || ''
                };
                items.push(itemData);
            });
            
            // Guardar en localStorage
            localStorage.setItem('entrega_data', JSON.stringify({
                nit: nit,
                token: token,
                items: items
            }));
        }
        
        // Restaurar datos desde localStorage si existen
        function restaurarDatos() {
            const datosGuardados = localStorage.getItem('entrega_data');
            if (datosGuardados) {
                try {
                    const data = JSON.parse(datosGuardados);
                    
                    // Restaurar NIT y Token
                    document.getElementById('nit').value = data.nit || '';
                    document.getElementById('token_temporal').value = data.token || '';
                    
                    // Limpiar y restaurar items
                    const itemsContainer = document.getElementById('itemsContainer');
                    itemsContainer.innerHTML = '';
                    itemCount = 0;
                    
                    data.items.forEach((item, idx) => {
                        addItem();
                        const itemDiv = itemsContainer.querySelectorAll('.item-field')[idx];
                        if (itemDiv) {
                            itemDiv.querySelector('input[name="id[]"]').value = item.id;
                            itemDiv.querySelector('input[name="cod_ser_tec_entregado[]"]').value = item.codSerTecEntregado;
                            itemDiv.querySelector('input[name="cant_tot_entregada[]"]').value = item.cantTotEntregada;
                            itemDiv.querySelector('input[name="ent_total[]"]').value = item.entTotal;
                            itemDiv.querySelector('select[name="causa_no_entrega[]"]').value = item.causaNoEntrega;
                            itemDiv.querySelector('input[name="fec_entrega[]"]').value = item.fecEntrega;
                            itemDiv.querySelector('input[name="no_lote[]"]').value = item.noLote;
                            itemDiv.querySelector('select[name="tipo_id_recibe[]"]').value = item.tipoIdRecibe;
                            itemDiv.querySelector('input[name="no_id_recibe[]"]').value = item.noIdRecibe;
                        }
                    });
                    
                    // No limpiar localStorage aquí, solo cuando sea exitoso
                } catch (e) {
                    console.log('Error restaurando datos:', e);
                }
            }
        }
        
        // Interceptar el envío del formulario
        function submitForm() {
            guardarDatos();
            document.getElementById('entregaForm').submit();
        }
        
        
        console.log('[v0] Datos precargados en JavaScript:', prefilledData);
        const usePrefilledData = itemCount === 1 && prefilledData.id;
        console.log('[v0] usePrefilledData:', usePrefilledData);

        // Interceptar el envío del formulario
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('entregaForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Guardar datos antes de enviar
                    guardarDatos();
                    
                    // Hacer el envío con fetch para capturar la respuesta
                    e.preventDefault();
                    
                    const formData = new FormData(form);
                    
                    fetch(form.action || '', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        // Si es exitoso (código 200-299), limpiar localStorage
                        if (response.ok) {
                            localStorage.removeItem('entrega_data');
                        }
                        // Independientemente de la respuesta, redirigir o mostrar el resultado
                        return response.text();
                    })
                    .then(html => {
                        // Mostrar la respuesta del servidor
                        document.open();
                        document.write(html);
                        document.close();
                    })
                    .catch(error => {
                        console.log('[v0] Error en envío:', error);
                        alert('Error al enviar el formulario. Los datos se han guardado y podrás reintentar.');
                    });
                });
            }
        });
                
            
        
        
        function addItem() {
            itemCount++;
            const container = document.getElementById('itemsContainer');
            
            const usePrefilledData = itemCount === 1 && prefilledData.id;
            const prefilledClass = usePrefilledData ? 'prefilled' : '';
            
            const itemHtml = `
                <div class="item-card" id="item-${itemCount}">
                    <div class="item-header">
                        <span class="item-number">Item #${itemCount}</span>
                        <button type="button" class="btn-remove" onclick="removeItem(${itemCount})">Eliminar</button>
                    </div>
                    
                    <div class="grid-3">
                        <div class="form-group">
                            <label>ID * ${usePrefilledData ? '(Editable)' : ''}</label>
                            <input type="text" name="id[]" class="${prefilledClass}" value="${usePrefilledData ? prefilledData.id : ''}" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Código Servicio Tecnología * ${usePrefilledData ? '(Editable)' : ''}</label>
                            <input type="text" name="cod_ser_tec_entregado[]" class="${prefilledClass}" value="${usePrefilledData ? prefilledData.codSerTec : ''}" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Cantidad Total Entregada * ${usePrefilledData ? '(Editable)' : ''}</label>
                            <input type="number" name="cant_tot_entregada[]" class="${prefilledClass}" value="${usePrefilledData ? prefilledData.cantTotal : ''}" required min="1" step="0.01">
                        </div>
                    </div>
                    
                    <div class="grid-3">
                        <div class="form-group">
                            <label>Entrega Total *</label>
                            <select name="ent_total[]" required>
                                <option value="">Seleccione...</option>
                                <option value="1">SI</option>
                                <option value="0">NO</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Causa No Entrega *</label>
                            <select name="causa_no_entrega[]" required>
                                <option value="0">NINGUNA</option>
                                <option value="1">NO SE ENCONTRO EL PACIENTE</option>
                                <option value="2">FALLECIDO</option>
                                <option value="3">SE NIEGA A RECIBIR EL SUMINISTRO</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Fecha de Entrega *</label>
                            <!-- Mapear la fecha de direccionamiento como fecha de entrega por defecto -->
                            <input type="date" name="fec_entrega[]" class="${usePrefilledData ? 'prefilled' : ''}" value="${usePrefilledData ? prefilledData.fecDireccionamiento : ''}" required>
                        </div>
                    </div>
                    
                    <div class="grid-3">
                        <div class="form-group">
                            <label>No. Lote *</label>
                            <input type="text" name="no_lote[]" required>
                        </div>
                        
                        <div class="form-group">
                            <!-- Mapear tipo ID del paciente como tipo ID de quien recibe -->
                            <label>Tipo ID Recibe * ${usePrefilledData ? '(Editable)' : ''}</label>
                            <input type="text" name="tipo_id_recibe[]" class="${usePrefilledData ? 'prefilled' : ''}" value="${usePrefilledData ? prefilledData.tipoIdPaciente : ''}" required placeholder="CC, TI, CE, etc.">
                        </div>
                        
                        <div class="form-group">
                            <!-- Mapear número ID del paciente como número ID de quien recibe -->
                            <label>No. ID Recibe * ${usePrefilledData ? '(Editable)' : ''}</label>
                            <input type="text" name="no_id_recibe[]" class="${usePrefilledData ? 'prefilled' : ''}" value="${usePrefilledData ? prefilledData.noIdPaciente : ''}" required>
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', itemHtml);
        }
        
        function removeItem(itemId) {
            const item = document.getElementById(`item-${itemId}`);
            if (item) {
                item.remove();
            }
        }
        
        function showConfirmation() {
            const form = document.getElementById('entregaForm');
            if (form.checkValidity()) {
                document.getElementById('confirmModal').classList.add('show');
            } else {
                form.reportValidity();
            }
        }
        
        function hideConfirmation() {
            document.getElementById('confirmModal').classList.remove('show');
        }
        
        function submitForm() {
            document.getElementById('entregaForm').submit();
        }
        
        function closePopup() {
            const popup = document.getElementById('popupNotification');
            if (popup) {
                popup.classList.remove('show');
            }
        }
        
        // Add first item on page load
        window.addEventListener('DOMContentLoaded', function() {
            addItem();
        });
        
        // Limpiar formulario si la operación fue exitosa
        <?php if (isset($limpiar_formulario) && $limpiar_formulario): ?>
        window.addEventListener('load', function() {
            // Limpiar el formulario después de éxito
            const form = document.getElementById('entregaForm');
            if (form) {
                form.reset();
                // Reiniciar el contenedor de items
                document.getElementById('itemsContainer').innerHTML = '';
                itemCount = 0;
                addItem();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
