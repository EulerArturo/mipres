<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

if (!$auth->isAuthenticated()) {
    header('Location: ../login.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$mensaje = '';
$tipo_mensaje = '';
$datos_formulario = [];

// Obtener datos de entrega si vienen via GET
if (!empty($_GET['no_prescripcion'])) {
    $datos_formulario['no_prescripcion'] = $_GET['no_prescripcion'];
}
if (!empty($_GET['tipo_tec'])) {
    $datos_formulario['tipo_tec'] = $_GET['tipo_tec'];
}
if (!empty($_GET['tipo_id_paciente'])) {
    $datos_formulario['tipo_id_paciente'] = $_GET['tipo_id_paciente'];
}
if (!empty($_GET['no_id_paciente'])) {
    $datos_formulario['no_id_paciente'] = $_GET['no_id_paciente'];
}
if (!empty($_GET['no_entrega'])) {
    $datos_formulario['no_entrega'] = $_GET['no_entrega'];
}
if (!empty($_GET['cod_ser_tec_entregado'])) {
    $datos_formulario['cod_ser_tec_entregado'] = $_GET['cod_ser_tec_entregado'];
}
if (!empty($_GET['cant_un_min_dis'])) {
    $datos_formulario['cant_un_min_dis'] = $_GET['cant_un_min_dis'];
}

// Token de sesión o GET
$token_temporal = $_SESSION['token_temporal'] ?? $_GET['token'] ?? '';
$nit_sesion = $_SESSION['token_nit'] ?? $_GET['nit'] ?? '';

// Procesar envío de facturación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_facturacion'])) {
    $no_prescripcion = trim($_POST['no_prescripcion'] ?? '');
    $tipo_tec = trim($_POST['tipo_tec'] ?? '');
    $con_tec = trim($_POST['con_tec'] ?? '');
    $tipo_id_paciente = trim($_POST['tipo_id_paciente'] ?? '');
    $no_id_paciente = trim($_POST['no_id_paciente'] ?? '');
    $no_entrega = trim($_POST['no_entrega'] ?? '');
    $no_sub_entrega = trim($_POST['no_sub_entrega'] ?? '');
    $no_factura = trim($_POST['no_factura'] ?? '');
    $no_id_eps = trim($_POST['no_id_eps'] ?? '');
    $cod_eps = trim($_POST['cod_eps'] ?? '');
    $cod_ser_tec_entregado = trim($_POST['cod_ser_tec_entregado'] ?? '');
    $cant_un_min_dis = trim($_POST['cant_un_min_dis'] ?? '0');
    $valor_unit_facturado = trim($_POST['valor_unit_facturado'] ?? '0');
    $valor_tot_facturado = trim($_POST['valor_tot_facturado'] ?? '0');
    $cuota_moderadora = trim($_POST['cuota_moderadora'] ?? '0');
    $copago = trim($_POST['copago'] ?? '0');
    $dir_paciente = trim($_POST['dir_paciente'] ?? '');
    
    if (empty($no_prescripcion) || empty($no_id_eps) || empty($valor_tot_facturado)) {
        $mensaje = 'Prescripción, NIT EPS y Valor Total son obligatorios';
        $tipo_mensaje = 'error';
    } else {
        try {
            if (empty($token_temporal)) {
                $token_temporal = $_SESSION['token_temporal'] ?? '';
            }
            
            if (empty($token_temporal)) {
                $mensaje = 'No hay token disponible';
                $tipo_mensaje = 'error';
            } else {
                // Guardar en BD
                $stmt = $pdo->prepare("
                    INSERT INTO facturaciones 
                    (usuario_id, nombre_usuario, no_prescripcion, tipo_tec, con_tec, 
                     tipo_id_paciente, no_id_paciente, no_entrega, no_sub_entrega, 
                     no_factura, no_id_eps, cod_eps, cod_ser_tec_entregado, 
                     cant_un_min_dis, valor_unit_facturado, valor_tot_facturado, 
                     cuota_moderadora, copago, dir_paciente, token_temporal, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $usuario['id'],
                    $usuario['nombre_completo'] ?? '',
                    $no_prescripcion,
                    $tipo_tec,
                    $con_tec,
                    $tipo_id_paciente,
                    $no_id_paciente,
                    $no_entrega,
                    $no_sub_entrega,
                    $no_factura,
                    $no_id_eps,
                    $cod_eps,
                    $cod_ser_tec_entregado,
                    (float)$cant_un_min_dis,
                    (float)$valor_unit_facturado,
                    (float)$valor_tot_facturado,
                    (float)$cuota_moderadora,
                    (float)$copago,
                    $dir_paciente,
                    $token_temporal,
                    'pendiente'
                ]);
                
                $facturacion_id = $pdo->lastInsertId();
                
                // Enviar a API MIPRES si se solicita
                if (isset($_POST['enviar_api'])) {
                    $token_api = rtrim($token_temporal, '=');
                    $url_api = "https://wsmipres.sispro.gov.co/WSFACMIPRESNOPBS/api/Facturacion/{$no_id_eps}/{$token_api}%3D";
                    
                    $datos_api = [
                        'NoEntrega' => $no_entrega,
                        'NoSubEntrega' => $no_sub_entrega,
                        'NoPrescripcion' => $no_prescripcion,
                        'TipoTec' => $tipo_tec,
                        'ConTec' => $con_tec,
                        'TipoIDPaciente' => $tipo_id_paciente,
                        'NoIDPaciente' => $no_id_paciente,
                        'NoFactura' => $no_factura,
                        'CodSerTecEntregado' => $cod_ser_tec_entregado,
                        'CantUnMinDis' => (float)$cant_un_min_dis,
                        'ValorUnitFacturado' => (float)$valor_unit_facturado,
                        'ValorTotFacturado' => (float)$valor_tot_facturado,
                        'CuotaMod' => (float)$cuota_moderadora,
                        'Copago' => (float)$copago,
                        'DirPaciente' => $dir_paciente
                    ];
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url_api);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos_api));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    
                    $response_json = json_decode($response, true);
                    
                    $pdo->prepare("UPDATE facturaciones SET estado = ?, respuesta_api = ?, http_code = ? WHERE id = ?")
                        ->execute([$http_code === 200 ? 'enviada' : 'error', $response, $http_code, $facturacion_id]);
                    
                    if ($http_code === 200) {
                        $mensaje = '✓ Facturación enviada a MIPRES exitosamente';
                        $tipo_mensaje = 'success';
                    } else {
                        $mensaje = '⚠ Facturación guardada pero error al enviar a API (Código: ' . $http_code . ')';
                        $tipo_mensaje = 'warning';
                    }
                } else {
                    $mensaje = '✓ Facturación registrada (no enviada a MIPRES)';
                    $tipo_mensaje = 'success';
                }
            }
        } catch (PDOException $e) {
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar Facturación</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="header">
        <h1>Realizar Facturación - MIPRES</h1>
        <div class="user-info">
            <span class="user-badge"><?php echo htmlspecialchars($usuario['nombre_completo'] ?? 'Usuario'); ?></span>
            <a href="../logout.php" class="logout-btn">Salir</a>
        </div>
    </div>

    <div class="layout">
        <div class="sidebar">
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('facturacion')">
                    <span>Facturación</span>
                    <span class="arrow open" id="arrow-facturacion">▶</span>
                </div>
                <div class="sidebar-children open" id="menu-facturacion">
                    <a href="realizar_facturacion.php" class="sidebar-item sidebar-child active">Realizar Facturación</a>
                    <a href="consultar_facturacion.php" class="sidebar-item sidebar-child">Consultar Facturación</a>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="card">
                <h2>Realizar Facturación</h2>
                <p class="card-subtitle">Complete los 16 campos requeridos por MIPRES v2.4</p>
                
                <?php if ($mensaje): ?>
                    <div class="result-box <?php echo $tipo_mensaje; ?>" style="margin-bottom: 20px;">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <!-- Campo 1: Número de Prescripción -->
                        <div class="form-group">
                            <label>1. Número de Prescripción *</label>
                            <input type="text" name="no_prescripcion" required maxlength="20" 
                                value="<?php echo htmlspecialchars($datos_formulario['no_prescripcion'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 2: Tipo Servicio/Tecnología -->
                        <div class="form-group">
                            <label>2. Tipo Servicio/Tecnología *</label>
                            <select name="tipo_tec" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="M" <?php echo ($datos_formulario['tipo_tec'] ?? '') === 'M' ? 'selected' : ''; ?>>M: Medicamento</option>
                                <option value="P" <?php echo ($datos_formulario['tipo_tec'] ?? '') === 'P' ? 'selected' : ''; ?>>P: Procedimiento</option>
                                <option value="D" <?php echo ($datos_formulario['tipo_tec'] ?? '') === 'D' ? 'selected' : ''; ?>>D: Dispositivo</option>
                                <option value="N" <?php echo ($datos_formulario['tipo_tec'] ?? '') === 'N' ? 'selected' : ''; ?>>N: Producto Nutricional</option>
                                <option value="S" <?php echo ($datos_formulario['tipo_tec'] ?? '') === 'S' ? 'selected' : ''; ?>>S: Servicio Complementario</option>
                            </select>
                        </div>
                        
                        <!-- Campo 3: Consecutivo Orden -->
                        <div class="form-group">
                            <label>3. Consecutivo Orden *</label>
                            <input type="text" name="con_tec" required maxlength="2"
                                value="<?php echo htmlspecialchars($datos_formulario['con_tec'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 4: Tipo ID Paciente -->
                        <div class="form-group">
                            <label>4. Tipo Documento Paciente *</label>
                            <select name="tipo_id_paciente" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="CC" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'CC' ? 'selected' : ''; ?>>CC: Cédula Ciudadanía</option>
                                <option value="RC" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'RC' ? 'selected' : ''; ?>>RC: Registro Civil</option>
                                <option value="TI" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'TI' ? 'selected' : ''; ?>>TI: Tarjeta Identidad</option>
                                <option value="CE" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'CE' ? 'selected' : ''; ?>>CE: Cédula Extranjería</option>
                                <option value="PA" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'PA' ? 'selected' : ''; ?>>PA: Pasaporte</option>
                                <option value="NV" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'NV' ? 'selected' : ''; ?>>NV: Nacido Vivo</option>
                                <option value="CD" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'CD' ? 'selected' : ''; ?>>CD: Carné Diplomático</option>
                                <option value="SC" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'SC' ? 'selected' : ''; ?>>SC: Salvoconducto</option>
                                <option value="PR" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'PR' ? 'selected' : ''; ?>>PR: Pasaporte ONU</option>
                                <option value="PE" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'PE' ? 'selected' : ''; ?>>PE: Permiso Especial</option>
                                <option value="AS" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'AS' ? 'selected' : ''; ?>>AS: Adulto sin ID</option>
                                <option value="MS" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'MS' ? 'selected' : ''; ?>>MS: Menor sin ID</option>
                                <option value="PT" <?php echo ($datos_formulario['tipo_id_paciente'] ?? '') === 'PT' ? 'selected' : ''; ?>>PT: Permiso Temporal</option>
                            </select>
                        </div>
                        
                        <!-- Campo 5: Número ID Paciente -->
                        <div class="form-group">
                            <label>5. Número ID Paciente *</label>
                            <input type="text" name="no_id_paciente" required maxlength="17"
                                value="<?php echo htmlspecialchars($datos_formulario['no_id_paciente'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 6: Número Entrega -->
                        <div class="form-group">
                            <label>6. Número Entrega *</label>
                            <input type="text" name="no_entrega" required maxlength="4"
                                value="<?php echo htmlspecialchars($datos_formulario['no_entrega'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 7: Número Sub Entrega -->
                        <div class="form-group">
                            <label>7. Número Sub Entrega *</label>
                            <input type="text" name="no_sub_entrega" required maxlength="2"
                                value="<?php echo htmlspecialchars($datos_formulario['no_sub_entrega'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 8: Número Factura -->
                        <div class="form-group">
                            <label>8. Número de Factura Electrónica *</label>
                            <input type="text" name="no_factura" required maxlength="96"
                                value="<?php echo htmlspecialchars($datos_formulario['no_factura'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 9: NIT EPS -->
                        <div class="form-group">
                            <label>9. NIT de EPS que realiza recobro *</label>
                            <input type="text" name="no_id_eps" required maxlength="17"
                                value="<?php echo htmlspecialchars($datos_formulario['no_id_eps'] ?? $nit_sesion ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 10: Código EPS -->
                        <div class="form-group">
                            <label>10. Código de EPS *</label>
                            <input type="text" name="cod_eps" required maxlength="6"
                                value="<?php echo htmlspecialchars($datos_formulario['cod_eps'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 11: Código Servicio Entregado -->
                        <div class="form-group">
                            <label>11. Código Servicio/Tecnología Entregado *</label>
                            <input type="text" name="cod_ser_tec_entregado" required maxlength="20"
                                value="<?php echo htmlspecialchars($datos_formulario['cod_ser_tec_entregado'] ?? ''); ?>">
                        </div>
                        
                        <!-- Campo 12: Cantidad -->
                        <div class="form-group">
                            <label>12. Cantidad (Un. Mín. Dispensación) *</label>
                            <input type="number" name="cant_un_min_dis" required step="0.0001" maxlength="16"
                                value="<?php echo htmlspecialchars($datos_formulario['cant_un_min_dis'] ?? '0'); ?>">
                        </div>
                        
                        <!-- Campo 13: Valor Unitario -->
                        <div class="form-group">
                            <label>13. Valor Unitario Facturado *</label>
                            <input type="number" name="valor_unit_facturado" required step="0.01" maxlength="16"
                                value="<?php echo htmlspecialchars($datos_formulario['valor_unit_facturado'] ?? '0'); ?>">
                        </div>
                        
                        <!-- Campo 14: Valor Total -->
                        <div class="form-group">
                            <label>14. Valor Total Facturado *</label>
                            <input type="number" name="valor_tot_facturado" required step="0.01" maxlength="16"
                                value="<?php echo htmlspecialchars($datos_formulario['valor_tot_facturado'] ?? '0'); ?>">
                        </div>
                        
                        <!-- Campo 15: Cuota Moderadora -->
                        <div class="form-group">
                            <label>15. Cuota Moderadora</label>
                            <input type="number" name="cuota_moderadora" step="0.01" maxlength="16"
                                value="<?php echo htmlspecialchars($datos_formulario['cuota_moderadora'] ?? '0'); ?>">
                        </div>
                        
                        <!-- Campo 16: Copago -->
                        <div class="form-group">
                            <label>16. Copago</label>
                            <input type="number" name="copago" step="0.01" maxlength="16"
                                value="<?php echo htmlspecialchars($datos_formulario['copago'] ?? '0'); ?>">
                        </div>
                        
                        <!-- Dirección Paciente -->
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Dirección del Paciente</label>
                            <input type="text" name="dir_paciente" maxlength="80"
                                value="<?php echo htmlspecialchars($datos_formulario['dir_paciente'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" name="enviar_facturacion" class="btn" style="flex: 1;">
                            Guardar en Base de Datos
                        </button>
                        <button type="submit" name="enviar_facturacion" value="1" class="btn" style="flex: 1; background-color: #007bff;">
                            Guardar y Enviar a MIPRES
                        </button>
                    </div>
                    <input type="hidden" name="enviar_api" id="enviar-api" value="0">
                </form>
            </div>
        </div>
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
        
        // Actualizar valor total automáticamente
        document.addEventListener('DOMContentLoaded', function() {
            const cantInput = document.querySelector('input[name="cant_un_min_dis"]');
            const valorUnitInput = document.querySelector('input[name="valor_unit_facturado"]');
            const valorTotalInput = document.querySelector('input[name="valor_tot_facturado"]');
            
            const actualizarTotal = () => {
                const cant = parseFloat(cantInput.value) || 0;
                const unitario = parseFloat(valorUnitInput.value) || 0;
                valorTotalInput.value = (cant * unitario).toFixed(2);
            };
            
            cantInput.addEventListener('change', actualizarTotal);
            valorUnitInput.addEventListener('change', actualizarTotal);
            
            // Detectar botón de envío a API
            document.querySelectorAll('button[name="enviar_facturacion"]').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    document.getElementById('enviar-api').value = this.value === '1' ? '1' : '0';
                });
            });
        });
    </script>
</body>
</html>
?>

    <script>
        function toggleMenu(menuId) {
            const menu = document.getElementById('menu-' + menuId);
            const arrow = document.getElementById('arrow-' + menuId);
            if (menu) {
                menu.classList.toggle('open');
                arrow.classList.toggle('open');
            }
        }
        
        // Actualizar valor total automáticamente
        document.addEventListener('DOMContentLoaded', function() {
            const cantInput = document.querySelector('input[name="cant_un_min_dis"]');
            const valorUnitInput = document.querySelector('input[name="valor_unit_facturado"]');
            const valorTotalInput = document.querySelector('input[name="valor_tot_facturado"]');
            
            const actualizarTotal = () => {
                const cant = parseFloat(cantInput.value) || 0;
                const unitario = parseFloat(valorUnitInput.value) || 0;
                valorTotalInput.value = (cant * unitario).toFixed(2);
            };
            
            cantInput.addEventListener('change', actualizarTotal);
            valorUnitInput.addEventListener('change', actualizarTotal);
            
            // Detectar botón de envío a API
            document.querySelectorAll('button[name="enviar_facturacion"]').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    document.getElementById('enviar-api').value = this.value === '1' ? '1' : '0';
                });
            });
        });
    </script>
</body>
</html>
