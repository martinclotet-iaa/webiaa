<?php
require_once 'conexion.php';
$res = $conexion->query("SHOW TABLES");
$data = [];
while ($row = $res->fetch_row()) {
    $data[] = $row[0];
}
echo json_encode($data);
