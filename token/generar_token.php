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

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$token_generado = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nit = trim($_POST['nit'] ?? '');
    $token_proveedor = trim($_POST['token_proveedor'] ?? '');
    $mipresApiClient = new MipresApiClient();
    
    // Validaciones
    if (empty($nit)) {
        $mensaje = 'Por favor ingrese el NIT de su organización para continuar';
        $tipo_mensaje = 'error';
    } elseif (empty($token_proveedor)) {
        $mensaje = 'Por favor ingrese el Token Proveedor proporcionado por MIPRES';
        $tipo_mensaje = 'error';
    } else {
        $apiResult = $mipresApiClient->get(
            "GenerarToken/{$nit}/{$token_proveedor}",
            30
        );

        $response = $apiResult['raw_response'];
        $http_code = $apiResult['http_code'];
        $curl_error = $apiResult['curl_error'];
        
        // Procesar respuesta
        if ($curl_error) {
            $mensaje = 'No se pudo conectar con el servidor de MIPRES. Verifique su conexión a internet e intente nuevamente';
            $tipo_mensaje = 'error';
        } elseif ($http_code === 200) {
            // Intentar decodificar JSON
            $data = json_decode($response, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Si la respuesta es un objeto JSON con el token
                if (isset($data['token'])) {
                    $token_generado = trim($data['token'], '"');
                } elseif (isset($data['Token'])) {
                    $token_generado = trim($data['Token'], '"');
                } else {
                    // Si la respuesta completa es el token
                    $token_generado = trim($response, '"');
                }
            } else {
                // Si no es JSON, la respuesta completa es el token - remove quotes
                $token_generado = trim($response, '"');
            }
            
            $mensaje = '¡Token generado correctamente! Puede utilizarlo durante las próximas 24 horas';
            $tipo_mensaje = 'success';
            
            $_SESSION['token_temporal'] = $token_generado;
            $_SESSION['token_timestamp'] = time();
            $_SESSION['token_nit'] = $nit;
            
            // Registrar en logs
            registrar_log_actividad($pdo, $usuario['id'], 'GENERAR_TOKEN', 'Token temporal generado correctamente');
        } elseif ($http_code === 401) {
            $mensaje = 'Las credenciales ingresadas son incorrectas. Por favor verifique que el NIT y Token Proveedor sean correctos';
            $tipo_mensaje = 'error';
        } elseif ($http_code === 404) {
            $mensaje = 'El servicio de MIPRES no está disponible en este momento. Por favor intente más tarde';
            $tipo_mensaje = 'error';
        } elseif ($http_code >= 500) {
            $mensaje = 'El servidor de MIPRES está experimentando problemas técnicos. Por favor intente nuevamente en unos minutos';
            $tipo_mensaje = 'error';
        } else {
            $mensaje = "No se pudo generar el token. Si el problema persiste, contacte al soporte técnico (Código: {$http_code})";
            $tipo_mensaje = 'error';
        }

        if ($tipo_mensaje === 'error') {
            registrar_log_actividad($pdo, $usuario['id'], 'GENERAR_TOKEN_ERROR', 'No se pudo obtener token temporal desde API externa');
        }
    }
}

if (isset($_SESSION['token_temporal']) && !$token_generado) {
    $token_generado = $_SESSION['token_temporal'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Token - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif;
            background-color: #f8f9fa;
            color: #212529;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Header profesional en blanco y negro consistente con dashboard */
        .header {
            background-color: #000000;
            color: white;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid #e9ecef;
        }
        
        .header h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
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
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background-color: #e9ecef;
        }
        
        .layout {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }
        
        /* Sidebar profesional en blanco y negro consistente con dashboard */
        .sidebar {
            width: 280px;
            background-color: #ffffff;
            border-right: 1px solid #e9ecef;
            padding: 24px 0;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-section {
            margin-bottom: 4px;
        }
        
        .sidebar-item {
            padding: 12px 24px;
            color: #495057;
            text-decoration: none;
            display: block;
            transition: all 0.2s ease;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid transparent;
        }
        
        .sidebar-item:hover {
            background-color: #f8f9fa;
            color: #000000;
            border-left-color: #dee2e6;
        }
        
        .sidebar-item.active {
            background-color: #000000;
            color: #ffffff;
            border-left-color: #000000;
        }
        
        .sidebar-parent {
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #212529;
        }
        
        .sidebar-children {
            display: none;
            background-color: #f8f9fa;
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
            display: flex;
            flex-direction: column;
            background-color: #f8f9fa;
        }
        
        .content-wrapper {
            padding: 40px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .back-link {
            display: inline-block;
            color: #000000;
            text-decoration: none;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .back-link:hover {
            color: #495057;
        }
        
        /* Cards profesionales en blanco y negro */
        .card {
            background: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e9ecef;
        }
        
        .card h2 {
            color: #000000;
            margin-bottom: 12px;
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }
        
        .card-subtitle {
            color: #6c757d;
            font-size: 15px;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: 14px;
            border: 1px solid;
        }
        
        .alert-success {
            background-color: #f8f9fa;
            color: #000000;
            border-color: #dee2e6;
        }
        
        .alert-error {
            background-color: #f8f9fa;
            color: #000000;
            border-color: #dee2e6;
        }
        
        .alert-info {
            background-color: #f8f9fa;
            color: #000000;
            border-color: #dee2e6;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #212529;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
            background-color: #ffffff;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #000000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            font-family: 'Courier New', monospace;
            background-color: #f8f9fa;
        }
        
        .form-group small {
            display: block;
            margin-top: 6px;
            color: #6c757d;
            font-size: 13px;
        }
        
        /* Botón profesional en negro */
        .btn {
            background-color: #000000;
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .btn:hover {
            background-color: #212529;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .token-display {
            background-color: #f8f9fa;
            border: 2px solid #000000;
            border-radius: 6px;
            padding: 20px;
            margin-top: 24px;
        }
        
        .token-display label {
            display: block;
            color: #000000;
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 14px;
        }
        
        .token-value {
            background-color: #ffffff;
            padding: 16px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            word-break: break-all;
            color: #212529;
            border: 1px solid #dee2e6;
        }
        
        .copy-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            margin-top: 12px;
            transition: all 0.2s ease;
        }
        
        .copy-btn:hover {
            background-color: #495057;
        }
        
        .warning-box {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 16px 20px;
            margin-top: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .warning-box .icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .warning-box .text {
            color: #495057;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-section">
                <a href="../dashboard.php" class="sidebar-item">
                    Inicio
                </a>
            </div>
            
            <div class="sidebar-section">
                <a href="generar_token.php" class="sidebar-item active">
                    Generación de Token
                </a>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('direccionamiento')">
                    <span>Direccionamiento</span>
                    <span class="arrow" id="arrow-direccionamiento">▶</span>
                </div>
                <div class="sidebar-children" id="menu-direccionamiento">
                    <a href="../direccionamiento/por_fecha.php" class="sidebar-item sidebar-child">Por Fecha</a>
                    <a href="../direccionamiento/por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripción</a>
                    <a href="../direccionamiento/por_paciente.php"class="sidebar-item sidebar-child">Por Paciente</a>
                    
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children" id="menu-programacion">
                    <a href="../programacion/realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programación</a>
                    <a href=../programacion/consultar_por_fecha.php" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="../programacion/consultar_programacion.php" class="sidebar-item sidebar-child">Consultar por Prescripción</a>
                    <a href="../programacion/consultar_por_paciente.php" class="sidebar-item sidebar-child">Consultar por Paciente</a>
                </div>
            </div>
            
            <!-- Agregada sección de Entrega -->
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('entrega')">
                    <span>Entrega</span>
                    <span class="arrow" id="arrow-entrega">▶</span>
                </div>
                <div class="sidebar-children" id="menu-entrega">
                    <a href="../entrega/registrar_entrega.php" class="sidebar-item sidebar-child">Registrar Entrega</a>
                    <a href="#" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="#" class="sidebar-item sidebar-child">Consultar por Prescripción</a>
                    <a href="#" class="sidebar-item sidebar-child">Consultar por Paciente</a>
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
                    <a href="#" class="sidebar-item sidebar-child">Generar Reportes</a>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        
        <main class="main-content">
            <div class="header">
                <h1><?php echo APP_NAME; ?></h1>
                <div class="user-info">
                    <span class="user-badge">
                        <?php echo htmlspecialchars($usuario['nombre_completo']); ?>
                    </span>
                    <a href="../logout.php" class="logout-btn">Cerrar Sesión</a>
                </div>
            </div>
            
            <div class="content-wrapper">
                <div class="container">
                    <a href="../dashboard.php" class="back-link">← Volver al Dashboard</a>
                    
                    <div class="card">
                        <h2>Generar Token Temporal</h2>
                        <p class="card-subtitle">Genera un token de acceso para consumir los servicios de MIPRES</p>
                        
                        <?php if ($mensaje): ?>
                            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                                <?php echo htmlspecialchars($mensaje); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="form-group">
                                <label for="nit">NIT *</label>
                                <input 
                                    type="text" 
                                    id="nit" 
                                    name="nit" 
                                    required
                                    placeholder="Ingrese el NIT"
                                    value="<?php echo htmlspecialchars($_POST['nit'] ?? ''); ?>"
                                >
                                <small>Número de Identificación Tributaria del proveedor</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="token_proveedor">Token Proveedor *</label>
                                <input 
                                    type="text" 
                                    id="token_proveedor" 
                                    name="token_proveedor" 
                                    required
                                    placeholder="Ingrese el Token Proveedor"
                                    value="<?php echo htmlspecialchars($_POST['token_proveedor'] ?? ''); ?>"
                                >
                                <small>Token proporcionado por MIPRES para su organización</small>
                            </div>
                            
                            <button type="submit" class="btn">Generar Token</button>
                        </form>
                        
                        <?php if ($token_generado): ?>
                            <div class="token-display">
                                <label>Token Temporal Generado:</label>
                                <div class="token-value" id="tokenValue">
                                    <?php echo htmlspecialchars(trim($token_generado, '"')); ?>
                                </div>
                                <button class="copy-btn" onclick="copiarToken()">Copiar Token</button>
                            </div>
                            
                            <div class="warning-box">
                                <span class="icon">⚠️</span>
                                <span class="text">
                                    Este token tiene una validez de 24 horas. Después de este tiempo deberá generar uno nuevo.
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
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
        
        function copiarToken() {
            const tokenValue = document.getElementById('tokenValue').textContent;
            
            // Crear elemento temporal para copiar
            const tempInput = document.createElement('textarea');
            tempInput.value = tokenValue;
            document.body.appendChild(tempInput);
            tempInput.select();
            
            try {
                document.execCommand('copy');
                alert('Token copiado al portapapeles');
            } catch (err) {
                alert('Error al copiar el token');
            }
            
            document.body.removeChild(tempInput);
        }
    </script>
</body>
</html>
