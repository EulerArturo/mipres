<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();

// Si ya está autenticado, redirigir al dashboard
if ($auth->isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

// Procesar el formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor ingrese usuario y contraseña';
    } else {
        $result = $auth->login($username, $password);
        
        if ($result['success']) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <!-- Removed all inline styles, using centralized CSS -->
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <h1><?php echo APP_NAME; ?></h1>
            <p>Sistema de Gestión MIPRES</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required 
                        autofocus
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                    >
                </div>
                
                <button type="submit" class="btn">Iniciar Sesión</button>
            </form>
        </div>
        
        <div class="login-footer">
           <p>David Pupiales - Conexion Salud</p>
            © <?php echo date('Y'); ?> - Todos los derechos reservados<br>
	   <p><?php echo "Versión " . APP_VERSION; ?></p>

        </div>
    </div>
</body>
</html>
