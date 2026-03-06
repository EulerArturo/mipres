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
$filtro_actual = 'todos';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consultar'])) {
    $nit = trim($_POST['nit'] ?? '');
    $token_temporal = trim($_POST['token_temporal'] ?? '');
    $fecha = trim($_POST['fecha'] ?? '');
    $mipresApiClient = new MipresApiClient();
    
    if (empty($nit) || empty($token_temporal) || empty($fecha)) {
        $mensaje = 'Por favor complete todos los campos (NIT, Token Temporal y Fecha) para realizar la consulta';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_FECHA_ERROR', "Validación fallida: campos incompletos. Fecha: {$fecha}");
    } else {
        // Procesar el token: quitar el "=" final y agregar %3D
        $token_procesado = rtrim($token_temporal, '=') . '%3D';
        
        $apiResult = $mipresApiClient->get(
            "ProgramacionXFecha/{$nit}/{$token_procesado}/{$fecha}",
            15
        );

        $response = $apiResult['raw_response'];
        $http_code = $apiResult['http_code'];
        $curl_error = $apiResult['curl_error'];
        
        if ($curl_error) {
            $mensaje = 'No se pudo conectar con el servidor de MIPRES. Verifique su conexión a internet e intente nuevamente';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_FECHA_ERROR', "Error cURL en consulta por fecha {$fecha}: {$curl_error}");
        } elseif ($http_code === 200) {
            $data = json_decode($response, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($data[0])) {
                    $resultados = $data;
                } else {
                    $resultados = [$data];
                }
                
                if (count($resultados) > 0) {
                    $mensaje = '✓ Consulta exitosa: Se encontraron ' . count($resultados) . ' registro(s) de programación para la fecha seleccionada';
                    $tipo_mensaje = 'success';
                    registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_FECHA', "Fecha: {$fecha}, Resultados: " . count($resultados));
                } else {
                    $mensaje = 'No se encontraron registros de programación para la fecha consultada';
                    $tipo_mensaje = 'warning';
                    registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_FECHA', "Fecha: {$fecha}, Resultados: 0");
                }
            } else {
                $mensaje = 'La respuesta del servidor no tiene el formato esperado. Por favor intente nuevamente';
                $tipo_mensaje = 'error';
                registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_FECHA_ERROR', "JSON inválido en consulta por fecha {$fecha}. HTTP: {$http_code}");
            }
        } else {
            $mensaje = 'No se pudo completar la consulta. Si el problema persiste, contacte al soporte técnico (Código: ' . $http_code . ')';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_PROGRAMACION_FECHA_ERROR', "HTTP {$http_code} en consulta por fecha {$fecha}");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_masivo'])) {
    $seleccionados = $_POST['seleccionados'] ?? [];
    $nit = $_POST['nit_envio'] ?? '';
    $token_temporal = $_POST['token_envio'] ?? '';
    
    if (empty($seleccionados)) {
        $mensaje = 'Selecciona al menos un registro para enviar a entrega';
        $tipo_mensaje = 'error';
    } else {
        // Redirigir a registrar entrega con los registros seleccionados
        $_SESSION['registros_entrega'] = [];
        foreach ($seleccionados as $index) {
            if (isset($_POST["id_$index"])) {
                $_SESSION['registros_entrega'][] = [
                    'id' => $_POST["id_$index"],
                    'fec_max_ent' => $_POST["fec_max_ent_$index"],
                    'tipo_id_sede_prov' => $_POST["tipo_id_sede_prov_$index"],
                    'no_id_sede_prov' => $_POST["no_id_sede_prov_$index"],
                    'cod_sede_prov' => $_POST["cod_sede_prov_$index"] ?? 'PROV008986',
                    'cod_ser_tec_a_entregar' => $_POST["cod_ser_tec_$index"],
                    'cant_tot_a_entregar' => $_POST["cant_tot_$index"],
                    'no_entrega' => $_POST["no_entrega_$index"] ?? ''
                ];
            }
        }
        
        header('Location: ../entrega/registrar_entrega.php?from=programacion&nit=' . urlencode($nit) . '&token=' . urlencode($token_temporal));
        exit;
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
    <title>Consultar Programación por Fecha - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .resultado-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            font-size: 11px;
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
            word-break: break-word;
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
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-container label {
            cursor: pointer;
            margin: 0;
            font-size: 13px;
        }
        
        .btn-enviar-masivo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            border: none;
            padding: 14px 28px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-bottom: 24px;
            display: none;
        }
        
        .btn-enviar-masivo.visible {
            display: inline-block;
        }
        
        .btn-enviar-masivo:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4);
        }
        
        .filtros-container {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .btn-filtro {
            background-color: #e5e5e5;
            color: #000000;
            border: 2px solid #e5e5e5;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-filtro.active {
            background-color: #000000;
            color: #ffffff;
            border-color: #000000;
        }
        
        .btn-filtro:hover {
            border-color: #000000;
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
                    <a href="../direccionamiento/por_fecha.php" class="sidebar-item sidebar-child">Por Fecha</a>
                    <a href="../direccionamiento/por_paciente.php" class="sidebar-item sidebar-child">Por Paciente</a>
                    <a href="../direccionamiento/por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripción</a>
                    <a href="#" class="sidebar-item sidebar-child">Consultar Direccionamiento</a>
                    <a href="#" class="sidebar-item sidebar-child">Anular Direccionamiento</a>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow open" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-programacion">
                    <a href="realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programación</a>
                    <a href="consultar_programacion.php" class="sidebar-item sidebar-child">Consultar por Prescripción</a>
                    <a href="consultar_por_fecha.php" class="sidebar-item sidebar-child active">Consultar por Fecha</a>
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
                <h2>Consultar Programación por Fecha</h2>
                <p class="card-subtitle">Consulta la programación registrada para una fecha específica</p>
                
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
                                   placeholder="Ej: 900123456"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="token_temporal">Token Temporal *</label>
                            <input type="text" 
                                   id="token_temporal" 
                                   name="token_temporal" 
                                   value="<?php echo htmlspecialchars(trim($token_sesion, '"')); ?>"
                                   placeholder="Token auto-mapeado"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="fecha">Fecha (AAAA-MM-DD) *</label>
                            <input type="date" 
                                   id="fecha" 
                                   name="fecha" 
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
                    
                    <form method="POST" action="" id="formSeleccionar">
                        <input type="hidden" name="nit_envio" value="<?php echo htmlspecialchars($_POST['nit'] ?? ''); ?>">
                        <input type="hidden" name="token_envio" value="<?php echo htmlspecialchars(trim($_POST['token_temporal'] ?? '', '"')); ?>">
                        
                        <button type="submit" name="enviar_masivo" class="btn-enviar-masivo visible" id="btnEnviarMasivo">
                            Enviar a Entrega (<span id="contadorMasivo"><?php echo count($resultados); ?></span> registros seleccionados)
                        </button>
                        
                        <?php foreach ($resultados as $index => $resultado): ?>
                            <div class="resultado-card">
                                <div class="resultado-header">
                                    <div class="checkbox-container">
                                        <input type="checkbox" 
                                               name="seleccionados[]" 
                                               value="<?php echo $index; ?>"
                                               id="sel_<?php echo $index; ?>"
                                               checked
                                               onchange="actualizarContador()">
                                        <label for="sel_<?php echo $index; ?>">Incluir en entrega</label>
                                    </div>
                                    <h3>Registro #<?php echo $index + 1; ?></h3>
                                </div>
                                
                                <div class="resultado-grid">
                                    <?php 
                                    $campos_clave = ['ID', 'id', 'FecMaxEnt', 'fec_max_ent', 'CodSerTecAEntregar', 'cod_ser_tec_a_entregar', 'CantTotAEntregar', 'cant_tot_a_entregar'];
                                    
                                    foreach ($resultado as $key => $value): 
                                        if (!is_array($value) && !is_object($value)):
                                    ?>
                                            <div class="resultado-field">
                                                <label><?php echo htmlspecialchars($key); ?></label>
                                                <span><?php echo htmlspecialchars(is_string($value) ? $value : json_encode($value)); ?></span>
                                            </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                                
                                <!-- Campos ocultos para envío -->
                                <input type="hidden" name="id_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['ID'] ?? $resultado['id'] ?? ''); ?>">
                                <input type="hidden" name="fec_max_ent_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['FecMaxEnt'] ?? $resultado['fec_max_ent'] ?? ''); ?>">
                                <input type="hidden" name="tipo_id_sede_prov_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['TipoIDSedeProv'] ?? $resultado['tipo_id_sede_prov'] ?? ''); ?>">
                                <input type="hidden" name="no_id_sede_prov_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['NoIDSedeProv'] ?? $resultado['no_id_sede_prov'] ?? ''); ?>">
                                <input type="hidden" name="cod_sede_prov_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['CodSedeProv'] ?? $resultado['cod_sede_prov'] ?? 'PROV008986'); ?>">
                                <input type="hidden" name="cod_ser_tec_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['CodSerTecAEntregar'] ?? $resultado['cod_ser_tec_a_entregar'] ?? ''); ?>">
                                <input type="hidden" name="cant_tot_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['CantTotAEntregar'] ?? $resultado['cant_tot_a_entregar'] ?? ''); ?>">
                                <input type="hidden" name="no_entrega_<?php echo $index; ?>" value="<?php echo htmlspecialchars($resultado['NoEntrega'] ?? $resultado['no_entrega'] ?? ''); ?>">
                            </div>
                        <?php endforeach; ?>
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
        
        function actualizarContador() {
            const checkboxes = document.querySelectorAll('input[name="seleccionados[]"]:checked');
            const contador = checkboxes.length;
            const totalResultados = document.querySelectorAll('input[name="seleccionados[]"]').length;
            
            document.getElementById('contadorMasivo').textContent = contador;
            
            const btn = document.getElementById('btnEnviarMasivo');
            if (contador > 0) {
                btn.classList.add('visible');
            } else {
                btn.classList.remove('visible');
            }
        }
    </script>
</body>
</html>
