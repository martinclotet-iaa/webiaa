<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

// Control de acceso dinámico basado en menús
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 0;
$idUsuario = intval($_SESSION['id_usuario']);
$puedeEditar = ($perfilActual === 4); // Solo admin (4) puede editar. Preceptor (5) solo lee.

$preceptorCursos = [];
if ($perfilActual === 5) {
    // Si es preceptor, obtener sus cursos asignados
    $resCP = $conexion->query("SHOW TABLES LIKE 'curso_preceptor'");
    if ($resCP && $resCP->num_rows > 0) {
        $res = $conexion->query("SELECT id_curso FROM curso_preceptor WHERE id_preceptor = $idUsuario");
    } else {
        $res = $conexion->query("SELECT id_curso FROM cursos WHERE id_preceptor = $idUsuario");
    }
    if ($res) {
        while ($row = $res->fetch_row()) { $preceptorCursos[] = (int)$row[0]; }
    }
}

// Cargar catálogo de Curso-Materia agrupado por Materia
$res_cm = $conexion->query(
    "SELECT cm.id_curso_materia, c.nombre_curso, m.nombre_materia, m.id_materia, m.tipo
     FROM curso_materia cm
     INNER JOIN cursos c ON cm.id_curso = c.id_curso
     INNER JOIN materias m ON cm.id_materia = m.id_materia
     ORDER BY m.nombre_materia, c.nombre_curso"
);
$materias_agrupadas = [];
while ($row = $res_cm->fetch_assoc()) {
    $materias_agrupadas[$row['nombre_materia']][] = $row;
}

// Catálogo de todas las materias disponibles
$res_todas_mat = $conexion->query("SELECT nombre_materia FROM materias ORDER BY nombre_materia");
$lista_materias_unicas = $res_todas_mat->fetch_all(MYSQLI_ASSOC);

// Catálogo de alumnos para alta global de intensificación (solo si puede editar)
$alumnos_catalogo = [];
if ($puedeEditar) {
    $alumnos_catalogo = $conexion->query(
        "SELECT a.id_alumno, u.apellido, u.nombre, c.nombre_curso
         FROM alumnos a
         INNER JOIN usuarios u ON a.id_usuario = u.id_usuario
         INNER JOIN cursos c ON a.id_curso = c.id_curso
         ORDER BY u.apellido, u.nombre"
    )->fetch_all(MYSQLI_ASSOC);
}

// Asignar materia a un alumno como 'intensificación'
if (isset($_POST['accion']) && $_POST['accion'] === 'asignar_intensificacion') {
    if (!$puedeEditar) { echo json_encode(['success' => false, 'message' => 'No tiene permisos']); exit; }
    header('Content-Type: application/json');
    $id_alumno = intval($_POST['id_alumno'] ?? 0);
    $id_curso_materia = intval($_POST['id_curso_materia'] ?? 0);
    if ($id_alumno <= 0 || $id_curso_materia <= 0) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        exit;
    }
    // Evitar duplicados
    $existe = $conexion->query(
        "SELECT 1 FROM notas WHERE id_alumno={$id_alumno} AND id_curso_materia={$id_curso_materia} AND id_estado IN (3,4) LIMIT 1"
    );
    if ($existe && $existe->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'La materia ya está marcada como intensificación para este alumno']);
        exit;
    }
    $anio_adeuda = intval($_POST['anio_adeuda'] ?? 0);
    $materia_adeuda = $conexion->real_escape_string($_POST['materia_adeuda'] ?? '');
    
    // Insertar/Actualizar registro de intensificación (estado 3 = Intensifica)
    $sql = "INSERT INTO notas (id_alumno, id_curso_materia, id_estado, anio_adeuda, materia_adeuda) 
            VALUES ({$id_alumno}, {$id_curso_materia}, 3, ".($anio_adeuda?$anio_adeuda:'NULL').", ".($materia_adeuda?"'{$materia_adeuda}'":'NULL').")
            ON DUPLICATE KEY UPDATE id_estado = 3, anio_adeuda = ".($anio_adeuda?$anio_adeuda:'NULL').", materia_adeuda = ".($materia_adeuda?"'{$materia_adeuda}'":'NULL');
    $ok = $conexion->query($sql);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// Eliminar una asignación de intensificación
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar_intensificacion') {
    if (!$puedeEditar) { echo json_encode(['success' => false, 'message' => 'No tiene permisos']); exit; }
    header('Content-Type: application/json');
    $id_alumno = intval($_POST['id_alumno'] ?? 0);
    $id_curso_materia = intval($_POST['id_curso_materia'] ?? 0);
    if ($id_alumno <= 0 || $id_curso_materia <= 0) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        exit;
    }
    // Volver a estado 'Cursa' (1) o simplemente quitar el estado de intensificación
    $ok = $conexion->query(
        "UPDATE notas SET id_estado = 1 WHERE id_alumno={$id_alumno} AND id_curso_materia={$id_curso_materia} AND id_estado IN (3,4)"
    );
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// Editar el curso_materia asignado a una intensificación
if (isset($_POST['accion']) && $_POST['accion'] === 'editar_intensificacion') {
    if (!$puedeEditar) { echo json_encode(['success' => false, 'message' => 'No tiene permisos']); exit; }
    header('Content-Type: application/json');
    $id_alumno          = intval($_POST['id_alumno'] ?? 0);
    $id_cm_viejo        = intval($_POST['id_curso_materia_viejo'] ?? 0);
    $id_cm_nuevo        = intval($_POST['id_curso_materia_nuevo'] ?? 0);
    $anio_adeuda        = intval($_POST['anio_adeuda'] ?? 0);
    $materia_adeuda     = $conexion->real_escape_string($_POST['materia_adeuda'] ?? '');
    if ($id_alumno <= 0 || $id_cm_viejo <= 0 || $id_cm_nuevo <= 0) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        exit;
    }
    if ($id_cm_nuevo === $id_cm_viejo) {
        // Solo actualizar info adicional
        $ok = $conexion->query(
            "UPDATE notas SET anio_adeuda=".($anio_adeuda?$anio_adeuda:'NULL').", materia_adeuda=".($materia_adeuda?"'{$materia_adeuda}'":'NULL')."
             WHERE id_alumno={$id_alumno} AND id_curso_materia={$id_cm_viejo} AND id_estado IN (3,4)"
        );
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }
    // Verificar que no exista ya el nuevo
    $existe = $conexion->query(
        "SELECT 1 FROM notas WHERE id_alumno={$id_alumno} AND id_curso_materia={$id_cm_nuevo} AND id_estado IN (3,4) LIMIT 1"
    );
    if ($existe && $existe->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'El alumno ya tiene esa materia/curso como intensificación']);
        exit;
    }
    // Actualizar registro viejo al nuevo curso_materia
    $ok = $conexion->query(
        "UPDATE notas SET id_curso_materia={$id_cm_nuevo}, anio_adeuda=".($anio_adeuda?$anio_adeuda:'NULL').", materia_adeuda=".($materia_adeuda?"'{$materia_adeuda}'":'NULL')."
         WHERE id_alumno={$id_alumno} AND id_curso_materia={$id_cm_viejo} AND id_estado IN (3,4)"
    );
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// Filtros básicos
$filtroCurso = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$busqueda    = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : "";

// Obtener alumnos y sus materias en intensificación
$sql = "
    SELECT a.id_alumno, u.id_usuario, u.nombre, u.apellido, u.email, c.id_curso, c.nombre_curso,
           GROUP_CONCAT(DISTINCT CONCAT(c2.nombre_curso, ' - ', m.nombre_materia, ' (', IFNULL(m.tipo, 'Programática'), ')', '|||', cm.id_curso_materia, '|||', IFNULL(i.anio_adeuda,''), '|||', IFNULL(i.materia_adeuda,''))
                        ORDER BY c2.nombre_curso, m.nombre_materia SEPARATOR ';;') AS intensificaciones
    FROM alumnos a
    INNER JOIN usuarios u ON a.id_usuario = u.id_usuario
    INNER JOIN cursos c ON a.id_curso = c.id_curso
    INNER JOIN notas i ON i.id_alumno = a.id_alumno AND i.id_estado IN (3, 4)
    LEFT JOIN curso_materia cm ON i.id_curso_materia = cm.id_curso_materia
    LEFT JOIN cursos c2 ON cm.id_curso = c2.id_curso
    LEFT JOIN materias m ON cm.id_materia = m.id_materia
    WHERE 1=1
";
if ($perfilActual === 5) {
    if (!empty($preceptorCursos)) {
        $ids = implode(',', $preceptorCursos);
        $sql .= " AND a.id_curso IN ($ids)";
    } else {
        $sql .= " AND 1=0";
    }
}
if ($filtroCurso > 0) $sql .= " AND a.id_curso={$filtroCurso}";
if ($busqueda !== "") $sql .= " AND (u.nombre LIKE '%{$busqueda}%' OR u.apellido LIKE '%{$busqueda}%' OR u.email LIKE '%{$busqueda}%')";
$sql .= " GROUP BY a.id_alumno ORDER BY c.nombre_curso, u.apellido, u.nombre";
$alumnos = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

// Respuesta parcial (solo lista)
if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    ob_start(); ?>
    <div class="docentes-lista">
      <?php if (empty($alumnos)): ?>
        <p style="color:#888; text-align:center; padding:30px;">No se encontraron alumnos en intensificación.</p>
      <?php else: ?>
      <?php foreach ($alumnos as $a): ?>
        <?php if (empty($a['intensificaciones'])) continue; ?>
        <div class="docente-row">
          <div class="docente-row-content">
            <div class="docente-nombre"><?= htmlspecialchars($a['apellido'].', '.$a['nombre']) ?></div>
            <div class="docente-sub">
                <span><i class="fa fa-graduation-cap"></i> <?= htmlspecialchars($a['nombre_curso']) ?></span>
                <span><i class="fa fa-envelope"></i> <?= htmlspecialchars($a['email']) ?></span>
            </div>
            <div class="docente-materias" id="materias-<?= $a['id_alumno'] ?>">
                <?php 
                $items = explode(';;', $a['intensificaciones']);
                foreach ($items as $it):
                    if (!$it) continue;
                    $parts = explode('|||', $it);
                    $label = $parts[0] ?? '';
                    $id_cm = $parts[1] ?? 0;
                    $anio_a = $parts[2] ?? '';
                    $mat_a = $parts[3] ?? '';
                    
                    $curso_m = $label;
                    if (strpos($label, ' - ') !== false) {
                        $curso_m = str_replace(' - ', ' • ', $label);
                    }
                    
                    $extra_info = "";
                    if ($anio_a || $mat_a) {
                        $extra_info = " <small style='opacity:0.8'>(" . ($anio_a ? $anio_a."º año" : "") . ($anio_a && $mat_a ? " • " : "") . ($mat_a ? $mat_a : "") . ")</small>";
                    }
                ?>
                    <span class="materia-badge" data-alumno="<?= $a['id_alumno'] ?>" data-cm="<?= intval($id_cm) ?>" data-anio="<?= htmlspecialchars($anio_a) ?>" data-materia-adeuda="<?= htmlspecialchars($mat_a) ?>">
                        <i class="fa fa-fire"></i> <?= htmlspecialchars($curso_m) ?><?= $extra_info ?>
                        <?php if ($puedeEditar): ?>
                            <button type="button" onclick='abrirModalEditar(<?= $a["id_alumno"] ?>, <?= intval($id_cm) ?>, <?= json_encode($anio_a) ?>, <?= json_encode($mat_a) ?>)' class="badge-edit" title="Editar materia"><i class="fa fa-pencil"></i></button>
                            <button type="button" onclick="quitarIntensificacion(<?= $a['id_alumno'] ?>, <?= intval($id_cm) ?>, this, event)" class="badge-remove" title="Quitar materia">×</button>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
                <?php if ($puedeEditar): ?>
                    <button type="button" onclick="abrirModalAgregar(<?= $a['id_alumno'] ?>)" class="btn-agregar-badge">
                        <i class="fa fa-plus"></i> Materia
                    </button>
                <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Intensifican</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css?v=<?= time(); ?>">
  <style>
    #confirm-dialog { display:none; position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; }
    #confirm-dialog .confirm-dialog-content { background:#fff; border-radius:8px; width:90%; max-width:420px; padding:20px; box-shadow:0 6px 20px rgba(0,0,0,0.5); }
    .confirm-dialog-content .row { display:flex; gap:12px; align-items:flex-start; }
    .confirm-dialog-icon { color:#ffc107; font-size:22px; margin-top:2px; }
    .confirm-dialog-message { margin:0; color:#000; font-size:16px; line-height:1.4; }
    .confirm-dialog-buttons { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
    .confirm-dialog-button { padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-weight:600; }
    .confirm-dialog-cancel { background:#6c757d; color:#fff; }
    .confirm-dialog-confirm { background:#dc3545; color:#fff; }

    .docentes-lista { width:95%; max-width: 1200px; margin:20px auto; display:flex; flex-direction:column; gap:8px; }
    .docente-row {
      background:#fff; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08);
      padding:12px 16px; display:flex; justify-content: space-between; align-items: flex-start;
      gap: 15px; transition:box-shadow 0.2s;
    }
    .docente-row:hover { box-shadow:0 3px 10px rgba(0,0,0,0.12); }
    
    .docente-row-content { flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .docente-nombre { font-weight:600; font-size:15px; color:#222; }
    .docente-sub { display:flex; flex-wrap:wrap; gap:12px; font-size:13px; color:#666; }
    
    .docente-materias { display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin-top: 4px; }
    .materia-badge { 
      background: #fff3e0; color: #e65100; border-radius: 4px; padding: 2px 8px; font-size: 11px; 
      display: flex; align-items: center; gap: 6px; border: 1px solid #ffe0b2; 
    }
    .badge-remove { background:none; border:none; color:#e65100; cursor:pointer; padding:0; font-size:14px; font-weight:bold; }
    .badge-edit { background:none; border:none; color:#1565c0; cursor:pointer; padding:0 2px; font-size:11px; opacity:0.7; }
    .badge-edit:hover { opacity:1; }
    
    .btn-agregar-badge {
      background:transparent; border:1px dashed #aaa; border-radius:4px;
      color:#666; font-size:11px; padding:1px 8px; cursor:pointer;
      display:inline-flex; align-items:center; gap:4px;
    }
    .btn-agregar-badge:hover { border-color:#e65100; color:#e65100; background:#fff3e0; }

    .alumno-options { font-size: 14px; width:100%; }
    .alumno-option { transition: background 0.15s ease; padding: 10px 12px; }
    .alumno-option:hover { background: #f8fafc; }
    .alumno-option + .alumno-option { border-top: 1px solid #eef2f7; }
    .alumno-nombre { text-transform: uppercase; letter-spacing: 0.3px; color:#2d3748; }
    .alumno-options .materia-curso {
      background: #f1f3f5 !important; color: #2d3748 !important; border: 1px solid #cfd3d7 !important;
      height: 20px; line-height: 20px; padding: 0 8px; border-radius: 4px; order: 2; margin-left: auto;
    }
    #alumnoPicker .materia-curso { order: 2; margin-left: auto; }

    @media (max-width: 600px) {
      .docente-row { padding: 10px; gap: 8px; }
      .docente-sub { gap: 8px; font-size: 12px; }
    }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<h1 style="text-align:center; color:#fff;">Intensificación</h1>

<div style="width:95%; max-width:1200px; margin:10px auto; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <form id="formFiltros" method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar alumno..." style="padding:10px; border-radius:6px; border:1px solid #ccc; width:220px;">
    </form>
    <div style="display:flex; gap:10px;">
      <?php 
      $urlVolver = 'menu.php';
      if (isset($_GET['from']) && $_GET['from'] === 'miscursos') {
          $urlVolver = 'miscursos.php';
      }
      ?>
      <a href="<?= $urlVolver ?>"><button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Volver</button></a>
      <?php if ($puedeEditar): ?>
        <button onclick="abrirModalAgregarGlobal()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;">➕ Agregar Intensificación</button>
      <?php endif; ?>
    </div>
</div>

<div id="tablaWrapper"></div>

<!-- Modal agregar intensificación -->
<div id="modalAgregar" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:500px;">
    <span class="cerrar" onclick="cerrarModalAgregar()">&times;</span>
    <h3 style="color:#000;">Asignar materia (Intensificación)</h3>
    <div class="form-group">
      <label style="color:#000;">Seleccionar Alumno:</label>
      <select id="selectAlumno" style="display:none;">
        <option value="">-- Seleccione un alumno --</option>
        <?php foreach ($alumnos_catalogo as $al): ?>
          <option value="<?= $al['id_alumno'] ?>"><?= htmlspecialchars($al['apellido'].', '.$al['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="position:relative; margin-bottom:15px;">
        <div id="alumnoPicker" class="alumno-picker" role="button" tabindex="0" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border:1px solid #ccc; border-radius:8px; background:#fff; color:#000; cursor:pointer;">
          <span id="alumnoPickerLabel" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#333;">-- Seleccione un alumno --</span>
          <span id="alumnoPickerCurso" class="materia-curso" style="display:none;"></span>
          <i class="fa-solid fa-caret-down" style="color:#777;"></i>
        </div>
        <div id="alumnoDropdown" style="display:none; position:absolute; left:0; top:calc(100% + 6px); z-index:3000; width:100%; background:#fff; color:#000; border:1px solid #ccc; border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,0.15);">
          <div style="padding:8px 10px; border-bottom:1px solid #eee;">
            <input id="alumnoSearch" type="text" placeholder="Buscar alumno..." style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; outline:none;">
          </div>
          <div id="alumnoOptions" class="alumno-options" style="max-height:250px; overflow:auto;">
            <?php foreach ($alumnos_catalogo as $al): ?>
              <div class="alumno-option" data-id="<?= $al['id_alumno'] ?>" data-curso="<?= htmlspecialchars($al['nombre_curso']) ?>" data-text="<?= htmlspecialchars(strtoupper($al['apellido'].', '.$al['nombre'])) ?>" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; cursor:pointer;">
                <span class="alumno-nombre" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#333;">
                  <?= htmlspecialchars($al['apellido'].', '.$al['nombre']) ?>
                </span>
                <span class="materia-curso" style="font-size:11px; background:#eee; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($al['nombre_curso']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 20px;">
      <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #1e293b;"><i class="fa-solid fa-circle-info"></i> Información de la materia que adeuda</h4>
      <div class="form-group">
        <label style="color:#000; font-size: 13px;">¿De qué año es la materia?</label>
        <select id="anioAdeuda" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:10px;">
          <option value="">-- Seleccione año --</option>
          <?php for($i=1; $i<=6; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?>º Año</option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom: 0;">
        <label style="color:#000; font-size: 13px;">Materia que adeuda:</label>
        <select id="materiaAdeuda" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">
          <option value="">-- Seleccione materia --</option>
          <?php foreach ($lista_materias_unicas as $m): ?>
            <option value="<?= htmlspecialchars($m['nombre_materia']) ?>"><?= htmlspecialchars($m['nombre_materia']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    
    <div class="form-group">
      <label style="color:#000; font-weight: 600;">¿En qué materia y curso va a intensificar?</label>
      <label style="color:#000; display: block; margin-top: 10px; font-size: 13px;">Seleccionar Materia:</label>
      <select id="selectMateriaInt" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; margin-bottom:15px;" onchange="updateCursosForMateria()">
        <option value="">-- Seleccione una materia --</option>
        <?php foreach ($materias_agrupadas as $m => $cursos): ?>
          <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?> (<?= htmlspecialchars($cursos[0]['tipo'] ?: 'Programática') ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" id="groupCurso" style="display:none;">
      <label style="color:#000;">Seleccionar Curso/Sección (donde intensifica):</label>
      <select id="selectCM" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; margin-bottom:20px;">
        <option value="">-- Seleccione un curso --</option>
      </select>
    </div>
    <script>
      const MATERIAS_DATA = <?= json_encode($materias_agrupadas) ?>;
      function updateCursosForMateria() {
        const mat = document.getElementById('selectMateriaInt').value;
        const selCM = document.getElementById('selectCM');
        const groupCurso = document.getElementById('groupCurso');
        selCM.innerHTML = '<option value="">-- Seleccione un curso --</option>';
        if (mat && MATERIAS_DATA[mat]) {
          MATERIAS_DATA[mat].forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id_curso_materia;
            opt.textContent = c.nombre_curso;
            selCM.appendChild(opt);
          });
          groupCurso.style.display = 'block';
        } else {
          groupCurso.style.display = 'none';
        }
      }
    </script>
    <button onclick="guardarIntensificacion()" style="width:100%; padding:12px; background:#16682b; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Guardar</button>
    <input type="hidden" id="alumnoIdHidden">
  </div>
</div>

<!-- Modal editar intensificación -->
<div id="modalEditar" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:500px;">
    <span class="cerrar" onclick="cerrarModalEditar()">&times;</span>
    <h3 style="color:#000;"><i class="fa fa-pencil" style="color:#1565c0;"></i> Editar Intensificación</h3>
    <input type="hidden" id="editAlumnoId">
    <input type="hidden" id="editCmViejo">
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-bottom:16px;">
      <h4 style="margin:0 0 10px 0; font-size:14px; color:#1e293b;"><i class="fa-solid fa-circle-info"></i> Información de la materia que adeuda</h4>
      <div class="form-group">
        <label style="color:#000; font-size:13px;">¿De qué año es la materia?</label>
        <select id="editAnioAdeuda" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:10px;">
          <option value="">-- Seleccione año --</option>
          <?php for($i=1; $i<=6; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?>º Año</option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label style="color:#000; font-size:13px;">Materia que adeuda:</label>
        <select id="editMateriaAdeuda" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">
          <option value="">-- Seleccione materia --</option>
          <?php foreach ($lista_materias_unicas as $m): ?>
            <option value="<?= htmlspecialchars($m['nombre_materia']) ?>"><?= htmlspecialchars($m['nombre_materia']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label style="color:#000; font-weight:600;">¿En qué materia y curso va a intensificar?</label>
      <label style="color:#000; display:block; margin-top:10px; font-size:13px;">Seleccionar Materia:</label>
      <select id="editSelectMateria" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; margin-bottom:15px;" onchange="updateCursosParaEdicion()">
        <option value="">-- Seleccione una materia --</option>
        <?php foreach ($materias_agrupadas as $m => $cursos): ?>
          <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?> (<?= htmlspecialchars($cursos[0]['tipo'] ?: 'Programática') ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" id="editGroupCurso" style="display:none;">
      <label style="color:#000;">Seleccionar Curso/Sección (donde intensifica):</label>
      <select id="editSelectCM" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; margin-bottom:20px;">
        <option value="">-- Seleccione un curso --</option>
      </select>
    </div>
    <button onclick="guardarEdicion()" style="width:100%; padding:12px; background:#1565c0; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Guardar cambios</button>
  </div>
</div>

<div id="confirm-dialog"><div class="confirm-dialog-content"><div class="row"><i class="fa-solid fa-exclamation-triangle confirm-dialog-icon"></i><p id="confirm-msg" class="confirm-dialog-message"></p></div><div class="confirm-dialog-buttons"><button onclick="closeConfirm(false)" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button><button onclick="closeConfirm(true)" class="confirm-dialog-button confirm-dialog-confirm">Aceptar</button></div></div></div>

<script>
let confirmResolve = null;
const form = document.getElementById('formFiltros');
const wrap = document.getElementById('tablaWrapper');
let alumnoActual = 0;

async function actualizarTabla(){
  const params = new URLSearchParams(new FormData(form));
  params.append('partial','1');
  const cursoParam = new URLSearchParams(window.location.search).get('curso');
  if (cursoParam) params.append('curso', cursoParam);
  const res  = await fetch('intensifican.php?' + params.toString(), { cache:'no-store' });
  wrap.innerHTML = await res.text();
}
document.addEventListener('DOMContentLoaded', actualizarTabla);
form.addEventListener('input', () => { clearTimeout(window.t); window.t = setTimeout(actualizarTabla, 350); });

function showConfirm(msg){
    document.getElementById("confirm-msg").textContent = msg;
    document.getElementById("confirm-dialog").style.display = "flex";
    return new Promise(r => confirmResolve = r);
}
function closeConfirm(v){ document.getElementById("confirm-dialog").style.display = "none"; if(confirmResolve) confirmResolve(v); }

function abrirModalAgregar(idAlumno){
  alumnoActual = idAlumno;
  document.getElementById('alumnoIdHidden').value = idAlumno;
  const sel = document.getElementById('selectAlumno');
  if (sel){ sel.value = String(idAlumno); sel.disabled = true; }
  // Actualizar picker visual y bloquear interacción
  setAlumnoSelection(String(idAlumno));
  lockAlumnoPicker(true);
  document.getElementById('modalAgregar').style.display = 'flex';
}
function abrirModalAgregarGlobal(){
  alumnoActual = 0;
  document.getElementById('alumnoIdHidden').value = '';
  const sel = document.getElementById('selectAlumno');
  if (sel){ sel.disabled = false; sel.value = ''; }
  // Reset picker visual y habilitar
  setAlumnoSelection('');
  lockAlumnoPicker(false);
  // Asegurar dropdown cerrado y búsqueda limpia
  toggleAlumnoOptions(false);
  const s = document.getElementById('alumnoSearch'); if (s) s.value = '';
  document.getElementById('modalAgregar').style.display = 'flex';
}
function cerrarModalAgregar(){
  document.getElementById('modalAgregar').style.display = 'none';
}

async function guardarIntensificacion(){
  let id_alumno = document.getElementById('alumnoIdHidden').value;
  if (!id_alumno){
    const sel = document.getElementById('selectAlumno');
    id_alumno = sel ? sel.value : '';
  }
  const id_curso_materia = document.getElementById('selectCM').value;
  const anio_adeuda = document.getElementById('anioAdeuda').value;
  const materia_adeuda = document.getElementById('materiaAdeuda').value;
  
  if (!id_alumno || !id_curso_materia) return;
  const body = new URLSearchParams();
  body.append('accion','asignar_intensificacion');
  body.append('id_alumno', id_alumno);
  body.append('id_curso_materia', id_curso_materia);
  body.append('anio_adeuda', anio_adeuda);
  body.append('materia_adeuda', materia_adeuda);
  const res = await fetch('intensifican.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
  const data = await res.json().catch(()=>({success:false,message:'Respuesta inválida'}));
  if (data.success){
    cerrarModalAgregar();
    actualizarTabla();
  } else {
    alert(data.message || 'No se pudo asignar.');
  }
}

function confirmModal(message){
  const dlg = document.getElementById('confirm-dialog');
  const msg = document.getElementById('confirm-dialog-message');
  const btnOk = document.getElementById('confirm-dialog-confirm');
  const btnCancel = document.getElementById('confirm-dialog-cancel');
  if (!dlg || !msg || !btnOk || !btnCancel){ return Promise.resolve(confirm(message || '¿Confirmar acción?')); }
  msg.textContent = message || '¿Confirmar acción?';
  dlg.style.display = 'flex';
  return new Promise((resolve)=>{
    const cleanup = ()=>{
      btnOk.removeEventListener('click', onOk);
      btnCancel.removeEventListener('click', onCancel);
      dlg.removeEventListener('click', onBackdrop);
      dlg.style.display = 'none';
    };
    const onOk = ()=>{ cleanup(); resolve(true); };
    const onCancel = ()=>{ cleanup(); resolve(false); };
    const onBackdrop = (e)=>{ if (e.target === dlg){ cleanup(); resolve(false); } };
    btnOk.addEventListener('click', onOk);
    btnCancel.addEventListener('click', onCancel);
    dlg.addEventListener('click', onBackdrop);
  });
}

async function quitarIntensificacion(id_alumno, id_curso_materia, btn, event){
  if (event) { try { event.preventDefault(); event.stopPropagation(); } catch(e){} }
  const ok = await showConfirm('¿Está seguro de quitar esta materia al alumno?');
  if (!ok) return;
  const body = new URLSearchParams();
  body.append('accion','eliminar_intensificacion');
  body.append('id_alumno', id_alumno);
  body.append('id_curso_materia', id_curso_materia);
  const res = await fetch('intensifican.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
  const data = await res.json().catch(()=>({success:false}));
  if (data.success){
    // Remover del DOM rápidamente
    const item = btn.closest('.materia-badge');
    if (item) item.remove();
    // Si el alumno se queda sin materias, refrescar solo la tabla
    try {
      const list = document.getElementById('materias-' + id_alumno);
      const remaining = list ? list.querySelectorAll('.materia-badge').length : 0;
      if (!remaining) {
        if (typeof actualizarTabla === 'function') {
          actualizarTabla();
          return;
        } else {
          window.location.reload();
          return;
        }
      }
    } catch (e) {}
  } else {
    alert('No se pudo eliminar.');
  }
}

// ==========================
// Editar intensificación
// ==========================
function updateCursosParaEdicion(){
  const mat = document.getElementById('editSelectMateria').value;
  const selCM = document.getElementById('editSelectCM');
  const group = document.getElementById('editGroupCurso');
  selCM.innerHTML = '<option value="">-- Seleccione un curso --</option>';
  if (mat && MATERIAS_DATA[mat]){
    MATERIAS_DATA[mat].forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id_curso_materia;
      opt.textContent = c.nombre_curso;
      selCM.appendChild(opt);
    });
    group.style.display = 'block';
  } else {
    group.style.display = 'none';
  }
}

function abrirModalEditar(id_alumno, id_cm, anio, materiaAdeuda){
  document.getElementById('editAlumnoId').value = id_alumno;
  document.getElementById('editCmViejo').value = id_cm;

  // Pre-poblar info adicional (pasados como parámetros directos)
  document.getElementById('editAnioAdeuda').value = anio || '';
  document.getElementById('editMateriaAdeuda').value = materiaAdeuda || '';

  // Pre-poblar materia y curso: buscar qué materia corresponde al id_cm
  let foundMat = '';
  for (const [mat, cursos] of Object.entries(MATERIAS_DATA)){
    const match = cursos.find(c => String(c.id_curso_materia) === String(id_cm));
    if (match){ foundMat = mat; break; }
  }
  const selMat = document.getElementById('editSelectMateria');
  selMat.value = foundMat;
  updateCursosParaEdicion();
  document.getElementById('editSelectCM').value = String(id_cm);

  document.getElementById('modalEditar').style.display = 'flex';
}

function cerrarModalEditar(){
  document.getElementById('modalEditar').style.display = 'none';
}

async function guardarEdicion(){
  const id_alumno   = document.getElementById('editAlumnoId').value;
  const id_cm_viejo = document.getElementById('editCmViejo').value;
  const id_cm_nuevo = document.getElementById('editSelectCM').value;
  const anio_adeuda = document.getElementById('editAnioAdeuda').value;
  const materia_adeuda = document.getElementById('editMateriaAdeuda').value;
  if (!id_alumno || !id_cm_viejo || !id_cm_nuevo){
    alert('Seleccione una materia y un curso.');
    return;
  }
  const body = new URLSearchParams();
  body.append('accion','editar_intensificacion');
  body.append('id_alumno', id_alumno);
  body.append('id_curso_materia_viejo', id_cm_viejo);
  body.append('id_curso_materia_nuevo', id_cm_nuevo);
  body.append('anio_adeuda', anio_adeuda);
  body.append('materia_adeuda', materia_adeuda);
  const res = await fetch('intensifican.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
  const data = await res.json().catch(()=>({success:false,message:'Respuesta inválida'}));
  if (data.success){
    cerrarModalEditar();
    actualizarTabla();
  } else {
    alert(data.message || 'No se pudo guardar.');
  }
}

// ==========================
// Custom Alumno Picker logic
// ==========================
function setAlumnoSelection(id){
  const sel = document.getElementById('selectAlumno');
  const label = document.getElementById('alumnoPickerLabel');
  const cursoSpan = document.getElementById('alumnoPickerCurso');
  const optionsList = document.getElementById('alumnoOptions');
  if (!sel || !label || !cursoSpan || !optionsList) return;
  let nombre = '-- Seleccione un alumno --';
  let curso = '';
  if (id){
    // Buscar en lista visual primero para obtener curso rápido
    const optDiv = optionsList.querySelector('.alumno-option[data-id="'+id+'"]');
    if (optDiv){
      nombre = optDiv.querySelector('.alumno-nombre')?.textContent?.trim() || nombre;
      curso = optDiv.getAttribute('data-curso') || '';
    } else {
      // fallback: del select
      const op = sel.querySelector('option[value="'+id+'"]');
      if (op) nombre = op.textContent.trim();
    }
    sel.value = id;
  } else {
    sel.value = '';
  }
  label.textContent = nombre;
  if (curso){ cursoSpan.textContent = curso; cursoSpan.style.display = ''; }
  else { cursoSpan.textContent = ''; cursoSpan.style.display = 'none'; }
}

function lockAlumnoPicker(lock){
  const picker = document.getElementById('alumnoPicker');
  if (!picker) return;
  picker.dataset.locked = lock ? '1' : '';
  picker.style.opacity = lock ? '0.8' : '1';
}

function toggleAlumnoOptions(force){
  const picker = document.getElementById('alumnoPicker');
  const dropdown = document.getElementById('alumnoDropdown');
  const overlay = document.getElementById('modalAgregar');
  if (!picker || !dropdown) return;
  if (picker.dataset.locked === '1') return; // no abrir si está bloqueado
  const currentHidden = (dropdown.style.display === 'none' || !dropdown.style.display);
  const show = (typeof force === 'boolean') ? force : currentHidden;
  dropdown.style.display = show ? 'block' : 'none';
  picker.setAttribute('aria-expanded', show ? 'true' : 'false');
  if (show){
    if (overlay) overlay.classList.add('picker-open');
    // Forzar crecimiento del modal al 90% de la pantalla
    const modalBox = overlay ? overlay.querySelector('.modal-box') : null;
    if (modalBox) modalBox.style.maxHeight = '90vh';
    // asegurar scroll interno del listado
    dropdown.style.maxHeight = '';
    const s = document.getElementById('alumnoSearch');
    if (s){ s.focus(); s.select && s.select(); }
  } else {
    if (overlay) overlay.classList.remove('picker-open');
    // Restaurar altura por defecto del modal
    const modalBox = overlay ? overlay.querySelector('.modal-box') : null;
    if (modalBox) modalBox.style.maxHeight = '80vh';
  }
}

// Attach events once modal is in DOM
document.addEventListener('DOMContentLoaded', function(){
  const picker = document.getElementById('alumnoPicker');
  const dropdown = document.getElementById('alumnoDropdown');
  const list = document.getElementById('alumnoOptions');
  const search = document.getElementById('alumnoSearch');
  if (picker && dropdown){
    picker.addEventListener('click', function(){ toggleAlumnoOptions(); });
    picker.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); toggleAlumnoOptions(); }
      if (e.key === 'Escape'){ toggleAlumnoOptions(false); }
    });
    if (list){
      list.addEventListener('click', function(e){
        const opt = e.target.closest('.alumno-option');
        if (!opt) return;
        const id = opt.getAttribute('data-id');
        setAlumnoSelection(id);
        toggleAlumnoOptions(false);
      });
    }
    if (search){
      search.addEventListener('input', function(){
        const q = this.value.trim().toUpperCase();
        const items = list ? list.querySelectorAll('.alumno-option') : [];
        items.forEach(function(el){
          const txt = el.getAttribute('data-text') || '';
          el.style.display = (!q || txt.includes(q)) ? 'flex' : 'none';
        });
      });
    }
    document.addEventListener('click', function(e){
      if (!e.target.closest('#alumnoPicker') && !e.target.closest('#alumnoDropdown')){
        toggleAlumnoOptions(false);
      }
    });
  }
});
</script>
</body>
</html>
