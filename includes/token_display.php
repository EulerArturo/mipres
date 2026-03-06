<?php

// Verificar si el token ha expirado (24 horas = 86400 segundos)
$token_valido = false;
$token_display = '';
$nit_display = '';

if (isset($_SESSION['token_temporal']) && isset($_SESSION['token_timestamp'])) {
    $tiempo_transcurrido = time() - $_SESSION['token_timestamp'];
    
    if ($tiempo_transcurrido < 86400) { // 24 horas
        $token_valido = true;
        $token_display = $_SESSION['token_temporal'];
        $nit_display = $_SESSION['token_nit'] ?? '';
    } else {
        // Token expirado, limpiar sesión
        unset($_SESSION['token_temporal']);
        unset($_SESSION['token_timestamp']);
        unset($_SESSION['token_nit']);
    }
}

$current_path = $_SERVER['PHP_SELF'];
$token_link = '/MIPRES/token/generar_token.php';
if (strpos($current_path, '/token/') !== false) {
    $token_link = 'generar_token.php';
} elseif (strpos($current_path, '/direccionamiento/') !== false || 
          strpos($current_path, '/programacion/') !== false || 
          strpos($current_path, '/entrega/') !== false ||
          strpos($current_path, '/admin/') !== false) {
    $token_link = '../token/generar_token.php';
}
?>

<?php if ($token_valido): ?>
<div class="token-info-box">
    <div class="token-info-header">
        <span class="token-icon">🔑</span>
        <span class="token-title">Token Temporal Activo</span>
    </div>
    <div class="token-info-content">
        <div class="token-field">
            <label>NIT:</label>
            <span class="token-value-small"><?php echo htmlspecialchars($nit_display); ?></span>
        </div>
        <div class="token-field">
            <label>Token:</label>
            <div class="token-value-display">
                <?php echo htmlspecialchars(substr($token_display, 0, 30)) . '...'; ?>
            </div>
        </div>
        <div class="token-expiry">
            ⏱️ Válido por <?php echo round((86400 - (time() - $_SESSION['token_timestamp'])) / 3600, 1); ?> horas más
        </div>
    </div>
</div>
<?php else: ?>
<div class="token-warning-box">
    <span class="warning-icon">⚠️</span>
    <span class="warning-text">No hay token temporal activo. <a href="<?php echo $token_link; ?>">Generar token</a></span>
</div>
<?php endif; ?>

<style>
.token-info-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
    color: white;
}

.token-info-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    font-weight: 600;
    font-size: 14px;
}

.token-icon {
    font-size: 18px;
}

.token-info-content {
    background-color: rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    padding: 12px;
}

.token-field {
    margin-bottom: 8px;
}

.token-field label {
    display: block;
    font-size: 11px;
    opacity: 0.9;
    margin-bottom: 4px;
}

.token-value-small {
    font-size: 13px;
    font-weight: 600;
}

.token-value-display {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    background-color: rgba(255, 255, 255, 0.2);
    padding: 6px 8px;
    border-radius: 4px;
    word-break: break-all;
}

.token-expiry {
    margin-top: 8px;
    font-size: 12px;
    text-align: center;
    opacity: 0.95;
}

.token-warning-box {
    background-color: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.warning-icon {
    font-size: 20px;
}

.warning-text {
    color: #856404;
    font-size: 13px;
}

.warning-text a {
    color: #667eea;
    font-weight: 600;
    text-decoration: none;
}

.warning-text a:hover {
    text-decoration: underline;
}
</style>
