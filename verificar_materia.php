<?php
session_start();
require_once 'conexion.php';

header('Content-Type: application/json');

// Verificar que la solicitud sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Obtener y validar los datos de entrada
$docenteId = filter_input(INPUT_POST, 'docente_id', FILTER_VALIDATE_INT);
$cursoMateriaId = filter_input(INPUT_POST, 'curso_materia_id', FILTER_VALIDATE_INT);

if (!$docenteId || !$cursoMateriaId) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos de entrada inválidos']);
    exit;
}

try {
    // Verificar si la relación ya existe
    $stmt = $conexion->prepare("
        SELECT COUNT(*) as existe 
        FROM docente_materia 
        WHERE id_docente = ? AND id_curso_materia = ?
    ");
    $stmt->bind_param("ii", $docenteId, $cursoMateriaId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    echo json_encode(['existe' => $row['existe'] > 0]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al verificar la materia']);
}

$conexion->close();
?>
