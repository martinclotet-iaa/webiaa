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
$info = $conexion->query("SELECT cm.id_curso_materia, c.id_curso, c.nombre_curso, m.id_materia, m.nombre_materia
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
} else {
    $puedeEditar = false;
}

// POST (AJAX): guardar un solo campo (ahora que $puedeEditar está definido)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='guardar_campo' && $puedeEditar) {
    header('Content-Type: application/json');
    $idAlumno = isset($_POST['id_alumno']) ? intval($_POST['id_alumno']) : 0;
    $campo = isset($_POST['campo']) ? preg_replace('/[^a-z_]/','', $_POST['campo']) : '';
    $valor = isset($_POST['valor']) ? $_POST['valor'] : null;
    $permitidos = [
        'marzo_asistencia','marzo_valoracion',
        'julio_asistencia','julio_valoracion',
        'agosto_asistencia','agosto_valoracion',
        'noviembre_asistencia','noviembre_valoracion',
        'diciembre_asistencia','diciembre_valoracion',
        'febrero_asistencia','febrero_valoracion',
        'calificacion_final'
    ];
    if ($idAlumno<=0 || !in_array($campo, $permitidos, true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'msg'=>'Parámetros inválidos']);
        exit;
    }
    if ($campo === 'calificacion_final') {
        $valSQL = ($valor===''||$valor===null) ? 'NULL' : intval($valor);
    } else {
        $valSQL = ($valor===''||$valor===null) ? 'NULL' : "'".$conexion->real_escape_string($valor)."'";
    }
    $sql = "INSERT INTO notas (id_alumno, id_curso_materia, $campo, id_estado) VALUES ($idAlumno, $idCursoMateria, $valSQL, 3) "
         . "ON DUPLICATE KEY UPDATE $campo=$valSQL, id_estado=3";
    $ok = $conexion->query($sql);
    echo json_encode(['ok'=> (bool)$ok]);
    exit;
}

// Catálogos para selects
$optsAsistencia = ['0%','25%','75%','100%'];
$optsValoracion = ['CCA','CSA','AA'];

// POST: agregar intensificación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='agregar' && $puedeEditar) {
    $idAlumnoNuevo = intval($_POST['id_alumno'] ?? 0);
    if ($idAlumnoNuevo>0) {
        // Insertar/Actualizar registro de intensificación (estado 3 = Intensifica)
        $conexion->query("INSERT INTO notas (id_alumno, id_curso_materia, id_estado) 
                         VALUES ($idAlumnoNuevo, $idCursoMateria, 3)
                         ON DUPLICATE KEY UPDATE id_estado = 3");
    }
    header('Location: notas_intensificacion.php?id='.$idCursoMateria);
    exit;
}

// POST: eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='eliminar' && $puedeEditar) {
    $idAlumnoDel = intval($_POST['id_alumno'] ?? 0);
    if ($idAlumnoDel>0) {
        $conexion->query("UPDATE notas SET id_estado = 1 WHERE id_alumno=$idAlumnoDel AND id_curso_materia=$idCursoMateria AND id_estado IN (3, 4)");
    }
    header('Location: notas_intensificacion.php?id='.$idCursoMateria);
    exit;
}

// POST: guardar filas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='guardar' && $puedeEditar) {
    $rows = $_POST['rows'] ?? [];
    foreach ($rows as $idAlumno => $r) {
        $idAlumno = intval($idAlumno);
        $fields = [
            'marzo_asistencia','marzo_valoracion',
            'julio_asistencia','julio_valoracion',
            'agosto_asistencia','agosto_valoracion',
            'noviembre_asistencia','noviembre_valoracion',
            'diciembre_asistencia','diciembre_valoracion',
            'febrero_asistencia','febrero_valoracion',
            'calificacion_final'
        ];
        $vals = [];
        foreach ($fields as $f) {
            if ($f==='calificacion_final') {
                $v = $r[$f] ?? null; $v = ($v===''?null:$v);
                $vals[$f] = is_null($v)?'NULL':intval($v);
            } else {
                $v = $r[$f] ?? null; $v = ($v===''?null:$v);
                $vals[$f] = is_null($v)?'NULL':"'".$conexion->real_escape_string($v)."'";
            }
        }
        $sql = "INSERT INTO notas (id_alumno, id_curso_materia, id_estado, ".implode(',', array_keys($vals)).") VALUES (".
               "$idAlumno, $idCursoMateria, 3, ".implode(',', array_values($vals)).") ".
               "ON DUPLICATE KEY UPDATE id_estado=3, ".
               implode(',', array_map(function($k,$v){ return "$k=$v"; }, array_keys($vals), array_values($vals)));
        $conexion->query($sql);
    }
    header('Location: notas_intensificacion.php?id='.$idCursoMateria.'&ok=1');
    exit;
}

// Alumnos con intensificación para este curso-materia
$rows = $conexion->query("SELECT i.*, a.id_alumno, u.apellido, u.nombre
                          FROM notas i
                          INNER JOIN alumnos a ON a.id_alumno = i.id_alumno
                          INNER JOIN usuarios u ON u.id_usuario = a.id_usuario
                          WHERE i.id_curso_materia = $idCursoMateria AND i.id_estado IN (3, 4)
                          ORDER BY u.apellido, u.nombre")->fetch_all(MYSQLI_ASSOC);

// Catálogo de alumnos del curso para agregar
$catalogoAlumnos = $conexion->query("SELECT a.id_alumno, u.apellido, u.nombre
                                     FROM alumnos a INNER JOIN usuarios u ON u.id_usuario=a.id_usuario
                                     WHERE a.id_curso = ".$info['id_curso']." ORDER BY u.apellido, u.nombre")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Intensificación - <?php echo htmlspecialchars($info['nombre_materia'].' - '.$info['nombre_curso']); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
  <style>
    :root { --verde:#16682b; --w-num:30px; --left-nombre: var(--w-num); --thead1-h: 36px; }
    .container { padding:16px; }
    .titulo-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .btn-volver { display:inline-flex; align-items:center; gap:8px; background:#16682b; color:#fff; border:1px solid #16682b; padding:6px 12px; border-radius:8px; font-size:14px; text-decoration:none; }
    .toolbar { display:flex; gap:10px; align-items:center; margin: 12px 0; }
    .btn { padding:6px 10px; border:none; border-radius:6px; cursor:pointer; }
    .btn-primary { background:#16682b; color:#fff; }
    .tabla-wrap { overflow:auto; border:1px solid #e5e5e5; border-radius:10px; max-height:70vh; position: relative; contain: paint; }
    table.intens { width: max-content; table-layout: fixed; border-collapse: separate; border-spacing:0; background:#fff; color:#000; }
    table.intens th, table.intens td { border:1px solid #e5e5e5; padding:6px 8px; font-size:13px; box-sizing:border-box; }
    .sticky-num { position: sticky; left: 0; width: var(--w-num); min-width: var(--w-num); max-width: var(--w-num); text-align:center; background:#fff; z-index: 2001; padding: 0 !important; box-shadow: none; border-right: 0; }
    .sticky-nombre { position: sticky; left: var(--left-nombre); width:270px; min-width:270px; max-width:270px; text-align: left; background:#fff; z-index: 2000; box-shadow: none; border-right: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .nombre-wrap { width:270px; max-width:270px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* Encabezados: evitar desborde y dibujar líneas verdes */
    #tablaIntens thead #trHead2 th { white-space: nowrap; padding: 2px 6px; font-size: 12px; position: sticky; top: var(--thead1-h); z-index: 90; background:#f7f7f9; background-clip: padding-box; box-shadow: inset 0 -2px 0 var(--verde); }
    #tablaIntens thead #trHead1 th { height: var(--thead1-h); background:#f7f7f9; box-shadow: inset 0 -2px 0 var(--verde); position: sticky; top: 0; z-index: 100; background-clip: padding-box; }
    /* Prioridad de superposición como en Asistencias: thead por encima de tbody */
    #tablaIntens thead #trHead1 .sticky-num { position: sticky; top: 0; left: 0; z-index: 3003 !important; background:#f7f7f9; background-clip: padding-box; }
    #tablaIntens thead #trHead1 .sticky-nombre { position: sticky; top: 0; left: var(--left-nombre); z-index: 3002 !important; background:#f7f7f9; background-clip: padding-box; }
    #tablaIntens thead #trHead2 .sticky-num { position: sticky; top: var(--thead1-h); left: 0; z-index: 3003 !important; background:#f7f7f9; background-clip: padding-box; }
    #tablaIntens thead #trHead2 .sticky-nombre { position: sticky; top: var(--thead1-h); left: var(--left-nombre); z-index: 3002 !important; background:#f7f7f9; background-clip: padding-box; }
    #tablaIntens tbody .sticky-num { z-index: 3001; background:#fff; background-clip: padding-box; }
    #tablaIntens tbody .sticky-nombre { z-index: 3000; background:#fff; background-clip: padding-box; }
    #tablaIntens thead th.left-sep { position: sticky; top: 0; background:#f7f7f9; background-clip: padding-box; }
    #tablaIntens thead th.left-sep::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 2px; background: var(--verde); }
    .mini { width:86px; }
    .mini select, .mini input { width:100%; box-sizing:border-box; }
    .badge-recursante { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; background:#000; color:#fff; border-radius:50%; font-size:11px; font-weight:bold; margin-left:6px; }
    /* Autosave feedback */
    .saving { outline: 2px dashed #ffc107; outline-offset: -2px; }
    .saved-ok { outline: 2px solid #198754; outline-offset: -2px; }
    .saved-err { outline: 2px solid #dc3545; outline-offset: -2px; }
    /* Floating status pill */
    #saveStatus { position: fixed; right: 16px; bottom: 16px; background:#6c757d; color:#fff; padding:6px 10px; border-radius:8px; font-size:12px; box-shadow:0 2px 8px rgba(0,0,0,.15); display:none; z-index: 4000; }
    #saveStatus.status-saving { background:#ffc107; color:#212529; display:inline-block; }
    #saveStatus.status-ok { background:#198754; color:#fff; display:inline-block; }
    #saveStatus.status-err { background:#dc3545; color:#fff; display:inline-block; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container">
  <div class="titulo-bar">
    <h2 class="titulo"><i class="fa fa-fire"></i> Intensificación - <?php echo htmlspecialchars($info['nombre_materia'].' ('.$info['nombre_curso'].')'); ?></h2>
    <a href="mismaterias.php" class="btn-volver"><i class="fa fa-arrow-left"></i> Volver</a>
  </div>

  <?php /* Se quita el formulario de agregado en esta vista */ ?>

  <form method="post">
    <input type="hidden" name="accion" value="guardar">
    <div class="tabla-wrap">
    <table class="intens" id="tablaIntens">
      <thead>
        <tr id="trHead1">
          <th class="sticky-num" rowspan="2">#</th>
          <th class="sticky-nombre" rowspan="2"><div class="nombre-wrap" style="text-align:center; width:100%;">Apellido y Nombre</div></th>
          <th class="left-sep" colspan="2">Marzo</th>
          <th class="left-sep" colspan="2">Julio</th>
          <th class="left-sep" colspan="2">Agosto</th>
          <th class="left-sep" colspan="2">Noviembre</th>
          <th class="left-sep" colspan="2">Diciembre</th>
          <th class="left-sep" colspan="2">Febrero</th>
          <th class="left-sep" rowspan="2">Calif. Final</th>
        </tr>
        <tr id="trHead2">
          <th class="left-sep">Asist.</th><th>Valorac.</th>
          <th class="left-sep">Asist.</th><th>Valorac.</th>
          <th class="left-sep">Asist.</th><th>Valorac.</th>
          <th class="left-sep">Asist.</th><th>Valorac.</th>
          <th class="left-sep">Asist.</th><th>Valorac.</th>
          <th class="left-sep">Asist.</th><th>Valorac.</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($rows as $r): ?>
          <tr>
            <td class="sticky-num"><?php echo $i++; ?></td>
            <td class="sticky-nombre"><div class="nombre-wrap">&nbsp;<?php echo htmlspecialchars($r['apellido'].', '.$r['nombre']); ?></div></td>
            <?php
              $idA = intval($r['id_alumno']);
              $row = $r; // alias
              $sa = function($name,$opts,$val,$ro) use($idA){
                $dis = $ro? ' disabled class="readonly"' : '';
                $html = '<select name="rows['.$idA.']['.$name.']"'.$dis.'>';
                $html .= '<option value=""></option>';
                foreach($opts as $o){
                  $sel = ($val===$o)?' selected':'';
                  $html .= '<option value="'.$o.'"'.$sel.'>'.$o.'</option>';
                }
                $html .= '</select>';
                return $html;
              };
              $si = function($name,$val,$ro) use($idA){
                $dis = $ro? ' readonly' : '';
                $v = htmlspecialchars((string)$val);
                return '<input type="number" name="rows['.$idA.']['.$name.']" min="1" max="10" step="1" value="'.$v.'"'.$dis.' />';
              };
              $ro = !$puedeEditar;
            ?>
            <td class="mini"><?php echo $sa('marzo_asistencia',$optsAsistencia,$row['marzo_asistencia']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('marzo_valoracion',$optsValoracion,$row['marzo_valoracion']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('julio_asistencia',$optsAsistencia,$row['julio_asistencia']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('julio_valoracion',$optsValoracion,$row['julio_valoracion']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('agosto_asistencia',$optsAsistencia,$row['agosto_asistencia']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('agosto_valoracion',$optsValoracion,$row['agosto_valoracion']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('noviembre_asistencia',$optsAsistencia,$row['noviembre_asistencia']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('noviembre_valoracion',$optsValoracion,$row['noviembre_valoracion']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('diciembre_asistencia',$optsAsistencia,$row['diciembre_asistencia']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('diciembre_valoracion',$optsValoracion,$row['diciembre_valoracion']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('febrero_asistencia',$optsAsistencia,$row['febrero_asistencia']??null,$ro); ?></td>
            <td class="mini"><?php echo $sa('febrero_valoracion',$optsValoracion,$row['febrero_valoracion']??null,$ro); ?></td>
            <td class="mini"><?php echo $si('calificacion_final',$row['calificacion_final']??null,$ro); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </form>
  <div id="saveStatus" aria-live="polite" role="status"></div>
</div>
</body>
<script>
(function(){
  var canEdit = <?php echo $puedeEditar ? 'true' : 'false'; ?>;
  if(!canEdit) return;

  function parseName(name){
    var m = name && name.match(/^rows\[(\d+)\]\[(.+)\]$/);
    if(!m) return null;
    return { alumno: parseInt(m[1],10), campo: m[2] };
  }

  function setStatus(kind, text){
    var el = document.getElementById('saveStatus');
    if(!el) return;
    el.className = '';
    el.textContent = text || '';
    if(kind==='saving') el.classList.add('status-saving');
    else if(kind==='ok') el.classList.add('status-ok');
    else if(kind==='err') el.classList.add('status-err');
    if(text) el.style.display = 'inline-block';
    if(kind==='ok') setTimeout(function(){ el.style.display='none'; }, 1500);
  }

  async function autosave(el){
    var info = parseName(el.name);
    if(!info) return;
    var val = (el.type === 'number') ? el.value : el.value;
    el.classList.remove('saved-ok','saved-err');
    el.classList.add('saving');
    setStatus('saving','Guardando...');
    try{
      var fd = new FormData();
      fd.append('accion','guardar_campo');
      fd.append('id_alumno', String(info.alumno));
      fd.append('campo', info.campo);
      fd.append('valor', val);
      var resp = await fetch('notas_intensificacion.php?id=<?php echo (int)$idCursoMateria; ?>', { method:'POST', body: fd, credentials:'same-origin' });
      var data = await resp.json();
      el.classList.remove('saving');
      if(data && data.ok){
        el.classList.add('saved-ok');
        setStatus('ok','Guardado');
        setTimeout(function(){ el.classList.remove('saved-ok'); }, 1200);
      } else {
        el.classList.add('saved-err');
        setStatus('err','Error al guardar');
      }
    } catch(e){
      el.classList.remove('saving');
      el.classList.add('saved-err');
      setStatus('err','Error al guardar');
    }
  }

  var inputs = document.querySelectorAll('#tablaIntens select, #tablaIntens input[type="number"]');
  inputs.forEach(function(el){
    el.addEventListener('change', function(){ autosave(el); });
    if(el.type === 'number'){
      el.addEventListener('blur', function(){ autosave(el); });
      el.addEventListener('keydown', function(ev){ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
    }
  });
})();
</script>
</html>
