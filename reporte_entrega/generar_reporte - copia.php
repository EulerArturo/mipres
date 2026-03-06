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

// Variables para mensajes y resultados
$mensaje = '';
$tipo_mensaje = '';

// Verificar si vinimos desde consultar_entrega con IDs pre-llenados
$entregas_pre_llenadas = [];
if (isset($_GET['entregas']) && !empty($_GET['entregas'])) {
    $entregas_ids = explode(',', $_GET['entregas']);
    foreach ($entregas_ids as $id) {
        $id_limpio = trim($id);
        if (!empty($id_limpio)) {
            $entregas_pre_llenadas[] = [
                'ID' => $id_limpio,
                'EstadoEntrega' => 1,
                'CausaNoEntrega' => 0,
                'ValorEntregado' => ''
            ];
        }
    }
}

// Obtener NIT y Token de los parámetros GET si existen
$nit_pre_llenado = isset($_GET['nit']) ? trim($_GET['nit']) : '';
$token_pre_llenado = isset($_GET['token']) ? trim($_GET['token']) : '';

// Procesar envío de reporte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_reporte'])) {
    $nit = trim($_POST['nit'] ?? '');
    $token_temporal = trim($_POST['token_temporal'] ?? '');
    
    // Construir array de reportes desde los datos del formulario
    $reportes = [];
    $num_items = count($_POST['id'] ?? []);
    
    for ($i = 0; $i < $num_items; $i++) {
        if (!empty($_POST['id'][$i])) {
            $reporte = [
                'ID' => (int)($_POST['id'][$i]),
                'EstadoEntrega' => (int)($_POST['estado_entrega'][$i] ?? 1),
                'CausaNoEntrega' => (int)($_POST['causa_no_entrega'][$i] ?? 0),
                'ValorEntregado' => (string)($_POST['valor_entregado'][$i] ?? '')
            ];
            $reportes[] = $reporte;
        }
    }
    
    // Validaciones
    if (empty($nit) || empty($token_temporal)) {
        $mensaje = 'Por favor complete los campos de NIT y Token Temporal para continuar con el envío del reporte';
        $tipo_mensaje = 'error';
    } elseif (empty($reportes)) {
        $mensaje = 'Debe agregar al menos un reporte para enviar';
        $tipo_mensaje = 'error';
    } else {
        $token_procesado = rtrim($token_temporal, '=');
        
        $resultados = [];
        $errores = [];
        $exitosos = 0;
        
        foreach ($reportes as $index => $reporte) {
            // Procesar token: reemplazar "=" al final con "%3D"
            $token_con_encoding = rtrim($token_temporal, '=') . '%3D';
            
            // Construir URL de la API
            $url = "https://wsmipres.sispro.gov.co/WSSUMMIPRESNOPBS/api/ReporteEntrega/{$nit}/{$token_con_encoding}";
            
            // Datos a enviar
            $json_reporte = json_encode($reporte);
            
            // Inicializar cURL
            $ch = curl_init();
            
            // Configurar opciones de cURL para método PUT
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_reporte);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $headers = array(
                'Accept: application/json',
                'Content-Type: application/json'
            );
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            // Ejecutar petición
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            // Procesar respuesta de cada item
            if ($curl_error) {
                $errores[] = "Item " . ($index + 1) . ": Error de conexión - " . $curl_error;
            } elseif ($http_code === 200 || $http_code === 201) {
                $exitosos++;
                $resultados[] = "Item " . ($index + 1) . ": Reportado exitosamente";
                
                // Guardar en BD cuando es exitoso
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO entregas_reportes_exitosos 
                        (tipo_registro, usuario_id, nombre_usuario, nit, no_prescripcion, id_entrega, estado_entrega, 
                         causa_no_entrega, valor_entregado, fecha_registro, http_code) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([
                        'REPORTE_ENTREGA',
                        $usuario['id'],
                        $usuario['nombre_completo'] ?? $usuario['nombre'] ?? '',
                        $nit,
                        $reporte['ID'] ?? '',
                        $reporte['ID'] ?? '',
                        $reporte['EstadoEntrega'] ?? 1,
                        $reporte['CausaNoEntrega'] ?? 0,
                        $reporte['ValorEntregado'] ?? '',
                        200
                    ]);
                } catch (PDOException $e) {
                    // Log silencioso para no interrumpir el flujo
                    error_log("Error al guardar reporte exitoso: " . $e->getMessage());
                }
            } elseif ($http_code === 401) {
                $errores[] = "Item " . ($index + 1) . ": Token inválido o expirado (401)";
            } elseif ($http_code === 404) {
                $errores[] = "Item " . ($index + 1) . ": Ruta no encontrada (404)";
            } elseif ($http_code === 422) {
                // Error de validación - parsear respuesta JSON
                $response_data = json_decode($response, true);
                $error_msg = $response_data['Message'] ?? 'Error de validación';
                if (isset($response_data['Errors']) && is_array($response_data['Errors'])) {
                    $error_msg .= " - " . implode(", ", $response_data['Errors']);
                }
                $errores[] = "Item " . ($index + 1) . ": " . $error_msg;
            } elseif ($http_code === 400) {
                $errores[] = "Item " . ($index + 1) . ": Solicitud mal formada (400)";
            } elseif ($http_code >= 500) {
                $errores[] = "Item " . ($index + 1) . ": Error en el servidor de MIPRES (" . $http_code . ")";
            } else {
                $errores[] = "Item " . ($index + 1) . ": Error HTTP {$http_code}";
            }
        }
        
        // Construir mensaje final
        if ($exitosos > 0 && empty($errores)) {
            $mensaje = "¡REPORTE ENVIADO EXITOSAMENTE!";
            $mensaje_detalle = "Se enviaron correctamente {$exitosos} reporte(s) de entrega al sistema MIPRES.";
            $tipo_mensaje = 'success';
            $limpiar_formulario = true;
        } elseif ($exitosos > 0 && !empty($errores)) {
            $mensaje = "REPORTE PARCIALMENTE COMPLETADO";
            $mensaje_detalle = "Se enviaron {$exitosos} reporte(s) correctamente. Errores: " . implode(" | ", $errores);
            $tipo_mensaje = 'error';
        } else {
            $mensaje = "NO SE PUDO COMPLETAR EL ENVÍO";
            $errores_texto = implode(" | ", $errores);
            $mensaje_detalle = "Detalles: " . ($errores_texto ?: "No se logró enviar ningún reporte. Por favor verifique los datos ingresados.");
            $tipo_mensaje = 'error';
        }
        
        // Registrar en logs
        if ($exitosos > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles) VALUES (?, ?, ?)");
                $stmt->execute([
                    $usuario['id'],
                    'GENERAR_REPORTE_ENTREGA',
                    "Reportes enviados: {$exitosos} de " . count($reportes)
                ]);
            } catch (PDOException $e) {
                // Error al registrar log
            }
        }
        
        // Limpiar formulario si fue exitoso
        if (isset($limpiar_formulario) && $limpiar_formulario) {
            $_SESSION['token_temporal'] = '';
            $_SESSION['token_nit'] = '';
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
    <title>Generar Reporte de Entrega - <?php echo APP_NAME; ?></title>
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
        
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        
        @media (max-width: 1200px) {
            .grid-4 {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .grid-4 {
                grid-template-columns: 1fr;
            }
        }
        
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
                    <a href="../direccionamiento/por_paciente.php" class="sidebar-item sidebar-child">Por Paciente</a>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children" id="menu-programacion">
                    <a href="../programacion/realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programación</a>
                    <a href="../programacion/consultar_programacion.php" class="sidebar-item sidebar-child">Consultar Programación</a>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('entrega')">
                    <span>Entrega</span>
                    <span class="arrow" id="arrow-entrega">▶</span>
                </div>
                <div class="sidebar-children" id="menu-entrega">
                    <a href="../entrega/registrar_entrega.php" class="sidebar-item sidebar-child">Registrar Entrega</a>
                    <a href="../entrega/consultar_entrega.php" class="sidebar-item sidebar-child">Consultar Entrega</a>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('reporte_entrega')">
                    <span>Reporte de Entrega</span>
                    <span class="arrow open" id="arrow-reporte_entrega">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-reporte_entrega">
                    <a href="generar_reporte.php" class="sidebar-item sidebar-child active">Generar Reporte</a>
                    <a href="consultar_reporte.php" class="sidebar-item sidebar-child">Consultar Reporte</a>
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
            <div class="card">
                <h2>Generar Reporte de Entrega</h2>
                <p class="card-subtitle">Envíe reportes de estado de entrega de medicamentos al sistema MIPRES</p>
                
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <?php if (isset($mensaje_detalle)): ?>
                            <br><small><?php echo htmlspecialchars($mensaje_detalle); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ($tipo_mensaje === 'success'): ?>
                        <script>
                            // Limpiar datos guardados si el envío fue exitoso
                            localStorage.removeItem('reportes_data');
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px;">
                        <div class="form-group">
                            <label for="nit">NIT *</label>
                            <input 
                                type="text" 
                                id="nit" 
                                name="nit" 
                                required
                                value="<?php echo htmlspecialchars($nit_pre_llenado ?: $nit_sesion); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="token_temporal">Token Temporal *</label>
                            <input 
                                type="text" 
                                id="token_temporal" 
                                name="token_temporal" 
                                required
                                value="<?php echo htmlspecialchars($token_pre_llenado ?: trim($token_sesion, '"')); ?>"
                            >
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-add" onclick="agregarReporte()">+ Agregar Reporte</button>
                    
                    <div class="items-container" id="items-container">
                        <!-- Los reportes se agregarán aquí dinámicamente -->
                    </div>
                    
                    <button type="submit" name="enviar_reporte" class="btn" style="width: 100%; margin-top: 24px;">Enviar Reporte</button>
                </form>
            </div>
        </main>
    </div>
    
    <div class="popup-overlay" id="popup-overlay">
        <div class="popup-content">
            <div class="popup-icon" id="popup-icon"></div>
            <div class="popup-title" id="popup-title"></div>
            <div class="popup-message" id="popup-message"></div>
            <button class="popup-btn" onclick="cerrarPopup()">Aceptar</button>
        </div>
    </div>
    
    <script>
        let reporteCount = 0;
        
        function agregarReporte() {
            reporteCount++;
            const container = document.getElementById('items-container');
            
            const itemDiv = document.createElement('div');
            itemDiv.className = 'item-card';
            itemDiv.id = 'reporte-' + reporteCount;
            
            itemDiv.innerHTML = `
                <div class="item-header">
                    <span class="item-number">Reporte #${reporteCount}</span>
                    <button type="button" class="btn-remove" onclick="eliminarReporte(${reporteCount})">Eliminar</button>
                </div>
                
                <div class="grid-4">
                    <div class="form-group">
                        <label>ID *</label>
                        <input 
                            type="number" 
                            name="id[]" 
                            required
                            placeholder="Ej: 123456"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label>Estado de Entrega</label>
                        <select name="estado_entrega[]">
                            <option value="1" selected>1 - Completada</option>
                            <option value="0">0 - Pendiente</option>
                            <option value="2">2 - Parcial</option>
                            <option value="3">3 - No Aplica</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Causa de No Entrega</label>
                        <select name="causa_no_entrega[]">
                            <option value="0" selected>0 - Entregada</option>
                            <option value="1">1 - No Disponible</option>
                            <option value="2">2 - Paciente Rechaza</option>
                            <option value="3">3 - Paciente No Se Presenta</option>
                            <option value="4">4 - Cambio de Medicamento</option>
                            <option value="5">5 - Restricción del Sistema</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Valor Entregado</label>
                        <input 
                            type="text" 
                            name="valor_entregado[]" 
                            placeholder="Ej: 1500"
                        >
                    </div>
                </div>
            `;
            
            container.appendChild(itemDiv);
        }
        
        function eliminarReporte(id) {
            const element = document.getElementById('reporte-' + id);
            if (element) {
                element.remove();
            }
        }
        
        function toggleMenu(menuId) {
            const menu = document.getElementById('menu-' + menuId);
            const arrow = document.getElementById('arrow-' + menuId);
            
            if (menu) {
                menu.classList.toggle('open');
                arrow.classList.toggle('open');
            }
        }
        
        function cerrarPopup() {
            document.getElementById('popup-overlay').classList.remove('show');
        }
        
        // Agregar un reporte inicial
        window.addEventListener('load', function() {
            // Datos de entregas pre-llenadas desde GET (desde consultar_entrega)
            const entregasPreLlenadas = <?php echo json_encode($entregas_pre_llenadas); ?>;
            
            // Restaurar datos del localStorage si existen
            const reportesGuardados = localStorage.getItem('reportes_data');
            if (reportesGuardados && entregasPreLlenadas.length === 0) {
                try {
                    const data = JSON.parse(reportesGuardados);
                    
                    // Restaurar NIT y Token
                    document.getElementById('nit').value = data.nit || '';
                    document.getElementById('token_temporal').value = data.token || '';
                    
                    // Restaurar reportes
                    const container = document.getElementById('items-container');
                    container.innerHTML = ''; // Limpiar contenedor
                    
                    data.reportes.forEach((reporte, idx) => {
                        agregarReporte();
                        // Obtener el último elemento agregado (el más reciente)
                        const itemCards = document.querySelectorAll('.item-card');
                        const itemCard = itemCards[itemCards.length - 1];
                        if (itemCard) {
                            itemCard.querySelector('input[name="id[]"]').value = reporte.id;
                            itemCard.querySelector('select[name="estado_entrega[]"]').value = reporte.estado;
                            itemCard.querySelector('select[name="causa_no_entrega[]"]').value = reporte.causa;
                            itemCard.querySelector('input[name="valor_entregado[]"]').value = reporte.valor;
                        }
                    });
                    
                    // Limpiar localStorage ya que se restauró
                    localStorage.removeItem('reportes_data');
                } catch (e) {
                    console.log('Error restaurando datos:', e);
                }
            } else if (entregasPreLlenadas.length > 0) {
                // Llenar con entregas desde GET
                const container = document.getElementById('items-container');
                container.innerHTML = ''; // Limpiar contenedor
                
                entregasPreLlenadas.forEach((entrega, idx) => {
                    agregarReporte();
                    // Obtener el último elemento agregado (el más reciente)
                    const itemCards = document.querySelectorAll('.item-card');
                    const itemCard = itemCards[itemCards.length - 1];
                    if (itemCard) {
                        itemCard.querySelector('input[name="id[]"]').value = entrega.ID;
                        itemCard.querySelector('select[name="estado_entrega[]"]').value = entrega.EstadoEntrega;
                        itemCard.querySelector('select[name="causa_no_entrega[]"]').value = entrega.CausaNoEntrega;
                        itemCard.querySelector('input[name="valor_entregado[]"]').value = entrega.ValorEntregado;
                    }
                });
                
                // Limpiar localStorage si hay entregas pre-llenadas
                localStorage.removeItem('reportes_data');
            } else if (document.getElementById('items-container').children.length === 0) {
                agregarReporte();
            }
            
            // Interceptar el envío del formulario para guardar datos
            const formulario = document.querySelector('form');
            if (formulario) {
                formulario.addEventListener('submit', function(e) {
                    const nit = document.getElementById('nit').value;
                    const token = document.getElementById('token_temporal').value;
                    const reportes = [];
                    
                    // Recolectar datos de todos los reportes
                    const items = document.querySelectorAll('.item-card');
                    items.forEach((item, idx) => {
                        const id = item.querySelector('input[name="id[]"]').value;
                        const estado = item.querySelector('select[name="estado_entrega[]"]').value;
                        const causa = item.querySelector('select[name="causa_no_entrega[]"]').value;
                        const valor = item.querySelector('input[name="valor_entregado[]"]').value;
                        
                        reportes.push({
                            id: id,
                            estado: estado,
                            causa: causa,
                            valor: valor
                        });
                    });
                    
                    // Guardar en localStorage antes de enviar
                    localStorage.setItem('reportes_data', JSON.stringify({
                        nit: nit,
                        token: token,
                        reportes: reportes
                    }));
                });
            }
        });
    </script>
</body>
</html>
