<?php
// Ensure no output before headers
while (ob_get_level()) {
    ob_end_clean();
}

// Remove any BOM and other output
if (ob_get_level()) ob_end_clean();
ob_start();

session_start();

// Set JSON header first
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(403);
    $respuesta = ['success' => false, 'message' => 'Acceso denegado'];
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

require_once "conexion.php";

// Function to validate and sanitize input
function validarEntrada($dato, $tipo = 'int') {
    $dato = trim($dato);
    switch($tipo) {
        case 'int':
            return filter_var($dato, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false ? (int)$dato : 0;
        case 'string':
            return htmlspecialchars($dato, ENT_QUOTES, 'UTF-8');
        default:
            return null;
    }
}

// Get the action
$accion = isset($_POST['accion']) ? $_POST['accion'] : (isset($_GET['accion']) ? $_GET['accion'] : '');
$respuesta = ['success' => false, 'message' => 'Acción no válida'];

try {
    switch($accion) {
        case 'asignar_materia':
            $id_docente = validarEntrada($_POST['id_docente'] ?? 0);
            $id_curso_materia = validarEntrada($_POST['id_curso_materia'] ?? 0);
            
            if($id_docente > 0 && $id_curso_materia > 0) {
                // Check if the assignment already exists
                $stmt = $conexion->prepare("SELECT 1 FROM docente_materia WHERE id_docente = ? AND id_curso_materia = ?");
                $stmt->bind_param('ii', $id_docente, $id_curso_materia);
                $stmt->execute();
                $existe = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                
                if(!$existe) {
                    // Create the assignment
                    $stmt = $conexion->prepare("INSERT INTO docente_materia (id_docente, id_curso_materia) VALUES (?, ?)");
                    $stmt->bind_param('ii', $id_docente, $id_curso_materia);
                    
                    if(!$stmt->execute()) {
                        throw new Exception("Error al asignar materia: " . $stmt->error);
                    }
                    $stmt->close();
                    
                    // Get course and subject info for the response
                    $stmt = $conexion->prepare("
                        SELECT c.nombre_curso, m.nombre_materia 
                        FROM curso_materia cm
                        INNER JOIN cursos c ON cm.id_curso = c.id_curso
                        INNER JOIN materias m ON cm.id_materia = m.id_materia
                        WHERE cm.id_curso_materia = ?
                    ");
                    $stmt->bind_param('i', $id_curso_materia);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if($result->num_rows > 0) {
                        $info = $result->fetch_assoc();
                        $respuesta = [
                            'success' => true,
                            'message' => 'Materia asignada correctamente',
                            'data' => [
                                'nombre_curso' => $info['nombre_curso'],
                                'nombre_materia' => $info['nombre_materia']
                            ]
                        ];
                    } else {
                        $respuesta = [
                            'success' => true,
                            'message' => 'Materia asignada correctamente (información adicional no disponible)'
                        ];
                    }
                    $stmt->close();
                } else {
                    $respuesta = ['success' => false, 'message' => 'La materia ya estaba asignada a este docente'];
                }
            } else {
                $respuesta = ['success' => false, 'message' => 'Datos de entrada no válidos'];
            }
            break;
            
        case 'obtener_materias':
            $id_docente = validarEntrada($_GET['id_docente'] ?? 0);
            
            if($id_docente > 0) {
                $sql = "
                    SELECT m.nombre_materia, c.nombre_curso 
                    FROM docente_materia dm
                    INNER JOIN curso_materia cm ON dm.id_curso_materia = cm.id_curso_materia
                    INNER JOIN materias m ON cm.id_materia = m.id_materia
                    INNER JOIN cursos c ON cm.id_curso = c.id_curso
                    WHERE dm.id_docente = ?
                    ORDER BY c.nombre_curso, m.nombre_materia
                ";
                
                $stmt = $conexion->prepare($sql);
                $stmt->bind_param('i', $id_docente);
                $stmt->execute();
                $result = $stmt->get_result();
                $materias = [];
                
                while($row = $result->fetch_assoc()) {
                    $materias[] = [
                        'nombre_materia' => $row['nombre_materia'],
                        'nombre_curso' => $row['nombre_curso']
                    ];
                }
                $stmt->close();
                
                // Return the list of subjects
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($materias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            break;
            
        case 'quitar_materia':
            $id_docente = validarEntrada($_POST['id_docente'] ?? 0);
            $id_curso_materia = validarEntrada($_POST['id_curso_materia'] ?? 0);
            
            if($id_docente > 0 && $id_curso_materia > 0) {
                // Start transaction
                $conexion->begin_transaction();
                
                try {
                    // Get info for logging
                    $stmt = $conexion->prepare("
                        SELECT u.nombre, u.apellido, c.nombre_curso, m.nombre_materia 
                        FROM docente_materia dm
                        JOIN docentes d ON dm.id_docente = d.id_docente
                        JOIN usuarios u ON d.id_usuario = u.id_usuario
                        JOIN curso_materia cm ON dm.id_curso_materia = cm.id_curso_materia
                        JOIN cursos c ON cm.id_curso = c.id_curso
                        JOIN materias m ON cm.id_materia = m.id_materia
                        WHERE dm.id_docente = ? 
                        AND dm.id_curso_materia = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('ii', $id_docente, $id_curso_materia);
                    $stmt->execute();
                    $info = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    // Remove the assignment
                    $stmt = $conexion->prepare("DELETE FROM docente_materia WHERE id_docente = ? AND id_curso_materia = ?");
                    $stmt->bind_param('ii', $id_docente, $id_curso_materia);
                    $result = $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    
                    if ($result && $affected > 0) {
                        $conexion->commit();
                        
                        $respuesta = [
                            'success' => true, 
                            'message' => 'Materia quitada correctamente',
                            'data' => [
                                'id_docente' => $id_docente,
                                'id_curso_materia' => $id_curso_materia,
                                'materia' => $info['nombre_materia'] ?? '',
                                'docente' => ($info['nombre'] ?? '') . ' ' . ($info['apellido'] ?? '')
                            ]
                        ];
                    } else {
                        $conexion->rollback();
                        $error = $conexion->error ?: 'No se pudo eliminar la relación';
                        throw new Exception("Error al quitar la materia: $error");
                    }
                } catch (Exception $e) {
                    $conexion->rollback();
                    throw $e;
                }
            } else {
                $respuesta = [
                    'success' => false, 
                    'message' => 'Datos de entrada no válidos',
                    'input' => [
                        'id_docente' => $id_docente,
                        'id_curso_materia' => $id_curso_materia
                    ]
                ];
            }
            break;
            
        default:
            $respuesta = ['success' => false, 'message' => 'Acción no reconocida'];
    }
} catch (Exception $e) {
    error_log('Error en docentes_ajax.php: ' . $e->getMessage());
    http_response_code(500);
    $respuesta = [
        'success' => false, 
        'message' => 'Error en el servidor: ' . $e->getMessage(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ];
}

// Clear all output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Output the JSON response
$json = json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Verify JSON encoding was successful
if ($json === false) {
    $json = json_encode([
        'success' => false,
        'message' => 'Error al codificar la respuesta JSON',
        'error' => json_last_error_msg()
    ]);
}

echo $json;

// Make sure no code executes after this
exit();
