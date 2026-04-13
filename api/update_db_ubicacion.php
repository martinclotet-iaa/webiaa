<?php
require_once __DIR__ . '/conexion.php';

function addColumn($conexion, $table, $column, $definition) {
    if (!$conexion->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->num_rows) {
        if ($conexion->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            echo "Added $column to $table\n";
        } else {
            echo "Error adding $column to $table: " . $conexion->error . "\n";
        }
    } else {
        echo "$column already exists in $table\n";
    }
}

addColumn($conexion, 'alumnos', 'ubicacion', 'INT DEFAULT NULL AFTER id_curso');
addColumn($conexion, 'notas', 'ubicacion', 'INT DEFAULT NULL AFTER id_curso_materia');

echo "DATABASE_UPDATED_UBICACION";
?>
