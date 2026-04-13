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
}

// Crear una nueva sesión: inserta filas por alumno con nota NULL y detalle opcional
if ($action === 'crear_sesion') {
    if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos para editar']); exit; }
    $fecha = isset($_POST['fecha']) ? $_POST['fecha'] : '';
    $sesion = isset($_POST['sesion']) ? $_POST['sesion'] : '';
    $detalle = isset($_POST['detalle']) ? $_POST['detalle'] : null;
    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { http_response_code(400); echo json_encode(['error'=>'Fecha inválida']); exit; }
    if ($sesion === '') { http_response_code(400); echo json_encode(['error'=>'Falta sesion']); exit; }

    // Obtener alumnos del curso asociado a id_curso_materia
    $sqlAl = "SELECT a.id_alumno
              FROM alumnos a
              INNER JOIN curso_materia cm ON cm.id_curso = a.id_curso
              WHERE cm.id_curso_materia = ?";
    $stmtAl = $conexion->prepare($sqlAl);
    $stmtAl->bind_param('i', $idCursoMateria);
    $stmtAl->execute();
    $rs = $stmtAl->get_result();
    $ids = [];
    while($r = $rs->fetch_assoc()){ $ids[] = intval($r['id_alumno']); }

    if (empty($ids)) { echo json_encode(['ok'=>true, 'inserted'=>0]); exit; }

    // Insertar por alumno con UPSERT (nota NULL)
    $sqlIns = "INSERT INTO notasparciales (id_alumno, id_curso_materia, fecha, sesion, nota, detalle)
               VALUES (?,?,?,?,NULL,?)
               ON DUPLICATE KEY UPDATE detalle=VALUES(detalle)";
    $stmtIns = $conexion->prepare($sqlIns);
    $inserted = 0;
    foreach ($ids as $idAl) {
        $stmtIns->bind_param('iisss', $idAl, $idCursoMateria, $fecha, $sesion, $detalle);
        if ($stmtIns->execute()) { $inserted += ($stmtIns->affected_rows > 0 ? 1 : 0); }
    }
    echo json_encode(['ok'=>true, 'inserted'=>$inserted]);
    exit;
}

// Listar todas las fechas/sesiones sin depender de una fecha concreta
if ($action === 'listar_todas') {
    // Sesiones con clave real y sesiones legadas (sin sesion) agrupadas por fecha con clave sintética
    $sqlSes = "(
                SELECT sesion, fecha, MIN(created_at) AS created_at, MIN(detalle) AS detalle
                FROM notasparciales
                WHERE id_curso_materia=? AND sesion IS NOT NULL
                GROUP BY sesion, fecha
              )
              UNION ALL
              (
                SELECT CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d')) AS sesion, fecha, MIN(created_at) AS created_at, MIN(detalle) AS detalle
                FROM notasparciales
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

    // Datos de notas: mapear los legados a la clave LEGACY-YYYY-MM-DD
    $stmtData = $conexion->prepare("SELECT id_alumno, COALESCE(sesion, CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d'))) AS sesion, nota
                                    FROM notasparciales
                                    WHERE id_curso_materia=?");
    $stmtData->bind_param('i', $idCursoMateria);
    $stmtData->execute();
    $rs = $stmtData->get_result();
    $data = [];
    while($r = $rs->fetch_assoc()){
        $s = $r['sesion'];
        $a = (int)$r['id_alumno'];
        $data[$s] = $data[$s] ?? [];
        $data[$s][$a] = is_null($r['nota']) ? null : (int)$r['nota'];
    }
    echo json_encode(['sesiones'=>$sesiones, 'data'=>$data]);
    exit;
}

if ($action === 'listar') {
    $fecha = isset($_POST['fecha']) ? $_POST['fecha'] : ($_GET['fecha'] ?? '');
    $sesion = isset($_POST['sesion']) ? $_POST['sesion'] : ($_GET['sesion'] ?? null);
    if (!$fecha) { http_response_code(400); echo json_encode(['error'=>'Falta fecha']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { http_response_code(400); echo json_encode(['error'=>'Fecha inválida']); exit; }
    if ($sesion) {
        $stmt = $conexion->prepare("SELECT id_alumno, nota FROM notasparciales WHERE id_curso_materia=? AND fecha=? AND sesion=?");
        $stmt->bind_param('iss', $idCursoMateria, $fecha, $sesion);
    } else {
        $stmt = $conexion->prepare("SELECT id_alumno, nota FROM notasparciales WHERE id_curso_materia=? AND fecha=?");
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

    $stmtSes = $conexion->prepare("(
                                     SELECT sesion, fecha, MIN(created_at) AS created_at, MIN(detalle) AS detalle
                                     FROM notasparciales
                                     WHERE id_curso_materia=? AND fecha=? AND sesion IS NOT NULL
                                     GROUP BY sesion, fecha
                                   )
                                   UNION ALL
                                   (
                                     SELECT CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d')) AS sesion, fecha, MIN(created_at) AS created_at, MIN(detalle) AS detalle
                                     FROM notasparciales
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

    $stmtData = $conexion->prepare("SELECT id_alumno,
                                           COALESCE(sesion, CONCAT('LEGACY-', DATE_FORMAT(fecha, '%Y-%m-%d'))) AS sesion,
                                           nota
                                    FROM notasparciales
                                    WHERE id_curso_materia=? AND fecha=?");
    $stmtData->bind_param('is', $idCursoMateria, $fecha);
    $stmtData->execute();
    $rs = $stmtData->get_result();
    $data = [];
    while($r = $rs->fetch_assoc()){
        $s = $r['sesion'];
        $a = (int)$r['id_alumno'];
        $data[$s] = $data[$s] ?? [];
        $data[$s][$a] = is_null($r['nota']) ? null : (int)$r['nota'];
    }
    echo json_encode(['sesiones'=>$sesiones, 'data'=>$data]);
    exit;
}

if ($action === 'guardar') {
    if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos para editar']); exit; }

    $idAlumno = checkInt($_POST['alumno'] ?? 0);
    $fecha = isset($_POST['fecha']) ? $_POST['fecha'] : '';
    $nota = isset($_POST['nota']) && $_POST['nota'] !== '' ? intval($_POST['nota']) : null;
    $sesion = isset($_POST['sesion']) ? $_POST['sesion'] : '';

    if ($idAlumno <= 0 || !$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        http_response_code(400); echo json_encode(['error'=>'Datos inválidos']); exit;
    }
    if ($sesion === '') { http_response_code(400); echo json_encode(['error'=>'Falta sesion']); exit; }
    if (!is_null($nota) && ($nota < 1 || $nota > 10)) { http_response_code(400); echo json_encode(['error'=>'Nota inválida']); exit; }

    // Asegurarse de que el alumno pertenece al curso del curso_materia
    $stmtChk = $conexion->prepare("SELECT 1 FROM alumnos a INNER JOIN curso_materia cm ON cm.id_curso = a.id_curso WHERE a.id_alumno=? AND cm.id_curso_materia=? LIMIT 1");
    $stmtChk->bind_param('ii', $idAlumno, $idCursoMateria);
    $stmtChk->execute();
    if (!$stmtChk->get_result()->fetch_row()) {
        http_response_code(400); echo json_encode(['error'=>'El alumno no pertenece a ese curso']); exit;
    }

    // UPSERT para evitar duplicados cuando el UPDATE afecte 0 filas (valor sin cambios)
    if (is_null($nota)) {
        $sql = "INSERT INTO notasparciales (id_alumno, id_curso_materia, fecha, sesion, nota)
                VALUES (?,?,?,?,NULL)
                ON DUPLICATE KEY UPDATE nota=NULL";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param('iiss', $idAlumno, $idCursoMateria, $fecha, $sesion);
    } else {
        $sql = "INSERT INTO notasparciales (id_alumno, id_curso_materia, fecha, sesion, nota)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE nota=VALUES(nota)";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param('iissi', $idAlumno, $idCursoMateria, $fecha, $sesion, $nota);
    }

    try {
        $ok = $stmt->execute();
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['error'=>'No se pudo guardar', 'detail'=>$stmt->error, 'errno'=>$stmt->errno]);
            exit;
        }
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error'=>'Excepción al guardar', 'detail'=>$e->getMessage()]);
    }
    exit;
}

// Cambiar la fecha asociada a una sesión (editar fecha en encabezado)
if ($action === 'cambiar_fecha_sesion') {
    if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos para editar']); exit; }

    $sesion = isset($_POST['sesion']) ? trim($_POST['sesion']) : '';
    $nuevaFecha = isset($_POST['nueva_fecha']) ? $_POST['nueva_fecha'] : '';
    $nuevoDetalle = isset($_POST['detalle']) ? $_POST['detalle'] : null;
    $oldFecha = isset($_POST['old_fecha']) ? $_POST['old_fecha'] : '';

    if ($sesion === '' || !$nuevaFecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nuevaFecha)) {
        http_response_code(400); echo json_encode(['error'=>'Datos inválidos']); exit;
    }

    // Modo LEGACY: sesiones sin clave, agrupar por fecha actual
    $isLegacy = (strpos($sesion, 'LEGACY-') === 0);
    if ($isLegacy) {
        if (!$oldFecha) { $oldFecha = substr($sesion, 7); }
        if (!$oldFecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldFecha)) { http_response_code(400); echo json_encode(['error'=>'Fecha original inválida']); exit; }
        if ($nuevoDetalle !== null) {
            $stmt = $conexion->prepare("UPDATE notasparciales SET fecha=?, detalle=? WHERE id_curso_materia=? AND sesion IS NULL AND fecha=?");
            $stmt->bind_param('ssis', $nuevaFecha, $nuevoDetalle, $idCursoMateria, $oldFecha);
        } else {
            $stmt = $conexion->prepare("UPDATE notasparciales SET fecha=? WHERE id_curso_materia=? AND sesion IS NULL AND fecha=?");
            $stmt->bind_param('sis', $nuevaFecha, $idCursoMateria, $oldFecha);
        }
    } else {
        if ($nuevoDetalle !== null) {
            $stmt = $conexion->prepare("UPDATE notasparciales SET fecha=?, detalle=? WHERE id_curso_materia=? AND sesion=?");
            $stmt->bind_param('ssis', $nuevaFecha, $nuevoDetalle, $idCursoMateria, $sesion);
        } else {
            $stmt = $conexion->prepare("UPDATE notasparciales SET fecha=? WHERE id_curso_materia=? AND sesion=?");
            $stmt->bind_param('sis', $nuevaFecha, $idCursoMateria, $sesion);
        }
    }

    try {
        $ok = $stmt->execute();
        if (!$ok) {
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

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
