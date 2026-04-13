<?php
require 'conexion.php';

// 1. Limpiar duplicados en preceptores
$conexion->query("CREATE TABLE tmp_preceptores SELECT id_usuario, MAX(id_preceptor) as max_id FROM preceptores GROUP BY id_usuario");
$conexion->query("DELETE FROM preceptores WHERE id_preceptor NOT IN (SELECT max_id FROM tmp_preceptores)");
$conexion->query("DROP TABLE tmp_preceptores");

// 2. Agregar restricción UNIQUE
$conexion->query("ALTER TABLE preceptores ADD UNIQUE (id_usuario)");

echo "Limpieza completada y restricción UNIQUE agregada.\n";
?>
