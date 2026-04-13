<?php
// AJAX endpoint para actualizar un solo campo de notas
// Input POST: id (id_curso_materia), alumno (id_alumno), campo, valor
// Respuesta JSON

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['ok'=>0,'msg'=>'No autenticado']);
    exit;
}

require_once __DIR__ . '/conexion.php';

$idUsuario = (int)$_SESSION['id_usuario'];
$perfilActual = isset($_SESSION['perfil_actual']) ? (int)$_SESSION['perfil_actual'] : 1; // 1=alumno,3=docente,4=admin

$idCursoMateria = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$idAlumno = isset($_POST['alumno']) ? (int)$_POST['alumno'] : 0;
$campo = isset($_POST['campo']) ? trim($_POST['campo']) : '';
$valor = isset($_POST['valor']) ? $_POST['valor'] : null;

if ($idCursoMateria <= 0 || $idAlumno <= 0 || $campo==='') {
    http_response_code(400);
    echo json_encode(['ok'=>0,'msg'=>'Parámetros inválidos']);
    exit;
}

// Validar curso/materia existe
$info = $conexion->query("SELECT cm.id_curso_materia, c.id_curso, c.nombre_curso, m.id_materia, m.nombre_materia
                          FROM curso_materia cm
                          INNER JOIN cursos c ON c.id_curso = cm.id_curso
                          INNER JOIN materias m ON m.id_materia = cm.id_materia
                          WHERE cm.id_curso_materia = $idCursoMateria")->fetch_assoc();
if (!$info) {
    http_response_code(404);
    echo json_encode(['ok'=>0,'msg'=>'Curso/Materia no encontrado']);
    exit;
}

// Permisos
$puedeEditar = false;
if ($perfilActual === 4) {
    $puedeEditar = true; // Admin
} elseif ($perfilActual === 3) {
    $tieneAsignacion = $conexion->query("SELECT 1
        FROM docentes d
        INNER JOIN docente_materia dm ON dm.id_docente = d.id_docente
        WHERE d.id_usuario = $idUsuario AND dm.id_curso_materia = $idCursoMateria
        LIMIT 1")->fetch_row();
    $puedeEditar = (bool)$tieneAsignacion;
} elseif ($perfilActual === 1) {
    // Alumno puede editar SOLO si es su propio registro y opcionalmente campos permitidos (normalmente no)
    $puedeEditar = false;
}
if (!$puedeEditar) {
    http_response_code(403);
    echo json_encode(['ok'=>0,'msg'=>'Sin permisos']);
    exit;
}

// Whitelist de campos permitidos
$validos = [
    'id_estado' => 'int',
    'valoracion_preliminar_1' => 'string',
    'calificacion_1' => 'float',
    'clases_dictadas_1' => 'int',
    'inasistencias_1' => 'int',
    'observacion_1' => 'int_or_null',
    'valoracion_preliminar_2' => 'string',
    'calificacion_2' => 'float',
    'clases_dictadas_2' => 'int',
    'inasistencias_2' => 'int',
    'observacion_2' => 'int_or_null',
    'intensificacion_1' => 'string',
    'diciembre' => 'string',
    'febrero' => 'string',
    'calificacion_final' => 'float',
    'marzo_asistencia' => 'string',
    'marzo_valoracion' => 'string',
    'julio_asistencia' => 'string',
    'julio_valoracion' => 'string',
    'agosto_asistencia' => 'string',
    'agosto_valoracion' => 'string',
    'noviembre_asistencia' => 'string',
    'noviembre_valoracion' => 'string',
    'diciembre_asistencia' => 'string',
    'diciembre_valoracion' => 'string',
    'febrero_asistencia' => 'string',
    'febrero_valoracion' => 'string'
];

if (!array_key_exists($campo, $validos)) {
    http_response_code(400);
    echo json_encode(['ok'=>0,'msg'=>'Campo no permitido']);
    exit;
}

// Normalizar valor según tipo
function to_null($v){ return ($v === '' || $v === null) ? null : $v; }
$tipo = $validos[$campo];
$valSQL = 'NULL';
try {
    if ($tipo === 'string') {
        $v = to_null($valor);
        if ($v !== null) {
            $v = substr($v,0,50);
            $valSQL = "'".$conexion->real_escape_string($v)."'";
        }
    } elseif ($tipo === 'float') {
        $v = to_null($valor);
        if ($v !== null) {
            $v = str_replace(',', '.', $v);
            if ($v === '') { $valSQL = 'NULL'; } else { $valSQL = (string)(0 + $v); }
        }
    } elseif ($tipo === 'int') {
        $v = to_null($valor);
        if ($v !== null) { $valSQL = (string)intval($v); }
    } elseif ($tipo === 'int_or_null') {
        $v = to_null($valor);
        if ($v !== null) { $valSQL = (string)intval($v); }
    }
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(['ok'=>0,'msg'=>'Valor inválido']);
    exit;
}

// Asegurar existencia de fila en notas
$ex = $conexion->query("SELECT id_nota FROM notas WHERE id_alumno=$idAlumno AND id_curso_materia=$idCursoMateria LIMIT 1")->fetch_assoc();
if ($ex) {
    $idNota = (int)$ex['id_nota'];
    $sql = "UPDATE notas SET $campo=$valSQL WHERE id_nota=$idNota";
    $ok = $conexion->query($sql);
} else {
    // Insert con solo el campo enviado
    $sql = "INSERT INTO notas (id_alumno,id_curso_materia,$campo) VALUES ($idAlumno,$idCursoMateria,$valSQL)";
    $ok = $conexion->query($sql);
}

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok'=>0,'msg'=>'Error BD','error'=>$conexion->error]);
    exit;
}

echo json_encode(['ok'=>1]);
