<?php
if (defined('AUTH_LOADED')){
	return;
}
define ('AUTH_LOADED',true);


require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // Iniciar sesión
    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");
            $stmt->execute([$username]);
            $usuario = $stmt->fetch();
            
            if ($usuario && password_verify($password, $usuario['password'])) {
                // Actualizar último acceso
                $updateStmt = $this->db->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
                $updateStmt->execute([$usuario['id']]);
                
                // Registrar log de acceso
                $this->registrarLog($usuario['id'], 'login', 'Inicio de sesión exitoso');
                
                // Crear sesión
                session_start();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $usuario['id'];
                $_SESSION['username'] = $usuario['username'];
                $_SESSION['nombre_completo'] = $usuario['nombre_completo'];
                $_SESSION['rol'] = $usuario['rol'];
                $_SESSION['login_time'] = time();
                
                return [
                    'success' => true,
                    'message' => 'Inicio de sesión exitoso',
                    'usuario' => [
                        'id' => $usuario['id'],
                        'username' => $usuario['username'],
                        'nombre_completo' => $usuario['nombre_completo'],
                        'rol' => $usuario['rol']
                    ]
                ];
            } else {
                // Registrar intento fallido
                if ($usuario) {
                    $this->registrarLog($usuario['id'], 'login_failed', 'Intento de inicio de sesión fallido');
                }
                
                return [
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ];
            }
        } catch(PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error en el sistema: ' . $e->getMessage()
            ];
        }
    }
    
    // Cerrar sesión
    public function logout() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['user_id'])) {
                // Registrar el log de cierre de sesión
                $this->registrarLog($_SESSION['user_id'], 'logout', 'Cierre de sesión');
            }
            
            // Eliminar todas las variables de sesión
            unset($_SESSION['token_temporal']);
            unset($_SESSION['token_timestamp']);
            unset($_SESSION['token_nit']);
            
            // Destruir la sesión
            session_unset();
            session_destroy();

            // Eliminar cookies de sesión si existen
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }

            // Redirigir a la página de login después del logout
            header('Location: ' . BASE_PATH . '/login.php');
            exit();
        }
    }
    
    // Verificar si el usuario está autenticado
    public function isAuthenticated() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Comprobamos si la sesión está activa
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
            return false;
        }
        
        // Verificar tiempo de sesión
        if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }
        
        return true;
    }
    
    // Verificar rol de administrador
    public function isAdmin() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador';
    }
    
    // Registrar log de acceso
    private function registrarLog($usuario_id, $accion, $descripcion) {
        try {
            $stmt = $this->db->prepare("INSERT INTO logs_acceso (usuario_id, accion, descripcion, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $accion, $descripcion, $_SERVER['REMOTE_ADDR']]);
        } catch(PDOException $e) {
            // Silenciar errores de log para no interrumpir el flujo
        }
    }
    
    // Obtener información del usuario actual
    public function getCurrentUser() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'nombre_completo' => $_SESSION['nombre_completo'],
            'rol' => $_SESSION['rol']
        ];
    }
}
?>
