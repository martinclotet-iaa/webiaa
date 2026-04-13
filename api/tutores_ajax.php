<?php
session_start();

error_log('Session data: ' . print_r($_SESSION, true));

if (!isset($_SESSION['id_usuario'])) {
    error_log('Acceso denegado - No hay sesión de usuario');
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Sesión no válida o expirada']));
}

require_once "conexion.php";

$accion = isset($_GET['accion']) ? $_GET['accion'] : '';

switch($accion) {
    case 'asignar_alumno':
        $id_tutor = isset($_GET['id_tutor']) ? intval($_GET['id_tutor']) : 0;
        $id_alumno = isset($_GET['id_alumno']) ? intval($_GET['id_alumno']) : 0;
        
        if($id_tutor > 0 && $id_alumno > 0) {
            // Verificar si ya existe la relación
            $existe = $conexion->query("SELECT 1 FROM alumno_tutor WHERE id_tutor=$id_tutor AND id_alumno=$id_alumno")->num_rows > 0;
            
            if(!$existe) {
                $conexion->query("INSERT INTO alumno_tutor (id_tutor, id_alumno) VALUES ($id_tutor, $id_alumno)");
                if($conexion->error) {
                    echo "Error al asignar alumno: " . $conexion->error;
                } else {
                    echo "OK";
                }
            } else {
                echo "OK"; // Ya existe la relación
            }
        } else {
            echo "Parámetros inválidos";
        }
        break;
        
    case 'remover_alumno':
        // Log all request headers and data
        error_log('=== REMOVER_ALUMNO REQUEST START ===');
        error_log('GET params: ' . print_r($_GET, true));
        error_log('Headers: ' . print_r(getallheaders(), true));
        
        $id_tutor = isset($_GET['id_tutor']) ? intval($_GET['id_tutor']) : 0;
        $id_alumno = isset($_GET['id_alumno']) ? intval($_GET['id_alumno']) : 0;
        
        error_log("Parsed values - id_tutor: $id_tutor, id_alumno: $id_alumno");
        
        if($id_tutor > 0 && $id_alumno > 0) {
            // Check if the record exists
            $check_sql = "SELECT 1 FROM alumno_tutor WHERE id_tutor = $id_tutor AND id_alumno = $id_alumno";
            error_log("Checking if record exists with SQL: $check_sql");
            
            $check_result = $conexion->query($check_sql);
            
            if (!$check_result) {
                $error = "Error checking record: " . $conexion->error;
                error_log($error);
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'error' => $error]);
                error_log('=== REMOVER_ALUMNO ERROR: ' . $error . ' ===');
                break;
            }
            
            if ($check_result->num_rows === 0) {
                $error = "No record found to delete";
                error_log($error);
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'error' => 'No se encontró la relación a eliminar']);
                error_log('=== REMOVER_ALUMNO NOT FOUND ===');
                break;
            }
            
            // Delete the record
            $sql = "DELETE FROM alumno_tutor WHERE id_tutor = $id_tutor AND id_alumno = $id_alumno";
            error_log("Executing SQL: $sql");
            
            if ($conexion->query($sql) === TRUE) {
                $affected_rows = $conexion->affected_rows;
                error_log("Record deleted successfully. Affected rows: $affected_rows");
                
                // Return success response
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success', 
                    'affected_rows' => $affected_rows,
                    'message' => 'Alumno quitado correctamente'
                ]);
                error_log('=== REMOVER_ALUMNO SUCCESS ===');
            } else {
                $error = "Error deleting record: " . $conexion->error;
                error_log($error);
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'error' => $error]);
                error_log('=== REMOVER_ALUMNO ERROR: ' . $error . ' ===');
            }
        } else {
            $error = "Parámetros inválidos - id_tutor: $id_tutor, id_alumno: $id_alumno";
            error_log($error);
            echo $error;
        }
        break;
        
    default:
        echo "Acción no válida";
        break;
}
?>
