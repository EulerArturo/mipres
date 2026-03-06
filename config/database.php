<?php
// Configuración de la base de datos para XAMPP
define('DB_HOST', 'localhost');
define('DB_PORT', '3306'); // Puerto personalizado de XAMPP
define('DB_NAME', 'mipres_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // Por defecto XAMPP no tiene contraseña

// Configuración de la aplicación
define('APP_NAME', 'CARGUE MIPRES - CONEXION SALUD');
define('APP_VERSION', '1.0.0');

// Definir la ruta base
define('BASE_PATH', '/herramienta-mipres-cs2-pruebas');

// Configuración de sesión
define('SESSION_LIFETIME', 3600); // 1 hora en segundos

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch(PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage() . "<br>Verifica que MySQL esté corriendo en el puerto " . DB_PORT . " y que la base de datos 'mipres_db' exista.");
}

// Clase Database para uso opcional con patrón singleton
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        global $pdo;
        $this->connection = $pdo;
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prevenir clonación
    private function __clone() {}
    
    // Prevenir deserialización
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>
