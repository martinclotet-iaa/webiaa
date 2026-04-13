<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/html; charset=UTF-8');

$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1; // 1=Alumno
$idUsuarioSesion = intval($_SESSION['id_usuario']);

// Resolver alumno objetivo
$idAlumno = null;
$alumnoNombre = '';
$alumnoCurso = '';
$alumnoLibro = '';
$alumnoFolio = '';

// 1) Si viene id_alumno por GET, usarlo
if (isset($_GET['id_alumno']) && ctype_digit($_GET['id_alumno'])) {
  $idAlumno = intval($_GET['id_alumno']);
} elseif (isset($_GET['id_usuario']) && ctype_digit($_GET['id_usuario'])) {
  // 2) Si viene id_usuario, mapear a id_alumno
  $idU = intval($_GET['id_usuario']);
  $rs = $conexion->query("SELECT id_alumno FROM alumnos WHERE id_usuario = $idU LIMIT 1");
  if ($rs && $row = $rs->fetch_assoc()) { $idAlumno = intval($row['id_alumno']); }
}

// 3) Si no vino nada y el perfil es Alumno, usar el alumno de la sesión
if ($idAlumno === null && $perfilActual === 1) {
  $rs = $conexion->query("SELECT id_alumno FROM alumnos WHERE id_usuario = $idUsuarioSesion LIMIT 1");
  if ($rs && $row = $rs->fetch_assoc()) { $idAlumno = intval($row['id_alumno']); }
}

// Datos del alumno (nombre y curso)
if ($idAlumno !== null) {
  $rs = $conexion->query("SELECT u.nombre, u.apellido, c.nombre_curso, a.libro, a.folio FROM alumnos a INNER JOIN usuarios u ON a.id_usuario=u.id_usuario INNER JOIN cursos c ON a.id_curso=c.id_curso WHERE a.id_alumno = $idAlumno LIMIT 1");
  if ($rs && $r = $rs->fetch_assoc()) {
    $alumnoNombre = trim(($r['apellido'] ?? '').', '.($r['nombre'] ?? ''));
    $alumnoCurso = $r['nombre_curso'] ?? '';
    $alumnoLibro = isset($r['libro']) ? (string)$r['libro'] : '';
    $alumnoFolio = isset($r['folio']) ? (string)$r['folio'] : '';
  }
}

// 4) Listado de alumnos para selector (TODOS los permitidos, para filtrar por JS)
// 5) Listado de todos los cursos para el selector (según perfil)
$sqlAllC = "SELECT c.id_curso, c.nombre_curso FROM cursos c ORDER BY c.nombre_curso";
if ($perfilActual == 5) { // Preceptor
  // Lógica compatible con miscursos.php
  $sqlAllC = "SELECT DISTINCT c.id_curso, c.nombre_curso 
              FROM cursos c 
              LEFT JOIN curso_preceptor cp ON c.id_curso = cp.id_curso 
              WHERE cp.id_preceptor = $idUsuarioSesion OR c.id_preceptor = $idUsuarioSesion 
              ORDER BY c.nombre_curso";
}
$rsC = $conexion->query($sqlAllC);
$allCursos = $rsC ? $rsC->fetch_all(MYSQLI_ASSOC) : [];

// 4) Listado de alumnos para selector (Filtrado por permisos del usuario)
$filtroUserSQL = "";
if ($perfilActual == 5) { // Preceptor - Solo alumnos de SUS cursos
  $idsCursos = !empty($allCursos) ? implode(',', array_column($allCursos, 'id_curso')) : '0';
  $filtroUserSQL = " AND a.id_curso IN ($idsCursos) ";
}

$alumnosList = [];
$sqlLArr = "SELECT a.id_alumno, u.apellido, u.nombre, c.nombre_curso, a.id_curso
            FROM alumnos a
            INNER JOIN usuarios u ON a.id_usuario = u.id_usuario
            INNER JOIN cursos c ON c.id_curso = a.id_curso
            WHERE 1=1 $filtroUserSQL
            ORDER BY u.apellido, u.nombre";
$rsL = $conexion->query($sqlLArr);
if ($rsL) { $alumnosList = $rsL->fetch_all(MYSQLI_ASSOC); }

if (isset($_GET['id_curso']) && ctype_digit($_GET['id_curso'])) {
  $idCF = intval($_GET['id_curso']);
}

// Si aún no tenemos alumno, mostrar selector
if ($idAlumno === null) {
  // Agrupar alumnos por curso para el listado
  $cursosList = [];
  foreach ($alumnosList as $al) {
      $c = $al['nombre_curso'] ?? 'Sin curso';
      if (!isset($cursosList[$c])) $cursosList[$c] = [];
      $cursosList[$c][] = $al;
  }
  ksort($cursosList);
  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RITE - Selección de Alumno</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="estilos.css">
    <style>
      .sel-page { min-height: 80vh; display: flex; align-items: flex-start; justify-content: center; padding: 30px 16px; }
      .sel-card {
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.13);
        padding: 32px 28px;
        width: 100%;
        max-width: 560px;
      }
      .sel-card h2 { margin: 0 0 6px 0; font-size: 22px; color: #1a1a2e; }
      .sel-card p  { margin: 0 0 22px 0; color: #666; font-size: 14px; }
      .search-box {
        position: relative;
        margin-bottom: 8px;
      }
      .search-box input {
        width: 100%;
        padding: 11px 14px 11px 38px;
        border: 1px solid #cfd3d7;
        border-radius: 8px;
        font-size: 14px;
        box-sizing: border-box;
        outline: none;
        transition: border-color .2s;
      }
      .search-box input:focus { border-color: #16682b; }
      .search-box .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999; font-size: 13px; }
      .filter-row { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
      .filter-chip {
        padding: 5px 14px; border-radius: 20px; border: 1px solid #cfd3d7;
        background: #f8f9fa; color: #444; font-size: 12px; cursor: pointer;
        transition: all .18s;
      }
      .filter-chip:hover, .filter-chip.active { background: #16682b; color: #fff; border-color: #16682b; }
      .alumnos-lista {
        max-height: 380px;
        overflow-y: auto;
        border: 1px solid #e9ecef;
        border-radius: 8px;
      }
      .alumno-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        cursor: pointer;
        transition: background .15s;
        border-bottom: 1px solid #f0f0f0;
        gap: 10px;
        text-decoration: none;
        color: inherit;
      }
      .alumno-item:last-child { border-bottom: none; }
      .alumno-item:hover { background: #f0f9f2; }
      .alumno-item:hover .alumno-nombre { color: #16682b; }
      .alumno-nombre { font-weight: 600; font-size: 14px; color: #222; flex: 1; }
      .alumno-curso-badge {
        background: #e8f4ef; color: #16682b; padding: 3px 9px;
        border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap;
      }
      .no-results { text-align: center; color: #999; padding: 24px; font-size: 14px; }
      .sel-actions { display: flex; justify-content: flex-end; margin-top: 18px; }
      .btn-volver { padding: 9px 18px; border-radius: 8px; background: #6c757d; color: #fff; text-decoration: none; font-size: 14px; }
    </style>
  </head>
  <body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="sel-page">
    <div class="sel-card">
      <h2><i class="fa fa-id-card" style="color:#16682b;"></i> RITE del Alumno</h2>
      <p>Busca por apellido, nombre o curso y hacé clic en el alumno.</p>

      <div class="search-box">
        <i class="fa fa-search search-icon"></i>
        <input type="text" id="searchInput" placeholder="Buscar: apellido, nombre o curso..." autofocus autocomplete="off">
      </div>

      <!-- Chips de curso -->
      <div class="filter-row" id="cursoChips">
        <span class="filter-chip active" data-curso="">Todos</span>
        <?php foreach (array_keys($cursosList) as $cNombre): ?>
          <span class="filter-chip" data-curso="<?= htmlspecialchars($cNombre) ?>">
            <?= htmlspecialchars($cNombre) ?>
          </span>
        <?php endforeach; ?>
      </div>

      <div class="alumnos-lista" id="alumnoLista">
        <?php foreach ($alumnosList as $al):
          $id  = (int)$al['id_alumno'];
          $nom = htmlspecialchars(strtoupper($al['apellido'] ?? '') . ', ' . ($al['nombre'] ?? ''));
          $cur = htmlspecialchars($al['nombre_curso'] ?? '');
        ?>
          <a href="rite_alumno.php?id_alumno=<?= $id ?><?= isset($idCF) ? '&id_curso='.$idCF : '' ?>" class="alumno-item"
             data-nombre="<?= strtoupper(strip_tags($nom)) ?>"
             data-curso="<?= strip_tags($cur) ?>">
            <span class="alumno-nombre"><?= $nom ?></span>
            <span class="alumno-curso-badge"><?= $cur ?></span>
          </a>
        <?php endforeach; ?>
        <div class="no-results" id="noResults" style="display:none;">No se encontraron alumnos.</div>
      </div>

      <div class="sel-actions">
        <a href="<?= isset($idCF) ? 'miscursos.php' : 'menu.php' ?>" class="btn-volver"><i class="fa fa-arrow-left"></i> Volver</a>
      </div>
    </div>
  </div>

  <script>
  const searchInput  = document.getElementById('searchInput');
  const lista        = document.getElementById('alumnoLista');
  const noResults    = document.getElementById('noResults');
  const chips        = document.querySelectorAll('#cursoChips .filter-chip');
  let filtroCurso    = '';

  function filtrar() {
    const q = searchInput.value.trim().toUpperCase();
    const items = lista.querySelectorAll('.alumno-item');
    let visibles = 0;
    items.forEach(el => {
      const nom = el.dataset.nombre || '';
      const cur = el.dataset.curso  || '';
      const matchQ     = !q || nom.includes(q) || cur.toUpperCase().includes(q);
      const matchCurso = !filtroCurso || cur === filtroCurso;
      const visible = matchQ && matchCurso;
      el.style.display = visible ? 'flex' : 'none';
      if (visible) visibles++;
    });
    noResults.style.display = visibles === 0 ? 'block' : 'none';
  }

  searchInput.addEventListener('input', filtrar);

  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      chips.forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      filtroCurso = chip.dataset.curso;
      filtrar();
    });
  });
  </script>
  </body>
  </html>
  <?php
  exit;
}

// Traer notas por materia del alumno
$sql = "
  SELECT m.nombre_materia, c.nombre_curso, n.id_curso_materia, n.id_estado
  FROM notas n
  INNER JOIN curso_materia cm ON cm.id_curso_materia = n.id_curso_materia
  INNER JOIN cursos c ON c.id_curso = cm.id_curso
  INNER JOIN materias m ON m.id_materia = cm.id_materia
  WHERE n.id_alumno = $idAlumno
  ORDER BY c.nombre_curso, m.nombre_materia
";
$notas = $conexion->query($sql);
$notas = $notas ? $notas->fetch_all(MYSQLI_ASSOC) : [];

?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>RITE del Alumno</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css?v=<?php echo time(); ?>">
  <style>
    .rite-topbar { background:#7a0000; color:#fff; padding:6px 10px; text-align:center; font-weight:700; border:none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin: 0 !important; }
    .rite-head { display:grid; grid-template-columns: 120px 1fr 120px; border:none !important; align-items: center; margin: 0 !important; gap: 0 !important; }
    .rite-logo { display:flex; align-items:center; justify-content:center; border-right:none; padding:8px; min-height:90px; background:#fff; }
    .rite-logo img { max-height:84px; max-width:100%; object-fit:contain; display:block; }
    .rite-title { padding:6px 10px; }
    .rite-title h2 { margin:0; text-align:center; font-size:18px; }
    .rite-title .sub { text-align:center; margin-top:4px; font-size:12px; }
    .rite-meta { width:100%; border-collapse:collapse; margin-top:6px; }
    .rite-meta td { border:none !important; padding:2px 4px; font-size:12px; }
    .rite-meta td.label { background:#d0e0e3; font-weight:400; width:120px; }
    .rite-meta { border:none !important; border-collapse: collapse; border-spacing: 0; }
    .header-data { font-size: 15px; font-weight: 600; color: #000 !important; }
    .name-data { font-size: 17px !important; }
    /* Overrides: logo y cabecera con fondo blanco y texto negro */
    .rite-logo, .rite-title, .rite-title * { background:#fff !important; color:#000 !important; }
    .rite-meta td, .rite-meta td.label { background:#fff !important; color:#000 !important; }
    .rite-container { width: 95%; max-width: 1100px; margin: 16px auto; }
    .rite-header { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .rite-header .right { margin-left:auto; display:flex; gap:10px; }
    .rite-header select { padding:6px 8px; border:1px solid #cfd3d7; border-radius:6px; }
    .table { width:100%; border-collapse: collapse; }
    .table th, .table td { border:1px solid #000; padding:2px 6px; color:#000; vertical-align: middle; }
    .table th { background:#d0e0e3; text-align:center; height:15px; line-height:15px; }
    /* Primera fila de encabezados (agrupadores) - CALIFICACIONES más grande */
    .table thead tr:first-child th { 
      font-size: 11px; 
      line-height: 1.1; 
      padding: 2px 1px;
      word-break: break-word; 
      white-space: normal;
    }
    /* El encabezado CALIFICACIÓN FINAL debe ser pequeño para que no desborde */
    .table thead tr:first-child th:nth-child(4) { font-size: 8.5px; }
    
    /* Segunda fila (subgrupos: 1°/2° cuatrimestre e intensificación) - Más chico */
    .table thead tr:nth-child(2) th { font-size: 9px; line-height:1; padding:1px 2px; text-align:center; white-space:nowrap; }
    /* Tercera fila (etiquetas largas) */
    .table thead tr:nth-child(3) th {
      font-size:8px;
      line-height:1;
      padding:0 1px;
      white-space:normal;
      word-break:break-word;
      hyphens:auto;
      text-align:center;
    }
    .table tbody td { background:#fff !important; color:#000 !important; height:15px; line-height:15px; }
    /* meta cells keep default height (no fixed height) */
    .badge { display:inline-block; padding:4px 8px; border-radius:10px; font-size:11px; font-weight:600; }
    .badge-recursa { background:#dc3545; color:#fff; }
    .badge-intensifica { background:#ff6b35; color:#fff; }
    .muted { color:#6c757d; }
    /* Observaciones: alinear a la izquierda y reducir 40% */
    .obs-item { text-align: left; font-size: 60%; line-height: 1.2; }
    .rite-page-sheet {
      background: #fff !important;
      color: #000 !important;
      padding: 30px;
      margin-top: 15px;
      border-radius: 4px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .rite-page-sheet *:not(.rite-topbar):not(.rite-topbar *) { color: #000 !important; }
    .rite-topbar, .rite-topbar * { color: #fff !important; }
    @media print {
      @page { size: A4 portrait; margin: 1cm; }
      .rite-page-sheet { 
        padding: 0; 
        box-shadow: none; 
        margin: 0; 
        transform: scale(0.85); 
        transform-origin: top center; 
      }
      .rite-container { width: 100%; max-width: none; margin: 0; padding: 0; }
      .no-print, .header, .rite-header, #searchWrap, .datetime { display: none !important; }
      body { background: #fff !important; padding: 0 !important; }
      body::before { display: none !important; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="rite-container">
    <div class="rite-header no-print">
      <h1 style="margin:0; flex-shrink:0;">RITE</h1>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <!-- Selector de Curso -->
        <select id="selectCurso" onchange="filtrarSelectDependent(this.value)" style="height:32px; padding:0 8px; border-radius:6px; border:1px solid #cfd3d7; cursor:pointer; font-size:12px; box-sizing:border-box; background:#fff;">
            <option value="">-- Todos los Cursos --</option>
            <?php foreach ($allCursos as $c): ?>
                <option value="<?= $c['id_curso'] ?>" <?= (isset($idCF) && $idCF == $c['id_curso']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre_curso']) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Selector de Alumno (Dependiente) -->
        <select id="selectAlumno" onchange="irAAlumno(this.value)" style="height:32px; padding:0 8px; border-radius:6px; border:1px solid #cfd3d7; cursor:pointer; font-size:12px; max-width:220px; box-sizing:border-box; background:#fff;">
            <option value="">-- Alumno del Curso --</option>
            <?php foreach ($alumnosList as $al): ?>
                <option value="<?= $al['id_alumno'] ?>" data-curso="<?= $al['id_curso'] ?>" <?= $idAlumno == $al['id_alumno'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(strtoupper($al['apellido'].', '.$al['nombre'])) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Buscador Independiente (A la derecha) -->
        <div style="position:relative; margin-left:auto; min-width:200px;" id="searchWrap">
            <i class="fa fa-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#888; font-size:11px;"></i>
            <input type="text" id="searchIndependiente" placeholder="Buscar por nombre..." autocomplete="off"
               style="width:100%; height:32px; padding:0 8px 0 24px; border-radius:6px; border:1px solid #cfd3d7; font-size:12px; outline:none; box-sizing:border-box;">
            <div id="searchResults" style="display:none; position:absolute; left:0; top:calc(100% + 4px); z-index:3000; width:100%; background:#fff; border:1px solid #cfd3d7; border-radius:8px; box-shadow:0 8px 16px rgba(0,0,0,0.15); max-height:300px; overflow-y:auto;"></div>
        </div>
      </div>
      <div class="right">
        <?php 
          $linkVolver = "menu.php";
          $textoVolver = "Volver al Menú";
          if (isset($idCF)) {
            $linkVolver = "rite_alumno.php?id_curso=" . $idCF;
            $textoVolver = "Volver al Listado";
          }
        ?>
        <button onclick="window.print()" style="height:32px; padding:0 14px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer; font-size:12px; display:inline-flex; align-items:center; gap:6px;"><i class="fa fa-print"></i> Imprimir</button>
        <a href="<?= $linkVolver ?>" style="text-decoration:none;"><button style="height:32px; padding:0 14px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer; font-size:12px; display:inline-flex; align-items:center; gap:6px;"><i class="fa fa-arrow-left"></i> <?= $textoVolver ?></button></a>
      </div>
    </div>
    <?php
      // Formateo de fecha en español simple
      function fecha_es($ts=null){
        $ts = $ts ?: time();
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $d = date('j', $ts);
        $m = $meses[intval(date('n',$ts))-1];
        $y = date('Y', $ts);
        return $d.' de '.$m.' de '.$y;
      }
      $fechaHoy = fecha_es();
      // Buscar logo en varias ubicaciones/formatos
      if (file_exists(__DIR__.'/fotos/logo.PNG')) { $logoPath = 'fotos/logo.PNG'; }
      elseif (file_exists(__DIR__.'/fotos/logo.png')) { $logoPath = 'fotos/logo.png'; }
      elseif (file_exists(__DIR__.'/fotos/logo.JPG')) { $logoPath = 'fotos/logo.JPG'; }
      elseif (file_exists(__DIR__.'/fotos/logo.jpg')) { $logoPath = 'fotos/logo.jpg'; }
      elseif (file_exists(__DIR__.'/logo.PNG')) { $logoPath = 'logo.PNG'; }
      elseif (file_exists(__DIR__.'/logo.png')) { $logoPath = 'logo.png'; }
      elseif (file_exists(__DIR__.'/resources/logo.png')) { $logoPath = 'resources/logo.png'; }
      elseif (file_exists(__DIR__.'/resources/logo.PNG')) { $logoPath = 'resources/logo.PNG'; }
      else { $logoPath = ''; }
    ?>
    <div class="rite-page-sheet">
      <div class="rite-topbar">REGISTRO INSTITUCIONAL DE TRAYECTORIA EDUCATIVA (RITE) | CICLO <?php echo date('Y'); ?></div>
    <div class="rite-head">
      <div class="rite-logo">
        <?php if ($logoPath): ?><img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo"><?php endif; ?>
      </div>
      <div class="rite-title">
        <h2>INSTITUTO ADOLFO ALSINA</h2>
        <div class="sub">NIVEL SECUNDARIO | DIEGEP 6962</div>
        <table class="rite-meta" style="border:none; border-collapse:collapse;">
          <tr>
            <td class="label">Apellido y Nombre:</td>
            <td class="header-data name-data"><?php echo htmlspecialchars($alumnoNombre ?: ''); ?></td>
            <td class="label">Fecha:</td>
            <td class="header-data" style="background:#e9f3fb;"><?php echo htmlspecialchars($fechaHoy); ?></td>
          </tr>
          <tr>
            <td class="label">Curso:</td>
            <td class="header-data"><?php echo htmlspecialchars($alumnoCurso ?: ''); ?></td>
            <td class="label">Nº:</td>
            <td class="header-data" style="background:#e9f3fb;"><?php echo htmlspecialchars((string)$idAlumno); ?></td>
          </tr>
          <tr>
            <td class="label">Libro:</td>
            <td class="header-data"><?php echo htmlspecialchars($alumnoLibro ?: ''); ?></td>
            <td class="label">Folio:</td>
            <td class="header-data"><?php echo htmlspecialchars($alumnoFolio ?: ''); ?></td>
          </tr>
        </table>
      </div>
      <div class="rite-empty"></div>
    </div>
    

    <?php
      // Obtener todas las materias (curso_materia) del curso del alumno
      $materiasCM = [];
      // Aplicar filtro para mostrar solo materias Programáticas si existe la columna 'tipo'
      $filtroTipo = '';
      if ($chk = $conexion->query("SHOW COLUMNS FROM materias LIKE 'tipo'")) {
        if ($chk->num_rows > 0) { $filtroTipo = " AND m.tipo = 'Programática'"; }
      }
      $rsCM = $conexion->query(
        "SELECT cm.id_curso_materia, m.nombre_materia, c.nombre_curso\n".
        "                                FROM alumnos a\n".
        "                                INNER JOIN curso_materia cm ON cm.id_curso = a.id_curso\n".
        "                                INNER JOIN cursos c ON c.id_curso = cm.id_curso\n".
        "                                INNER JOIN materias m ON m.id_materia = cm.id_materia\n".
        "                                WHERE a.id_alumno = $idAlumno".$filtroTipo.
        "                                ORDER BY m.nombre_materia"
      );
      if ($rsCM) { $materiasCM = $rsCM->fetch_all(MYSQLI_ASSOC); }

      // Cargar todas las notas del alumno para las materias listadas, indexadas por id_curso_materia
      $ids = array_column($materiasCM, 'id_curso_materia');
      $notasPorCM = [];
      if (!empty($ids)) {
        $in = implode(',', array_map('intval', $ids));
        $rsN = $conexion->query("SELECT * FROM notas WHERE id_alumno = $idAlumno AND id_curso_materia IN ($in)");
        if ($rsN) {
          while ($r = $rsN->fetch_assoc()) { $notasPorCM[(int)$r['id_curso_materia']] = $r; }
        }
      }

      // Cargar catálogo de observaciones (id_observacion => descripcion)
      $obsMap = [];
      if ($obsRes = $conexion->query("SELECT id_observacion, descripcion FROM observaciones")) {
        while ($o = $obsRes->fetch_assoc()) { $obsMap[(int)$o['id_observacion']] = (string)$o['descripcion']; }
      }

      // Helpers flexibles para leer posibles nombres de columnas
      function nget($row, $keys){
        foreach ($keys as $k){ if (isset($row[$k]) && $row[$k] !== '') return $row[$k]; }
        return '';
      }
      function year_from_course($name){
        if (!$name) return '';
        if (preg_match('/(\d{1,2})/u', $name, $m)) return $m[1];
        return '';
      }
    ?>
    <table class="table" style="table-layout:fixed;">
      <thead>
        <tr>
          <th rowspan="3" style="width:26%; background:#d0e0e3;">MATERIAS PROGRAMÁTICAS</th>
          <th rowspan="3" style="width:3%; background:#d0e0e3;">AÑO</th>
          <th colspan="7" style="background:#b6d7a8; text-align:center;">CALIFICACIONES</th>
          <th rowspan="3" style="width:6%; background:#93c47d;">CALIFICACIÓN FINAL</th>
          <th rowspan="3" style="width:24%; background:#c9d0d6;">OBSERVACIONES</th>
        </tr>
        <tr>
          <th colspan="2" style="background:#b6d7a8;">1° CUATRIMESTRE</th>
          <th colspan="2" style="background:#b6d7a8;">2° CUATRIMESTRE</th>
          <th colspan="3" style="background:#cfe2f3; text-align:center;">INTENSIFICACIÓN</th>
        </tr>
        <tr>
          <th style="width:9%;">1° Valoración preliminar</th>
          <th style="width:9%;">Calificación</th>
          <th style="width:9%;">2° Valoración preliminar</th>
          <th style="width:9%;">Calificación</th>
          <th style="width:9%;">Intensificación 1° cuatrimestre</th>
          <th style="width:9%;">DICIEMBRE</th>
          <th style="width:9%;">FEBRERO</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($materiasCM as $row): $cmid = (int)$row['id_curso_materia']; $n = $notasPorCM[$cmid] ?? []; ?>
          <?php
            $anio = year_from_course($row['nombre_curso'] ?? '');
            $v1  = nget($n, ['valoracion_1','valoracion1','vp1','val1','valoracion_preliminar_1']);
            $c1  = nget($n, ['calificacion_1','calificacion1','cal1','c1','nota1']);
            $v2  = nget($n, ['valoracion_2','valoracion2','vp2','val2','valoracion_preliminar_2']);
            $c2  = nget($n, ['calificacion_2','calificacion2','cal2','c2','nota2']);
            $i1c = nget($n, ['intensificacion_1','int_1c','int1c','intensificacion_1c','intensificacion_1cuatrimestre','int_1cuatri']);
            $dic = nget($n, ['int_dic','diciembre','intensificacion_dic','intensificacion_diciembre']);
            $feb = nget($n, ['int_feb','febrero','intensificacion_feb','intensificacion_febrero']);
            $fin = nget($n, ['cal_final','final','calificacion_final']);
            $obs1Id = nget($n, ['observacion_1','obs_1c','observaciones_1c','obs1']);
            $obs2Id = nget($n, ['observacion_2','obs_2c','observaciones_2c','obs2']);
            $obs1Text = $obsMap[(int)$obs1Id] ?? '';
            $obs2Text = $obsMap[(int)$obs2Id] ?? '';
          ?>
          <tr>
            <td><?php echo htmlspecialchars($row['nombre_materia']); ?></td>
            <td style="text-align:center;">&nbsp;<?php echo htmlspecialchars($anio); ?></td>
            <td><?php echo htmlspecialchars($v1); ?></td>
            <td><?php echo htmlspecialchars($c1); ?></td>
            <td><?php echo htmlspecialchars($v2); ?></td>
            <td><?php echo htmlspecialchars($c2); ?></td>
            <td><?php echo htmlspecialchars($i1c); ?></td>
            <td><?php echo htmlspecialchars($dic); ?></td>
            <td><?php echo htmlspecialchars($feb); ?></td>
            <td><?php echo htmlspecialchars($fin); ?></td>
            <td>
              <div class="obs-item">1°C <?php echo htmlspecialchars($obs1Text); ?></div>
              <div class="obs-item">2°C <?php echo htmlspecialchars($obs2Text); ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
      // Listar Materias Extraprogramáticas debajo (solo si existe la columna 'tipo')
      $extraMaterias = [];
      $extraNotas = [];
      $tieneTipo = false;
      if ($__chkTipo = $conexion->query("SHOW COLUMNS FROM materias LIKE 'tipo'")) {
        $tieneTipo = ($__chkTipo->num_rows > 0);
      }
      if ($tieneTipo) {
        $rsExtra = $conexion->query(
          "SELECT cm.id_curso_materia, m.nombre_materia, c.nombre_curso\n".
          "FROM alumnos a\n".
          "INNER JOIN curso_materia cm ON cm.id_curso = a.id_curso\n".
          "INNER JOIN cursos c ON c.id_curso = cm.id_curso\n".
          "INNER JOIN materias m ON m.id_materia = cm.id_materia\n".
          "WHERE a.id_alumno = $idAlumno AND m.tipo = 'Extraprogramática'\n".
          "ORDER BY m.nombre_materia"
        );
        if ($rsExtra) { $extraMaterias = $rsExtra->fetch_all(MYSQLI_ASSOC); }
        if (!empty($extraMaterias)) {
          $idsExtra = array_column($extraMaterias, 'id_curso_materia');
          $inExtra = implode(',', array_map('intval', $idsExtra));
          $rsNE = $conexion->query("SELECT * FROM notas WHERE id_alumno = $idAlumno AND id_curso_materia IN ($inExtra)");
          if ($rsNE) {
            while ($r = $rsNE->fetch_assoc()) { $extraNotas[(int)$r['id_curso_materia']] = $r; }
          }
        }
      }
    ?>
    <?php if (!empty($extraMaterias)): ?>

    <table class="table" style="table-layout:fixed;">
      <thead>
        <tr>
          <th rowspan="3" style="width:26%; background:#d0e0e3;">MATERIAS EXTRAPROGRAMÁTICAS</th>
          <th rowspan="3" style="width:3%; background:#d0e0e3;">AÑO</th>
          <th colspan="7" style="background:#b6d7a8; text-align:center;">CALIFICACIONES</th>
          <th rowspan="3" style="width:6%; background:#93c47d;">CALIFICACIÓN FINAL</th>
          <th rowspan="3" style="width:24%; background:#c9d0d6;">OBSERVACIONES</th>
        </tr>
        <tr>
          <th colspan="2" style="background:#b6d7a8;">1° CUATRIMESTRE</th>
          <th colspan="2" style="background:#b6d7a8;">2° CUATRIMESTRE</th>
          <th colspan="3" style="background:#cfe2f3; text-align:center;">INTENSIFICACIÓN</th>
        </tr>
        <tr>
          <th style="width:9%;">1° Valoración preliminar</th>
          <th style="width:9%;">Calificación</th>
          <th style="width:9%;">2° Valoración preliminar</th>
          <th style="width:9%;">Calificación</th>
          <th style="width:9%;">Intensificación 1° cuatrimestre</th>
          <th style="width:9%;">DICIEMBRE</th>
          <th style="width:9%;">FEBRERO</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($extraMaterias as $row): $cmid = (int)$row['id_curso_materia']; $n = $extraNotas[$cmid] ?? []; ?>
          <?php
            $anio = year_from_course($row['nombre_curso'] ?? '');
            $v1  = nget($n, ['valoracion_1','valoracion1','vp1','val1','valoracion_preliminar_1']);
            $c1  = nget($n, ['calificacion_1','calificacion1','cal1','c1','nota1']);
            $v2  = nget($n, ['valoracion_2','valoracion2','vp2','val2','valoracion_preliminar_2']);
            $c2  = nget($n, ['calificacion_2','calificacion2','cal2','c2','nota2']);
            $i1c = nget($n, ['intensificacion_1','int_1c','int1c','intensificacion_1c','intensificacion_1cuatrimestre','int_1cuatri']);
            $dic = nget($n, ['int_dic','diciembre','intensificacion_dic','intensificacion_diciembre']);
            $feb = nget($n, ['int_feb','febrero','intensificacion_feb','intensificacion_febrero']);
            $fin = nget($n, ['cal_final','final','calificacion_final']);
            $obs1Id = nget($n, ['observacion_1','obs_1c','observaciones_1c','obs1']);
            $obs2Id = nget($n, ['observacion_2','obs_2c','observaciones_2c','obs2']);
            $obs1Text = $obsMap[(int)$obs1Id] ?? '';
            $obs2Text = $obsMap[(int)$obs2Id] ?? '';
          ?>
          <tr>
            <td><?php echo htmlspecialchars($row['nombre_materia']); ?></td>
            <td style="text-align:center;">&nbsp;<?php echo htmlspecialchars($anio); ?></td>
            <td><?php echo htmlspecialchars($v1); ?></td>
            <td><?php echo htmlspecialchars($c1); ?></td>
            <td><?php echo htmlspecialchars($v2); ?></td>
            <td><?php echo htmlspecialchars($c2); ?></td>
            <td><?php echo htmlspecialchars($i1c); ?></td>
            <td><?php echo htmlspecialchars($dic); ?></td>
            <td><?php echo htmlspecialchars($feb); ?></td>
            <td><?php echo htmlspecialchars($fin); ?></td>
            <td>
              <div class="obs-item">1°C <?php echo htmlspecialchars($obs1Text); ?></div>
              <div class="obs-item">2°C <?php echo htmlspecialchars($obs2Text); ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php
      // Tabla de Materias que recursa (según notas.estado = 'recursa')
      $recursaMaterias = [];
      $recursaNotas = [];
      $rsRec = $conexion->query(
        "SELECT cm.id_curso_materia, m.nombre_materia, c.nombre_curso\n".
        "FROM notas n\n".
        "INNER JOIN curso_materia cm ON cm.id_curso_materia = n.id_curso_materia\n".
        "INNER JOIN cursos c ON c.id_curso = cm.id_curso\n".
        "INNER JOIN materias m ON m.id_materia = cm.id_materia\n".
        "WHERE n.id_alumno = $idAlumno AND n.id_estado = 2\n".
        "ORDER BY m.nombre_materia"
      );
      if ($rsRec) { $recursaMaterias = $rsRec->fetch_all(MYSQLI_ASSOC); }
      if (empty($recursaMaterias)) {
        $recursaMaterias = [ [], [], [] ];
      }
      if (!empty($recursaMaterias)) {
        $idsRec = array_column($recursaMaterias, 'id_curso_materia');
        $inRec = implode(',', array_map('intval', array_filter($idsRec)));
        if ($inRec) {
          $rsNR = $conexion->query("SELECT * FROM notas WHERE id_alumno = $idAlumno AND id_curso_materia IN ($inRec)");
          if ($rsNR) {
            while ($r = $rsNR->fetch_assoc()) { $recursaNotas[(int)$r['id_curso_materia']] = $r; }
          }
        }
      }
    ?>
    <table class="table" style="table-layout:fixed;">
      <thead>
        <tr>
          <th rowspan="3" style="width:26%; background:#d0e0e3;">MATERIAS QUE RECURSA</th>
          <th rowspan="3" style="width:3%; background:#d0e0e3;">AÑO</th>
          <th colspan="7" style="background:#b6d7a8; text-align:center;">CALIFICACIONES</th>
          <th rowspan="3" style="width:6%; background:#93c47d;">CALIFICACIÓN FINAL</th>
          <th rowspan="3" style="width:24%; background:#c9d0d6;">OBSERVACIONES</th>
        </tr>
        <tr>
          <th colspan="2" style="background:#b6d7a8;">1° CUATRIMESTRE</th>
          <th colspan="2" style="background:#b6d7a8;">2° CUATRIMESTRE</th>
          <th colspan="3" style="background:#cfe2f3; text-align:center;">INTENSIFICACIÓN</th>
        </tr>
        <tr>
          <th style="width:9%;">1° Valoración preliminar</th>
          <th style="width:9%;">Calificación</th>
          <th style="width:9%;">2° Valoración preliminar</th>
          <th style="width:9%;">Calificación</th>
          <th style="width:9%;">Intensificación 1° cuatrimestre</th>
          <th style="width:9%;">DICIEMBRE</th>
          <th style="width:9%;">FEBRERO</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recursaMaterias as $row): $cmid = (int)($row['id_curso_materia'] ?? 0); $n = $recursaNotas[$cmid] ?? []; ?>
          <?php
            $anio = year_from_course($row['nombre_curso'] ?? '');
            $v1  = nget($n, ['valoracion_1','valoracion1','vp1','val1','valoracion_preliminar_1']);
            $c1  = nget($n, ['calificacion_1','calificacion1','cal1','c1','nota1']);
            $v2  = nget($n, ['valoracion_2','valoracion2','vp2','val2','valoracion_preliminar_2']);
            $c2  = nget($n, ['calificacion_2','calificacion2','cal2','c2','nota2']);
            $i1c = nget($n, ['intensificacion_1','int_1c','int1c','intensificacion_1c','intensificacion_1cuatrimestre','int_1cuatri']);
            $dic = nget($n, ['int_dic','diciembre','intensificacion_dic','intensificacion_diciembre']);
            $feb = nget($n, ['int_feb','febrero','intensificacion_feb','intensificacion_febrero']);
            $fin = nget($n, ['cal_final','final','calificacion_final']);
            $obs1Id = nget($n, ['observacion_1','obs_1c','observaciones_1c','obs1']);
            $obs2Id = nget($n, ['observacion_2','obs_2c','observaciones_2c','obs2']);
            $obs1Text = $obsMap[(int)$obs1Id] ?? '';
            $obs2Text = $obsMap[(int)$obs2Id] ?? '';
          ?>
          <tr>
            <td><?php echo htmlspecialchars($row['nombre_materia'] ?? ''); ?></td>
            <td style="text-align:center;">&nbsp;<?php echo htmlspecialchars($anio); ?></td>
            <td><?php echo htmlspecialchars($v1 ?? ''); ?></td>
            <td><?php echo htmlspecialchars($c1 ?? ''); ?></td>
            <td><?php echo htmlspecialchars($v2 ?? ''); ?></td>
            <td><?php echo htmlspecialchars($c2 ?? ''); ?></td>
            <td><?php echo htmlspecialchars($i1c ?? ''); ?></td>
            <td><?php echo htmlspecialchars($dic ?? ''); ?></td>
            <td><?php echo htmlspecialchars($feb ?? ''); ?></td>
            <td><?php echo htmlspecialchars($fin ?? ''); ?></td>
            <td>
              <div class="obs-item">1°C <?php echo htmlspecialchars($obs1Text ?? ''); ?></div>
              <div class="obs-item">2°C <?php echo htmlspecialchars($obs2Text ?? ''); ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
      // Materias pendientes de aprobación y acreditación - Intensificación (desde tabla 'intensificaciones')
      $intRows = [];
      $rsInt = $conexion->query(
        "SELECT i.*, cm.id_curso_materia, m.nombre_materia, c.nombre_curso\n".
        "FROM notas i\n".
        "INNER JOIN curso_materia cm ON cm.id_curso_materia = i.id_curso_materia\n".
        "INNER JOIN cursos c ON c.id_curso = cm.id_curso\n".
        "INNER JOIN materias m ON m.id_materia = cm.id_materia\n".
        "WHERE i.id_alumno = $idAlumno AND i.id_estado IN (3, 4)\n".
        "ORDER BY c.nombre_curso, m.nombre_materia"
      );
      if ($rsInt) { $intRows = $rsInt->fetch_all(MYSQLI_ASSOC); }
      // Asegurar al menos 5 filas (reales + vacías)
      $minRows = 5;
      while (count($intRows) < $minRows) {
          $intRows[] = [];
      }

      // --- Gestión de Asistencias ---
      $asistQ1 = 0; $asistQ2 = 0; $cicloA = date('Y');
      $resAs = $conexion->query("SELECT cuatrimestre, inasistencias FROM inasistencias_resumen WHERE id_alumno = $idAlumno AND ciclo_lectivo = $cicloA");
      if ($resAs) {
          while ($ra = $resAs->fetch_assoc()) {
              if ($ra['cuatrimestre'] == 1) $asistQ1 = (float)$ra['inasistencias'];
              if ($ra['cuatrimestre'] == 2) $asistQ2 = (float)$ra['inasistencias'];
          }
      }
      $resCfgA = $conexion->query("SELECT clave, valor_int FROM configuraciones WHERE clave IN ('dias_habiles_q1', 'dias_habiles_q2')");
      $dhQ1 = 0; $dhQ2 = 0;
      if ($resCfgA) {
          while ($rc = $resCfgA->fetch_assoc()) {
              if ($rc['clave'] === 'dias_habiles_q1') $dhQ1 = (int)$rc['valor_int'];
              if ($rc['clave'] === 'dias_habiles_q2') $dhQ2 = (int)$rc['valor_int'];
          }
      }
      function fmtInas($val) {
          if ($val == 0) return "0";
          $ent = floor($val);
          $dec = $val - $ent;
          $fr = "";
          if (abs($dec - 0.25) < 0.01) $fr = "1/4";
          if (abs($dec - 0.50) < 0.01) $fr = "1/2";
          if (abs($dec - 0.75) < 0.01) $fr = "3/4";
          return ($ent > 0 ? (int)$ent : "0") . ($fr ? " " . $fr : "");
      }
    ?>
    <div style="display:flex; gap:10px; align-items:stretch; margin-top:20px;">
      <div style="width:76%; display:flex; flex-direction:column;">
        <table class="table" style="table-layout:fixed; width:100%; margin-top:0; height:100%;">
          <thead>
            <tr>
              <th colspan="10" style="background:#cfe2f3; font-weight:700;">MATERIAS PENDIENTES DE APROBACIÓN Y ACREDITACIÓN- INTENSIFICACIÓN</th>
            </tr>
            <tr>
              <th style="width:34%;">MATERIA</th>
              <th style="width:6%;">AÑO</th>
              <th style="width:13%;">CICLO LECTIVO</th>
              <th colspan="6" style="background:#e9f3fb; text-align:center;">PERÍODOS DE INTENSIFICACIÓN</th>
              <th style="width:10%;">Calific. Final</th>
            </tr>
            <tr>
              <th></th><th></th><th></th>
              <th style="width:6%;">Marzo</th><th style="width:6%;">Julio</th><th style="width:6%;">Agosto</th>
              <th style="width:6%;">Nov.</th><th style="width:7%;">Dic.</th><th style="width:6%;">Feb.</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($intRows as $ir): ?>
              <?php
                $mId = $ir['id_materia'] ?? 0;
                $anio = year_from_course($ir['nombre_curso'] ?? '');
                $ciclo = nget($ir, ['ciclo_lectivo','ciclo','anio','anio_lectivo']);
                $mar = nget($ir, ['marzo','int_marzo']);
                $jul = nget($ir, ['julio','int_julio']);
                $ago = nget($ir, ['agosto','int_agosto']);
                $nov = nget($ir, ['noviembre','int_noviembre']);
                $dic = nget($ir, ['diciembre','int_diciembre']);
                $feb = nget($ir, ['febrero','int_febrero']);
                $fin = nget($ir, ['calificacion_final','cal_final','final']);
              ?>
              <tr style="height:25px;">
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($ir['nombre_materia'] ?? ''); ?></td>
                <td style="text-align:center; padding:2px 6px;"><?php echo htmlspecialchars($anio); ?></td>
                <td style="text-align:center; padding:2px 6px;"><?php echo htmlspecialchars($ciclo ?: ($mId ? date('Y') : '')); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($mar ?? ''); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($jul ?? ''); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($ago ?? ''); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($nov ?? ''); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($dic ?? ''); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($feb ?? ''); ?></td>
                <td style="padding:2px 6px;"><?php echo htmlspecialchars($fin ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="width:23.5%; display:flex; flex-direction:column;">
        <table class="table" style="width:100%; border-collapse:collapse; margin-top:0; height:100%;">
          <thead>
            <tr><th style="background:#d0e0e3; padding:5px; font-weight:700;">ASISTENCIAS</th></tr>
          </thead>
          <tbody style="font-size:11px;">
            <tr><td style="padding:2px 6px; border-bottom:none; line-height:1.1;"><strong>PRIMER CUATRIMESTRE</strong></td></tr>
            <tr><td style="padding:1px 6px; border-top:none; border-bottom:1px solid #eee; line-height:1;">DIAS HÁBILES: <?=$dhQ1?></td></tr>
            <tr><td style="padding:1px 6px; border-top:none; line-height:1;">INASISTENCIAS: <?=fmtInas($asistQ1)?></td></tr>
            
            <tr><td style="padding:4px 6px 1px 6px; border-bottom:none; line-height:1.1;"><strong>SEGUNDO CUATRIMESTRE</strong></td></tr>
            <tr><td style="padding:1px 6px; border-top:none; border-bottom:1px solid #eee; line-height:1;">DIAS HÁBILES: <?=$dhQ2?></td></tr>
            <tr><td style="padding:1px 6px; border-top:none; line-height:1;">INASISTENCIAS: <?=fmtInas($asistQ2)?></td></tr>
            
            <tr><td style="padding:4px 6px; border-top:2px solid #000; font-size:12px; line-height:1;"><strong>TOTAL DE INASISTENCIAS: <?=fmtInas($asistQ1 + $asistQ2)?></strong></td></tr>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>
</body>
<script>
// Datos base para el buscador independiente 
const alumnosData = <?= json_encode($alumnosList) ?>;

function filtrarSelectDependent(idCurso) {
    const selA = document.getElementById('selectAlumno');
    const options = selA.querySelectorAll('option');
    options.forEach(opt => {
        if (!opt.value) return; 
        const alCur = opt.getAttribute('data-curso');
        if (!idCurso || alCur == idCurso) {
            opt.style.display = ""; opt.disabled = false;
        } else {
            opt.style.display = "none"; opt.disabled = true;
        }
    });
    if (selA.selectedOptions[0] && selA.selectedOptions[0].style.display === 'none') {
        selA.value = "";
    }
}

function irAAlumno(idAl) {
    if (!idAl) return;
    const idCur = document.getElementById('selectCurso').value;
    window.location.href = 'rite_alumno.php?id_alumno=' + idAl + (idCur ? '&id_curso=' + idCur : '');
}

// Lógica del Buscador Independiente con Dropdown 
(function(){
    const input = document.getElementById('searchIndependiente');
    const results = document.getElementById('searchResults');
    if (!input || !results) return;

    input.addEventListener('input', function() {
        const qRaw = this.value.trim().toUpperCase();
        if (qRaw.length < 2) { results.style.display = 'none'; return; }

        // Dividir la búsqueda en palabras, quitando comas y espacios extras
        const qWords = qRaw.replace(/,/g, ' ').split(/\s+/).filter(w => w.length > 0);
        
        const filtered = alumnosData.filter(a => {
            const fullName = (a.apellido + ' ' + a.nombre).toUpperCase().replace(/,/g, ' ');
            const fullCourse = (a.nombre_curso || '').toUpperCase();
            const targetStr = fullName + ' ' + fullCourse;
            
            // El alumno coincide si TODAS las palabras buscadas están en su nombre/curso
            return qWords.every(word => targetStr.includes(word));
        });

        if (filtered.length === 0) {
            results.innerHTML = '<div style="padding:10px; font-size:12px; color:#999; text-align:center;">No se encontraron resultados</div>';
        } else {
            let html = '';
            filtered.forEach(a => {
                html += `
                <a href="rite_alumno.php?id_alumno=${a.id_alumno}&id_curso=${a.id_curso}" style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border-bottom:1px solid #f0f0f0; text-decoration:none; color:inherit; font-size:12px;">
                    <span style="font-weight:600; color:#222;">${a.apellido}, ${a.nombre}</span>
                    <span style="background:#e8f4ef; color:#16682b; padding:2px 6px; border-radius:6px; font-size:10px;">${a.nombre_curso}</span>
                </a>`;
            });
            results.innerHTML = html;
        }
        results.style.display = 'block';
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#searchWrap')) results.style.display = 'none';
    });
})();

document.addEventListener('DOMContentLoaded', () => {
    filtrarSelectDependent(document.getElementById('selectCurso').value);
});
</script>
</script>
</html>
