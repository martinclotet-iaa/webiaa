<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) { http_response_code(401); echo json_encode(['error'=>'No autenticado']); exit; }
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1; // 1=alumno,3=docente,4=admin,5=preceptor

function I($v){ return isset($v) && is_numeric($v) ? intval($v) : 0; }
$idCurso = I($_POST['id'] ?? $_GET['id'] ?? 0);
if ($idCurso <= 0) { http_response_code(400); echo json_encode(['error'=>'Falta id de curso']); exit; }

// Crear tabla si no existe
$conexion->query("CREATE TABLE IF NOT EXISTS asistencias_curso (
  id_alumno INT NOT NULL,
  id_curso INT NOT NULL,
  fecha DATE NOT NULL,
  sesion VARCHAR(64) NOT NULL,
  estado ENUM('P','A') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_alumno, id_curso, fecha, sesion),
  KEY idx_curso_fecha (id_curso, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Permisos: Admin y Preceptor pueden editar
$puedeEditar = ($perfilActual === 4 || $perfilActual === 5);

if ($action === 'listar_todo') {
  $fecha = $_POST['fecha'] ?? $_GET['fecha'] ?? '';
  if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { http_response_code(400); echo json_encode(['error'=>'Fecha inválida']); exit; }

  // Sesiones del día + sesiones previas para render completo
  $stmtSes = $conexion->prepare("(
      SELECT sesion, fecha, MIN(created_at) AS created_at
      FROM asistencias_curso
      WHERE id_curso=? AND fecha=?
      GROUP BY sesion, fecha
    ) UNION ALL (
      SELECT sesion, fecha, MIN(created_at) AS created_at
      FROM asistencias_curso
      WHERE id_curso=? AND fecha<>?
      GROUP BY sesion, fecha
    ) ORDER BY fecha ASC, created_at ASC");
  $stmtSes->bind_param('isis', $idCurso, $fecha, $idCurso, $fecha);
  $stmtSes->execute();
  $rsSes = $stmtSes->get_result();
  $sesiones = [];
  while($row = $rsSes->fetch_assoc()){ $sesiones[] = $row; }

  $stmtData = $conexion->prepare("SELECT id_alumno, sesion, estado FROM asistencias_curso WHERE id_curso=?");
  $stmtData->bind_param('i', $idCurso);
  $stmtData->execute();
  $rs = $stmtData->get_result();
  $data = [];
  while($r = $rs->fetch_assoc()){
    $s = $r['sesion']; $a = (int)$r['id_alumno'];
    if (!isset($data[$s])) $data[$s] = [];
    $data[$s][$a] = $r['estado'];
  }
  echo json_encode(['sesiones'=>$sesiones, 'data'=>$data]);
  exit;
}

if ($action === 'guardar') {
  if (!$puedeEditar) { http_response_code(403); echo json_encode(['error'=>'Sin permisos']); exit; }
  $idAlumno = I($_POST['alumno'] ?? 0);
  $fecha = $_POST['fecha'] ?? '';
  $estado = $_POST['estado'] ?? '';
  $sesion = $_POST['sesion'] ?? '';
  if ($idAlumno<=0 || !$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha) || $sesion==='') { http_response_code(400); echo json_encode(['error'=>'Datos inválidos']); exit; }

  // Validar que el alumno pertenece al curso
  $chk = $conexion->prepare("SELECT 1 FROM alumnos WHERE id_alumno=? AND id_curso=? LIMIT 1");
  $chk->bind_param('ii',$idAlumno,$idCurso);
  $chk->execute();
  if (!$chk->get_result()->fetch_row()) { http_response_code(400); echo json_encode(['error'=>'Alumno no pertenece al curso']); exit; }

  $stmt = $conexion->prepare("INSERT INTO asistencias_curso (id_alumno,id_curso,fecha,sesion,estado) VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE estado=VALUES(estado)");
  $stmt->bind_param('iisss', $idAlumno, $idCurso, $fecha, $sesion, $estado);
  $ok = $stmt->execute();
  if (!$ok) { http_response_code(500); echo json_encode(['error'=>'No se pudo guardar']); exit; }
  echo json_encode(['ok'=>true]);
  exit;
}

http_response_code(400);
echo json_encode(['error'=>'Acción no válida']);
