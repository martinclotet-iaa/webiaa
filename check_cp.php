<?php
require_once 'conexion.php';
$res = $conexion->query("DESCRIBE curso_preceptor");
$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
