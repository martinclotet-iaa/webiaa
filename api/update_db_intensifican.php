<?php
require_once "conexion.php";
try {
    // Verificar si las columnas ya existen
    $res = $conexion->query("SHOW COLUMNS FROM notas LIKE 'anio_adeuda'");
    if ($res->num_rows == 0) {
        $conexion->query("ALTER TABLE notas ADD COLUMN anio_adeuda INT DEFAULT NULL");
        $conexion->query("ALTER TABLE notas ADD COLUMN materia_adeuda VARCHAR(100) DEFAULT NULL");
        echo "DATABASE_UPDATED";
    } else {
        echo "ALREADY_UPDATED";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
// unlink(__FILE__); // Se puede borrar después o dejarlo
