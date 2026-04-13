<?php
session_start();
require_once "conexion.php";

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(['ok' => false, 'error' => 'No session']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'get_docentes_full') {
    $id_cm = isset($_GET['id_cm']) ? intval($_GET['id_cm']) : 0;
    if ($id_cm <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
        exit;
    }

    $query = "
        SELECT dm.id_docente, u.nombre, u.apellido
        FROM docente_materia dm
        INNER JOIN docentes d ON dm.id_docente = d.id_docente
        INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
        WHERE dm.id_curso_materia = $id_cm
        ORDER BY dm.id_docente_materia ASC
    ";
    
    $result = $conexion->query($query);
    $docentes = [];
    while ($row = $result->fetch_assoc()) {
        $docentes[] = [
            'id_docente' => $row['id_docente'],
            'nombre' => $row['apellido'] . ', ' . $row['nombre']
        ];
    }

    echo json_encode(['ok' => true, 'docentes' => $docentes]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action']);
?>
