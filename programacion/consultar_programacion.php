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
$resultados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consultar'])) {
    $nit = trim($_POST['nit'] ?? '');
    $token_temporal = trim($_POST['token_temporal'] ?? '');
    $no_prescripcion = trim($_POST['no_prescripcion'] ?? '');
    $mipresApiClient = new MipresApiClient();
    
    if (empty($nit) || empty($token_temporal) || empty($no_prescripcion)) {
        $mensaje = 'Por favor complete todos los campos (NIT, Token y Número de Prescripción) para realizar la consulta';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_ERROR', "Validación fallida: campos incompletos. Prescripción: {$no_prescripcion}");
    } else {
        // Procesar el token: quitar el "=" final y agregar %3D
        $token_procesado = rtrim($token_temporal, '=') . '%3D';
        
        $apiResult = $mipresApiClient->get(
            "ProgramacionXPrescripcion/{$nit}/{$token_procesado}/{$no_prescripcion}",
            30
        );

        $response = $apiResult['raw_response'];
        $http_code = $apiResult['http_code'];
        $curl_error = $apiResult['curl_error'];
        
        if ($curl_error) {
            $mensaje = 'No se pudo conectar con el servidor de MIPRES. Verifique su conexión a internet e intente nuevamente';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_ERROR', "Error cURL en consulta por prescripción {$no_prescripcion}: {$curl_error}");
        } elseif ($http_code === 200) {
            $data = json_decode($response, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Verificar si es un array de resultados o un solo resultado
                if (isset($data[0])) {
                    $resultados = $data;
                } else {
                    $resultados = [$data];
                }
                
                if (count($resultados) > 0) {
                    $mensaje = '✓ Consulta exitosa: Se encontraron ' . count($resultados) . ' registro(s) de programación para esta prescripción';
                    $tipo_mensaje = 'success';
                    registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION', "Prescripción: {$no_prescripcion}, Resultados: " . count($resultados));
                } else {
                    $mensaje = 'No se encontraron registros de programación para el número de prescripción consultado';
                    $tipo_mensaje = 'warning';
                    registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION', "Prescripción: {$no_prescripcion}, Resultados: 0");
                }
            } else {
                $mensaje = 'La respuesta del servidor no tiene el formato esperado. Por favor intente nuevamente';
                $tipo_mensaje = 'error';
                registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_ERROR', "JSON inválido en consulta por prescripción {$no_prescripcion}. HTTP: {$http_code}");
            }
        } else {
            $mensaje = 'No se pudo completar la consulta. Si el problema persiste, contacte al soporte técnico (Código: ' . $http_code . ')';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_ERROR', "HTTP {$http_code} en consulta por prescripción {$no_prescripcion}");
        }
    }
}

$token_sesion = $_SESSION['token_temporal'] ?? '';
$nit_sesion = $_SESSION['token_nit'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Programación - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
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
            text-decoration: none;
            transition: all 0.2s ease;
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
        
        .alert-warning {
            background-color: #fffbf0;
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
        
        .resultado-card {
            background-color: #f8f9fa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
        }
        
        .resultado-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #000000;
        }
        
        .resultado-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #000000;
        }
        
        .resultado-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .resultado-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .resultado-field label {
            font-weight: 600;
            color: #000000;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .resultado-field span {
            color: #000000;
            background-color: #ffffff;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
            border: 1px solid #e5e5e5;
        }
        
        .btn-entrega {
            background-color: #000000;
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }
        
        .btn-entrega:hover {
            background-color: #333333;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
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
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow open" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-programacion">
                    <a href="realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programación</a>
                    <a href="consultar_programacion.php" class="sidebar-item sidebar-child active">Consultar por Prescripción</a>
                    <a href="consultar_por_fecha.php" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="consultar_por_paciente.php" class="sidebar-item sidebar-child">Consultar por Paciente</a>

                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('entrega')">
                    <span>Entrega</span>
                    <span class="arrow" id="arrow-entrega">▶</span>
                </div>
                <div class="sidebar-children" id="menu-entrega">
                    <a href="../entrega/registrar_entrega.php" class="sidebar-item sidebar-child">Registrar Entrega</a>
                    <a href="../entrega/consultar_entrega.php" class="sidebar-item sidebar-child">Consultar por No. Prescripción</a>
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
                <h2>Consultar Programación por Prescripción</h2>
                <p class="card-subtitle">Consulta los registros de programación asociados a una prescripción</p>
                
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nit">NIT *</label>
                            <input type="text" 
                                   id="nit" 
                                   name="nit" 
                                   value="<?php echo htmlspecialchars($nit_sesion); ?>"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="token_temporal">Token Temporal *</label>
                            <input type="text" 
                                   id="token_temporal" 
                                   name="token_temporal" 
                                   value="<?php echo htmlspecialchars(trim($token_sesion, '"')); ?>"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="no_prescripcion">No. Prescripción *</label>
                            <input type="text" 
                                   id="no_prescripcion" 
                                   name="no_prescripcion" 
                                   placeholder="Ej: 123456789"
                                   required>
                        </div>
                    </div>
                    
                    <button type="submit" name="consultar" class="btn">
                        Consultar Programación
                    </button>
                </form>
            </div>
            
            <?php if (!empty($resultados)): ?>
                <div class="card">
                    <h2>Resultados de la Consulta</h2>
                    <p class="card-subtitle">Se encontraron <?php echo count($resultados); ?> registro(s)</p>
                    
                    <div class="selection-controls" style="margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="select-all-prog" style="width: 20px; height: 20px; cursor: pointer;">
                            <span>Seleccionar todos</span>
                        </label>
                    </div>
                    
                    <?php foreach ($resultados as $index => $resultado): ?>
                        <?php
                        $id = $resultado['ID'] ?? $resultado['id'] ?? '';
                        $codSerTec = $resultado['CodSerTecAEntregar'] ?? $resultado['cod_ser_tec_a_entregar'] ?? '';
                        $cantTotal = $resultado['CantTotAEntregar'] ?? $resultado['cant_tot_a_entregar'] ?? '';
                        ?>
                        <div class="resultado-card">
                            <div class="resultado-header" style="display: flex; align-items: center; gap: 15px;">
                                <input type="checkbox" class="prog-checkbox" 
                                       data-id="<?php echo htmlspecialchars($id); ?>"
                                       data-cod-ser-tec="<?php echo htmlspecialchars($codSerTec); ?>"
                                       data-cant-total="<?php echo htmlspecialchars($cantTotal); ?>"
                                       style="width: 20px; height: 20px; cursor: pointer;">
                                <h3 style="margin: 0;">Registro #<?php echo $index + 1; ?></h3>
                                <form method="POST" action="../entrega/registrar_entrega.php" style="display: inline; margin-left: auto;">
                                    <input type="hidden" name="from_programacion" value="1">
                                    <input type="hidden" name="id_prog" value="<?php echo htmlspecialchars($id); ?>">
                                    <input type="hidden" name="cod_ser_tec_prog" value="<?php echo htmlspecialchars($codSerTec); ?>">
                                    <input type="hidden" name="cant_total_prog" value="<?php echo htmlspecialchars($cantTotal); ?>">
                                    <input type="hidden" name="nit" value="<?php echo htmlspecialchars($_POST['nit'] ?? ''); ?>">
                                    <input type="hidden" name="token_temporal" value="<?php echo htmlspecialchars(trim($_POST['token_temporal'] ?? '', '"')); ?>">
                                    <button type="submit" class="btn-entrega">
                                        Ir a Entrega →
                                    </button>
                                </form>
                            </div>
                            
                            <div class="resultado-grid">
                                <?php foreach ($resultado as $key => $value): ?>
                                    <div class="resultado-field">
                                        <label><?php echo htmlspecialchars($key); ?>:</label>
                                        <span><?php echo htmlspecialchars(is_array($value) ? json_encode($value) : $value); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <form method="POST" action="../entrega/registrar_entrega.php" style="margin-top: 20px;" onsubmit="limpiarSelecciones();">
                        <input type="hidden" name="from_programacion_masiva" value="1">
                        <input type="hidden" id="programaciones-data" name="programaciones" value="">
                        <input type="hidden" name="nit" value="<?php echo htmlspecialchars($_POST['nit'] ?? ''); ?>">
                        <input type="hidden" name="token_temporal" value="<?php echo htmlspecialchars(trim($_POST['token_temporal'] ?? '', '"')); ?>">
                        <button type="submit" id="btn-ir-entrega-masivo" class="btn" style="background-color: #28a745; color: white; padding: 12px 24px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; width: 100%;" disabled>
                            Ir a Entrega Masiva (Seleccionadas)
                        </button>
                    </form>
                </div>
            <?php endif; ?>
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
        
        // Limpiar selecciones guardadas en localStorage
        function limpiarSelecciones() {
            const nit = document.querySelector('input[name="nit"]')?.value || '';
            localStorage.removeItem('prog_selecciones_' + nit);
        }
        
        // Manejo de selección de programaciones
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all-prog');
            const progCheckboxes = document.querySelectorAll('.prog-checkbox');
            const programacionesInput = document.getElementById('programaciones-data');
            const btnEntregaMasivo = document.getElementById('btn-ir-entrega-masivo');
            const nit = document.querySelector('input[name="nit"]')?.value || '';
            const token = document.querySelector('input[name="token_temporal"]')?.value || '';
            
            // Restaurar selecciones desde localStorage si existen
            function restaurarSelecciones() {
                const seleccionesGuardadas = localStorage.getItem('prog_selecciones_' + nit);
                if (seleccionesGuardadas) {
                    try {
                        const idsGuardados = JSON.parse(seleccionesGuardadas);
                        progCheckboxes.forEach(checkbox => {
                            if (idsGuardados.includes(checkbox.getAttribute('data-id'))) {
                                checkbox.checked = true;
                            }
                        });
                        actualizarProgramacionesSeleccionadas();
                    } catch (e) {
                        console.log('Error restaurando selecciones:', e);
                    }
                }
            }
            
            // Función para actualizar el campo hidden con los datos seleccionados
            function actualizarProgramacionesSeleccionadas() {
                const seleccionadas = Array.from(progCheckboxes)
                    .filter(checkbox => checkbox.checked)
                    .map(checkbox => ({
                        id: checkbox.getAttribute('data-id'),
                        codSerTec: checkbox.getAttribute('data-cod-ser-tec'),
                        cantTotal: checkbox.getAttribute('data-cant-total')
                    }));
                
                programacionesInput.value = JSON.stringify(seleccionadas);
                
                // Guardar en localStorage para recuperar si hay error
                const idsSeleccionados = seleccionadas.map(s => s.id);
                localStorage.setItem('prog_selecciones_' + nit, JSON.stringify(idsSeleccionados));
                
                // Desabilitar botón si no hay selección
                if (seleccionadas.length === 0) {
                    btnEntregaMasivo.disabled = true;
                    btnEntregaMasivo.style.opacity = '0.5';
                    btnEntregaMasivo.style.cursor = 'not-allowed';
                } else {
                    btnEntregaMasivo.disabled = false;
                    btnEntregaMasivo.style.opacity = '1';
                    btnEntregaMasivo.style.cursor = 'pointer';
                }
            }
            
            // Evento para seleccionar/deseleccionar todos
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    progCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    actualizarProgramacionesSeleccionadas();
                });
            }
            
            // Evento para cada checkbox individual
            progCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const todosCheckeados = Array.from(progCheckboxes).every(cb => cb.checked);
                    const algunosCheckeados = Array.from(progCheckboxes).some(cb => cb.checked);
                    
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = todosCheckeados;
                        selectAllCheckbox.indeterminate = algunosCheckeados && !todosCheckeados;
                    }
                    
                    actualizarProgramacionesSeleccionadas();
                });
            });
            
            // Validar que haya selección antes de enviar
            if (btnEntregaMasivo) {
                btnEntregaMasivo.addEventListener('click', function(e) {
                    const seleccionadas = Array.from(progCheckboxes)
                        .filter(checkbox => checkbox.checked);
                    
                    if (seleccionadas.length === 0) {
                        e.preventDefault();
                        alert('Por favor selecciona al menos una programación para ir a entrega.');
                    }
                });
            }
            
            // Inicializar estado del botón y restaurar selecciones
            restaurarSelecciones();
        });
    </script>
</body>
</html>
