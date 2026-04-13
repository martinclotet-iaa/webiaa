<?php
require_once __DIR__ . '/conexion.php';

if (!$conexion->query("SHOW COLUMNS FROM `cursos` LIKE 'asiento_fijo'")->num_rows) {
    if ($conexion->query("ALTER TABLE `cursos` ADD COLUMN `asiento_fijo` TINYINT(1) DEFAULT 0")) {
        echo "Added asiento_fijo to cursos\n";
    } else {
        echo "Error adding column: " . $conexion->error . "\n";
    }
} else {
    echo "asiento_fijo already exists\n";
}
echo "DATABASE_UPDATED_LOCK";
?>
