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
$registros = [];
$respuestas_detalladas = []; // Array para guardar respuestas individuales

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['multiple'])) {
    // Múltiples registros
    $registros = $_POST['registros'] ?? [];
    $nit = $_POST['nit'] ?? '';
    $token_temporal = $_POST['token_temporal'] ?? '';
    
    foreach ($registros as &$registro) {
        $registro['NIT'] = $nit;
        $registro['TokenTemporal'] = $token_temporal;
    }
    unset($registro); // Liberar la referencia para evitar problemas en loops posteriores
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    // Un solo registro
    $registros[] = [
        'id' => $_POST['id'] ?? '',
        'fec_max_ent' => $_POST['fec_max_ent'] ?? '',
        'tipo_id_sede_prov' => $_POST['tipo_id_sede_prov'] ?? '',
        'no_id_sede_prov' => $_POST['no_id_sede_prov'] ?? '',
        'cod_sede_prov' => $_POST['cod_sede_prov'] ?? 'PROV008986',
        'cod_ser_tec_a_entregar' => $_POST['cod_ser_tec_a_entregar'] ?? '',
        'cant_tot_a_entregar' => $_POST['cant_tot_a_entregar'] ?? '',
        'no_entrega' => $_POST['no_entrega'] ?? '',
        'tipo_id_paciente' => $_POST['tipo_id_paciente'] ?? '',
        'no_id_paciente' => $_POST['no_id_paciente'] ?? '',
        'fec_direccionamiento' => $_POST['fec_direccionamiento'] ?? '',
        'NIT' => $_POST['nit'] ?? '',
        'TokenTemporal' => $_POST['token_temporal'] ?? ''
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_masivo'])) {
    $seleccionados = $_POST['seleccionados'] ?? [];
    $nit = $_POST['nit_envio'] ?? '';
    $token_temporal = $_POST['token_envio'] ?? '';
    $mipresApiClient = new MipresApiClient();
    
    if (empty($seleccionados)) {
        $mensaje = 'Selecciona al menos un registro para enviar';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'PROGRAMACION_MASIVA_ERROR', 'Validación fallida: intento de programación masiva sin registros seleccionados');
    } else {
        $exitosos = 0;
        $errores = 0;
        
        foreach ($seleccionados as $index) {
            if (!isset($_POST["id_$index"])) continue;
            
            $json_data = [
                'ID' => $_POST["id_$index"],
                'FecMaxEnt' => $_POST["fec_max_ent_$index"],
                'TipoIDSedeProv' => $_POST["tipo_id_sede_prov_$index"],
                'NoIDSedeProv' => $_POST["no_id_sede_prov_$index"],
                'CodSedeProv' => $_POST["cod_sede_prov_$index"] ?? 'PROV008986',
                'CodSerTecAEntregar' => $_POST["cod_ser_tec_$index"],
                'CantTotAEntregar' => $_POST["cant_tot_$index"],
                'TipoIDPaciente' => $_POST["tipo_id_paciente_$index"],
                'NoIDPaciente' => $_POST["no_id_paciente_$index"],
                'FecDireccionamiento' => $_POST["fec_direccionamiento_$index"]
            ];
            
            $token_procesado = rtrim($token_temporal, '=') . '%3D';
            $apiResult = $mipresApiClient->putJson(
                "Programacion/{$nit}/{$token_procesado}",
                $json_data,
                30
            );

            $response = $apiResult['raw_response'];
            $http_code = $apiResult['http_code'];
            $curl_error = $apiResult['curl_error'];
            
            $respuesta_info = [
                'registro_num' => $index + 1,
                'id' => $_POST["id_$index"],
                'http_code' => $http_code,
                'response' => $response,
                'curl_error' => $curl_error,
                'exitoso' => ($http_code === 200 || $http_code === 201)
            ];
            
            $respuestas_detalladas[] = $respuesta_info;
            
            if ($http_code === 200 || $http_code === 201) {
                $exitosos++;
            } else {
                $errores++;
            }
        }
        
        $mensaje = "Programación masiva completada. Exitosos: $exitosos, Errores: $errores";
        $tipo_mensaje = $errores > 0 ? 'warning' : 'success';
        
        if ($errores === 0) {
            $_SESSION['mensaje_programacion'] = $mensaje;
            $_SESSION['tipo_mensaje_programacion'] = $tipo_mensaje;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        $total_enviados = count($seleccionados);
        $total_errores = count($respuestas_detalladas) - $exitosos;

        if ($exitosos > 0) {
            registrar_log_actividad($pdo, $usuario['id'], 'PROGRAMACION_MASIVA', "Enviados: {$total_enviados}, Exitosos: {$exitosos}, Errores: {$total_errores}");
        }

        if ($total_errores > 0) {
            $primer_error = '';
            foreach ($respuestas_detalladas as $respuesta_detallada) {
                if (!$respuesta_detallada['exitoso']) {
                    $primer_error = $respuesta_detallada['curl_error'] ?: ('HTTP ' . $respuesta_detallada['http_code']);
                    break;
                }
            }

            registrar_log_actividad($pdo, $usuario['id'], 'PROGRAMACION_MASIVA_ERROR', "Enviados: {$total_enviados}, Exitosos: {$exitosos}, Errores: {$total_errores}, Primer error: {$primer_error}");
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
    <title>Realizar Programación - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
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
                    <a href="../direccionamiento/por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripción</a>
                    <a href="../direccionamiento/por_paciente.php" class="sidebar-item sidebar-child">Por Paciente</a>
                 
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow open" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-programacion">
                    <a href="realizar_programacion.php" class="sidebar-item sidebar-child active">Realizar Programación</a>
                    <a href="consultar_por_fecha.php" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="consultar_programacion.php" class="sidebar-item sidebar-child">Consultar por Prescripción</a>
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
                    <a href="#" class="sidebar-item sidebar-child">Generar Reportes</a>
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
                <h2>Realizar Programación</h2>
                <p class="card-subtitle">Envía la programación a la API de MIPRES</p>
                
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($respuestas_detalladas)): ?>
                    <div class="respuestas-container">
                        <h3 style="margin-bottom: 16px; color: #333;">Respuestas Detalladas de la API</h3>
                        <?php 
                        $hay_exitosos = false;
                        foreach ($respuestas_detalladas as $resp): 
                            if ($resp['exitoso']) {
                                $hay_exitosos = true;
                            }
                        ?>
                            <div class="respuesta-item <?php echo $resp['exitoso'] ? '' : 'error'; ?>">
                                <div class="respuesta-header">
                                    <span>Registro #<?php echo $resp['registro_num']; ?> - ID: <?php echo htmlspecialchars($resp['id']); ?></span>
                                    <span class="<?php echo $resp['exitoso'] ? 'badge-success' : 'badge-error'; ?>">
                                        HTTP <?php echo $resp['http_code']; ?>
                                    </span>
                                </div>
                                <div class="respuesta-body">
                                    <?php if ($resp['curl_error']): ?>
                                        <strong>Error de conexión:</strong> <?php echo htmlspecialchars($resp['curl_error']); ?>
                                    <?php else: ?>
                                        <strong>Respuesta:</strong><br>
                                        <?php 
                                        $response_decoded = json_decode($resp['response'], true);
                                        if ($response_decoded) {
                                            echo htmlspecialchars(json_encode($response_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                        } else {
                                            echo htmlspecialchars($resp['response']);
                                        }
                                        ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if ($hay_exitosos): ?>
                            <div style="margin-top: 24px; padding: 20px; background-color: #f8f9fa; border-radius: 8px; border: 2px solid #28a745;">
                                <h4 style="color: #28a745; margin-bottom: 12px;">Programación Exitosa</h4>
                                <p style="margin-bottom: 16px; color: #333;">Ahora puedes proceder a registrar la entrega de estos items.</p>
                                <form method="POST" action="../entrega/registrar_entrega.php">
                                    <input type="hidden" name="from_programacion" value="1">
                                    <?php
                                    // Get first successful record data
                                    foreach ($respuestas_detalladas as $resp) {
                                        if ($resp['exitoso']) {
                                            $index = $resp['registro_num'] - 1;
                                            if (isset($_POST["id_$index"])) {
                                                echo '<input type="hidden" name="id_prog" value="' . htmlspecialchars($_POST["id_$index"]) . '">';
                                                echo '<input type="hidden" name="cod_ser_tec_prog" value="' . htmlspecialchars($_POST["cod_ser_tec_$index"]) . '">';
                                                echo '<input type="hidden" name="cant_total_prog" value="' . htmlspecialchars($_POST["cant_tot_$index"]) . '">';
                                                
                                                // Buscar los datos del paciente en $registros
                                                if (isset($registros[$index])) {
                                                    echo '<input type="hidden" name="tipo_id_paciente" value="' . htmlspecialchars($registros[$index]['tipo_id_paciente'] ?? '') . '">';
                                                    echo '<input type="hidden" name="no_id_paciente" value="' . htmlspecialchars($registros[$index]['no_id_paciente'] ?? '') . '">';
                                                    echo '<input type="hidden" name="fec_direccionamiento" value="' . htmlspecialchars($registros[$index]['fec_direccionamiento'] ?? '') . '">';
                                                }
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <input type="hidden" name="nit" value="<?php echo htmlspecialchars($_POST['nit_envio'] ?? ''); ?>">
                                    <input type="hidden" name="token_temporal" value="<?php echo htmlspecialchars(trim($_POST['token_envio'] ?? '', '"')); ?>">
                                    <button type="submit" class="btn" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                                        Ir a Registrar Entrega →
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($registros)): ?>
                    <form method="POST" action="" id="formMasivo">
                        <input type="hidden" name="enviar_masivo" value="1">
                        <input type="hidden" name="nit_envio" value="<?php echo htmlspecialchars($registros[0]['NIT'] ?? $nit_sesion); ?>">
                        <input type="hidden" name="token_envio" value="<?php echo htmlspecialchars(trim($registros[0]['TokenTemporal'] ?? $token_sesion, '"')); ?>">
                        
                        <!-- Botón siempre visible cuando hay registros -->
                        <button type="submit" class="btn-enviar-masivo visible" id="btnEnviarMasivo">
                             Realizar Programación (<span id="contadorMasivo"><?php echo count($registros); ?></span> registros)
                        </button>
                        
                        <h3 style="margin-bottom: 16px; color: #333;">Registros a Programar (<?php echo count($registros); ?>)</h3>
                        
                        <?php foreach ($registros as $index => $registro): ?>
                            <div class="registro-card <?php echo count($registros) > 1 ? 'selected' : ''; ?>">
                                <div class="registro-header">
                                    <?php if (count($registros) > 1): ?>
                                        <div class="checkbox-container">
                                            <input type="checkbox" 
                                                   name="seleccionados[]" 
                                                   value="<?php echo $index; ?>"
                                                   id="sel_<?php echo $index; ?>"
                                                   checked
                                                   onchange="actualizarContadorMasivo()">
                                            <label for="sel_<?php echo $index; ?>">Incluir en envío</label>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" name="seleccionados[]" value="<?php echo $index; ?>">
                                    <?php endif; ?>
                                    <span style="font-weight: 600; color: #667eea;">Registro #<?php echo $index + 1; ?></span>
                                </div>
                                
                                <!-- Datos en formato horizontal optimizado con CodSedeProv -->
                                <div class="registro-grid">
                                    <div class="registro-field">
                                        <label>ID:</label>
                                        <span><?php echo htmlspecialchars($registro['id']); ?></span>
                                    </div>
                                    
                                    <!-- NoEntrega solo para visualización -->
                                    <div class="registro-field visualizacion">
                                        <label>No. Entrega (Solo visualización):</label>
                                        <span><?php echo htmlspecialchars($registro['no_entrega'] ?? 'N/A'); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>Fecha Máx. Entrega:</label>
                                        <span><?php echo htmlspecialchars($registro['fec_max_ent']); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>Tipo ID Sede:</label>
                                        <span><?php echo htmlspecialchars($registro['tipo_id_sede_prov']); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>No. ID Sede:</label>
                                        <span><?php echo htmlspecialchars($registro['no_id_sede_prov']); ?></span>
                                    </div>
                                    
                                    <!-- Agregado campo CodSedeProv -->
                                    <div class="registro-field">
                                        <label>Cód. Sede Prov:</label>
                                        <span><?php echo htmlspecialchars($registro['cod_sede_prov'] ?? 'PROV008986'); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>Cód. Servicio Tec:</label>
                                        <span><?php echo htmlspecialchars($registro['cod_ser_tec_a_entregar']); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>Cantidad Total:</label>
                                        <span><?php echo htmlspecialchars($registro['cant_tot_a_entregar']); ?></span>
                                    </div>
                                    
                                    <!-- Campos del paciente -->
                                    <div class="registro-field">
                                        <label>Tipo ID Paciente:</label>
                                        <span><?php echo htmlspecialchars($registro['tipo_id_paciente'] ?? ''); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>No. ID Paciente:</label>
                                        <span><?php echo htmlspecialchars($registro['no_id_paciente'] ?? ''); ?></span>
                                    </div>
                                    
                                    <div class="registro-field">
                                        <label>Fecha de Direccionamiento:</label>
                                        <span><?php echo htmlspecialchars($registro['fec_direccionamiento'] ?? ''); ?></span>
                                    </div>
                                </div>
                                
                                <!-- Campos ocultos para envío -->
                                <input type="hidden" name="id_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['id']); ?>">
                                <input type="hidden" name="fec_max_ent_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['fec_max_ent']); ?>">
                                <input type="hidden" name="tipo_id_sede_prov_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['tipo_id_sede_prov']); ?>">
                                <input type="hidden" name="no_id_sede_prov_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['no_id_sede_prov']); ?>">
                                <input type="hidden" name="cod_sede_prov_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['cod_sede_prov'] ?? 'PROV008986'); ?>">
                                <input type="hidden" name="cod_ser_tec_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['cod_ser_tec_a_entregar']); ?>">
                                <input type="hidden" name="cant_tot_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['cant_tot_a_entregar']); ?>">
                                <!-- Agregando campos ocultos del paciente y fecha de direccionamiento -->
                                <input type="hidden" name="tipo_id_paciente_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['tipo_id_paciente'] ?? ''); ?>">
                                <input type="hidden" name="no_id_paciente_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['no_id_paciente'] ?? ''); ?>">
                                <input type="hidden" name="fec_direccionamiento_<?php echo $index; ?>" value="<?php echo htmlspecialchars($registro['fec_direccionamiento'] ?? ''); ?>">
                            </div>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        No hay registros para programar. Selecciona registros desde la sección de Direccionamiento.
                    </div>
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
        
        function actualizarContadorMasivo() {
            const checkboxes = document.querySelectorAll('input[name="seleccionados[]"]:checked');
            const contador = checkboxes.length;
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
