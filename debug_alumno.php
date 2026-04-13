<?php
require_once "conexion.php";

header('Content-Type: text/plain; charset=UTF-8');

// Ver estructura actual
$r = $conexion->query("SHOW COLUMNS FROM alumnos LIKE 'id_curso'");
$col = $r->fetch_assoc();
echo "Columna id_curso: " . json_encode($col) . "\n\n";

// Ver foreign keys
$r2 = $conexion->query("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
FROM information_schema.KEY_COLUMN_USAGE 
WHERE TABLE_NAME = 'alumnos' AND COLUMN_NAME = 'id_curso' AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_SCHEMA = DATABASE()");
echo "FK en id_curso:\n";
while ($row = $r2->fetch_assoc()) {
    echo "  " . json_encode($row) . "\n";
}
echo "\n";

// Intentar hacer NULL
$r3 = $conexion->query("ALTER TABLE alumnos MODIFY id_curso INT(11) NULL");
if ($r3 === false) {
    echo "ALTER error: " . $conexion->error . "\n";
} else {
    echo "ALTER OK\n";
}

// Test NULL update con un id real
$first = $conexion->query("SELECT id_alumno FROM alumnos LIMIT 1")->fetch_assoc();
if ($first) {
    $id = $first['id_alumno'];
    $r4 = $conexion->query("UPDATE alumnos SET id_curso = NULL WHERE id_alumno = $id");
    if ($r4 === false) {
        echo "UPDATE NULL error: " . $conexion->error . "\n";
    } else {
        echo "UPDATE NULL OK (affected: " . $conexion->affected_rows . ")\n";
        // Restaurar
        $conexion->query("UPDATE alumnos SET id_curso = 1 WHERE id_alumno = $id");
    }
}
