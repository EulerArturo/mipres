<?php
// Comienza el script sin ninguna salida antes de esta línea
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Inicia sesión si no está activa
session_start();

// Crear objeto de autenticación y realizar el logout
$auth = new Auth();
$auth->logout(); // Llama a la función logout para destruir la sesión

// Después de destruir la sesión, redirige al login
header('Location: login.php');
exit; // Asegúrate de usar exit() después del redireccionamiento
?>
