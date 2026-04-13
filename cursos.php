<?php  
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

 // Control de acceso dinámico basado en menús
 require_once __DIR__ . '/acceso.php';

require_once "conexion.php";

// Detectar si existe la columna id_preceptor en cursos (para compatibilidad)
$hasPreceptorCol = false;
if ($rs = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'")) { $hasPreceptorCol = ($rs->num_rows > 0); }

// Asignar preceptor a curso (AJAX) — solo ADMIN
if (isset($_POST['accion']) && $_POST['accion'] == "asignar_preceptor") {
    if (!isset($_SESSION['perfil_actual']) || intval($_SESSION['perfil_actual']) !== 4) {
        http_response_code(403); echo 'NO_PERM'; exit;
    }
    $id_curso = intval($_POST['id_curso'] ?? 0);
    $id_preceptor = isset($_POST['id_preceptor']) && $_POST['id_preceptor'] !== '' ? intval($_POST['id_preceptor']) : null;
    
    if ($id_curso <= 0) { http_response_code(400); echo 'ERROR'; exit; }
    
    if ($id_preceptor === null) {
        // En este contexto, si no hay preceptor, tal vez se quiere limpiar?
        // Pero tenemos remover_preceptor_especifico para eso.
        echo 'OK'; exit; 
    }

    // Validar que el usuario sea preceptor
    $val = $conexion->prepare("SELECT 1 FROM usuario_perfil WHERE id_usuario=? AND id_perfil=5 LIMIT 1");
    $val->bind_param('i', $id_preceptor);
    $val->execute();
    if (!$val->get_result()->fetch_row()) { http_response_code(400); echo 'ERROR_PERFIL'; exit; }

    // Insertar en tabla puente (idempotente)
    $conexion->query("INSERT IGNORE INTO curso_preceptor (id_curso, id_preceptor) VALUES ($id_curso, $id_preceptor)");
    
    // Sincronizar columna legacy (opcional, para compatibilidad)
    $hasPreCol = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'")->num_rows > 0;
    if ($hasPreCol) {
        $conexion->query("UPDATE cursos SET id_preceptor = $id_preceptor WHERE id_curso = $id_curso");
    }

    echo 'OK';
    exit;
}

// Remover preceptor específico (AJAX)
if (isset($_POST['accion']) && $_POST['accion'] == "remover_preceptor_especifico") {
    $id_curso = intval($_POST['id_curso']);
    $id_preceptor = intval($_POST['id_preceptor']);
    
    $conexion->query("DELETE FROM curso_preceptor WHERE id_curso = $id_curso AND id_preceptor = $id_preceptor");
    
    // Limpiar columna legacy si coincide
    $hasPreCol = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'")->num_rows > 0;
    if ($hasPreCol) {
        $conexion->query("UPDATE cursos SET id_preceptor = NULL WHERE id_curso = $id_curso AND id_preceptor = $id_preceptor");
    }
    
    echo 'OK';
    exit;
}

// Crear curso
if (isset($_POST['accion']) && $_POST['accion'] == "crear") {
    $nombre_curso = trim($_POST['nombre_curso']);
    $ids_preceptores = isset($_POST['ids_preceptores']) && is_array($_POST['ids_preceptores']) ? array_map('intval', $_POST['ids_preceptores']) : [];
    $id_preceptor_legacy = !empty($ids_preceptores) ? $ids_preceptores[0] : null;

    if (!empty($nombre_curso)) {
        if ($hasPreceptorCol) {
            // Insertar con o sin preceptor en tabla cursos (legacy)
            if ($id_preceptor_legacy === null) {
                $stmt = $conexion->prepare("INSERT INTO cursos (nombre_curso, id_preceptor) VALUES (?, NULL)");
                $stmt->bind_param("s", $nombre_curso);
            } else {
                $stmt = $conexion->prepare("INSERT INTO cursos (nombre_curso, id_preceptor) VALUES (?, ?)");
                $stmt->bind_param("si", $nombre_curso, $id_preceptor_legacy);
            }
        } else {
            $stmt = $conexion->prepare("INSERT INTO cursos (nombre_curso) VALUES (?)");
            $stmt->bind_param("s", $nombre_curso);
        }
        
        if ($stmt->execute()) {
            $id_curso_nuevo = $conexion->insert_id;
            if (!empty($ids_preceptores)) {
                // Insertar en tabla puente
                $stmtP = $conexion->prepare("INSERT IGNORE INTO curso_preceptor (id_curso, id_preceptor) VALUES (?, ?)");
                foreach ($ids_preceptores as $pid) {
                    $stmtP->bind_param("ii", $id_curso_nuevo, $pid);
                    $stmtP->execute();
                }
            }
            header("Location: cursos.php?success=created");
        } else {
            header("Location: cursos.php?error=duplicate");
        }
        exit;
    }
}

// Editar curso
if (isset($_POST['accion']) && $_POST['accion'] == "editar") {
    $id_curso = intval($_POST['id_curso']);
    $nombre_curso = trim($_POST['nombre_curso']);
    $ids_preceptores = isset($_POST['ids_preceptores']) && is_array($_POST['ids_preceptores']) ? array_map('intval', $_POST['ids_preceptores']) : [];
    $id_preceptor_legacy = !empty($ids_preceptores) ? $ids_preceptores[0] : null;

    if (!empty($nombre_curso) && $id_curso > 0) {
        $conexion->query("UPDATE cursos SET nombre_curso = '$nombre_curso' WHERE id_curso = $id_curso");
        
        // Limpiar y re-insertar en tabla puente
        $conexion->query("DELETE FROM curso_preceptor WHERE id_curso = $id_curso");
        if (!empty($ids_preceptores)) {
            $stmtP = $conexion->prepare("INSERT IGNORE INTO curso_preceptor (id_curso, id_preceptor) VALUES (?, ?)");
            foreach ($ids_preceptores as $pid) {
                $stmtP->bind_param("ii", $id_curso, $pid);
                $stmtP->execute();
            }
        }

        // Sincronizar columna legacy
        $hasPreCol = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'")->num_rows > 0;
        if ($hasPreCol) {
            $legacyVal = $id_preceptor_legacy !== null ? $id_preceptor_legacy : "NULL";
            $conexion->query("UPDATE cursos SET id_preceptor = $legacyVal WHERE id_curso = $id_curso");
        }
        
        header("Location: cursos.php?success=updated");
        exit;
    }
}

// Obtener alumnos de un curso (AJAX)
if (isset($_GET['obtener_alumnos_curso'])) {
    $id_curso = intval($_GET['obtener_alumnos_curso']);
    $alumnos = $conexion->query("
        SELECT a.id_alumno, u.nombre, u.apellido
        FROM usuarios u
        INNER JOIN alumnos a ON u.id_usuario = a.id_usuario
        WHERE a.id_curso = $id_curso
        ORDER BY u.apellido, u.nombre
    ")->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($alumnos);
    exit;
}

// Obtener alumnos de un curso con su ubicación y estado de asiento fijo (AJAX)
if (isset($_GET['obtener_alumnos_ubicacion'])) {
    $id_curso = intval($_GET['obtener_alumnos_ubicacion']);
    $alumnos = $conexion->query("
        SELECT a.id_alumno, u.nombre, u.apellido, a.ubicacion, c.asiento_fijo
        FROM usuarios u
        INNER JOIN alumnos a ON u.id_usuario = a.id_usuario
        INNER JOIN cursos c ON c.id_curso = a.id_curso
        WHERE a.id_curso = $id_curso
        ORDER BY u.apellido, u.nombre
    ")->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($alumnos);
    exit;
}

// Guardar ubicación global de alumnos y estado de asiento fijo (AJAX)
if (isset($_POST['accion']) && $_POST['accion'] == 'guardar_ubicacion_global') {
    $id_curso = intval($_POST['id_curso'] ?? 0);
    $asiento_fijo = intval($_POST['asiento_fijo'] ?? 0);
    if ($id_curso <= 0) { echo "ERROR"; exit; }

    // 1. Actualizar estado de bloqueo del curso
    $conexion->query("UPDATE cursos SET asiento_fijo = $asiento_fijo WHERE id_curso = $id_curso");

    // 2. Actualizar ubicaciones globales en tabla alumnos
    $data = isset($_POST['ubicaciones']) ? $_POST['ubicaciones'] : []; // [id_alumno => valor]
    foreach ($data as $id_alumno => $valor) {
        $id_alumno = intval($id_alumno);
        $v = $valor === '' ? 'NULL' : intval($valor);
        $conexion->query("UPDATE alumnos SET ubicacion = $v WHERE id_alumno = $id_alumno");
    }

    // 3. SI ESTÁ BLOQUEADO: Sincronizar automáticamente a todas las materias
    if ($asiento_fijo === 1) {
        // Obtener todas las materias del curso
        $cms = $conexion->query("SELECT id_curso_materia FROM curso_materia WHERE id_curso = $id_curso")->fetch_all(MYSQLI_ASSOC);
        // Obtener alumnos y su ubicación global recién guardada
        $alums = $conexion->query("SELECT id_alumno, ubicacion FROM alumnos WHERE id_curso = $id_curso AND ubicacion IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
        
        foreach ($cms as $cm) {
            $id_cm = $cm['id_curso_materia'];
            foreach ($alums as $al) {
                $id_a = $al['id_alumno'];
                $ubi = $al['ubicacion'];
                // Forzar actualización en tabla notas para cada materia
                $conexion->query("INSERT INTO notas (id_alumno, id_curso_materia, ubicacion) 
                                 VALUES ($id_a, $id_cm, $ubi) 
                                 ON DUPLICATE KEY UPDATE ubicacion = $ubi");
            }
        }
    }
    
    echo "OK";
    exit;
}

// Remover alumno de un curso (AJAX)
if (isset($_POST['accion']) && $_POST['accion'] == 'remover_alumno_curso') {
    $id_alumno = intval($_POST['id_alumno']);
    if ($id_alumno <= 0) { ob_clean(); echo "ERROR:id_invalido"; exit; }
    $r = $conexion->query("UPDATE alumnos SET id_curso = NULL WHERE id_alumno = $id_alumno");
    ob_clean();
    if ($r === false) {
        echo "ERROR:" . $conexion->error;
    } else {
        echo "OK";
    }
    exit;
}

// Agregar alumno a un curso (AJAX)
if (isset($_POST['accion']) && $_POST['accion'] == 'agregar_alumno_curso') {
    $id_alumno = intval($_POST['id_alumno']);
    $id_curso = intval($_POST['id_curso']);
    if ($id_alumno <= 0 || $id_curso <= 0) { ob_clean(); echo "ERROR:id_invalido"; exit; }
    $r = $conexion->query("UPDATE alumnos SET id_curso = $id_curso WHERE id_alumno = $id_alumno");
    ob_clean();
    if ($r === false) {
        echo "ERROR:" . $conexion->error;
    } else {
        echo "OK";
    }
    exit;
}

// Buscar alumnos para agregar (AJAX)
if (isset($_GET['buscar_alumnos_disponibles'])) {
    $q = $conexion->real_escape_string($_GET['q'] ?? '');
    $id_curso_actual = intval($_GET['id_curso_actual']);
    // Alumnos que NO están ya en este curso
    $sqlA = "SELECT a.id_alumno, u.nombre, u.apellido, COALESCE(c.nombre_curso, 'Sin curso') as curso_desc 
             FROM usuarios u 
             INNER JOIN alumnos a ON u.id_usuario = a.id_usuario 
             LEFT JOIN cursos c ON a.id_curso = c.id_curso
             WHERE (u.nombre LIKE '%$q%' OR u.apellido LIKE '%$q%' OR u.email LIKE '%$q%')
             AND (a.id_curso != $id_curso_actual OR a.id_curso IS NULL)
             LIMIT 15";
    $res = $conexion->query($sqlA)->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Obtener materias de un curso específico (AJAX)
if (isset($_GET['obtener_materias_curso'])) {
    $id_curso = intval($_GET['obtener_materias_curso']);
    
    $materias = $conexion->query("
        SELECT m.id_materia, m.nombre_materia, m.tipo, cm.id_curso_materia
        FROM materias m
        INNER JOIN curso_materia cm ON m.id_materia = cm.id_materia
        WHERE cm.id_curso = $id_curso
        ORDER BY m.nombre_materia
    ")->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($materias);
    exit;
}

// Obtener materias disponibles para asignar a un curso (AJAX)
if (isset($_GET['obtener_materias_disponibles'])) {
    $id_curso = intval($_GET['obtener_materias_disponibles']);
    
    $materias = $conexion->query("
        SELECT m.id_materia, m.nombre_materia, m.tipo
        FROM materias m
        WHERE m.id_materia NOT IN (
            SELECT cm.id_materia 
            FROM curso_materia cm 
            WHERE cm.id_curso = $id_curso
        )
        ORDER BY m.nombre_materia
    ")->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($materias);
    exit;
}

// Asignar materia a curso
if (isset($_POST['accion']) && $_POST['accion'] == "asignar_materia") {
    $id_materia = intval($_POST['id_materia']);
    $id_curso = intval($_POST['id_curso']);
    
    $conexion->query("INSERT INTO curso_materia (id_curso, id_materia) VALUES ($id_curso, $id_materia)");
    
    echo "OK";
    exit;
}

// Remover materia de curso
if (isset($_POST['accion']) && $_POST['accion'] == "remover_materia") {
    $id_curso_materia = intval($_POST['id_curso_materia']);
    
    // Primero eliminar las relaciones en docente_materia
    $conexion->query("DELETE FROM docente_materia WHERE id_curso_materia = $id_curso_materia");
    
    // Luego eliminar de curso_materia
    $conexion->query("DELETE FROM curso_materia WHERE id_curso_materia = $id_curso_materia");
    
    echo "OK";
    exit;
}

// Eliminar curso
if (isset($_GET['eliminar'])) {
    $id_curso = intval($_GET['eliminar']);
    
    // Verificar si el curso tiene alumnos asignados
    $check = $conexion->query("SELECT COUNT(*) as count FROM alumnos WHERE id_curso = $id_curso")->fetch_assoc();
    
    if ($check['count'] > 0) {
        header("Location: cursos.php?error=has_students");
        exit;
    }
    
    // Eliminar relaciones curso-materia
    $conexion->query("DELETE FROM curso_materia WHERE id_curso = $id_curso");
    
    // Eliminar curso
    $conexion->query("DELETE FROM cursos WHERE id_curso = $id_curso");
    
    header("Location: cursos.php?success=deleted");
    exit;
}

// Filtros de búsqueda
$busqueda = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : "";

// Preceptores disponibles para asignar (id_usuario, apellido, nombre)
$preceptores = [];
if ($hasPreceptorCol) {
    $preceptores = $conexion->query("SELECT u.id_usuario, CONCAT(u.apellido, ', ', u.nombre) AS nom
                                     FROM usuarios u
                                     INNER JOIN usuario_perfil up ON up.id_usuario=u.id_usuario AND up.id_perfil=5
                                     ORDER BY u.apellido, u.nombre")->fetch_all(MYSQLI_ASSOC);
}

// Query para obtener cursos (incluye nombres de preceptores desde tabla puente o columna legacy)
$sql = "SELECT c.*, 
               COUNT(DISTINCT a.id_alumno) as total_alumnos,
               COUNT(DISTINCT cm.id_materia) as total_materias,
               GROUP_CONCAT(DISTINCT CONCAT(upre.id_usuario, ':', upre.apellido, ', ', upre.nombre) ORDER BY upre.apellido, upre.nombre SEPARATOR '|') as preceptores_pares
        FROM cursos c
        LEFT JOIN alumnos a ON c.id_curso = a.id_curso
        LEFT JOIN curso_materia cm ON c.id_curso = cm.id_curso
        LEFT JOIN curso_preceptor cp ON cp.id_curso = c.id_curso
        LEFT JOIN usuarios upre ON upre.id_usuario = cp.id_preceptor
        WHERE 1=1";

if ($busqueda != "") {
    $sql .= " AND c.nombre_curso LIKE '%$busqueda%'";
}

$sql .= " GROUP BY c.id_curso ORDER BY c.nombre_curso";

$cursos = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

// Respuesta AJAX parcial
if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    ob_start(); ?>
    <div class="cursos-grid">
        <?php foreach ($cursos as $curso): ?>
        <div class="curso-card">
            <div class="curso-header">
                <h3><?= htmlspecialchars($curso['nombre_curso']) ?></h3>
                <div style="display:flex; gap: 8px;">
                    <span class="badge" style="background: #007bff; color: white; cursor: pointer;"
                          onclick="abrirModalAlumnos(<?= $curso['id_curso'] ?>, '<?= htmlspecialchars($curso['nombre_curso']) ?>')">
                        <i class="fa fa-users"></i> <?= $curso['total_alumnos'] ?>
                    </span>
                    <span class="badge" style="background: #6f42c1; color: white; cursor: pointer;" 
                          onclick="abrirModalUbicacion(<?= $curso['id_curso'] ?>, '<?= htmlspecialchars($curso['nombre_curso']) ?>')" title="Configurar ubicación global">
                        <i class="fa fa-couch"></i>
                    </span>
                    <span class="badge" style="background: #28a745; color: white; cursor: pointer;"
                          onclick="abrirModalMaterias(<?= $curso['id_curso'] ?>, '<?= htmlspecialchars($curso['nombre_curso']) ?>')">
                        <i class="fa fa-book"></i> <?= $curso['total_materias'] ?>
                    </span>
                </div>
            </div>
            
            <?php if ($hasPreceptorCol): ?>
            <div style="padding: 15px; padding-bottom: 0;">
                <div style="margin-bottom:8px; font-weight:600; color:#444; font-size: 14px;">Preceptores:</div>
                <div id="preceptor-<?= $curso['id_curso'] ?>" style="display: flex; flex-direction: column; gap: 6px; position:relative;">
                    <?php 
                      $pares = array_filter(explode('|', $curso['preceptores_pares'] ?? ''));
                      if (!empty($pares)) {
                        foreach ($pares as $par) {
                          $colonPos = strpos($par, ':');
                          $pid = (int)substr($par, 0, $colonPos);
                          $nombrePre = substr($par, $colonPos + 1);
                          echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa; box-shadow:0 1px 2px rgba(0,0,0,0.05);">';
                          echo '<span style="font-size:13px; color:#333;"><i class="fa fa-user-circle" style="color:#6c757d; margin-right:6px;"></i>'.htmlspecialchars($nombrePre).'</span>';
                          echo '<button type="button" onclick="removerPreceptorEspecifico('.(int)$curso['id_curso'].', '.$pid.', event)" title="Quitar preceptor" style="background:none; border:none; color:#dc3545; cursor:pointer; padding:0; display:flex; align-items:center;"><i class="fa fa-times-circle"></i></button>';
                          echo '</div>';
                        }
                      } else {
                        echo '<div style="color: #6c757d; font-style: italic; font-size: 13px; padding: 4px 0;">Sin asignar</div>';
                      }
                    ?>
                </div>
                <button type="button" onclick="abrirModalPreceptor(<?= $curso['id_curso'] ?>)" class="btn-grid-add" title="Asignar preceptor">
                    <i class="fa-solid fa-plus-circle"></i> Asignar Preceptor
                </button>
            </div>
            <?php endif; ?>
            
            <div class="curso-actions">
                <a href="#" onclick='abrirModalEditar(<?= json_encode($curso) ?>)' class="btn-grid btn-edit">
                    <i class="fa-solid fa-pen-to-square"></i> Editar
                </a>
                <a href="cursos.php?eliminar=<?= $curso['id_curso'] ?>" class="btn-grid btn-delete delete-link">
                    <i class="fa-solid fa-trash"></i> Eliminar
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    echo ob_get_clean();
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Cursos</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="estilos.css?v=<?php echo time(); ?>">
<style>
  /* Modal de confirmación */
  #confirm-dialog { 
    display:none; 
    position:fixed; 
    inset:0; 
    z-index:2000; 
    background:rgba(0,0,0,0.6); 
    align-items:center; 
    justify-content:center; 
  }
  #confirm-dialog .confirm-dialog-content { 
    background:#fff; 
    color:#000; 
    border-radius:8px; 
    width:90%; 
    max-width:420px; 
    padding:20px; 
    box-shadow:0 6px 20px rgba(0,0,0,0.5); 
  }
  .confirm-dialog-content .row { 
    display:flex; 
    gap:12px; 
    align-items:flex-start; 
  }
  .confirm-dialog-icon { 
    color:#ffc107; 
    font-size:22px; 
    margin-top:2px; 
  }
  .confirm-dialog-message { 
    margin:0; 
    color:#000; 
    font-size:16px; 
    line-height:1.4; 
  }
  .confirm-dialog-buttons { 
    display:flex; 
    justify-content:flex-end; 
    gap:10px; 
    margin-top:16px; 
  }
  .confirm-dialog-button { 
    padding:8px 16px; 
    border:none; 
    border-radius:6px; 
    cursor:pointer; 
    font-weight:600; 
  }
  .confirm-dialog-cancel { 
    background:#6c757d; 
    color:#fff; 
  }
  .confirm-dialog-confirm { 
    background:#dc3545; 
    color:#fff; 
  }
  .confirm-dialog-button:hover { 
    opacity:.95; 
  }

  /* Alertas */
  .alert {
    padding: 12px 16px;
    margin: 20px auto;
    width: 90%;
    border-radius: 6px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
  }
  .alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
  }
  .alert i {
    font-size: 16px;
  }

  /* Badges */
  .badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }

  /* Grid Layout (Replaces Table) */
  .cursos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin: 20px auto;
    width: 90%;
  }
  .curso-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .curso-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }
  .curso-header {
    background-color: #E8F5E9;
    padding: 12px 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .curso-header h3 {
    margin: 0;
    font-size: 1.2rem;
    color: #333;
  }
  .curso-actions {
    margin-top: auto;
    padding: 15px;
    border-top: 1px solid #eee;
    display: flex;
    gap: 10px;
    justify-content: center;
    background: #fafafa;
  }
  .btn-grid {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    flex: 1;
    color: white !important;
  }
  .btn-grid:hover {
    opacity: 0.9;
  }
  .btn-edit { background-color: #2196F3; }
  .btn-delete { background-color: #dc3545; }
  .btn-grid i { margin-right: 6px; }
  .btn-grid-add {
    margin-top: 10px;
    width: 100%;
    padding: 8px;
    border: 1px dashed #ccc;
    background: transparent;
    border-radius: 4px;
    color: #555;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }
  .btn-grid-add:hover {
    border-color: #28a745;
    color: #28a745;
    background: #f8fff9;
  }

  /* Modal Materias */
  .materia-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
  }
  .card-header h3 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 18px;
  }
  .materias-lista {
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: 15px;
    width: 100% !important;
    padding: 0 !important;
  }
  .materia-item {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 0 8px !important;
    border: 1px solid #ddd !important;
    border-radius: 4px !important;
    margin: 0 0 3px 0 !important;
    background: white !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    height: 28px !important;
    line-height: 28px !important;
  }
  .materia-nombre {
    font-weight: 400;
    color: #333;
    font-size: 14px;
    flex: 1;
    display: flex !important;
    align-items: center !important;
    line-height: 1 !important;
  }
  .btn-eliminar {
    background: #dc3545 !important;
    color: white !important;
    border: none;
    border-radius: 3px;
    padding: 0 !important;
    margin: 0 !important;
    cursor: pointer;
    font-size: 10px !important;
    width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    position: relative;
    top: -1px;
  }
  .btn-eliminar:hover {
    background: #c82333 !important;
  }
  .btn-agregar-materia {
    background: #28a745;
    color: white;
    border: none;
    border-radius: 6px;
    padding: 10px 20px;
    cursor: pointer;
    font-weight: 600;
    width: 100%;
    margin-top: 15px;
  }
  .btn-agregar-materia:hover {
    background: #218838;
  }
  .select-materia {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
  }

  /* Modal de confirmación */
  .confirmation-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
  }
  .confirmation-content {
    background-color: #fff;
    padding: 24px;
    border: none;
    width: 90%;
    max-width: 450px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    position: relative;
    animation: modalSlideUp 0.3s ease-out;
  }
  @keyframes modalSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }
</style>
</head>
<body>
<?php include "navbar.php"; ?>

<h1>Gestión de Cursos</h1>

<!-- Alertas -->
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
        <i class="fa fa-check-circle"></i>
        <?php if ($_GET['success'] == 'created'): ?>
            Curso creado exitosamente.
        <?php elseif ($_GET['success'] == 'updated'): ?>
            Curso actualizado exitosamente.
        <?php elseif ($_GET['success'] == 'deleted'): ?>
            Curso eliminado exitosamente.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
        <i class="fa fa-exclamation-triangle"></i>
        <?php if ($_GET['error'] == 'duplicate'): ?>
            Ya existe un curso con ese nombre.
        <?php elseif ($_GET['error'] == 'has_students'): ?>
            No se puede eliminar el curso porque tiene alumnos asignados.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div style="width:90%; margin:20px auto; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    
    <!-- Filtros -->
    <form id="formFiltros" method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" 
               placeholder="Buscar curso..." 
               style="padding:8px; border-radius:6px; border:none; width:220px;">
    </form>

    <!-- Botones a la derecha -->
    <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
      <a href="menu.php" style="text-decoration:none;">
        <button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">
          ← Volver al Menú
        </button>
      </a>
      <button onclick="abrirModalCrear()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;">
          ➕ Nuevo Curso
      </button>
    </div>
</div>

<!-- Tabla cursos -->
<div id="tablaWrapper">
  <!-- Se llena por PHP o AJAX -->
</div>

<!-- Modal Crear -->
<div id="modalCrear" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:500px;">
    <span class="cerrar" onclick="cerrarModalCrear()">&times;</span>
    <h3>Crear Nuevo Curso</h3>
    <form method="post">
        <input type="hidden" name="accion" value="crear">
        <div class="form-group">
          <label>Nombre del curso:</label>
          <input type="text" name="nombre_curso" placeholder="Ej: 1A, 2B" required maxlength="10" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; margin-bottom:10px;">
        </div>
        <?php if ($hasPreceptorCol): ?>
          <div class="form-group">
            <label style="display:block; margin-bottom:5px; font-weight:600;">Preceptores (puedes seleccionar varios):</label>
            <select name="ids_preceptores[]" multiple style="width:100%; height:120px; padding:8px; border:1px solid #ddd; border-radius:6px;">
              <?php foreach($preceptores as $p): ?>
                <option value="<?= (int)$p['id_usuario'] ?>"><?= htmlspecialchars($p['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <small style="color: #666;">Mantén presionada la tecla Ctrl (o Cmd) para seleccionar múltiples.</small>
          </div>
        <?php endif; ?>
        <button type="submit" style="margin-top:15px; width:100%;">Crear Curso</button>
    </form>
  </div>
</div>

<?php if ($hasPreceptorCol): ?>
<!-- Modal Asignar Preceptor -->
<div id="modalPreceptor" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:480px;">
    <span class="cerrar" onclick="cerrarModalPreceptor()">&times;</span>
    <h3>Asignar Preceptor al Curso</h3>
    <div class="form-group" style="margin-top:10px;">
      <label>Seleccionar Preceptor:</label>
      <select id="selectPreceptor" class="form-control" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
        <option value="">-- Sin asignar --</option>
        <?php foreach($preceptores as $p): ?>
          <option value="<?= (int)$p['id_usuario'] ?>"><?= htmlspecialchars($p['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" id="cursoIdPreceptor" />
    </div>
    <div class="form-actions" style="margin-top: 16px; display:flex; gap:10px; justify-content:flex-end;">
      <button type="button" class="btn btn-secondary" onclick="cerrarModalPreceptor()">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="asignarPreceptorConfirm()">Guardar</button>
    </div>
  </div>
  
</div>
<?php endif; ?>

<!-- Modal Editar -->
<div id="modalEditar" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:500px;">
    <span class="cerrar" onclick="cerrarModalEditar()">&times;</span>
    <h3>Editar Curso</h3>
    <form method="post">
        <input type="hidden" name="accion" value="editar">
        <input type="hidden" name="id_curso" id="edit_id_curso">
        <div class="form-group">
          <label>Nombre del curso:</label>
          <input type="text" name="nombre_curso" id="edit_nombre_curso" placeholder="Nombre del curso" required maxlength="10" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; margin-bottom:10px;">
        </div>
        <?php if ($hasPreceptorCol): ?>
          <div class="form-group">
            <label style="display:block; margin-bottom:5px; font-weight:600;">Preceptores asignados:</label>
            <select name="ids_preceptores[]" id="edit_ids_preceptores" multiple style="width:100%; height:120px; padding:8px; border:1px solid #ddd; border-radius:6px;">
              <?php foreach($preceptores as $p): ?>
                <option value="<?= (int)$p['id_usuario'] ?>"><?= htmlspecialchars($p['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <small style="color: #666;">Mantén presionada la tecla Ctrl (o Cmd) para seleccionar múltiples.</small>
          </div>
        <?php endif; ?>
        <button type="submit" style="margin-top:15px; width:100%;">Guardar Cambios</button>
    </form>
  </div>
</div>

<!-- Modal Gestionar Materias -->
<div id="modalMaterias" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:600px; width:90%;">
    <span class="cerrar" onclick="cerrarModalMaterias()">&times;</span>
    <h3 id="tituloModalMaterias">Materias del Curso</h3>

    <div class="materia-card">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h3 id="nombreCursoMaterias" style="margin:0;">Curso: </h3>
        <span id="totalMaterias" style="background:#28a745; color:white; padding:2px 10px; border-radius:10px; font-size:13px; font-weight:bold;">0 materias</span>
      </div>
      <div id="listaMaterias" class="materias-lista">
        <!-- Se llena por AJAX -->
      </div>
    </div>

    <div class="materia-card" style="border-top:2px solid #eee; padding-top:15px;">
      <h4 style="margin-bottom:10px; font-size:14px; color:#444;"><i class="fa-solid fa-plus-circle"></i> Agregar materia al curso</h4>
      <select id="selectMateria" class="select-materia">
        <option value="">-- Seleccione una materia para agregar --</option>
      </select>
      <button class="btn-agregar-materia" onclick="agregarMateria()">
        <i class="fa-solid fa-plus"></i> Agregar Materia
      </button>
    </div>

    <div class="form-actions" style="margin-top:20px; display:flex; justify-content:flex-end;">
      <button type="button" class="btn btn-secondary" onclick="cerrarModalMaterias()">Cerrar</button>
    </div>

    <input type="hidden" id="cursoMateriaId">
  </div>
</div>

<!-- Modal Ver Alumnos -->
<div id="modalAlumnos" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:600px; width: 90%;">
    <span class="cerrar" onclick="cerrarModalAlumnos()">&times;</span>
    <h3 id="tituloModalAlumnos">Alumnos del Curso</h3>
    
    <div class="materia-card" style="margin-bottom:15px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3 id="nombreCursoAlumnos" style="margin:0;">Curso: </h3>
        <span id="totalAlumnos" style="background:#007bff; color:white; padding:2px 10px; border-radius:10px; font-size:13px; font-weight:bold;">0 alumnos</span>
      </div>
      <div id="listaAlumnos" style="max-height:300px; overflow-y:auto; margin-top:10px; border:1px solid #eee; border-radius:6px; background:#fafafa;">
        <!-- Se llena por AJAX -->
      </div>
    </div>

    <!-- Sección para agregar alumnos -->
    <div class="materia-card" style="border-top:2px solid #eee; padding-top:15px;">
      <h4 style="margin-bottom:10px; font-size:14px; color:#444;"><i class="fa-solid fa-user-plus"></i> Agregar alumno al curso</h4>
      <div style="position:relative;">
        <input type="text" id="buscarAlumnoNombre" placeholder="Buscar por nombre, apellido o email..." 
               style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"
               oninput="buscarAlumnosParaAgregar(this.value)">
        <div id="resultadosBusquedaAlumnos" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-top:none; border-radius:0 0 6px 6px; box-shadow:0 4px 6px rgba(0,0,0,0.1); z-index:100; max-height:200px; overflow-y:auto;">
          <!-- Resultados AJAX -->
        </div>
      </div>
    </div>

    <div class="form-actions" style="margin-top:20px; display:flex; justify-content:flex-end;">
      <button type="button" class="btn btn-secondary" onclick="cerrarModalAlumnos()">Cerrar</button>
    </div>
  </div>
</div>

<input type="hidden" id="currentIdCursoModal">

<!-- Modal de Confirmación Genérico -->
<div id="confirmationModal" class="confirmation-modal" style="display:none;">
  <div class="confirmation-content" style="max-width:450px;">
    <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 20px;">
      <i id="confirmIcon" class="fas fa-exclamation-triangle" style="color: #ffc107; font-size: 24px; margin-top: 2px;"></i>
      <p id="confirmMessage" style="margin: 0; color: #000; font-size: 16px; line-height: 1.4;"></p>
    </div>
    <div style="display: flex; justify-content: flex-end; gap: 10px;">
      <button type="button" class="btn btn-secondary" onclick="cerrarConfirmacion()" style="padding: 8px 16px;">
        Cancelar
      </button>
      <button type="button" id="confirmOkBtn" style="padding: 8px 16px; border:none; border-radius:4px; cursor:pointer; color:white; font-weight:600; display:flex; align-items:center; gap:8px;">
        <i id="confirmOkIcon" class="fas fa-check"></i> <span id="confirmOkText">Confirmar</span>
      </button>
    </div>
  </div>
</div>

<script>
// Utilidad para mostrar confirmaciones personalizadas de la plataforma
function abrirConfirmacion(opciones) {
    const modal = document.getElementById('confirmationModal');
    const msg = document.getElementById('confirmMessage');
    const btn = document.getElementById('confirmOkBtn');
    const icon = document.getElementById('confirmOkIcon');
    const text = document.getElementById('confirmOkText');
    const mainIcon = document.getElementById('confirmIcon');

    msg.textContent = opciones.mensaje || '¿Estás seguro?';
    text.textContent = opciones.botonTexto || 'Confirmar';
    
    // Configurar estilo del botón
    btn.className = opciones.botonClase || 'btn-danger'; 
    if (opciones.botonClase === 'btn-primary') {
        btn.style.backgroundColor = '#007bff';
    } else if (opciones.botonClase === 'btn-danger') {
        btn.style.backgroundColor = '#dc3545';
    } else if (opciones.botonClase === 'btn-success') {
        btn.style.backgroundColor = '#28a745';
    }

    icon.className = opciones.botonIcono || 'fas fa-check';
    mainIcon.className = opciones.iconoPrincipal || 'fas fa-exclamation-triangle';
    mainIcon.style.color = opciones.colorPrincipal || '#ffc107';

    modal.style.display = 'flex';
    
    btn.onclick = async function() {
        cerrarConfirmacion();
        if (opciones.callback) await opciones.callback();
    };
}

function cerrarConfirmacion() {
    document.getElementById('confirmationModal').style.display = 'none';
}
// Funciones del modal de creación
function abrirModalCrear() { 
    document.getElementById("modalCrear").style.display = "flex"; 
}

function cerrarModalCrear() { 
    document.getElementById("modalCrear").style.display = "none"; 
}

function abrirModalEditar(curso) {
    document.getElementById("modalEditar").style.display = "flex";
    document.getElementById("edit_id_curso").value = curso.id_curso;
    document.getElementById("edit_nombre_curso").value = curso.nombre_curso;
    
    // Configurar múltiple selección de preceptores
    var sel = document.getElementById('edit_ids_preceptores');
    if (sel) {
      // Limpiar selección previa
      for (var i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = false;
      }
      // Obtener IDs desde el atributo data de la tabla o del propio objeto curso
      var pids = (curso.preceptor_ids || '').split(',').map(s => s.trim()).filter(s => s !== '');
      for (var i = 0; i < sel.options.length; i++) {
        if (pids.includes(sel.options[i].value)) {
          sel.options[i].selected = true;
        }
      }
    }
}

function cerrarModalEditar() { 
    document.getElementById("modalEditar").style.display = "none"; 
}

// Funciones del modal de alumnos
async function abrirModalAlumnos(idCurso, nombreCurso) {
    document.getElementById("modalAlumnos").style.display = "flex";
    document.getElementById("currentIdCursoModal").value = idCurso;
    document.getElementById("tituloModalAlumnos").textContent = `Gestionar Alumnos - ${nombreCurso}`;
    document.getElementById("nombreCursoAlumnos").textContent = `Curso: ${nombreCurso}`;
    document.getElementById("buscarAlumnoNombre").value = "";
    document.getElementById("resultadosBusquedaAlumnos").style.display = "none";
    await cargarAlumnosCurso(idCurso);
}

function cerrarModalAlumnos() {
    document.getElementById("modalAlumnos").style.display = "none";
}

async function cargarAlumnosCurso(idCurso) {
    try {
        const response = await fetch(`cursos.php?obtener_alumnos_curso=${idCurso}`);
        const alumnos = await response.json();
        const container = document.getElementById('listaAlumnos');
        const totalSpan = document.getElementById('totalAlumnos');
        
        totalSpan.textContent = `${alumnos.length} alumnos`;
        
        if (alumnos.length === 0) {
            container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No hay alumnos inscritos en este curso.</p>';
            return;
        }
        
        let html = '';
        alumnos.forEach((alumno, idx) => {
            const nom = `${alumno.apellido}, ${alumno.nombre}`;
            html += `
                <div class="materia-item" style="display:flex; justify-content:space-between; align-items:center; padding:8px 15px; border-bottom:1px solid #eee; background:#fff;" 
                     data-id-alumno="${alumno.id_alumno}" data-nombre-alumno="${nom.replace(/"/g, '&quot;')}">
                    <div style="display:flex; align-items:center; flex-grow: 1;">
                        <i class="fa-solid fa-user" style="margin-right:12px; font-size:14px; color:#007bff; width:20px; text-align:center;"></i>
                        <span style="font-weight:500; font-size:14px; color:#333;">${nom}</span>
                    </div>
                    <button type="button" class="btn-quitar-alumno"
                            title="Quitar del curso" 
                            style="background:transparent; border:none; color:#dc3545; cursor:pointer; padding:0; width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:50%; transition:background 0.2s;"
                            onmouseover="this.style.backgroundColor='rgba(220, 53, 69, 0.1)'"
                            onmouseout="this.style.backgroundColor='transparent'">
                        <i class="fa-solid fa-user-minus"></i>
                    </button>
                </div>
            `;
        });
        container.innerHTML = html;
        // Asignar eventos después de renderizar para evitar problemas con comillas
        container.querySelectorAll('.btn-quitar-alumno').forEach(btn => {
            const row = btn.closest('[data-id-alumno]');
            const idAlumno = row.dataset.idAlumno;
            const nombre = row.dataset.nombreAlumno;
            btn.addEventListener('click', () => removerAlumnoCurso(idAlumno, nombre));
        });
    } catch (error) {
        console.error('Error al cargar alumnos:', error);
        document.getElementById('listaAlumnos').innerHTML = '<p style="color: red; text-align: center;">Error al cargar los alumnos.</p>';
    }
}

async function removerAlumnoCurso(idAlumno, nombreCompleto) {
    abrirConfirmacion({
        mensaje: `¿Estás seguro de que deseas quitar a "${nombreCompleto}" de este curso?`,
        botonTexto: 'Quitar Alumno',
        botonClase: 'btn-danger',
        botonIcono: 'fas fa-user-minus',
        callback: async function() {
            try {
                const idCurso = document.getElementById("currentIdCursoModal").value;
                const fd = new FormData();
                fd.append('accion', 'remover_alumno_curso');
                fd.append('id_alumno', idAlumno);
                
                const res = await fetch('cursos.php', { method: 'POST', body: fd });
                const txt = await res.text();
                console.log('Respuesta servidor remover alumno:', txt);
                
                if (txt.trim() === 'OK') {
                    await cargarAlumnosCurso(idCurso);
                    if (typeof actualizarTabla === 'function') actualizarTabla();
                } else {
                    // Mostrar primeras 200 chars del error en consola
                    console.error('Error respuesta:', txt.substring(0, 200));
                    const msg = txt.length > 100 ? txt.substring(0, 100) + '...' : txt;
                    document.getElementById('listaAlumnos').innerHTML += `<p style="color:red;padding:10px;">Error: ${msg}</p>`;
                }
            } catch (err) {
                console.error('Error fetch remover alumno:', err);
            }
        }
    });
}

let timeoutBusqueda = null;
function buscarAlumnosParaAgregar(q) {
    clearTimeout(timeoutBusqueda);
    const container = document.getElementById('resultadosBusquedaAlumnos');
    const idCursoActual = document.getElementById("currentIdCursoModal").value;
    
    if (q.length < 2) {
        container.style.display = "none";
        return;
    }
    
    timeoutBusqueda = setTimeout(async () => {
        try {
            const res = await fetch(`cursos.php?buscar_alumnos_disponibles=1&q=${encodeURIComponent(q)}&id_curso_actual=${idCursoActual}`);
            const data = await res.json();
            
            if (data.length === 0) {
                container.innerHTML = '<div style="padding:10px; color:#888; text-align:center;">No se encontraron alumnos</div>';
            } else {
                let html = '';
                data.forEach(a => {
                    html += `
                        <div style="padding:10px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; cursor:pointer;" 
                             onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='transparent'"
                             onclick="agregarAlumnoCurso(${a.id_alumno}, '${a.apellido}, ${a.nombre}')">
                            <div style="display:flex; flex-direction:column;">
                                <span style="font-weight:500; font-size:13px;">${a.apellido}, ${a.nombre}</span>
                                <span style="font-size:11px; color:#666;">Curso actual: ${a.curso_desc}</span>
                            </div>
                            <i class="fa-solid fa-plus" style="color:#28a745;"></i>
                        </div>
                    `;
                });
                container.innerHTML = html;
            }
            container.style.display = "block";
        } catch (err) {
            console.error(err);
        }
    }, 300);
}

async function agregarAlumnoCurso(idAlumno, nombreCompleto) {
    const idCurso = document.getElementById("currentIdCursoModal").value;
    const nombreCurso = document.getElementById("tituloModalAlumnos").textContent.split(' - ')[1];
    
    abrirConfirmacion({
        mensaje: `¿Deseas agregar a "${nombreCompleto}" al curso "${nombreCurso}"?`,
        botonTexto: 'Agregar Alumno',
        botonClase: 'btn-primary',
        botonIcono: 'fas fa-user-plus',
        iconoPrincipal: 'fas fa-user-plus',
        colorPrincipal: '#007bff',
        callback: async function() {
            try {
                const fd = new FormData();
                fd.append('accion', 'agregar_alumno_curso');
                fd.append('id_alumno', idAlumno);
                fd.append('id_curso', idCurso);
                
                const res = await fetch('cursos.php', { method: 'POST', body: fd });
                const txt = await res.text();
                
                if (txt.trim() === 'OK') {
                    document.getElementById("buscarAlumnoNombre").value = "";
                    document.getElementById("resultadosBusquedaAlumnos").style.display = "none";
                    await cargarAlumnosCurso(idCurso);
                    actualizarTabla(); // Actualizar contador en la tabla principal
                } else {
                    alert('Error al agregar alumno: ' + txt);
                }
            } catch (err) {
                console.error(err);
            }
        }
    });
}

// Funciones del modal de materias
async function abrirModalMaterias(idCurso, nombreCurso) {
    document.getElementById("modalMaterias").style.display = "flex";
    document.getElementById("tituloModalMaterias").textContent = `Materias del Curso ${nombreCurso}`;
    document.getElementById("nombreCursoMaterias").textContent = `Curso: ${nombreCurso}`;
    document.getElementById("cursoMateriaId").value = idCurso;
    
    // Cargar materias del curso y materias disponibles
    await cargarMateriasCurso(idCurso);
    await cargarMateriasDisponibles(idCurso);
}

function cerrarModalMaterias() {
    document.getElementById("modalMaterias").style.display = "none";
}

async function cargarMateriasCurso(idCurso) {
    try {
        const response = await fetch(`cursos.php?obtener_materias_curso=${idCurso}`);
        const materias = await response.json();
        
        const container = document.getElementById('listaMaterias');
        const totalSpan = document.getElementById('totalMaterias');
        totalSpan.textContent = `${materias.length} materia${materias.length !== 1 ? 's' : ''}`;
        
        if (materias.length === 0) {
            container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No hay materias asignadas a este curso.</p>';
            return;
        }
        
        let html = '';
        materias.forEach(materia => {
            html += `
                <div class="materia-item">
                    <span class="materia-nombre">${materia.nombre_materia} <small style="opacity:0.6; margin-left:5px;">(${materia.tipo || 'Programática'})</small></span>
                    <button class="btn-eliminar" onclick="confirmarEliminar(${materia.id_curso_materia}, '${materia.nombre_materia}', ${idCurso})" title="Eliminar materia">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            `;
        });
        
        container.innerHTML = html;
    } catch (error) {
        console.error('Error al cargar materias:', error);
        document.getElementById('listaMaterias').innerHTML = '<p style="color: red; text-align: center;">Error al cargar las materias.</p>';
    }
}

async function cargarMateriasDisponibles(idCurso) {
    try {
        const response = await fetch(`cursos.php?obtener_materias_disponibles=${idCurso}`);
        const materias = await response.json();
        
        const select = document.getElementById('selectMateria');
        select.innerHTML = '<option value="">-- Seleccione una materia para agregar --</option>';
        
        materias.forEach(materia => {
            select.innerHTML += `<option value="${materia.id_materia}">${materia.nombre_materia} (${materia.tipo || 'Programática'})</option>`;
        });
    } catch (error) {
        console.error('Error al cargar materias disponibles:', error);
    }
}

async function agregarMateria() {
    const selectMateria = document.getElementById('selectMateria');
    const idMateria = selectMateria.value;
    const idCurso = document.getElementById('cursoMateriaId').value;
    
    if (!idMateria) {
        alert('Por favor seleccione una materia');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('accion', 'asignar_materia');
        formData.append('id_materia', idMateria);
        formData.append('id_curso', idCurso);
        
        const response = await fetch('cursos.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.text();
        
        if (result === 'OK') {
            // Recargar listas
            await cargarMateriasCurso(idCurso);
            await cargarMateriasDisponibles(idCurso);
            
            // Actualizar tabla principal
            actualizarTabla();
        } else {
            alert('Error al agregar la materia');
        }
    } catch (error) {
        console.error('Error al agregar materia:', error);
        alert('Error al agregar la materia');
    }
}

// Variables para el modal de confirmación
let pendingDeleteId = null;
let pendingCursoId = null;

function confirmarEliminar(idCursoMateria, nombreMateria, idCurso) {
    abrirConfirmacion({
        mensaje: `¿Estás seguro de que deseas desvincular la materia "${nombreMateria}" de este curso?\n\nEsta acción solo eliminará la relación con el curso, no la materia en sí.`,
        botonTexto: 'Eliminar Materia',
        botonClase: 'btn-danger',
        botonIcono: 'fas fa-trash-alt',
        callback: function() {
            eliminarMateria(idCursoMateria, idCurso);
        }
    });
}

function cerrarConfirmacion() {
    document.getElementById('confirmationModal').style.display = 'none';
    pendingDeleteId = null;
    pendingCursoId = null;
}

async function eliminarMateria(idCursoMateria, idCurso) {
    console.log('Eliminando materia:', idCursoMateria, 'del curso:', idCurso);
    
    try {
        const formData = new FormData();
        formData.append('accion', 'remover_materia');
        formData.append('id_curso_materia', idCursoMateria);
        
        console.log('Enviando datos:', {
            accion: 'remover_materia',
            id_curso_materia: idCursoMateria
        });
        
        const response = await fetch('cursos.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.text();
        console.log('Respuesta del servidor:', result);
        
        if (result.trim() === 'OK') {
            console.log('Eliminación exitosa');
            // Cerrar modal de confirmación
            cerrarConfirmacion();
            
            // Recargar listas
            await cargarMateriasCurso(idCurso);
            await cargarMateriasDisponibles(idCurso);
            
            // Actualizar tabla principal
            actualizarTabla();
        } else {
            alert('Error al eliminar la materia: ' + result);
            console.log('Error - Response:', result);
        }
    } catch (error) {
        console.error('Error al eliminar materia:', error);
        alert('Error al eliminar la materia: ' + error.message);
    }
}

// AJAX filtros
const form = document.getElementById('formFiltros');
const inp = form.querySelector('input[name="q"]');
const wrap = document.getElementById('tablaWrapper');

function debounce(fn, delay = 300) {
    let t; 
    return (...args) => { 
        clearTimeout(t); 
        t = setTimeout(() => fn(...args), delay); 
    };
}

async function actualizarTabla() {
    const params = new URLSearchParams(new FormData(form));
    params.append('partial', '1');
    const res = await fetch('cursos.php?' + params.toString(), { cache: 'no-store' });
    wrap.innerHTML = await res.text();
}

// Helper: asignar o quitar preceptor por AJAX y refrescar la grilla
async function asignarPreceptor(idCurso, idPreceptor){
  try{
    const fd = new FormData();
    fd.append('accion','asignar_preceptor');
    fd.append('id_curso', idCurso);
    // si está vacío, mandar vacío para que el backend lo ponga NULL
    fd.append('id_preceptor', idPreceptor);
    const res = await fetch('cursos.php', { method:'POST', body: fd, headers:{ 'X-Requested-With':'XMLHttpRequest' } });
    const txt = await res.text();
    if (!/\bOK\b/i.test(txt)) {
      alert('No se pudo asignar el preceptor. Detalle: ' + txt);
    }
    await actualizarTabla();
  }catch(err){
    console.warn(err);
    alert('Error al asignar preceptor');
  }
}

inp.addEventListener('input', debounce(actualizarTabla, 350));
document.addEventListener("DOMContentLoaded", actualizarTabla);

// Modal de confirmación
const confirmTpl = `
  <div id="confirm-dialog">
    <div class="confirm-dialog-content">
      <div class="row">
        <i class="fa fa-exclamation-triangle confirm-dialog-icon"></i>
        <p class="confirm-dialog-message" id="confirm-dialog-message">¿Está seguro de eliminar este curso?</p>
      </div>
      <div class="confirm-dialog-buttons">
        <button id="confirm-dialog-cancel" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button>
        <button id="confirm-dialog-confirm" class="confirm-dialog-button confirm-dialog-confirm">Eliminar</button>
      </div>
    </div>
  </div>`;
document.body.insertAdjacentHTML('beforeend', confirmTpl);

let pendingDeleteHref = null;
const confirmDialog = document.getElementById('confirm-dialog');
const confirmMsg = document.getElementById('confirm-dialog-message');
const btnCancel = document.getElementById('confirm-dialog-cancel');
const btnOk = document.getElementById('confirm-dialog-confirm');

function openConfirm(href, message) {
    pendingDeleteHref = href;
    confirmMsg.textContent = message || '¿Está seguro de eliminar este curso?';
    confirmDialog.style.display = 'flex';
}

function closeConfirm() { 
    confirmDialog.style.display = 'none'; 
    pendingDeleteHref = null; 
}

btnCancel.addEventListener('click', closeConfirm);
btnOk.addEventListener('click', function() { 
    if (pendingDeleteHref) window.location.href = pendingDeleteHref; 
});
confirmDialog.addEventListener('click', function(e) { 
    if (e.target === confirmDialog) closeConfirm(); 
});

// Delegación: capturar clic en enlaces de eliminar
document.addEventListener('click', function(e) {
    const a = e.target.closest('a.delete-link');
    if (a) {
        e.preventDefault();
        openConfirm(a.getAttribute('href'), '¿Está seguro de eliminar este curso?');
    }
});

// Cerrar modales al hacer clic fuera
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
    }
});
</script>

<?php if ($hasPreceptorCol): ?>
<script>
function abrirModalPreceptor(idCurso){
  var modal = document.getElementById('modalPreceptor');
  if (!modal) return;
  document.getElementById('cursoIdPreceptor').value = idCurso;
  // Pre-seleccionar actual si se ve en la fila
  var box = document.getElementById('preceptor-'+idCurso);
  var sel = document.getElementById('selectPreceptor');
  if (box && sel){
    var currentId = box.getAttribute('data-preceptor-id') || '';
    sel.value = currentId ? String(currentId) : '';
  }
  modal.style.display = 'flex';
}
function cerrarModalPreceptor(){
  var modal = document.getElementById('modalPreceptor');
  if (modal) modal.style.display = 'none';
}
async function asignarPreceptorConfirm(){
  var idCurso = document.getElementById('cursoIdPreceptor').value;
  var idPrec  = document.getElementById('selectPreceptor').value;
  if (!idCurso) { cerrarModalPreceptor(); return; }
  await asignarPreceptor(idCurso, idPrec);
  cerrarModalPreceptor();
}
async function removerPreceptorEspecifico(idCurso, idPreceptor, ev){
  if (ev) ev.stopPropagation();
  abrirConfirmacion({
    mensaje: '¿Desea desvincular este preceptor del curso?',
    botonTexto: 'Desvincular',
    botonClase: 'btn-danger',
    botonIcono: 'fas fa-user-times',
    iconoPrincipal: 'fas fa-exclamation-triangle',
    colorPrincipal: '#ffc107',
    callback: async function() {
      try {
        const fd = new FormData();
        fd.append('accion', 'remover_preceptor_especifico');
        fd.append('id_curso', idCurso);
        fd.append('id_preceptor', idPreceptor);
        const res = await fetch('cursos.php', { method:'POST', body: fd });
        if (res.ok) await actualizarTabla();
      } catch (err) {
        console.error(err);
      }
    }
  });
}
</script>
<?php endif; ?>

<!-- Modal Ubicación Global -->
<div id="modalUbicacion" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:600px; padding:25px; border-radius:15px;">
    <span class="cerrar" onclick="cerrarModalUbicacion()" style="font-size:28px; cursor:pointer;">&times;</span>
    <h3 style="margin-top:0; color:#16682b;"><i class="fa fa-couch"></i> Ubicación en Aula - <span id="ubiCursoNombre"></span></h3>
    
    <div style="background:#f8f9fa; padding:15px; border-radius:8px; border-left:4px solid #6f42c1; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
        <div>
           <i class="fa fa-info-circle"></i> Configuración Global
        </div>
        <div style="display:flex; align-items:center; gap:15px;">
           <button type="button" onclick="limpiarTodasUbi()" style="background:#dc3545; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:11px; cursor:pointer; font-weight:600; display:flex; align-items:center; gap:4px;">
               <i class="fa fa-eraser"></i> LIMPIAR TODOS
           </button>
           <div style="display:flex; align-items:center; gap:8px;">
               <span style="font-size:12px; font-weight:700;" id="lockStatusText">CERRADO</span>
               <label class="switch-lock">
                   <input type="checkbox" id="checkAsientoFijo">
                   <span class="slider-lock"></span>
               </label>
           </div>
        </div>
    </div>
    
    <div id="ubiAlumnosLista" style="max-height:400px; overflow-y:auto; margin-bottom:20px; border:1px solid #eee; border-radius:8px; padding:10px; background:#fff;">
      <!-- Se carga dinámicamente -->
    </div>
    
    <div style="display:flex; gap:12px;">
      <button type="button" onclick="guardarUbicacionGlobal()" style="flex:1; background:#16682b; color:#fff; border:none; padding:15px; border-radius:8px; cursor:pointer; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; font-size:15px;">
        <i class="fa fa-save"></i> GUARDAR CONFIGURACIÓN
      </button>
    </div>
  </div>
</div>

<style>
/* Switch styling for Lock */
.switch-lock { position: relative; display: inline-block; width: 46px; height: 24px; }
.switch-lock input { opacity: 0; width: 0; height: 0; }
.slider-lock { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
.slider-lock:before { position: absolute; content: "🔓"; display:flex; align-items:center; justify-content:center; font-size:12px; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
input:checked + .slider-lock { background-color: #6f42c1; }
input:checked + .slider-lock:before { transform: translateX(22px); content: "🔒"; }
#lockStatusText { color: #666; }
input:checked ~ .lockStatusText { color: #6f42c1 !important; }
</style>

<script>
let currentUbiCursoId = null;

async function abrirModalUbicacion(id, nombre) {
    currentUbiCursoId = id;
    document.getElementById('ubiCursoNombre').textContent = nombre;
    document.getElementById('modalUbicacion').style.display = 'flex';
    document.getElementById('ubiAlumnosLista').innerHTML = '<div style="text-align:center; padding:20px;"><i class="fa fa-spinner fa-spin"></i> Cargando alumnos...</div>';
    
    try {
        const res = await fetch('cursos.php?obtener_alumnos_ubicacion=' + id);
        const alumnos = await res.json();
        const asientoFijo = alumnos.length > 0 ? parseInt(alumnos[0].asiento_fijo) : 0;
        
        document.getElementById('checkAsientoFijo').checked = (asientoFijo === 1);
        const text = document.getElementById('lockStatusText');
        text.textContent = (asientoFijo === 1) ? 'MODO GLOBAL (Bloqueado)' : 'MODO FLEXIBLE (Docentes editan)';
        text.style.color = (asientoFijo === 1) ? '#6f42c1' : '#666';

        if (alumnos.length === 0) {
            document.getElementById('ubiAlumnosLista').innerHTML = '<div style="text-align:center; padding:20px; color:#666;">No hay alumnos en este curso.</div>';
            return;
        }

        let html = '<table style="width:100%; border-collapse:collapse;">';
        html += '<tr style="border-bottom:2px solid #eee; background:#f8f9fa;"><th style="text-align:left; padding:10px; font-size:13px;">Alumno</th><th style="width:100px; text-align:center; padding:10px; font-size:13px;">Posición</th></tr>';
        alumnos.forEach(al => {
            html += `<tr style="border-bottom:1px solid #f2f2f2;">
                <td style="padding:10px; font-size:14px; font-weight:500;">${al.apellido}, ${al.nombre}</td>
                <td style="padding:10px; text-align:center;">
                    <input type="number" class="ubi-input" data-id="${al.id_alumno}" value="${al.ubicacion || ''}" min="1" max="99" 
                           style="width:60px; padding:6px; text-align:center; border:1px solid #cbd5e1; border-radius:6px; font-weight:700; color:#1e293b;">
                </td>
            </tr>`;
        });
        html += '</table>';
        document.getElementById('ubiAlumnosLista').innerHTML = html;
    } catch(e) {
        document.getElementById('ubiAlumnosLista').innerHTML = '<div style="color:red; padding:10px;">Error al cargar datos.</div>';
    }
}

function cerrarModalUbicacion() {
    document.getElementById('modalUbicacion').style.display = 'none';
}

function limpiarTodasUbi() {
    if (!confirm('¿Deseas vaciar todas las ubicaciones de este listado?')) return;
    document.querySelectorAll('.ubi-input').forEach(inp => inp.value = '');
}

async function guardarUbicacionGlobal() {
    const btn = event.currentTarget;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Guardando...';

    const inputs = document.querySelectorAll('.ubi-input');
    const data = new URLSearchParams();
    data.append('accion', 'guardar_ubicacion_global');
    data.append('id_curso', currentUbiCursoId);
    data.append('asiento_fijo', document.getElementById('checkAsientoFijo').checked ? '1' : '0');
    
    inputs.forEach(inp => {
        data.append(`ubicaciones[${inp.dataset.id}]`, inp.value);
    });
    
    try {
        const res = await fetch('cursos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        });
        if (await res.text() === 'OK') {
            alert('Configuración guardada correctamente.');
            cerrarModalUbicacion();
        } else {
            alert('Hubo un error al guardar.');
        }
    } catch(e) { alert('Error de conexión'); }
    finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

async function aplicarUbicacionMaterias() {
    // Función eliminada, ahora se integra en guardarUbicacionGlobal
}
</script>

</body>
</html>

