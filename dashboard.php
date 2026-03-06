<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();

// Verificar autenticación
if (!$auth->isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <!-- Removed all inline styles, using centralized CSS -->
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="header">
        <h1><?php echo APP_NAME; ?></h1>
        <div class="user-info">
            <span class="user-badge">
                <?php echo htmlspecialchars($usuario['nombre_completo']); ?>
            </span>
            <a href="logout.php" class="logout-btn">Cerrar Sesión</a>
        </div>
    </div>
    
    <div class="container">
        <aside class="sidebar">
            <div class="sidebar-section">
                <a href="dashboard.php" class="sidebar-item active">
                    Inicio
                </a>
            </div>
            
            <div class="sidebar-section">
                <a href="token/generar_token.php" class="sidebar-item">
                    Generación de Token
                </a>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('direccionamiento')">
                    <span>Direccionamiento</span>
                    <span class="arrow" id="arrow-direccionamiento">▶</span>
                </div>
                <div class="sidebar-children" id="menu-direccionamiento">
                    <a href="direccionamiento/por_prescripcion.php" class="sidebar-item sidebar-child">Por Num. Prescripción</a>
                    <a href="direccionamiento/por_fecha.php" class="sidebar-item sidebar-child">Por Fecha</a>
                    <a href="direccionamiento/por_paciente.php" class="sidebar-item sidebar-child">Por Paciente</a>
                    
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('programacion')">
                    <span>Programación</span>
                    <span class="arrow" id="arrow-programacion">▶</span>
                </div>
                <div class="sidebar-children" id="menu-programacion">
                    <a href="programacion/realizar_programacion.php" class="sidebar-item sidebar-child">Realizar Programación</a>
                    <a href="programacion/consultar_por_fecha.php" class="sidebar-item sidebar-child">Consultar por Fecha</a>
                    <a href="programacion/consultar_programacion.php" class="sidebar-item sidebar-child">Consultar por Prescripción</a>
                    <a href="programacion/consultar_por_paciente.php" class="sidebar-item sidebar-child">Consultar por Paciente</a>
                </div>
            </div>
            
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('entrega')">
                    <span>Entrega</span>
                    <span class="arrow" id="arrow-entrega">▶</span>
                </div>
                <div class="sidebar-children" id="menu-entrega">
                    <a href="entrega/registrar_entrega.php" class="sidebar-item sidebar-child">Registrar Entrega</a>
                    <a href="entrega/consultar_entrega.php" class="sidebar-item sidebar-child">Consultar Entrega</a>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('reporte_entrega')">
                    <span>Reporte de Entrega</span>
                    <span class="arrow" id="arrow-reporte_entrega">▶</span>
                </div>
                <div class="sidebar-children" id="menu-reporte_entrega">
                    <a href="reporte_entrega/generar_reporte.php" class="sidebar-item sidebar-child">Generar Reporte</a>
                    <a href="reporte_entrega/consultar_reporte.php" class="sidebar-item sidebar-child">Consultar Reporte</a>
                </div>
            </div>
            
	    <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('facturacion')">
                    <span>Facturación</span>
                    <span class="arrow" id="arrow-facturacion">▶</span>
                </div>
                <div class="sidebar-children" id="menu-facturacion">
                    <a href="facturacion/realizar_facturacion.php" class="sidebar-item sidebar-child">Realizar Facturación</a>
                    <a href="facturacion/consultar_facturacion.php" class="sidebar-item sidebar-child">Consultar Facturación</a>
                </div>
            </div>
            

            <?php if ($isAdmin): ?>
            <div class="sidebar-section">
                <div class="sidebar-item sidebar-parent" onclick="toggleMenu('admin')">
                    <span>Administración</span>
                    <span class="arrow" id="arrow-admin">▶</span>
                </div>
                <div class="sidebar-children" id="menu-admin">
                    <a href="admin/crear_usuario.php" class="sidebar-item sidebar-child">Crear Usuarios</a>
                    <a href="#" class="sidebar-item sidebar-child">Generar Reportes</a>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        
        <main class="main-content">
            <div class="welcome-card">
                <h2>Bienvenido, <?php echo htmlspecialchars($usuario['nombre_completo']); ?></h2>
                <p>Has iniciado sesión exitosamente en el sistema de gestión MIPRES - Conexión Salud.</p>
                <span class="role-badge">
                    <?php echo $isAdmin ? 'ADMINISTRADOR' : 'USUARIO'; ?>
                </span>
            </div>
            
            <div class="info-grid">
                <div class="info-card">
                    <h3>Estado del Sistema</h3>
                    <p>El sistema está operativo y listo para procesar solicitudes de MIPRES.</p>
                </div>
                
                <div class="info-card">
                    <h3>Próximos Pasos</h3>
                    <p>Selecciona una opción del menú lateral para comenzar a trabajar con las APIs de MIPRES.</p>
                </div>
                
                <div class="info-card">
                    <h3>Información</h3>
                    <p>Este sistema permite gestionar direccionamientos, programaciones y entregas de medicamentos.</p>
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
    </script>
</body>
</html>
