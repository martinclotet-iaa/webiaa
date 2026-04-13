<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

$idUsuario = intval($_SESSION['id_usuario']);
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1; // 1=alumno, 3=docente, 4=admin
$idCursoMateria = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($idCursoMateria <= 0) {
    header('Location: menu.php');
    exit;
}

// Info de curso y materia
$info = $conexion->query("SELECT cm.id_curso_materia, c.id_curso, c.nombre_curso, m.id_materia, m.nombre_materia, m.tipo
                          FROM curso_materia cm
                          INNER JOIN cursos c ON c.id_curso = cm.id_curso
                          INNER JOIN materias m ON m.id_materia = cm.id_materia
                          WHERE cm.id_curso_materia = $idCursoMateria")->fetch_assoc();
if (!$info) {
    header('Location: menu.php');
    exit;
}

// Permisos: Docente asignado o Administrador. Alumno solo ve su fila (readonly)
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
    $puedeEditar = false; // Alumno: solo lectura de su fila
}

// Guardar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeEditar) {
    $rows = $_POST['rows'] ?? [];

    foreach ($rows as $idAlumno => $r) {
        $idAlumno = intval($idAlumno);
        // Normalizar valores
        $vp1 = $r['valoracion_preliminar_1'] ?? null; if ($vp1==='') $vp1=null;
        $c1  = ($r['calificacion_1'] === '' ? null : str_replace(',', '.', $r['calificacion_1']));
        $cd1 = ($r['clases_dictadas_1'] === '' ? null : intval($r['clases_dictadas_1']));
        $in1 = ($r['inasistencias_1'] === '' ? null : intval($r['inasistencias_1']));
        $ob1 = ($r['observacion_1'] === '' ? null : intval($r['observacion_1']));

        $vp2 = $r['valoracion_preliminar_2'] ?? null; if ($vp2==='') $vp2=null;
        $c2  = ($r['calificacion_2'] === '' ? null : str_replace(',', '.', $r['calificacion_2']));
        $cd2 = ($r['clases_dictadas_2'] === '' ? null : intval($r['clases_dictadas_2']));
        $in2 = ($r['inasistencias_2'] === '' ? null : intval($r['inasistencias_2']));
        $ob2 = ($r['observacion_2'] === '' ? null : intval($r['observacion_2']));

        $int1 = ($r['intensificacion_1'] === '' ? null : str_replace(',', '.', $r['intensificacion_1']));
        $dic  = ($r['diciembre'] === '' ? null : str_replace(',', '.', $r['diciembre']));
        $feb  = ($r['febrero'] === '' ? null : str_replace(',', '.', $r['febrero']));
        $cf   = ($r['calificacion_final'] === '' ? null : str_replace(',', '.', $r['calificacion_final']));
        $id_estado = intval($r['id_estado'] ?? 1);

        $q = sprintf(
            "REPLACE INTO notas (id_nota, id_alumno, id_curso_materia, valoracion_preliminar_1, calificacion_1, clases_dictadas_1, inasistencias_1, observacion_1, valoracion_preliminar_2, calificacion_2, clases_dictadas_2, inasistencias_2, observacion_2, intensificacion_1, diciembre, febrero, calificacion_final, id_estado)
             VALUES ((SELECT id_nota FROM notas WHERE id_alumno=%d AND id_curso_materia=%d LIMIT 1), %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d)",
            $idAlumno, $idCursoMateria, $idAlumno, $idCursoMateria,
            is_null($vp1)?'NULL':"'".$conexion->real_escape_string($vp1)."'",
            is_null($c1)?'NULL':"'".$conexion->real_escape_string($c1)."'",
            is_null($cd1)?'NULL':intval($cd1),
            is_null($in1)?'NULL':intval($in1),
            is_null($ob1)?'NULL':intval($ob1),
            is_null($vp2)?'NULL':"'".$conexion->real_escape_string($vp2)."'",
            is_null($c2)?'NULL':"'".$conexion->real_escape_string($c2)."'",
            is_null($cd2)?'NULL':intval($cd2),
            is_null($in2)?'NULL':intval($in2),
            is_null($ob2)?'NULL':intval($ob2),
            is_null($int1)?'NULL':"'".$conexion->real_escape_string($int1)."'",
            is_null($dic)?'NULL':"'".$conexion->real_escape_string($dic)."'",
            is_null($feb)?'NULL':"'".$conexion->real_escape_string($feb)."'",
            is_null($cf)?'NULL':"'".$conexion->real_escape_string($cf)."'",
            $id_estado
        );
        $conexion->query($q);
    }

    header('Location: rite_materia_curso.php?id='.$idCursoMateria.'&ok=1');
    exit;
}

// Listado de alumnos del curso (incluye recursantes e intensificantes)
$alumnos = $conexion->query("
    SELECT DISTINCT a.id_alumno, u.apellido, u.nombre, c_orig.nombre_curso AS curso_origen, a.id_curso AS id_curso_alumno
    FROM alumnos a
    INNER JOIN usuarios u ON u.id_usuario = a.id_usuario
    INNER JOIN cursos c_orig ON c_orig.id_curso = a.id_curso
    LEFT JOIN notas n ON n.id_alumno = a.id_alumno AND n.id_curso_materia = ".$idCursoMateria."
    WHERE a.id_curso = ".$info['id_curso']." OR n.id_nota IS NOT NULL
    ORDER BY u.apellido, u.nombre")->fetch_all(MYSQLI_ASSOC);

// Notas existentes indexadas por id_alumno (necesaria para la partición)
$notas = [];
$rowsNotas = $conexion->query("SELECT * FROM notas WHERE id_curso_materia = $idCursoMateria");
while ($r = $rowsNotas->fetch_assoc()) { $notas[intval($r['id_alumno'])] = $r; }

// Obtener estados de TODOS los alumnos de este curso en OTRAS materias (para el "sean de esa materia u otra")
$alumnos_ids = array_map(function($a){ return $a['id_alumno']; }, $alumnos);
if (!empty($alumnos_ids)) {
    $ids_str = implode(',', $alumnos_ids);
    $sql_otros = "SELECT id_alumno, id_estado, id_curso_materia, anio_adeuda, materia_adeuda FROM notas WHERE id_alumno IN ($ids_str) AND id_estado IN (3, 4)";
    $res_otros = $conexion->query($sql_otros);
    $estados_otros = [];
    while ($r = $res_otros->fetch_assoc()) {
        $estados_otros[intval($r['id_alumno'])][] = $r;
    }
} else {
    $estados_otros = [];
}

foreach ($alumnos as $al) {
    $n = $notas[intval($al['id_alumno'])] ?? [];
    $id_est_esta_materia = intval($n['id_estado'] ?? 1);
    
    // Si el alumno está en intensificación para ESTA materia, o tiene intensificaciones en OTRAS materias (siendo de este curso)
    $tiene_intensificacion_otra = !empty($estados_otros[intval($al['id_alumno'])]);
    
    if ($id_est_esta_materia === 3 || $id_est_esta_materia === 4 || $tiene_intensificacion_otra) {
        // Guardar las otras intensificaciones para mostrar info
        $al['otras_intensificaciones'] = $estados_otros[intval($al['id_alumno'])] ?? [];
        $alumnos_acreditacion[] = $al;
    } else {
        $alumnos_regulares[] = $al;
    }
}

// Notas existentes indexadas por id_alumno
$notas = [];
$rowsNotas = $conexion->query("SELECT * FROM notas WHERE id_curso_materia = $idCursoMateria");
while ($r = $rowsNotas->fetch_assoc()) { $notas[intval($r['id_alumno'])] = $r; }

// Observaciones
$obs = $conexion->query("SELECT id_observacion, descripcion FROM observaciones ORDER BY id_observacion")->fetch_all(MYSQLI_ASSOC);

// Fecha de corte del 2° cuatrimestre (para separar inasistencias 1/2)
$fechaCorteQ2 = null;
try {
    $rfc = $conexion->query("SELECT valor_date FROM configuraciones WHERE clave='inicio_segundo_cuatrimestre' LIMIT 1");
    if ($rfc && ($row = $rfc->fetch_assoc())) { $fechaCorteQ2 = $row['valor_date']; }
} catch (Exception $e) { /* ignorar */ }
if (!$fechaCorteQ2) { $fechaCorteQ2 = date('Y').'-08-30'; }

// Ciclo lectivo (año)
$cicloLectivo = null;
try {
    $rcl = $conexion->query("SELECT valor_int FROM configuraciones WHERE clave='ciclo_lectivo' LIMIT 1");
    if ($rcl && ($row = $rcl->fetch_assoc())) { $cicloLectivo = isset($row['valor_int']) ? intval($row['valor_int']) : null; }
} catch (Exception $e) { /* ignorar */ }
if (!$cicloLectivo) { $cicloLectivo = intval(date('Y')); }

// Conteo de inasistencias (estado 'A') y clases dictadas (P o A) por alumno, separado en Q1/Q2 por fecha de corte
$faltas = [];
try {
    $fcEsc = $conexion->real_escape_string($fechaCorteQ2);
    $sqlF = "SELECT id_alumno,
                SUM(CASE WHEN estado='A' AND fecha < '".$fcEsc."' THEN 1 ELSE 0 END) AS f1,
                SUM(CASE WHEN estado='A' AND fecha >= '".$fcEsc."' THEN 1 ELSE 0 END) AS f2,
                SUM(CASE WHEN (estado='P' OR estado='A') AND fecha < '".$fcEsc."' THEN 1 ELSE 0 END) AS cd1,
                SUM(CASE WHEN (estado='P' OR estado='A') AND fecha >= '".$fcEsc."' THEN 1 ELSE 0 END) AS cd2
             FROM asistencias
             WHERE id_curso_materia = ".$idCursoMateria." 
             GROUP BY id_alumno";
    if ($resF = $conexion->query($sqlF)) {
        while($r = $resF->fetch_assoc()){
            $faltas[(int)$r['id_alumno']] = [ 'f1' => (int)$r['f1'], 'f2' => (int)$r['f2'], 'cd1' => (int)($r['cd1'] ?? 0), 'cd2' => (int)($r['cd2'] ?? 0) ];
        }
    }
} catch (Exception $e) { /* ignorar faltas si falla */ }

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Acreditación - <?php echo htmlspecialchars($info['nombre_materia'].' - '.$info['nombre_curso']); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
  <style>
<?php
// Para mantener el estilo y funcionalidad, reutilizamos los estilos del archivo original de notas.
// Copiamos tal cual desde la versión existente para consistencia visual.
?>
    :root { 
      --w-num: 40px;
      --sep-num: 2px;
      --left-nombre: var(--w-num);
      --thead1-h: 40px;
    }
    .container { padding:8px; }
    .titulo { margin: 12px 0 8px; }
    .titulo-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .btn-volver { 
      display:inline-flex; align-items:center; gap:8px; 
      background:#16682b; color:#fff; border:1px solid #16682b; 
      padding:6px 12px; border-radius:8px; font-size:14px; text-decoration:none;
      box-shadow:0 2px 4px rgba(0,0,0,0.15);
    }
    .btn-volver:hover { background:#1a7530; border-color:#1a7530; }
    .btn-volver i { font-size:14px; }
    table.notas { 
      width: 100%; 
      min-width: 1400px;
      border-collapse: collapse;
      background:#fff; 
      color:#000; 
    }
    table.notas th, table.notas td { border:1px solid #e5e5e5; padding:4px 6px; font-size: 13px; color:#000; box-sizing: border-box; }
    table.notas th { background:#f7f7f9; font-weight:600; text-align:center; color:#000; }
    table.notas input[type="number"],
    table.notas input[type="text"],
    table.notas select { width: 100%; padding:5px; font-size:13px; color:#000; }
    table.notas option { color:#000; }
    .acciones { text-align:right; margin-top:10px; }
    .badge-ok { color:#0a7c2f; background:#dff3e3; padding:6px 10px; border-radius:8px; font-size:12px; }
    .readonly { background: #f9fafb; color:#000; }
    .numbox { position: relative; display: inline-block; width: 34px; height: 22px; border:1px solid #cfd3d7; border-radius:3px; background:#fff; user-select: none; }
    .numbox .numshow { width: 20px; height: 100%; border: none; outline: none; text-align: center; font-size: 12px; color:#000; background: transparent; line-height: 22px; box-sizing: border-box; padding: 0; }
    .numbox .btn { position:absolute; right:0; width: 10px; height: 50%; border-left:1px solid #cfd3d7; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index: 2; }
    .numbox .btn.up { top:0; border-bottom:1px solid #e5e5e5; }
    .numbox .btn.down { bottom:0; }
    .numbox .btn i { border: solid #555; border-width: 0 1px 1px 0; display: inline-block; padding: 1px; pointer-events: none; }
    .numbox .btn.up i { transform: rotate(-135deg); }
    .numbox .btn.down i { transform: rotate(45deg); }
    .numbox.readonly { background: #f9fafb; }
    .mini-sel { 
      height: 26px; 
      line-height: 26px; 
      font-size: 12px; 
      border:1px solid #cfd3d7; 
      border-radius:3px; 
      padding: 0 10px 0 2px; 
      background:#fff; 
      color:#000; 
      width: 44px; 
      text-align-last: center; 
      -moz-text-align-last: center; 
      -webkit-appearance: menulist; 
      appearance: menulist;
      box-sizing: border-box;
      vertical-align: middle;
    }
    .mini-sel option { text-align: center; }
    .mini-num { width: 40px; }
    .mini-w { width: 44px; }
    .mini-sel-wrap { position: relative; display: inline-block; width: 44px; height: 26px; }
    .mini-sel-wrap .mini-sel { width: 100%; height: 100%; color: transparent; }
    .mini-sel-wrap::after { content: attr(data-abbr); position: absolute; inset: 0 8px 0 2px; display: flex; align-items: center; justify-content: center; color:#000; font-size: 11px; pointer-events: none; }
    /* Estilo sutil para badges de Cursa/Recursa */
    .badge-condicion { font-weight: 700; font-size: 13px; display: inline-block; width: 20px; text-align: center; }
    .badge-cursa { color: #999; }
    .badge-recursa { color: #dc3545; }
    .badge-intensifica { color: #fd7e14; }
    .badge-adeuda { color: #6f42c1; }
    .badge-aprobada { color: #198754; }

    /* Centrar texto de calificación final */
    .final-show { display:inline-block; width:100%; text-align:center; }
    /* Centrar texto de inasistencias (solo lectura) */
    .inasist-show { display:inline-block; width:100%; text-align:center; }
    table.notas th.col-observaciones, table.notas td.col-observaciones { width: 260px !important; min-width: 260px !important; max-width: 260px !important; white-space: normal; overflow: visible; text-align: left; }
    .sel-observacion { height: 30px; line-height: normal; font-size: 13px; border:1px solid #cfd3d7; border-radius:4px; padding: 4px 10px 4px 6px; background:#fff; color:#000; width: 100%; box-sizing: border-box; -webkit-appearance: menulist; appearance: menulist; text-align-last: left; }
    .tabla-wrap { overflow-x: auto; overflow-y: auto; width: 100%; max-height: 70vh; border: 1px solid #e5e5e5; border-radius: 10px; position: relative; }
    table.notas { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 1400px; position: relative; table-layout: fixed; }
    .sticky-num { position: sticky; left: 0; width: var(--w-num); min-width: var(--w-num); max-width: var(--w-num); text-align: center; background: #f7f7f9; border-right: none; z-index: 1000; padding: 0 !important; will-change: left; backface-visibility: hidden; transform: translateZ(0); }
    .sticky-nombre { position: sticky; left: var(--left-nombre); width: 200px; min-width: 200px; max-width: 200px; background: #f7f7f9; border-left: none; border-right: none; z-index: 999; text-align: left; padding-left: 8px; padding-right: 8px; will-change: left; backface-visibility: hidden; transform: translateZ(0); }
    .sticky-nombre::before{ content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: var(--sep-num); background: #16682b; }
    thead th.left-sep { position: sticky; }
    thead th.left-sep::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: var(--sep-num); background: #16682b; }
    tbody .sticky-num { background: #ffffff !important; }
    tbody .sticky-nombre { background: #ffffff !important; }
    thead tr:first-child th { position: sticky; top: 0; background: #e9ecef; z-index: 12; border-bottom: 2px solid #16682b; box-shadow: 0 2px 4px rgba(0,0,0,0.1); height: var(--thead1-h); box-sizing: border-box; white-space: normal; }
    thead tr:nth-child(2) th { position: sticky; top: var(--thead1-h); background: #e9ecef; z-index: 11; border-bottom: 2px solid #16682b; box-shadow: 0 2px 4px rgba(0,0,0,0.1); white-space: normal; }
    thead tr:first-child .sticky-num { position: sticky !important; left: 0 !important; z-index: 1002 !important; background: #e9ecef !important; }
    thead tr:first-child .sticky-nombre { position: sticky !important; left: var(--left-nombre) !important; z-index: 1001 !important; background: #e9ecef !important; }
    thead tr:nth-child(2) .sticky-num { position: sticky !important; left: 0 !important; z-index: 1001 !important; background: #e9ecef !important; }
    thead tr:nth-child(2) .sticky-nombre { position: sticky !important; left: var(--left-nombre) !important; z-index: 1000 !important; background: #e9ecef !important; }
    th.col-estado, td.col-estado { width: 45px; min-width: 45px; }
    .tabla-wrap::-webkit-scrollbar { height: 12px; }
    .tabla-wrap::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 6px; }
    .tabla-wrap::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 6px; }
    .tabla-wrap::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    table.notas tbody { counter-reset: rownum; }
    table.notas tbody tr { counter-increment: rownum; }
    td.sticky-num { color: transparent; position: sticky; }
    td.sticky-num::before { content: counter(rownum); position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color:#000; font-variant-numeric: tabular-nums; }
    .rite-topbar { background:#16682b; color:#fff; padding:8px 12px; border-radius:8px; font-weight:600; margin-bottom:10px; text-align:center; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="container">
    <div class="rite-topbar">REGISTRO INSTITUCIONAL DE TRAYECTORIA EDUCATIVA (RITE) | CICLO <?php echo htmlspecialchars((string)$cicloLectivo); ?></div>
    <div class="titulo-bar">
      <h2 class="titulo"><i class="fa fa-clipboard-list"></i> Acreditación - <?php echo htmlspecialchars($info['nombre_materia']); ?> (<?php echo htmlspecialchars($info['nombre_curso']); ?>) <span style="font-size: 0.8rem; opacity: 0.7; font-weight: normal; margin-left: 10px;">[<?php echo htmlspecialchars($info['tipo'] ?: 'Programática'); ?>]</span></h2>
      <?php 
      $url_back = ($perfilActual === 4) ? 'materias.php' : 'mismaterias.php';
      ?>
      <a href="<?php echo $url_back; ?>" class="btn-volver" title="Volver"><i class="fa fa-arrow-left"></i> Volver</a>
    </div>
    <?php if (isset($_GET['ok'])): ?><div class="badge-ok"><i class="fa fa-check"></i> Cambios guardados</div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="id" value="<?php echo $idCursoMateria; ?>" />
      <div class="tabla-wrap">
      <table class="notas">
        <colgroup>
          <col style="width: var(--w-num);">
          <col style="width: 200px;">
          <col style="width: 45px;">
          <col style="width: 120px;">
          <col style="width: 100px;">
          <col style="width: 60px;">
          <col style="width: 60px;">
          <col style="width: 260px;">
          <col style="width: 120px;">
          <col style="width: 100px;">
          <col style="width: 60px;">
          <col style="width: 60px;">
          <col style="width: 260px;">
          <col style="width: 140px;">
          <col style="width: 80px;">
          <col style="width: 80px;">
          <col style="width: 100px;">
        </colgroup>
        <thead>
          <tr>
            <th rowspan="2" class="sticky-num">#</th>
            <th rowspan="2" class="sticky-nombre col-nombre">Apellido y Nombre</th>
            <th rowspan="2" class="col-estado left-sep">Cond.</th>
            <th colspan="5" class="left-sep" style="background:#b6d7a8; color:#000;">1° CUATRIMESTRE</th>
            <th colspan="5" class="left-sep" style="background:#b6d7a8; color:#000;">2° CUATRIMESTRE</th>
            <th colspan="3" class="left-sep" style="background:#cfe2f3; color:#000;">INTENSIFICACIÓN</th>
            <th rowspan="2" class="left-sep" style="background:#93c47d; color:#000;">CALIFICACIÓN FINAL</th>
          </tr>
          <tr>
            <th class="left-sep">1ª Valoración Preliminar</th>
            <th>Calificación</th>
            <th>Clases dictadas</th>
            <th style="text-align:center">Inasisten<br>cias</th>
            <th class="col-observaciones" style="width:260px">Observaciones 1° Cuatrimestre</th>
            <th class="left-sep">2ª Valoración Preliminar</th>
            <th>Calificación</th>
            <th>Clases dictadas</th>
            <th style="text-align:center">Inasisten<br>cias</th>
            <th class="col-observaciones" style="width:260px">Observaciones 2° Cuatrimestre</th>
            <th class="left-sep">Intensificación 1° cuatrimestre</th>
            <th>DICIEMBRE</th>
            <th>FEBRERO</th>
          </tr>
        </thead>
        <tbody>
        <?php $i=1; foreach ($alumnos_regulares as $al): 
            $n = $notas[$al['id_alumno']] ?? [];
            $ro = (!$puedeEditar && $perfilActual !== 4 && $perfilActual !== 3) || ($perfilActual===1 && $idUsuario !== intval($al['id_alumno']));
        ?>
          <tr>
            <td class="sticky-num" style="text-align:center;"></td>
            <td class="sticky-nombre col-nombre">
              <?php echo htmlspecialchars($al['apellido'].', '.$al['nombre']); ?>
              <?php if (intval($al['id_curso_alumno']) !== intval($info['id_curso'])): ?>
                <span style="font-size:10px; color:#c62828; font-weight:700; margin-left:4px;">(<?php echo htmlspecialchars($al['curso_origen']); ?>)</span>
              <?php endif; ?>
            </td>
            <td class="col-estado" style="text-align:center;">
              <?php 
                $val = intval($n['id_estado'] ?? 1); 
                $abbrNum = 'C';
                $badgeClass = 'badge-cursa';
                $nombreEstado = 'Cursa';
                if ($val === 2) { $abbrNum = 'R'; $badgeClass = 'badge-recursa'; $nombreEstado = 'Recursa'; }
                elseif ($val === 3) { $abbrNum = 'I'; $badgeClass = 'badge-intensifica'; $nombreEstado = 'Intensifica'; }
                elseif ($val === 4) { $abbrNum = 'A'; $badgeClass = 'badge-adeuda'; $nombreEstado = 'Adeuda'; }
                elseif ($val === 5) { $abbrNum = 'AP'; $badgeClass = 'badge-aprobada'; $nombreEstado = 'Aprobada'; }
              ?>
              <span class="badge-condicion <?php echo $badgeClass; ?>" title="<?php echo $nombreEstado; ?>"><?php echo $abbrNum; ?></span>
              <input type="hidden" name="rows[<?php echo $al['id_alumno']; ?>][id_estado]" value="<?php echo $val; ?>">
            </td>
            <td>
              <select class="mini-sel" name="rows[<?php echo $al['id_alumno']; ?>][valoracion_preliminar_1]" <?php echo $puedeEditar? '':'disabled class="readonly"'; ?>>
                <?php $val = $n['valoracion_preliminar_1'] ?? ''; ?>
                <option value="">-</option>
                <option value="TEA" <?php echo ($val==='TEA'?'selected':''); ?>>TEA</option>
                <option value="TEP" <?php echo ($val==='TEP'?'selected':''); ?>>TEP</option>
                <option value="TED" <?php echo ($val==='TED'?'selected':''); ?>>TED</option>
              </select>
            </td>
            <td>
              <?php $val = isset($n['calificacion_1']) ? (string)$n['calificacion_1'] : ''; ?>
              <select class="mini-sel mini-num" name="rows[<?php echo $al['id_alumno']; ?>][calificacion_1]" <?php echo $puedeEditar? '':'disabled class="readonly"'; ?>>
                <option value=""></option>
                <?php for($i=1;$i<=10;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($val==(string)$i?'selected':''); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <?php $cd1 = (int)($faltas[$al['id_alumno']]['cd1'] ?? 0); ?>
              <span class="inasist-show" title="Clases dictadas 1er cuatrimestre (P o A)"><?php echo number_format($cd1, 0, ',', '.'); ?></span>
            </td>
            <td>
              <?php $cnt1 = (int)($faltas[$al['id_alumno']]['f1'] ?? 0); ?>
              <span class="inasist-show" title="Inasistencias 1er cuatrimestre (solo lectura)"><?php echo number_format($cnt1, 0, ',', '.'); ?></span>
            </td>
            <td class="col-observaciones">
              <select class="sel-observacion" name="rows[<?php echo $al['id_alumno']; ?>][observacion_1]" <?php echo $puedeEditar? '':'disabled class="readonly"'; ?>>
                <option value="">-</option>
                <?php $sel = $n['observacion_1'] ?? ''; foreach ($obs as $o): ?>
                  <option value="<?php echo $o['id_observacion']; ?>" <?php echo ($sel==$o['id_observacion']?'selected':''); ?>><?php echo htmlspecialchars($o['descripcion']); ?></option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <select class="mini-sel" name="rows[<?php echo $al['id_alumno']; ?>][valoracion_preliminar_2]" <?php echo $puedeEditar? '':'disabled class="readonly"'; ?>>
                <?php $val = $n['valoracion_preliminar_2'] ?? ''; ?>
                <option value="">-</option>
                <option value="TEA" <?php echo ($val==='TEA'?'selected':''); ?>>TEA</option>
                <option value="TEP" <?php echo ($val==='TEP'?'selected':''); ?>>TEP</option>
                <option value="TED" <?php echo ($val==='TED'?'selected':''); ?>>TED</option>
              </select>
            </td>
            <td>
              <?php $val = isset($n['calificacion_2']) ? (string)$n['calificacion_2'] : ''; ?>
              <select class="mini-sel mini-num" name="rows[<?php echo $al['id_alumno']; ?>][calificacion_2]" <?php echo $puedeEditar? '':'disabled class="readonly"'; ?>>
                <option value=""></option>
                <?php for($i=1;$i<=10;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($val==(string)$i?'selected':''); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <?php $cd2 = (int)($faltas[$al['id_alumno']]['cd2'] ?? 0); ?>
              <span class="inasist-show" title="Clases dictadas 2° cuatrimestre (P o A)"><?php echo number_format($cd2, 0, ',', '.'); ?></span>
            </td>
            <td>
              <?php $cnt2 = (int)($faltas[$al['id_alumno']]['f2'] ?? 0); ?>
              <span class="inasist-show" title="Inasistencias 2° cuatrimestre (solo lectura)"><?php echo number_format($cnt2, 0, ',', '.'); ?></span>
            </td>
            <td class="col-observaciones">
              <select class="sel-observacion" name="rows[<?php echo $al['id_alumno']; ?>][observacion_2]" <?php echo $puedeEditar? '':'disabled class="readonly"'; ?>>
                <option value="">-</option>
                <?php $sel = $n['observacion_2'] ?? ''; foreach ($obs as $o): ?>
                  <option value="<?php echo $o['id_observacion']; ?>" <?php echo ($sel==$o['id_observacion']?'selected':''); ?>><?php echo htmlspecialchars($o['descripcion']); ?></option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <?php $val = isset($n['intensificacion_1']) ? (string)$n['intensificacion_1'] : ''; ?>
              <select class="mini-sel mini-num" name="rows[<?php echo $al['id_alumno']; ?>][intensificacion_1]" <?php echo $puedeEditar? '':'disabled class="readonly" data-server-locked="1"'; ?>>
                <option value=""></option>
                <option value="Cont. Int" <?php echo ($val==='Cont. Int'?'selected':''); ?>>Cont. Int</option>
                <?php for($i=7;$i<=10;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($val==(string)$i?'selected':''); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <?php $val = isset($n['diciembre']) ? (string)$n['diciembre'] : ''; ?>
              <select class="mini-sel mini-num" name="rows[<?php echo $al['id_alumno']; ?>][diciembre]" <?php echo $puedeEditar? '':'disabled class="readonly" data-server-locked="1"'; ?>>
                <option value=""></option>
                <option value="Cont. Int" <?php echo ($val==='Cont. Int'?'selected':''); ?>>Cont. Int</option>
                <?php for($i=4;$i<=10;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($val==(string)$i?'selected':''); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <?php $val = isset($n['febrero']) ? (string)$n['febrero'] : ''; ?>
              <select class="mini-sel mini-num" name="rows[<?php echo $al['id_alumno']; ?>][febrero]" <?php echo $puedeEditar? '':'disabled class="readonly" data-server-locked="1"'; ?>>
                <option value=""></option>
                <option value="Ausente" <?php echo ($val==='Ausente'?'selected':''); ?>>Ausente</option>
                <?php for($i=1;$i<=10;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($val==(string)$i?'selected':''); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <?php $val = isset($n['calificacion_final']) ? (string)$n['calificacion_final'] : ''; ?>
              <span class="final-show"><?php echo htmlspecialchars($val); ?></span>
              <input type="hidden" name="rows[<?php echo $al['id_alumno']; ?>][calificacion_final]" value="<?php echo htmlspecialchars($val); ?>">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if (!empty($alumnos_acreditacion)): ?>
      <h3 class="mt-4" style="margin-top:40px; border-bottom:2px solid #16682b; padding-bottom:5px;">
        <i class="fa fa-graduation-cap"></i> Intensificación - <?php echo htmlspecialchars($info['nombre_materia']); ?> (<?php echo htmlspecialchars($info['nombre_curso']); ?>)
      </h3>
      <div class="tabla-wrap">
      <table class="notas table-acreditacion">
        <thead>
          <tr>
            <th rowspan="2" class="sticky-num">#</th>
            <th rowspan="2" class="sticky-nombre col-nombre">Apellido y Nombre</th>
            <th colspan="2" style="background:#f1f3f4;">MARZO</th>
            <th colspan="2" style="background:#f1f3f4;">JULIO</th>
            <th colspan="2" style="background:#f1f3f4;">AGOSTO</th>
            <th colspan="2" style="background:#f1f3f4;">NOVIEMBRE</th>
            <th colspan="2" style="background:#f1f3f4;">DICIEMBRE</th>
            <th colspan="2" style="background:#f1f3f4;">FEBRERO</th>
            <th rowspan="2" style="background:#93c47d;">Calificación Final</th>
          </tr>
          <tr>
            <th style="font-size:10px;">Asistencia</th><th style="font-size:10px;">Valoración</th>
            <th style="font-size:10px;">Asistencia</th><th style="font-size:10px;">Valoración</th>
            <th style="font-size:10px;">Asistencia</th><th style="font-size:10px;">Valoración</th>
            <th style="font-size:10px;">Asistencia</th><th style="font-size:10px;">Valoración</th>
            <th style="font-size:10px;">Asistencia</th><th style="font-size:10px;">Valoración</th>
            <th style="font-size:10px;">Asistencia</th><th style="font-size:10px;">Valoración</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $meses = ['marzo','julio','agosto','noviembre','diciembre','febrero'];
          foreach ($alumnos_acreditacion as $al): 
            $n = $notas[intval($al['id_alumno'])] ?? [];
          ?>
          <tr>
            <td class="sticky-num"></td>
            <td class="sticky-nombre">
              <div style="font-weight:600; font-size:12px;">
                <?php echo htmlspecialchars($al['apellido'].', '.$al['nombre']); ?>
                <?php if (intval($al['id_curso_alumno']) !== intval($info['id_curso'])): ?>
                  <span style="color:#d32f2f; font-weight:700; margin-left:4px;">(<?php echo htmlspecialchars($al['curso_origen']); ?>)</span>
                <?php endif; ?>
              </div>
              <div style="font-size:10px; color:#666;">
                <?php 
                  $n_est = $notas[intval($al['id_alumno'])] ?? [];
                  $a_ad = $n_est['anio_adeuda'] ?? '';
                  $m_ad = $n_est['materia_adeuda'] ?? '';
                  if ($a_ad || $m_ad):
                ?>
                   <?php echo (intval($al['id_curso_alumno']) !== intval($info['id_curso']) ? ' | ' : ''); ?>
                   <span style="color:#d32f2f; font-weight:600;">Adeuda: <?php echo ($a_ad? $a_ad."º " : "").$m_ad; ?></span>
                <?php endif; ?>
                <?php if (!empty($al['otras_intensificaciones'])): ?>
                   | <span style="color:#e65100">Otras intensif.</span>
                <?php endif; ?>
              </div>
            </td>
            <?php foreach($meses as $mes): ?>
              <td>
                <select class="mini-sel" name="rows[<?php echo $al['id_alumno']; ?>][<?php echo $mes; ?>_asistencia]">
                  <option value="">-</option>
                  <?php foreach(['0%','25%','75%','100%'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo (($n[$mes.'_asistencia'] ?? '') === $opt ? 'selected':''); ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="mini-sel" name="rows[<?php echo $al['id_alumno']; ?>][<?php echo $mes; ?>_valoracion]">
                  <option value="">-</option>
                  <?php foreach(['CCA','CSA','AA'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo (($n[$mes.'_valoracion'] ?? '') === $opt ? 'selected':''); ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            <?php endforeach; ?>
            <td>
              <?php $val = isset($n['calificacion_final']) ? (string)$n['calificacion_final'] : ''; ?>
              <select class="mini-sel mini-num" name="rows[<?php echo $al['id_alumno']; ?>][calificacion_final]">
                <option value=""></option>
                <?php for($i=1;$i<=10;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($val == (string)$i ? 'selected':''); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>

      
    </form>
  </div>
  <script>
    function stepNum(btn, delta){
      var input = btn && btn.parentElement ? btn.parentElement.querySelector('input[type="number"]') : null;
      if(!input) return;
      var min = parseInt(input.min || '0', 10);
      var max = parseInt(input.max || '99', 10);
      var val = parseInt(input.value || '0', 10);
      if (isNaN(val)) val = 0;
      val = val + delta;
      if (val < min) val = min;
      if (val > max) val = max;
      input.value = val;
      var ev = new Event('input', { bubbles: true });
      input.dispatchEvent(ev);
    }

    document.addEventListener('input', function(e){
      if(e.target && e.target.matches('.stepper input[type="number"]')){
        var input = e.target;
        var min = parseInt(input.min || '0', 10);
        var max = parseInt(input.max || '99', 10);
        var val = parseInt(input.value || '0', 10);
        if (isNaN(val)) val = min;
        if (val < min) val = min;
        if (val > max) val = max;
        input.value = val;
      }
    });
    function spinAdjust(el, delta){
      var wrap = el.closest('.spin-wrap');
      var input = wrap ? wrap.querySelector('input[type="number"]') : null;
      if(!input) return;
      var min = parseInt(input.min || '0', 10);
      var max = parseInt(input.max || '99', 10);
      var curr = parseInt(input.value === '' ? (delta < 0 ? min : min) : input.value, 10);
      if (isNaN(curr)) curr = min;
      var next = curr + delta;
      if (next < min) next = min;
      if (next > max) next = max;
      input.value = next;
      var ev = new Event('input', { bubbles: true });
      input.dispatchEvent(ev);
    }
  </script>
  <script>
    function ncStep(btn, delta){
      var box = btn && btn.closest ? btn.closest('.numbox') : null;
      if(!box) return;
      var min = parseInt(box.getAttribute('data-min')||'0',10);
      var max = parseInt(box.getAttribute('data-max')||'99',10);
      var hidden = box.querySelector('input[type="hidden"]');
      var display = box.querySelector('.numshow');
      var curr = parseInt((hidden && hidden.value !== '' ? hidden.value : '0'), 10);
      if (isNaN(curr)) curr = min;
      var next = curr + delta;
      if (next < min) next = min;
      if (next > max) next = max;
      if (hidden) hidden.value = next;
      if (display) display.value = String(next);
    }

    (function(){
      var boxes = document.querySelectorAll('.numbox');
      boxes.forEach(function(box){
        var min = parseInt(box.getAttribute('data-min')||'0',10);
        var max = parseInt(box.getAttribute('data-max')||'99',10);
        var hidden = box.querySelector('input[type="hidden"]');
        var display = box.querySelector('.numshow');
        var curr = parseInt((hidden && hidden.value !== '' ? hidden.value : '0'), 10);
        if (isNaN(curr)) curr = min;
        if (curr < min) curr = min;
        if (curr > max) curr = max;
        if (hidden) hidden.value = curr;
        if (display) display.value = String(curr);
        if (display && !display.readOnly) {
          display.addEventListener('input', function(){
            var v = this.value.replace(/[^0-9]/g,'');
            if (v === '') { hidden.value = ''; return; }
            var num = parseInt(v,10);
            if (isNaN(num)) num = min;
            if (num < min) num = min;
            if (num > max) num = max;
            hidden.value = num;
            this.value = String(num);
          });
          display.addEventListener('blur', function(){
            if (this.value === '') { this.value = hidden.value || '0'; }
          });
        }
      });
    })();
  </script>
  <script>
    function estadoAbbrChange(sel){
      var wrap = sel && sel.closest ? sel.closest('.mini-sel-wrap') : null;
      if(!wrap) return;
      var abbr = (sel.value == '2') ? 'R' : 'C';
      wrap.setAttribute('data-abbr', abbr);
      try { saveFieldFromInput(sel); } catch(e){}
    }
  </script>
  <script>
    var ID_CM = <?php echo (int)$idCursoMateria; ?>;

    function parseName(name){
      var m = name.match(/^rows\[(\d+)\]\[(.+)\]$/);
      if(!m) return null;
      return { alumno: parseInt(m[1],10), campo: m[2] };
    }

    async function saveField(alumno, campo, valor){
      try{
        const body = new URLSearchParams();
        body.append('id', ID_CM);
        body.append('alumno', alumno);
        body.append('campo', campo);
        body.append('valor', valor==null? '': String(valor));
        const res = await fetch('rite_guardar_ajax.php',{
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: body.toString()
        });
        if(!res.ok){ console.warn('Autosave error', await res.text()); }
      }catch(err){ console.warn('Autosave fetch fail', err); }
    }

    function saveFieldFromInput(el){
      if(!el || !el.name) return;
      var meta = parseName(el.name);
      if(!meta) return;
      saveField(meta.alumno, meta.campo, el.value);
    }

    document.addEventListener('change', function(e){
      var t = e.target;
      if(t && t.tagName==='SELECT' && t.name && t.name.startsWith('rows[')){
        saveFieldFromInput(t);
      }
    });

    var _ncStepOrig = window.ncStep;
    window.ncStep = function(btn, delta){
      _ncStepOrig(btn, delta);
      var box = btn.closest('.numbox');
      if(!box) return;
      var hidden = box.querySelector('input[type="hidden"]');
      if(hidden) saveFieldFromInput(hidden);
    };

    function debounce(fn, ms){ let to; return function(){ clearTimeout(to); var args=arguments, ctx=this; to=setTimeout(function(){ fn.apply(ctx,args); }, ms); }; }
    var debouncedSaveFromHidden = debounce(function(hidden){ saveFieldFromInput(hidden); }, 350);

    document.querySelectorAll('.numbox').forEach(function(box){
      var hidden = box.querySelector('input[type="hidden"]');
      var show = box.querySelector('.numshow');
      if(show && !show.readOnly){
        show.addEventListener('input', function(){ if(hidden){ hidden.value = this.value; debouncedSaveFromHidden(hidden);} });
        show.addEventListener('blur', function(){ if(hidden){ hidden.value = this.value; saveFieldFromInput(hidden);} });
      }
    });
  </script>
  <script>
    // Cálculo dinámico de Calificación Final (no editable por usuario)
    (function(){
      function asNum(val){
        if (val == null) return null;
        var s = String(val).trim();
        if (/^\d+(?:[\.,]\d+)?$/.test(s)) return parseFloat(s.replace(',','.'));
        return null;
      }

      function fmt(val){
        if (val == null || val === '') return '';
        var n = Number(val);
        if (!isFinite(n)) return '';
        // siempre mostrar 2 decimales
        return n.toFixed(2);
      }

      function clamp10(x){ if (x == null || isNaN(x)) return null; if (x < 1) x = 1; if (x > 10) x = 10; return x; }
      function avg(a,b){ return (a + b) / 2; }

      function findSelect(row, field){
        return row.querySelector('select[name$="['+field+']"]');
      }
      function findFinalHidden(row){
        return row.querySelector('input[type="hidden"][name$="[calificacion_final]"]');
      }
      function findFinalShow(row){
        return row.querySelector('.final-show');
      }

      function setAndSaveFinal(row, newVal){
        var hidden = findFinalHidden(row);
        var show = findFinalShow(row);
        if (!hidden || !show) return;
        var v = (newVal==null? '' : fmt(newVal));
        if (hidden.value === v){ show.textContent = v; return; }
        hidden.value = v;
        show.textContent = v;
        try { saveFieldFromInput(hidden); } catch(e){}
      }

      function computeFinalForRow(row){
        if (!row) return;
        var sC1 = findSelect(row, 'calificacion_1');
        var sC2 = findSelect(row, 'calificacion_2');
        var sInt = findSelect(row, 'intensificacion_1');
        var sDic = findSelect(row, 'diciembre');
        var sFeb = findSelect(row, 'febrero');

        var vC1 = asNum(sC1 && sC1.value);
        var vC2 = asNum(sC2 && sC2.value);
        var vInt = asNum(sInt && sInt.value); // ignora 'Cont. Int' (no num)
        var vDic = asNum(sDic && sDic.value); // ignora 'Cont. Int' si no es num
        var febValRaw = sFeb ? sFeb.value : '';
        var vFeb = asNum(febValRaw); // 'Ausente' => null

        // Prioridad:
        // 1) Febrero: si num => final=febrero; si no num y completado => final vacía
        if (sFeb && febValRaw !== ''){
          if (vFeb != null){ setAndSaveFinal(row, clamp10(vFeb)); return; }
          setAndSaveFinal(row, ''); return;
        }

        // 2) Diciembre: si num >= 4 => final=diciembre
        if (vDic != null && vDic >= 4){ setAndSaveFinal(row, clamp10(vDic)); return; }

        // 3) Reglas con calificaciones e intensificación (con decimales)
        if (vC1 != null && vC2 != null && vC1 >= 7 && vC2 >= 7){
          setAndSaveFinal(row, clamp10(avg(vC1, vC2))); return;
        }
        if ((vC1 == null || vC1 < 7) && vInt != null && vInt >= 7 && vC2 != null && vC2 >= 7){
          setAndSaveFinal(row, clamp10(avg(vInt, vC2))); return;
        }
        // Nota: no promediar con Intensificación si calificación_2 < 7; dejar vacío hasta diciembre/febrero

        // 4) Si nada aplica, dejar vacío
        setAndSaveFinal(row, '');
      }

      // Deshabilitar intensificación cuando calificación_1 sea >= 7 (respetando bloqueo readonly del servidor)
      function updateIntensificacionUsability(row){
        var sC1 = findSelect(row, 'calificacion_1');
        var sInt = findSelect(row, 'intensificacion_1');
        if (!sInt) return;
        // Si viene bloqueado por el servidor (sin permisos), no tocar
        if (sInt.hasAttribute('data-server-locked')) return;
        var vC1 = asNum(sC1 && sC1.value);
        // Habilitar cuando calificacion_1 sea < 7; deshabilitar cuando sea >= 7 o vacía
        var enable = (vC1 != null && vC1 < 7);
        sInt.disabled = !enable;
        if (!enable) { sInt.classList.add('readonly'); } else { sInt.classList.remove('readonly'); }
      }

      // Habilitar Diciembre solo si calificacion_1 < 7 o calificacion_2 < 7
      function updateDiciembreUsability(row){
        var sC1 = findSelect(row, 'calificacion_1');
        var sC2 = findSelect(row, 'calificacion_2');
        var sDic = findSelect(row, 'diciembre');
        if (!sDic) return;
        if (sDic.hasAttribute('data-server-locked')) return; // respetar bloqueo del servidor
        var vC1 = asNum(sC1 && sC1.value);
        var vC2 = asNum(sC2 && sC2.value);
        var enable = ( (vC1 != null && vC1 < 7) || (vC2 != null && vC2 < 7) );
        if (!enable){
          sDic.disabled = true;
          sDic.classList.add('readonly');
          // Limpiar valor y autoguardar si estaba completo
          if (sDic.value !== ''){
            sDic.value = '';
            try { saveFieldFromInput(sDic); } catch(e){}
          }
          // Recalcular final por si dependía de diciembre
          computeFinalForRow(row);
        } else {
          sDic.disabled = false;
          sDic.classList.remove('readonly');
        }
      }

      // Habilitar Febrero solo si Diciembre tiene algún valor
      function updateFebreroUsability(row){
        var sDic = findSelect(row, 'diciembre');
        var sFeb = findSelect(row, 'febrero');
        if (!sFeb) return;
        if (sFeb.hasAttribute('data-server-locked')) return; // respetar bloqueo del servidor
        var hasDic = !!(sDic && sDic.value && sDic.value !== '');
        if (!hasDic){
          sFeb.disabled = true;
          sFeb.classList.add('readonly');
          // limpiar y autoguardar si había algo
          if (sFeb.value !== ''){
            sFeb.value = '';
            try { saveFieldFromInput(sFeb); } catch(e){}
          }
          computeFinalForRow(row); // final podría depender de feb
        } else {
          sFeb.disabled = false;
          sFeb.classList.remove('readonly');
        }
      }

      function onChangeMaybeRecalc(e){
        var t = e.target;
        if (!t || t.tagName !== 'SELECT' || !t.name) return;
        if (/\[(calificacion_1)\]$/.test(t.name)){
          var row = t.closest('tr');
          updateIntensificacionUsability(row);
          updateDiciembreUsability(row);
          updateFebreroUsability(row);
        }
        if (/\[(calificacion_2)\]$/.test(t.name)){
          var row = t.closest('tr');
          updateDiciembreUsability(row);
          updateFebreroUsability(row);
        }
        if (/\[(diciembre)\]$/.test(t.name)){
          var row = t.closest('tr');
          updateFebreroUsability(row);
        }
        if (!/\[(calificacion_1|calificacion_2|intensificacion_1|diciembre|febrero)\]$/.test(t.name)) return;
        var row = t.closest('tr');
        computeFinalForRow(row);
      }

      document.addEventListener('change', onChangeMaybeRecalc);

      // Inicial: calcular todas las filas al cargar (si el DOM ya está listo, correr de inmediato)
      function initAll(){
        var rows = document.querySelectorAll('table.notas tbody tr');
        rows.forEach(function(r){
          updateIntensificacionUsability(r);
          updateDiciembreUsability(r);
          updateFebreroUsability(r);
          computeFinalForRow(r);
        });
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
      } else {
        // DOM ya cargado
        initAll();
      }

      // Observar nuevas filas/controles que se inserten dinámicamente
      var tbody = document.querySelector('table.notas tbody');
      if (tbody && 'MutationObserver' in window){
        var mo = new MutationObserver(function(muts){
          muts.forEach(function(m){
            m.addedNodes && m.addedNodes.forEach(function(n){
              if (n.nodeType !== 1) return;
              if (n.tagName === 'TR'){
                updateIntensificacionUsability(n);
                updateDiciembreUsability(n);
                updateFebreroUsability(n);
                computeFinalForRow(n);
              } else {
                // Si se agrega un select dentro de una fila existente
                var tr = n.closest ? n.closest('tr') : null;
                if (tr) { updateIntensificacionUsability(tr); updateDiciembreUsability(tr); updateFebreroUsability(tr); computeFinalForRow(tr); }
              }
            });
          });
        });
        mo.observe(tbody, { childList: true, subtree: true });
      }

      // Fallback: recargar una vez tras cambiar calificaciones, por si el render diferido impide init en todas las filas
      var _pendingReload = false;
      function scheduleReload(){
        if (_pendingReload) return;
        _pendingReload = true;
        setTimeout(function(){ try { location.reload(); } catch(e){} }, 800);
      }
    })();
  </script>
  
  <script>
    // Navegación tipo Excel para inputs/selects de RITE
    (function(){
      function findTd(el){
        let n = el;
        while(n && n.tagName !== 'TD') n = n.parentElement;
        return n;
      }

      function editableControlInCell(td){
        if (!td) return null;
        // Prioridad: select, input.numshow (display), hidden no es enfocable
        let sel = td.querySelector('select');
        if (sel && !sel.disabled) return sel;
        let num = td.querySelector('input.numshow');
        if (num && !num.readOnly) return num;
        // Otros inputs de texto/number
        let any = td.querySelector('input, select, textarea');
        if (any && !(any.disabled || any.readOnly)) return any;
        return null;
      }

      function focusSameColInRow(row, cellIndex, dir){
        let r = row;
        while (r) {
          r = dir > 0 ? r.nextElementSibling : r.previousElementSibling;
          if (!r) return;
          const tds = r.children;
          if (cellIndex < tds.length) {
            const td = tds[cellIndex];
            if (td && !td.classList.contains('sticky-num')) {
              const ctrl = editableControlInCell(td);
              if (ctrl) { ctrl.focus(); if (ctrl.select) ctrl.select(); return; }
            }
          }
        }
      }

      function moveHorizontalFromCell(td, dir){
        let cur = td;
        while (cur) {
          cur = dir > 0 ? cur.nextElementSibling : cur.previousElementSibling;
          if (!cur) return;
          if (cur.classList && cur.classList.contains('sticky-num')) continue;
          const ctrl = editableControlInCell(cur);
          if (ctrl) { ctrl.focus(); if (ctrl.select) ctrl.select(); return; }
        }
      }

      // Atajos para selects específicos
      function handleValueShortcuts(el, key){
        // Estado Cursa/Recursa
        if (el.tagName === 'SELECT' && /\[estado\]$/.test(el.name)) {
          if (key === 'c' || key === 'C') { el.value = 'cursa'; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
          if (key === 'r' || key === 'R') { el.value = 'recursa'; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
        }
        // Valoración preliminar (A->TEA, P->TEP, D->TED)
        if (el.tagName === 'SELECT' && /\[valoracion_preliminar_(1|2)\]$/.test(el.name)) {
          if (key === 'a' || key === 'A') { el.value = 'TEA'; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
          if (key === 'p' || key === 'P') { el.value = 'TEP'; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
          if (key === 'd' || key === 'D') { el.value = 'TED'; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
        }
        // Selects mini-num (1..10)
        if (el.tagName === 'SELECT' && el.classList.contains('mini-num')) {
          if (key >= '0' && key <= '9') {
            // 0 lo tomamos como 10
            const v = (key === '0') ? '10' : key;
            const opt = Array.from(el.options).find(o => o.value === v);
            if (opt) { el.value = v; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
          }
        }
        // numshow: permitir + / -
        if (el.classList && el.classList.contains('numshow')) {
          const box = el.closest('.numbox');
          if (!box) return false;
          if (key === '+') { window.ncStep(box.querySelector('.btn.up')||box, +1); return true; }
          if (key === '-') { window.ncStep(box.querySelector('.btn.down')||box, -1); return true; }
        }
        return false;
      }

      document.addEventListener('keydown', function(e){
        const el = document.activeElement;
        if (!el) return;
        // Solo si estamos en controles editables de la grilla
        const isGridCtrl = (
          (el.tagName === 'SELECT' || el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') &&
          el.name && el.name.startsWith('rows[')
        ) || (el.classList && el.classList.contains('numshow'));
        if (!isGridCtrl) return;

        const td = findTd(el);
        if (!td) return;
        const row = td.parentElement;

        const key = e.key;

        // Atajos de valor (C/R, dígitos, +/-)
        if (handleValueShortcuts(el, key)) { e.preventDefault(); return; }

        // Enter: ir al mismo índice de columna en la siguiente fila
        if (key === 'Enter') {
          e.preventDefault();
          focusSameColInRow(row, td.cellIndex, +1);
          return;
        }

        // Flechas verticales
        if (key === 'ArrowDown') { e.preventDefault(); focusSameColInRow(row, td.cellIndex, +1); return; }
        if (key === 'ArrowUp')   { e.preventDefault(); focusSameColInRow(row, td.cellIndex, -1); return; }

        // Flechas horizontales: moverse a la siguiente celda editable
        if (key === 'ArrowRight') { e.preventDefault(); moveHorizontalFromCell(td, +1); return; }
        if (key === 'ArrowLeft')  { e.preventDefault(); moveHorizontalFromCell(td, -1); return; }
      }, true);
    })();
  </script>

</body>
</html>
