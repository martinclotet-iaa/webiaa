<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'No autorizado']));
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/conexion.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit(json_encode(['error' => 'ID inválido']));
}

// Datos básicos del usuario
$sql = "SELECT id_usuario, nombre, apellido, email FROM usuarios WHERE id_usuario = $id LIMIT 1";
$result = $conexion->query($sql);
if (!$result || $result->num_rows === 0) {
    http_response_code(404);
    exit(json_encode(['error' => 'Usuario no encontrado']));
}
$usuario = $result->fetch_assoc();

// Perfiles del usuario
$perfiles = [];
$sqlPerfiles = "SELECT id_perfil FROM usuario_perfil WHERE id_usuario = $id";
$resPerfiles = $conexion->query($sqlPerfiles);
if ($resPerfiles) {
    while ($row = $resPerfiles->fetch_assoc()) {
        $perfiles[] = (int)$row['id_perfil'];
    }
}
$usuario['perfiles'] = $perfiles;

// Datos específicos según perfil
// Alumno (id_perfil = 1)
if (in_array(1, $perfiles)) {
    $sqlAlumno = "SELECT dni, id_curso, libro, folio FROM alumnos WHERE id_usuario = $id LIMIT 1";
    $resAlumno = $conexion->query($sqlAlumno);
    if ($resAlumno && $resAlumno->num_rows > 0) {
        $usuario['alumno'] = $resAlumno->fetch_assoc();
    }
}

// Tutor (id_perfil = 2)
if (in_array(2, $perfiles)) {
    $sqlTutor = "SELECT telefono FROM tutores WHERE id_usuario = $id LIMIT 1";
    $resTutor = $conexion->query($sqlTutor);
    if ($resTutor && $resTutor->num_rows > 0) {
        $usuario['tutor'] = $resTutor->fetch_assoc();
    }
}

// Docente (id_perfil = 3)
if (in_array(3, $perfiles)) {
    $sqlDocente = "SELECT telefono FROM docentes WHERE id_usuario = $id LIMIT 1";
    $resDocente = $conexion->query($sqlDocente);
    if ($resDocente && $resDocente->num_rows > 0) {
        $usuario['docente'] = $resDocente->fetch_assoc();
    }

    // Materias asignadas (ids de curso_materia)
    $docenteFila = $conexion->query("SELECT id_docente FROM docentes WHERE id_usuario = $id LIMIT 1");
    if ($docenteFila && $docenteFila->num_rows > 0) {
        $id_docente = (int)$docenteFila->fetch_assoc()['id_docente'];
        $resDM = $conexion->query("SELECT id_curso_materia FROM docente_materia WHERE id_docente = $id_docente");
        $dm = [];
        if ($resDM) {
            while ($r = $resDM->fetch_assoc()) { $dm[] = (int)$r['id_curso_materia']; }
        }
        $usuario['docente_materias'] = $dm;
    }
}

// Preceptor (id_perfil = 5) - cursos asignados
if (in_array(5, $perfiles)) {
    $hasJoinTbl = $conexion->query("SHOW TABLES LIKE 'curso_preceptor'");
    $cursosPreceptor = [];
    if ($hasJoinTbl && $hasJoinTbl->num_rows > 0) {
        $sqlPre = "SELECT id_curso FROM curso_preceptor WHERE id_preceptor = $id";
        $res = $conexion->query($sqlPre);
        if ($res) { while ($row = $res->fetch_assoc()) { $cursosPreceptor[] = (int)$row['id_curso']; } }
    } else {
        $hasPreCol = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'");
        if ($hasPreCol && $hasPreCol->num_rows > 0) {
            $sqlPre = "SELECT id_curso FROM cursos WHERE id_preceptor = $id";
            $res = $conexion->query($sqlPre);
            if ($res) { while ($row = $res->fetch_assoc()) { $cursosPreceptor[] = (int)$row['id_curso']; } }
        }
    }
    $usuario['preceptor_cursos'] = $cursosPreceptor;

    // Nuevo: Teléfono del Preceptor
    $sqlP = "SELECT telefono FROM preceptores WHERE id_usuario = $id LIMIT 1";
    $resP = $conexion->query($sqlP);
    if ($resP && $resP->num_rows > 0) {
        $usuario['preceptor'] = $resP->fetch_assoc();
    }
}

echo json_encode($usuario, JSON_UNESCAPED_UNICODE);
