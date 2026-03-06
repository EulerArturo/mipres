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
$respuesta_api = null;

// Procesar consulta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consultar'])) {
    $nit = trim($_POST['nit'] ?? '');
    $token_temporal = trim($_POST['token_temporal'] ?? '');
    $no_prescripcion = trim($_POST['no_prescripcion'] ?? '');
    
    // Validaciones
    if (empty($nit) || empty($token_temporal) || empty($no_prescripcion)) {
        $mensaje = 'Por favor complete todos los campos (NIT, Token y Número de Prescripción) para realizar la consulta';
        $tipo_mensaje = 'error';
    } else {
        // Procesar token: quitar "=" y agregar %3D al final
        $token_procesado = rtrim($token_temporal, '=');
        
        // Construir URL de la API
        $url = "https://wsmipres.sispro.gov.co/WSSUMMIPRESNOPBS/api/EntregaXPrescripcion/{$nit}/{$token_procesado}%3D/{$no_prescripcion}";
        
        // Inicializar cURL
        $ch = curl_init();
        
        // Configurar opciones de cURL para método GET
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json'
        ));
        
        // Ejecutar petición
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        curl_close($ch);
        
        // Procesar respuesta
        if ($curl_error) {
            $mensaje = 'No se pudo conectar con el servidor de MIPRES. Verifique su conexión a internet e intente nuevamente';
            $tipo_mensaje = 'error';
        } elseif ($http_code === 200) {
            $mensaje = '✓ Se encontró una entrega registrada para esta prescripción';
            $tipo_mensaje = 'success';
            $respuesta_api = json_decode($response, true);
        } elseif ($http_code === 404) {
            $mensaje = 'No se encontró ninguna entrega registrada para el número de prescripción consultado';
            $tipo_mensaje = 'error';
        } elseif ($http_code === 401) {
            $mensaje = 'El token ha expirado o no es válido. Por favor genere un nuevo token temporal e intente nuevamente';
            $tipo_mensaje = 'error';
        } elseif ($http_code >= 500) {
            $mensaje = 'El servidor de MIPRES no está disponible en este momento. Por favor intente nuevamente en unos minutos';
            $tipo_mensaje = 'error';
        } else {
            $mensaje = 'Ocurrió un error al consultar la entrega. Si el problema persiste, contacte al soporte técnico (Código: ' . $http_code . ')';
            $tipo_mensaje = 'error';
        }
        
        // Registrar en logs
        try {
            $stmt = $pdo->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles) VALUES (?, ?, ?)");
            $stmt->execute([
                $usuario['id'],
                'CONSULTAR_ENTREGA',
                "Consulta de entrega - Prescripción: {$no_prescripcion}, Resultado: {$mensaje}"
            ]);
        } catch (PDOException $e) {
            // Error al registrar log
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
    <title>Consultar Entrega - <?php echo APP_NAME; ?></title>
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
        
        .form-horizontal {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 24px;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #000000;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s ease;
        }
        
        .form-group input:focus {
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
            white-space: nowrap;
        }
        
        .btn:hover {
            background-color: #333333;
        }
        
        .result-box {
            padding: 20px;
            border-radius: 8px;
            margin-top: 24px;
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            border: 2px solid;
        }
        
        .result-box.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #28a745;
        }
        
        .result-box.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #dc3545;
        }
        
        .response-data {
            background-color: #f8f9fa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            padding: 16px;
            margin-top: 16px;
            text-align: left;
        }
        
        .response-data pre {
            margin: 0;
            font-size: 13px;
            color: #000000;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .records-container {
            margin-top: 20px;
        }

        .record-item {
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .record-item h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        .record-fields {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 15px;
        }

        .field-group {
            display: flex;
            flex-direction: column;
        }

        .field-group label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .field-value {
            font-size: 14px;
            color: #000;
            padding: 8px;
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            word-break: break-word;
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
                    <a href="#" class="sidebar-item sidebar-child">Consultar Direccionamiento</a>
                    <a href="#" class="sidebar-item sidebar-child">Anular Direccionamiento</a>
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
                    <a href="registrar_entrega.php" class="sidebar-item sidebar-child">Registrar Entrega</a>
                    <a href="consultar_entrega.php" class="sidebar-item sidebar-child active">Consultar por No. Prescripción</a>
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
                <h2>Consultar Entrega por No. Prescripción</h2>
                <p class="card-subtitle">Consulta si existe una entrega registrada para un número de prescripción</p>
                
                <form method="POST" action="">
                    <div class="form-horizontal">
                        <div class="form-group">
                            <label for="nit">NIT *</label>
                            <input 
                                type="text" 
                                id="nit" 
                                name="nit" 
                                required
                                value="<?php echo htmlspecialchars($nit_sesion); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="token_temporal">Token Temporal *</label>
                            <input 
                                type="text" 
                                id="token_temporal" 
                                name="token_temporal" 
                                required
                                value="<?php echo htmlspecialchars(trim($token_sesion, '"')); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="no_prescripcion">No. Prescripción *</label>
                            <input 
                                type="text" 
                                id="no_prescripcion" 
                                name="no_prescripcion" 
                                required
                                placeholder="Ej: 123456789"
                            >
                        </div>
                        
                        <button type="submit" name="consultar" class="btn">Consultar</button>
                    </div>
                </form>
                
                <?php if ($mensaje): ?>
                    <div class="result-box <?php echo $tipo_mensaje; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                    
                    <?php if ($respuesta_api): ?>
                        <div class="records-container">
                            <div class="selection-controls" style="margin-bottom: 20px;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                    <input type="checkbox" id="select-all" style="width: 20px; height: 20px; cursor: pointer;">
                                    <span>Seleccionar todos</span>
                                </label>
                            </div>
                            
                            <?php 
                            // Mapeo de claves a labels legibles
                            $fieldLabels = [
                                'ID' => 'ID',
                                'IDEntrega' => 'ID Entrega',
                                'NoEntrega' => 'No. Entrega',
                                'NoPrescripcion' => 'No. Prescripción',
                                'TipoTec' => 'Tipo Tec',
                                'ConTec' => 'Con Tec',
                                'TipoIDPaciente' => 'Tipo ID Paciente',
                                'NoIDPaciente' => 'No. ID Paciente',
                                'CodSertecaentregar' => 'Cod Ser Tec a Entregar',
                                'TipoIDRecibe' => 'Tipo ID Recibe',
                                'NoIDRecibe' => 'No. ID Recibe',
                                'EstEntrega' => 'Est. Entrega',
                                'FecEntrega' => 'Fec. Entrega',
                                'FecAnulacion' => 'Fec. Anulación',
                                'NeLote' => 'Ne Lote',
                                'CantTotEntregada' => 'Cant. Tot. Entregada',
                                'EntTotal' => 'Ent. Total',
                                'CausaNoEntrega' => 'Causa No Entrega',
                                'Fecentrega' => 'Fec. Entrega',
                                'CodEntrega' => 'Cod. Entrega'
                            ];

                            if (is_array($respuesta_api) && count($respuesta_api) > 0):
                                foreach ($respuesta_api as $index => $record): 
                                    if (is_array($record)):
                                        $entrega_id = isset($record['ID']) ? $record['ID'] : (isset($record['IDEntrega']) ? $record['IDEntrega'] : $index);
                            ?>
                                <div class="record-item" style="border: 2px solid #e0e0e0; padding: 15px; margin-bottom: 15px; border-radius: 8px;">
                                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                        <input type="checkbox" class="entrega-checkbox" data-id="<?php echo htmlspecialchars($entrega_id); ?>" style="width: 20px; height: 20px; cursor: pointer;">
                                        <h3 style="margin: 0;">Registro #<?php echo ($index + 1); ?> - ID: <?php echo htmlspecialchars($entrega_id); ?></h3>
                                    </div>
                                    <div class="record-fields">
                                        <?php 
                                        $fieldCount = 0;
                                        foreach ($record as $key => $value): 
                                            $label = isset($fieldLabels[$key]) ? $fieldLabels[$key] : str_replace([' ', '_'], ' ', ucwords(str_replace('_', ' ', $key)));
                                            $fieldValue = is_array($value) ? json_encode($value) : ($value ?? 'N/A');
                                        ?>
                                            <div class="field-group">
                                                <label><?php echo htmlspecialchars($label); ?>:</label>
                                                <div class="field-value"><?php echo htmlspecialchars($fieldValue); ?></div>
                                            </div>
                                        <?php 
                                            $fieldCount++;
                                            if ($fieldCount % 3 == 0): 
                                        ?>
                                            </div><div class="record-fields">
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                    endif;
                                endforeach;
                            else:
                                $entrega_id = isset($respuesta_api['ID']) ? $respuesta_api['ID'] : (isset($respuesta_api['IDEntrega']) ? $respuesta_api['IDEntrega'] : 0);
                            ?>
                                <div class="record-item" style="border: 2px solid #e0e0e0; padding: 15px; margin-bottom: 15px; border-radius: 8px;">
                                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                        <input type="checkbox" class="entrega-checkbox" data-id="<?php echo htmlspecialchars($entrega_id); ?>" style="width: 20px; height: 20px; cursor: pointer;">
                                        <h3 style="margin: 0;">Registro #1 - ID: <?php echo htmlspecialchars($entrega_id); ?></h3>
                                    </div>
                                    <div class="record-fields">
                                        <?php 
                                        $fieldCount = 0;
                                        foreach ($respuesta_api as $key => $value): 
                                            $label = isset($fieldLabels[$key]) ? $fieldLabels[$key] : str_replace([' ', '_'], ' ', ucwords(str_replace('_', ' ', $key)));
                                            $fieldValue = is_array($value) ? json_encode($value) : ($value ?? 'N/A');
                                        ?>
                                            <div class="field-group">
                                                <label><?php echo htmlspecialchars($label); ?>:</label>
                                                <div class="field-value"><?php echo htmlspecialchars($fieldValue); ?></div>
                                            </div>
                                        <?php 
                                            $fieldCount++;
                                            if ($fieldCount % 3 == 0): 
                                        ?>
                                            </div><div class="record-fields">
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                            endif;
                            ?>
                            
                            <form action="../reporte_entrega/generar_reporte.php" method="GET" style="margin-top: 20px;">
                                <input type="hidden" name="nit" value="<?php echo htmlspecialchars($nit_sesion); ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token_sesion); ?>">
                                <input type="hidden" id="entregas-ids" name="entregas" value="">
                                <button type="submit" class="btn-realizar-reporte" style="background-color: #28a745; color: white; padding: 12px 24px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; width: 100%;">
                                    Realizar Reporte de Entregas Seleccionadas
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
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
        
        // Manejo de selección de entregas
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            const entregaCheckboxes = document.querySelectorAll('.entrega-checkbox');
            const entregasInput = document.getElementById('entregas-ids');
            const realizarReporteBtn = document.querySelector('.btn-realizar-reporte');
            
            // Función para actualizar el campo hidden con los IDs seleccionados
            function actualizarEntregasSeleccionadas() {
                const seleccionadas = Array.from(entregaCheckboxes)
                    .filter(checkbox => checkbox.checked)
                    .map(checkbox => checkbox.getAttribute('data-id'));
                
                entregasInput.value = seleccionadas.join(',');
                
                // Desabilitar botón si no hay selección
                if (seleccionadas.length === 0) {
                    realizarReporteBtn.disabled = true;
                    realizarReporteBtn.style.opacity = '0.5';
                    realizarReporteBtn.style.cursor = 'not-allowed';
                } else {
                    realizarReporteBtn.disabled = false;
                    realizarReporteBtn.style.opacity = '1';
                    realizarReporteBtn.style.cursor = 'pointer';
                }
            }
            
            // Evento para seleccionar/deseleccionar todos
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    entregaCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    actualizarEntregasSeleccionadas();
                });
            }
            
            // Evento para cada checkbox individual
            entregaCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    // Actualizar estado del checkbox "Seleccionar todos"
                    const todosCheckeados = Array.from(entregaCheckboxes).every(cb => cb.checked);
                    const algunosCheckeados = Array.from(entregaCheckboxes).some(cb => cb.checked);
                    
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = todosCheckeados;
                        selectAllCheckbox.indeterminate = algunosCheckeados && !todosCheckeados;
                    }
                    
                    actualizarEntregasSeleccionadas();
                });
            });
            
            // Validar que haya selección antes de enviar
            if (realizarReporteBtn) {
                realizarReporteBtn.addEventListener('click', function(e) {
                    const seleccionadas = Array.from(entregaCheckboxes)
                        .filter(checkbox => checkbox.checked)
                        .map(checkbox => checkbox.getAttribute('data-id'));
                    
                    if (seleccionadas.length === 0) {
                        e.preventDefault();
                        alert('Por favor selecciona al menos una entrega para realizar el reporte.');
                    }
                });
            }
            
            // Inicializar estado del botón
            actualizarEntregasSeleccionadas();
        });
    </script>
</body>
</html>

