<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

// Verificar autenticación y rol de administrador
if (!$auth->isAuthenticated() || !$auth->isAdmin()) {
    header('Location: ../login.php');
    exit;
}

$usuario = $auth->getCurrentUser();
$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $nombre_completo = trim($_POST['nombre_completo'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rol = $_POST['rol'] ?? 'usuario';
    
    // Validaciones
    if (empty($username) || empty($password) || empty($nombre_completo) || empty($email)) {
        $mensaje = 'Por favor complete todos los campos requeridos para crear el usuario';
        $tipo_mensaje = 'error';
    } elseif ($password !== $confirm_password) {
        $mensaje = 'Las contraseñas ingresadas no coinciden. Por favor verifique e intente nuevamente';
        $tipo_mensaje = 'error';
    } elseif (strlen($password) < 6) {
        $mensaje = 'La contraseña debe contener al menos 6 caracteres para mayor seguridad';
        $tipo_mensaje = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensaje = 'El formato del correo electrónico no es válido. Por favor verifique (ejemplo: usuario@dominio.com)';
        $tipo_mensaje = 'error';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Verificar si el usuario ya existe
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if ($stmt->fetch()) {
                $mensaje = 'Ya existe un usuario registrado con ese nombre de usuario o correo electrónico. Por favor utilice datos diferentes';
                $tipo_mensaje = 'error';
            } else {
                // Crear usuario
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO usuarios (username, password, nombre_completo, email, rol) VALUES (?, ?, ?, ?, ?)");
                
                if ($stmt->execute([$username, $password_hash, $nombre_completo, $email, $rol])) {
                    $mensaje = '¡Usuario creado exitosamente! Ya puede iniciar sesión con sus credenciales';
                    $tipo_mensaje = 'success';
                    
                    // Limpiar formulario
                    $_POST = [];
                } else {
                    $mensaje = 'No se pudo crear el usuario. Por favor intente nuevamente o contacte al administrador del sistema';
                    $tipo_mensaje = 'error';
                }
            }
        } catch(PDOException $e) {
            $mensaje = 'Ocurrió un error en el sistema al crear el usuario. Por favor intente nuevamente más tarde';
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
    <title>Crear Usuario - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            font-size: 20px;
            font-weight: 600;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .user-badge {
            background-color: rgba(255, 255, 255, 0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
        }
        
        .back-btn, .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }
        
        .back-btn:hover, .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 24px;
            font-size: 24px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        
        .required {
            color: #e74c3c;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo APP_NAME; ?> - Crear Usuario</h1>
        <div class="user-info">
            <a href="../dashboard.php" class="back-btn">← Volver al Dashboard</a>
            <span class="user-badge">
                👤 <?php echo htmlspecialchars($usuario['nombre_completo']); ?>
            </span>
            <a href="../logout.php" class="logout-btn">Cerrar Sesión</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <h2>Crear Nuevo Usuario</h2>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Nombre de Usuario <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            required
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            required
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="nombre_completo">Nombre Completo <span class="required">*</span></label>
                    <input 
                        type="text" 
                        id="nombre_completo" 
                        name="nombre_completo" 
                        value="<?php echo htmlspecialchars($_POST['nombre_completo'] ?? ''); ?>"
                        required
                    >
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Contraseña <span class="required">*</span></label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            minlength="6"
                            required
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirmar Contraseña <span class="required">*</span></label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            minlength="6"
                            required
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="rol">Rol <span class="required">*</span></label>
                    <select id="rol" name="rol" required>
                        <option value="usuario" <?php echo (($_POST['rol'] ?? '') === 'usuario') ? 'selected' : ''; ?>>
                            Usuario
                        </option>
                        <option value="administrador" <?php echo (($_POST['rol'] ?? '') === 'administrador') ? 'selected' : ''; ?>>
                            Administrador
                        </option>
                    </select>
                </div>
                
                <button type="submit" class="btn-primary">Crear Usuario</button>
            </form>
        </div>
    </div>
</body>
</html>
