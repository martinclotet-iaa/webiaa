<?php
require_once "conexion.php";
try {
    $conexion->query("ALTER TABLE alumnos MODIFY id_curso INT(11) NULL");
    $conexion->query("UPDATE alumnos SET id_curso = NULL WHERE id_curso = 0");
    echo "SCHEMA_UPDATED";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
unlink(__FILE__);
