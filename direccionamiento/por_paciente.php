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
$resultados = [];

// Procesar formulario de consulta
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nit = trim($_POST['nit'] ?? '');
    $fecha = trim($_POST['fecha'] ?? '');
    $token_temporal = trim($_POST['token_temporal'] ?? '');
    $tipodoc = trim($_POST['tipodoc'] ?? '');
    $numero_documento = trim($_POST['numero_documento'] ?? '');
    $mipresApiClient = new MipresApiClient();
    
    // Validaciones
    if (empty($nit)) {
        $mensaje = 'Por favor ingrese el NIT de su organización para continuar con la consulta';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', 'Validación fallida: NIT vacío');
    } elseif (empty($fecha)) {
        $mensaje = 'Por favor ingrese la fecha para consultar el direccionamiento';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "Validación fallida: Fecha vacía. Paciente: {$tipodoc} {$numero_documento}");
    } elseif (empty($token_temporal)) {
        $mensaje = 'Por favor ingrese el Token Temporal generado previamente en la sección "Generación de Token"';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "Validación fallida: Token vacío. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}");
    } elseif (empty($tipodoc)) {
        $mensaje = 'Por favor ingrese el tipo de documento del paciente';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "Validación fallida: Tipo documento vacío. Fecha: {$fecha}");
    } elseif (empty($numero_documento)) {
        $mensaje = 'Por favor ingrese el número de documento del paciente';
        $tipo_mensaje = 'error';
        registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "Validación fallida: Número documento vacío. Tipo: {$tipodoc}, Fecha: {$fecha}");
    } else {
        // Procesar token
        $token_procesado = rtrim($token_temporal, '=');
        $token_procesado .= '%3D%20%20%20%20%20%20%20%20%20%20%20%20%20';
        
        $apiResult = $mipresApiClient->get(
            "DireccionamientoXPacienteFecha/{$nit}/{$fecha}/{$token_procesado}/{$tipodoc}/{$numero_documento}",
            15
        );

        $response = $apiResult['raw_response'];
        $http_code = $apiResult['http_code'];
        $curl_error = $apiResult['curl_error'];
        
        if ($curl_error) {
            $mensaje = 'No se pudo conectar con el servidor de MIPRES. Verifique su conexión a internet e intente nuevamente';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "Error cURL. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}, HTTP: {$http_code}, Error: {$curl_error}");
        } elseif ($http_code === 200) {
            $data = json_decode($response, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $resultados = $data;
                
                $ids = array_column($resultados, 'IDDireccionamiento');
                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $stmt = $pdo->prepare("SELECT id_direccionamiento, direccionado FROM direccionamientos_estado WHERE id_direccionamiento IN ($placeholders)");
                    $stmt->execute($ids);
                    $estados = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    // Agregar estado a cada resultado
                    foreach ($resultados as &$resultado) {
                        $resultado['direccionado'] = $estados[$resultado['IDDireccionamiento']] ?? 0;
                    }
                }
                
                $mensaje = '✓ Consulta exitosa: Se encontraron ' . count($resultados) . ' registro(s) de direccionamiento para este paciente en esta fecha';
                $tipo_mensaje = 'success';

                registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE', "Consulta por paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}. Resultados: " . count($resultados));
            } else {
                $mensaje = 'La respuesta del servidor no tiene el formato esperado. Por favor intente nuevamente';
                $tipo_mensaje = 'error';
                registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "Respuesta JSON inválida. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}, HTTP: {$http_code}");
            }
        } elseif ($http_code === 401) {
            $mensaje = 'El token ha expirado o no es válido. Por favor genere un nuevo token temporal e intente nuevamente';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "HTTP 401. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}");
        } elseif ($http_code === 404) {
            $mensaje = 'No se encontraron registros de direccionamiento para este paciente en la fecha consultada';
            $tipo_mensaje = 'warning';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "HTTP 404 sin resultados. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}");
        } elseif ($http_code >= 500) {
            $mensaje = 'El servidor de MIPRES no está disponible en este momento. Por favor intente nuevamente en unos minutos';
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "HTTP {$http_code}. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}");
        } else {
            $mensaje = "No se pudo completar la consulta. Si el problema persiste, contacte al soporte técnico (Código: {$http_code})";
            $tipo_mensaje = 'error';
            registrar_log_actividad($pdo, $usuario['id'], 'CONSULTA_DIRECCIONAMIENTO_PACIENTE_ERROR', "HTTP {$http_code} no controlado. Paciente: {$tipodoc} {$numero_documento}, Fecha: {$fecha}");
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
    <title>Direccionamiento por Paciente - <?php echo APP_NAME; ?></title>
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
                    <span class="arrow open" id="arrow-direccionamiento">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-direccionamiento">
                    <div class="sidebar-children open" id="menu-direccionamiento">
                    
                    <a href="por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripción</a>
                    <a href="por_fecha.php" class="sidebar-item sidebar-child">Por Fecha</a>
                    <a href="por_paciente.php" class="sidebar-item sidebar-child active">Por Paciente</a>
                    
                    
                </div>
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
                <h2>Direccionamiento por Paciente</h2>
                <p class="card-subtitle">Consulta el direccionamiento por paciente y fecha</p>
                
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="grid-3">
                        <div class="form-group">
                            <label for="nit">NIT *</label>
                            <input 
                                type="text" 
                                id="nit" 
                                name="nit" 
                                required
                                placeholder="Ingrese el NIT"
                                value="<?php echo htmlspecialchars($_POST['nit'] ?? $nit_sesion); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="fecha">Fecha *</label>
                            <input 
                                type="date" 
                                id="fecha" 
                                name="fecha" 
                                required
                                value="<?php echo htmlspecialchars($_POST['fecha'] ?? ''); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="token_temporal">Token Temporal *</label>
                            <input 
                                type="text" 
                                id="token_temporal" 
                                name="token_temporal" 
                                required
                                placeholder="Token Temporal"
                                value="<?php echo htmlspecialchars(trim($_POST['token_temporal'] ?? $token_sesion, '"')); ?>"
                            >
                        </div>
                    </div>
                    
                    <div class="grid-3">
                        <div class="form-group">
                            <label for="tipodoc">Tipo Documento *</label>
                            <select 
                                id="tipodoc" 
                                name="tipodoc" 
                                required
                            >
                                <option value="">Seleccione el tipo de documento</option>
                                <option value="CC" <?php echo ($_POST['tipodoc'] ?? '') === 'CC' ? 'selected' : ''; ?>>Cédula de Ciudadanía (CC)</option>
                                <option value="CE" <?php echo ($_POST['tipodoc'] ?? '') === 'CE' ? 'selected' : ''; ?>>Cédula de Extranjería (CE)</option>
                                <option value="PA" <?php echo ($_POST['tipodoc'] ?? '') === 'PA' ? 'selected' : ''; ?>>Pasaporte (PA)</option>
                                <option value="TI" <?php echo ($_POST['tipodoc'] ?? '') === 'TI' ? 'selected' : ''; ?>>Tarjeta de Identidad (TI)</option>
                                <option value="RC" <?php echo ($_POST['tipodoc'] ?? '') === 'RC' ? 'selected' : ''; ?>>Registro Civil (RC)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="numero_documento">Número Documento *</label>
                            <input 
                                type="text" 
                                id="numero_documento" 
                                name="numero_documento" 
                                required
                                placeholder="Número de documento"
                                value="<?php echo htmlspecialchars($_POST['numero_documento'] ?? ''); ?>"
                            >
                        </div>
                        
                        <div class="form-group" style="display: flex; flex-direction: column; justify-content: flex-end;">
                            <button type="submit" class="btn">Consultar Direccionamiento</button>
                        </div>
                    </div>
                </form>
                
                <?php if (!empty($resultados)): ?>
                    <div class="filter-buttons">
                        <button class="btn-filter active" onclick="filtrarResultados('todos')">Todos</button>
                        <button class="btn-filter" onclick="filtrarResultados('direccionados')">Direccionados</button>
                        <button class="btn-filter" onclick="filtrarResultados('no_direccionados')">No Direccionados</button>
                    </div>
                    
                    <button class="btn-programacion-masiva" id="btnProgramacionMasiva" onclick="enviarProgramacionMasiva()">
                        Realizar Programación Masiva (<span id="contadorSeleccionados">0</span> seleccionados)
                    </button>
                    
                    <h3 style="margin-top: 32px; margin-bottom: 16px; color: #333;">Resultados (<?php echo count($resultados); ?>)</h3>
                    
                    <?php foreach ($resultados as $index => $resultado): ?>
                        <div class="result-card" data-direccionado="<?php echo $resultado['direccionado']; ?>">
                            <div class="result-header">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="checkbox-container">
                                        <input type="checkbox" 
                                               class="checkbox-resultado" 
                                               id="check_<?php echo $index; ?>"
                                               data-id="<?php echo htmlspecialchars($resultado['ID']); ?>"
                                               data-fec-max-ent="<?php echo htmlspecialchars($resultado['FecMaxEnt'] ?? ''); ?>"
                                               data-tipo-id-prov="<?php echo htmlspecialchars($resultado['TipoIDProv'] ?? ''); ?>"
                                               data-no-id-prov="<?php echo htmlspecialchars($resultado['NoIDProv'] ?? ''); ?>"
                                               data-cod-ser-tec="<?php echo htmlspecialchars($resultado['CodSerTecAEntregar'] ?? ''); ?>"
                                               data-cant-tot="<?php echo htmlspecialchars($resultado['CantTotAEntregar'] ?? ''); ?>"
                                               data-no-entrega="<?php echo htmlspecialchars($resultado['NoEntrega'] ?? ''); ?>"
                                               onchange="actualizarContador()">
                                        <label for="check_<?php echo $index; ?>">Seleccionar para programación</label>
                                    </div>
                                    <span>Registro #<?php echo $index + 1; ?></span>
                                </div>
                            </div>
                            
                            <form method="POST" action="" style="margin-bottom: 16px;">
                                <input type="hidden" name="actualizar_estado" value="1">
                                <input type="hidden" name="id_direccionamiento" value="<?php echo htmlspecialchars($resultado['IDDireccionamiento']); ?>">
                                <input type="hidden" name="no_prescripcion" value="<?php echo htmlspecialchars($resultado['NoPrescripcion']); ?>">
                                <div class="checkbox-container">
                                    <input type="checkbox" 
                                           name="direccionado" 
                                           id="dir_<?php echo $index; ?>"
                                           <?php echo $resultado['direccionado'] ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <label for="dir_<?php echo $index; ?>">
                                        <?php echo $resultado['direccionado'] ? '✅ Direccionado' : '⏳ Pendiente de direccionar'; ?>
                                    </label>
                                </div>
                            </form>
                            
                            <div class="result-grid">
                                <div class="result-field">
                                    <label>ID:</label>
                                    <span><?php echo htmlspecialchars($resultado['ID'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>No. Prescripción:</label>
                                    <span><?php echo htmlspecialchars($resultado['NoPrescripcion'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Tipo Tecnología:</label>
                                    <span><?php echo htmlspecialchars($resultado['TipoTec'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Consecutivo Tech:</label>
                                    <span><?php echo htmlspecialchars($resultado['ConTec'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Tipo ID Paciente:</label>
                                    <span><?php echo htmlspecialchars($resultado['TipoIDPaciente'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>No. ID Paciente:</label>
                                    <span><?php echo htmlspecialchars($resultado['NoIDPaciente'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Tipo ID Proveedor:</label>
                                    <span><?php echo htmlspecialchars($resultado['TipoIDProv'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>No. ID Proveedor:</label>
                                    <span><?php echo htmlspecialchars($resultado['NoIDProv'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Código Municipio:</label>
                                    <span><?php echo htmlspecialchars($resultado['CodMunEnt'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Fecha Máx. Entrega:</label>
                                    <span><?php echo htmlspecialchars($resultado['FecMaxEnt'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Cantidad Total:</label>
                                    <span><?php echo htmlspecialchars($resultado['CantTotAEntregar'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="result-field">
                                    <label>Código Servicio:</label>
                                    <span><?php echo htmlspecialchars($resultado['CodSerTecAEntregar'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
        
        function actualizarContador() {
            const checkboxes = document.querySelectorAll('.checkbox-resultado:checked');
            const contador = checkboxes.length;
            document.getElementById('contadorSeleccionados').textContent = contador;
            
            const btnMasiva = document.getElementById('btnProgramacionMasiva');
            if (contador > 0) {
                btnMasiva.classList.add('visible');
            } else {
                btnMasiva.classList.remove('visible');
            }
        }
        
        function filtrarResultados(filtro) {
            const cards = document.querySelectorAll('.result-card');
            const buttons = document.querySelectorAll('.btn-filter');
            
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            cards.forEach(card => {
                const direccionado = card.getAttribute('data-direccionado');
                
                if (filtro === 'todos') {
                    card.style.display = 'block';
                } else if (filtro === 'direccionados' && direccionado === '1') {
                    card.style.display = 'block';
                } else if (filtro === 'no_direccionados' && direccionado === '0') {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function enviarProgramacionMasiva() {
            const checkboxes = document.querySelectorAll('.checkbox-resultado:checked');
            
            if (checkboxes.length === 0) {
                alert('Por favor seleccione al menos un registro para realizar la programación masiva');
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../programacion/realizar_programacion.php';
            
            // Agregar NIT y Token
            const nit = document.querySelector('input[name="nit"]').value;
            const token = document.querySelector('input[name="token_temporal"]').value;
            
            const inputNit = document.createElement('input');
            inputNit.type = 'hidden';
            inputNit.name = 'nit';
            inputNit.value = nit;
            form.appendChild(inputNit);
            
            const inputToken = document.createElement('input');
            inputToken.type = 'hidden';
            inputToken.name = 'token_temporal';
            inputToken.value = token;
            form.appendChild(inputToken);
            
            // Agregar flag de múltiples registros
            const inputMultiple = document.createElement('input');
            inputMultiple.type = 'hidden';
            inputMultiple.name = 'multiple';
            inputMultiple.value = '1';
            form.appendChild(inputMultiple);
            
            // Agregar datos de cada checkbox seleccionado
            checkboxes.forEach((checkbox, index) => {
                const datos = {
                    id: checkbox.getAttribute('data-id'),
                    fec_max_ent: checkbox.getAttribute('data-fec-max-ent'),
                    tipo_id_sede_prov: checkbox.getAttribute('data-tipo-id-prov'),
                    no_id_sede_prov: checkbox.getAttribute('data-no-id-prov'),
                    cod_ser_tec_a_entregar: checkbox.getAttribute('data-cod-ser-tec'),
                    cant_tot_a_entregar: checkbox.getAttribute('data-cant-tot'),
                    no_entrega: checkbox.getAttribute('data-no-entrega')
                };
                
                Object.keys(datos).forEach(key => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `registros[${index}][${key}]`;
                    input.value = datos[key];
                    form.appendChild(input);
                });
            });
            
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
