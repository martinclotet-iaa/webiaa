<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$idUsuario = intval($_SESSION['id_usuario']);
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1; // 1=alumno, 3=docente, 4=admin

function checkInt($v){ return isset($v) && is_numeric($v) ? intval($v) : 0; }

$idCursoMateria = checkInt($_POST['id'] ?? $_GET['id'] ?? 0);
if ($idCursoMateria <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta id de curso_materia']);
    exit;
}

// Permisos: docente asignado o admin pueden editar; docentes y alumnos pueden listar (alumno solo del curso en el que está)
$puedeEditar = false;
if ($perfilActual === 4) {
    $puedeEditar = true;
} elseif ($perfilActual === 3) {
    $tieneAsignacion = $conexion->query("SELECT 1
        FROM docentes d
        INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
        INNER JOIN docente_materia dm ON dm.id_docente = d.id_docente
        WHERE u.id_usuario = $idUsuario AND dm.id_curso_materia = $idCursoMateria
        LIMIT 1")->fetch_row();
    $puedeEditar = (bool)$tieneAsignacion;
} elseif ($perfilActual === 5) {
    // Preceptor: permitir edición en asistencias
    $puedeEditar = true;
}

// Listar todas las fechas/sesiones sin depender de una fecha concreta
if ($action === 'listar_todas') {
    // Sesiones con clave real y sesiones legadas (sin sesion) agrupadas por fecha con clave sintética
    $sqlSes = "(
                SELECT sesion, fecha, MIN(created_at) AS created_at
                FROM asistencias
                WHERE id_curso_materia=? AND sesion IS NOT NULL
                GROUP BY sesion, fecha
              )
              UNION ALL
              (
                SELECT CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d')) AS sesion, fecha, MIN(created_at) AS created_at
                FROM asistencias
                WHERE id_curso_materia=? AND sesion IS NULL
                GROUP BY fecha
              )
              ORDER BY fecha ASC, created_at ASC";
    $stmtSes = $conexion->prepare($sqlSes);
    $stmtSes->bind_param('ii', $idCursoMateria, $idCursoMateria);
    $stmtSes->execute();
    $rsSes = $stmtSes->get_result();
    $sesiones = [];
    while($row = $rsSes->fetch_assoc()){
        $sesiones[] = $row;
    }

    // Estados: mapear los legados a la clave LEGACY-YYYY-MM-DD
    $stmtData = $conexion->prepare("SELECT id_alumno, COALESCE(sesion, CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d'))) AS sesion, estado
                                    FROM asistencias
                                    WHERE id_curso_materia=?");
    $stmtData->bind_param('i', $idCursoMateria);
    $stmtData->execute();
    $rs = $stmtData->get_result();
    $data = [];
    while($r = $rs->fetch_assoc()){
        $s = $r['sesion'];
        $a = (int)$r['id_alumno'];
        $data[$s] = $data[$s] ?? [];
        $data[$s][$a] = $r['estado'];
    }
    echo json_encode(['sesiones'=>$sesiones, 'data'=>$data]);
    exit;
}

if ($action === 'listar') {
    // Compat: lista sin sesiones (no recomendado). Si se envía 'sesion', filtra esa sesión.
    $fecha = isset($_POST['fecha']) ? $_POST['fecha'] : ($_GET['fecha'] ?? '');
    $sesion = isset($_POST['sesion']) ? $_POST['sesion'] : ($_GET['sesion'] ?? null);
    if (!$fecha) { http_response_code(400); echo json_encode(['error'=>'Falta fecha']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { http_response_code(400); echo json_encode(['error'=>'Fecha inválida']); exit; }
    if ($sesion) {
        $stmt = $conexion->prepare("SELECT id_alumno, estado FROM asistencias WHERE id_curso_materia=? AND fecha=? AND sesion=?");
        $stmt->bind_param('iss', $idCursoMateria, $fecha, $sesion);
    } else {
        $stmt = $conexion->prepare("SELECT id_alumno, estado FROM asistencias WHERE id_curso_materia=? AND fecha=?");
        $stmt->bind_param('is', $idCursoMateria, $fecha);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while($r = $res->fetch_assoc()) { $rows[] = $r; }
    echo json_encode($rows);
    exit;
}

if ($action === 'listar_todo') {
    $fecha = isset($_POST['fecha']) ? $_POST['fecha'] : ($_GET['fecha'] ?? '');
    if (!$fecha) { http_response_code(400); echo json_encode(['error'=>'Falta fecha']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { http_response_code(400); echo json_encode(['error'=>'Fecha inválida']); exit; }

    // Sesiones existentes (incluye legadas sin 'sesion' generando clave LEGACY-YYYY-MM-DD)
    $stmtSes = $conexion->prepare("(
                                     SELECT sesion, fecha, MIN(created_at) AS created_at
                                     FROM asistencias
                                     WHERE id_curso_materia=? AND fecha=? AND sesion IS NOT NULL
                                     GROUP BY sesion, fecha
                                   )
                                   UNION ALL
                                   (
                                     SELECT CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d')) AS sesion, fecha, MIN(created_at) AS created_at
                                     FROM asistencias
                                     WHERE id_curso_materia=? AND fecha=? AND sesion IS NULL
                                     GROUP BY fecha
                                   )
                                   ORDER BY created_at ASC");
    $stmtSes->bind_param('isis', $idCursoMateria, $fecha, $idCursoMateria, $fecha);
    $stmtSes->execute();
    $rsSes = $stmtSes->get_result();
    $sesiones = [];
    while($row = $rsSes->fetch_assoc()){
        $sesiones[] = $row;
    }

    // Estados por alumno y sesión
    $stmtData = $conexion->prepare("SELECT id_alumno,
                                           COALESCE(sesion, CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d'))) AS sesion,
                                           estado
                                    FROM asistencias
                                    WHERE id_curso_materia=? AND fecha=?");
    $stmtData->bind_param('is', $idCursoMateria, $fecha);
    $stmtData->execute();
    $rs = $stmtData->get_result();
    $data = [];
    while($r = $rs->fetch_assoc()){
        $s = $r['sesion'];
        $a = (int)$r['id_alumno'];
        $data[$s] = $data[$s] ?? [];
        $data[$s][$a] = $r['estado'];
    }
    echo json_encode(['sesiones'=>$sesiones, 'data'=>$data]);
    exit;
}

if ($action === 'guardar') {
    if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos para editar']); exit; }

    $idAlumno = checkInt($_POST['alumno'] ?? 0);
    $fecha = isset($_POST['fecha']) ? $_POST['fecha'] : '';
    $estado = isset($_POST['estado']) ? $_POST['estado'] : '';
    $sesion = isset($_POST['sesion']) ? $_POST['sesion'] : '';

    if ($idAlumno <= 0 || !$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        http_response_code(400); echo json_encode(['error'=>'Datos inválidos']); exit;
    }
    if ($estado !== 'P' && $estado !== 'A') {
        http_response_code(400); echo json_encode(['error'=>'Estado inválido']); exit;
    }
    if ($sesion === '') { http_response_code(400); echo json_encode(['error'=>'Falta sesion']); exit; }

    // Validar alumno: pertenece al curso del curso_materia O tiene asignación en 'notas' para esa materia
    $stmtChk = $conexion->query(
        "SELECT 1
         FROM alumnos a
         LEFT JOIN curso_materia cm ON cm.id_curso_materia = {$idCursoMateria} AND cm.id_curso = a.id_curso
         LEFT JOIN notas n ON n.id_alumno = a.id_alumno AND n.id_curso_materia = {$idCursoMateria}
         WHERE a.id_alumno = {$idAlumno}
           AND (cm.id_curso_materia IS NOT NULL OR n.id_nota IS NOT NULL)
         LIMIT 1"
    );
    if (!$stmtChk->fetch_row()) {
        http_response_code(400); echo json_encode(['error'=>'El alumno no pertenece a ese curso']); exit;
    }

    // Upsert asistencia
    $stmtIns = $conexion->prepare("INSERT INTO asistencias (id_alumno, id_curso_materia, fecha, sesion, estado) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE estado=VALUES(estado)");
    $stmtIns->bind_param('iisss', $idAlumno, $idCursoMateria, $fecha, $sesion, $estado);
    $ok = $stmtIns->execute();
    if (!$ok) { http_response_code(500); echo json_encode(['error'=>'No se pudo guardar']); exit; }

    echo json_encode(['ok'=>true]);
    exit;
}
// Cambiar la fecha asociada a una sesión (editar fecha en encabezado)
if ($action === 'cambiar_fecha_sesion') {
    if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos para editar']); exit; }
    $nuevaFecha = isset($_POST['nueva_fecha']) ? $_POST['nueva_fecha'] : '';
    $oldFecha = isset($_POST['old_fecha']) ? $_POST['old_fecha'] : '';

    if ($sesion === '' || !$nuevaFecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nuevaFecha)) {
        http_response_code(400); echo json_encode(['error'=>'Datos inválidos']); exit;
    }

    // Modo LEGACY: sesiones sin clave, agrupar por fecha actual
    $isLegacy = (strpos($sesion, 'LEGACY-') === 0);
    if ($isLegacy) {
        // Derivar fecha vieja desde la clave si no fue provista explícitamente
        if (!$oldFecha) {
            $oldFecha = substr($sesion, 7); // LEGACY-YYYY-MM-DD
        }
        if (!$oldFecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldFecha)) {
            http_response_code(400); echo json_encode(['error'=>'Fecha original inválida']); exit;
        }

        $stmt = $conexion->prepare("UPDATE asistencias SET fecha=? WHERE id_curso_materia=? AND sesion IS NULL AND fecha=?");
        $stmt->bind_param('sis', $nuevaFecha, $idCursoMateria, $oldFecha);
    } else {
        // Sesión con clave (UUID). Actualizar todas las filas de esa sesión.
        $stmt = $conexion->prepare("UPDATE asistencias SET fecha=? WHERE id_curso_materia=? AND sesion=?");
        $stmt->bind_param('sis', $nuevaFecha, $idCursoMateria, $sesion);
    }

    try {
        $ok = $stmt->execute();
        if (!$ok) {
            // Posible colisión de UNIQUE KEY (1062) si ya existen filas en la fecha destino
            $errno = $stmt->errno;
            $msg = $stmt->error;
            http_response_code(500);
            echo json_encode(['error'=>'No se pudo actualizar la fecha', 'errno'=>$errno, 'detail'=>$msg]);
            exit;
        }
        echo json_encode(['ok'=>true, 'affected'=>$stmt->affected_rows]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error'=>'Excepción al actualizar', 'detail'=>$e->getMessage()]);
    }
    exit;
}

// Guardar ubicación del alumno en el aula (específico de la materia)
if ($action === 'guardar_ubicacion') {
    if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos']); exit; }
    
    // Verificar si el curso tiene la ubicación bloqueada (asiento_fijo)
    $stmtLock = $conexion->prepare("SELECT c.asiento_fijo FROM curso_materia cm JOIN cursos c ON cm.id_curso = c.id_curso WHERE cm.id_curso_materia = ?");
    $stmtLock->bind_param("i", $idCursoMateria);
    $stmtLock->execute();
    $rowLock = $stmtLock->get_result()->fetch_assoc();
    $isLocked = ($rowLock && intval($rowLock['asiento_fijo']) === 1);

    // Si está bloqueado, solo administradores (4) pueden cambiarlo (puedes añadir 5 si preceptor debe poder)
    if ($isLocked && $perfilActual !== 4) {
        http_response_code(403); 
        echo json_encode(['error'=>'La ubicación está bloqueada por administración y no puede modificarse.']); 
        exit; 
    }

    $idAlumno = checkInt($_POST['id_alumno'] ?? 0);
    $valor = $_POST['valor'] === '' ? 'NULL' : intval($_POST['valor']);
    if ($idAlumno <= 0) { http_response_code(400); echo json_encode(['error'=>'Falta id_alumno']); exit; }
    
    // UPSERT en la tabla notas
    $sql = "INSERT INTO notas (id_alumno, id_curso_materia, ubicacion) 
            VALUES ($idAlumno, $idCursoMateria, $valor)
            ON DUPLICATE KEY UPDATE ubicacion = $valor";
    if ($conexion->query($sql)) {
        echo json_encode(['ok'=>true]);
    } else {
        http_response_code(500); echo json_encode(['error'=>$conexion->error]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
?>
