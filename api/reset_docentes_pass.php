<?php
require_once __DIR__ . '/conexion.php';

// Resetear claves de todos los usuarios que son DOCENTES (perfil 3)
$sql = "UPDATE usuarios u 
        INNER JOIN usuario_perfil up ON u.id_usuario = up.id_usuario 
        SET u.clave = '12345' 
        WHERE up.id_perfil = 3";

if ($conexion->query($sql)) {
    echo "OK: Claves de docentes reseteadas a 12345\n";
} else {
    echo "ERROR: " . $conexion->error . "\n";
}

echo "DOCENTES_PASSWORD_RESET_DONE";
?>
