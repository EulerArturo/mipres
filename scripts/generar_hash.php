<?php
// Script para generar el hash correcto de la contraseña
// Ejecuta este archivo en tu navegador: http://localhost/mipres/scripts/generar_hash.php

$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Hash generado para la contraseña: admin123</h2>";
echo "<p><strong>Hash:</strong></p>";
echo "<pre style='background: #f4f4f4; padding: 15px; border-radius: 5px;'>" . $hash . "</pre>";
echo "<hr>";
echo "<h3>Instrucciones:</h3>";
echo "<ol>";
echo "<li>Copia el hash de arriba</li>";
echo "<li>Ve a phpMyAdmin</li>";
echo "<li>Abre la base de datos 'mipres_db'</li>";
echo "<li>Abre la tabla 'usuarios'</li>";
echo "<li>Edita el usuario 'admin'</li>";
echo "<li>Pega este hash en el campo 'password'</li>";
echo "<li>Guarda los cambios</li>";
echo "</ol>";
echo "<hr>";
echo "<h3>O ejecuta este SQL directamente:</h3>";
echo "<pre style='background: #f4f4f4; padding: 15px; border-radius: 5px;'>";
echo "UPDATE usuarios SET password = '" . $hash . "' WHERE username = 'admin';";
echo "</pre>";
?>
