<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

$idUsuario = intval($_SESSION['id_usuario']);
$fechaCorteQ2 = null; // fecha de inicio del 2° cuatrimestre tomada de BD
$umbralAsistencia = null; // porcentaje mínimo de asistencia tomado de BD
try {
    $cfg = $conexion->query("SELECT clave, valor_date FROM configuraciones WHERE clave IN ('inicio_segundo_cuatrimestre')") ?: null;
    if ($cfg && $cfg->num_rows) {
        while($row = $cfg->fetch_assoc()){
            if ($row['clave'] === 'inicio_segundo_cuatrimestre' && !empty($row['valor_date'])) {
                $fechaCorteQ2 = $row['valor_date'];
            }
        }
    }
} catch (Exception $e) { /* silencioso: usamos fallback en cliente si fuera necesario */ }

// Cargar porcentaje de asistencia configurado
try {
    $r = $conexion->query("SELECT valor FROM configuraciones WHERE clave='porcentaje_asistencia' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $umbralAsistencia = intval($row['valor']);
    }
} catch (Exception $e) { /* ignorar */ }
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

// Permisos: Docente asignado o Administrador. Alumno solo ve (read-only) su fila.
$puedeEditar = false;
if ($perfilActual === 4) {
    $puedeEditar = true; // Admin
} elseif ($perfilActual === 3) {
    $tieneAsignacion = $conexion->query("SELECT 1
        FROM docentes d
        INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
        INNER JOIN docente_materia dm ON dm.id_docente = d.id_docente
        WHERE u.id_usuario = $idUsuario AND dm.id_curso_materia = $idCursoMateria
        LIMIT 1")->fetch_row();
    $puedeEditar = (bool)$tieneAsignacion;
} elseif ($perfilActual === 1) {
    $puedeEditar = false; // Alumno: solo lectura
}

// Listado de alumnos del curso (incluye recursantes)
$alumnos = $conexion->query("
    SELECT DISTINCT a.id_alumno, u.apellido, u.nombre,
           CASE WHEN n.id_estado = 2 THEN 1 ELSE 0 END AS es_recursante
    FROM alumnos a
    INNER JOIN usuarios u ON u.id_usuario = a.id_usuario
    LEFT JOIN curso_materia cm ON cm.id_curso = a.id_curso
    LEFT JOIN notas n ON n.id_alumno = a.id_alumno AND n.id_curso_materia = ".$idCursoMateria." AND n.id_estado = 2
    WHERE cm.id_curso_materia = ".$idCursoMateria." OR n.id_nota IS NOT NULL
    ORDER BY u.apellido, u.nombre")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Notas Parciales - <?php echo htmlspecialchars($info['nombre_materia'].' - '.$info['nombre_curso']); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
  <style>
    :root { --verde:#16682b; --w-num: 30px; --sep: 2px; --left-nombre: var(--w-num); --thead1-h: 40px; }
    .container { padding:8px; }
    .titulo-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .titulo { margin: 12px 0 8px; }
    .btn-volver { display:inline-flex; align-items:center; gap:8px; background:#16682b; color:#fff; border:1px solid #16682b; padding:6px 12px; border-radius:8px; font-size:14px; text-decoration:none; box-shadow:0 2px 4px rgba(0,0,0,0.15); }
    .btn-volver:hover { background:#1a7530; border-color:#1a7530; }
    
    /* Badge recursante */
    .sticky-nombre { padding-right:2px !important; padding-left:4px !important; }
    .nombre-wrap { display:flex !important; align-items:center !important; justify-content:space-between !important; width:100% !important; gap:4px !important; overflow:visible !important; white-space:normal !important; }
    .nombre-wrap > span:first-child { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
    .badge-recursante { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; background:#000; color:#fff; border-radius:50%; font-size:10px; font-weight:bold; flex-shrink:0; margin-right:6px; line-height:1; }

    .toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin: 8px 0 12px; }
    .toolbar label { font-weight:600; }
    .toolbar input[type="date"] { padding:6px 8px; border:1px solid #cfd3d7; border-radius:6px; }
    .toolbar .toolbar-right { margin-left:auto; display:flex; align-items:center; gap:8px; }
    .btn-add { display:inline-flex; align-items:center; gap:8px; background:#0d6efd; color:#fff; border:1px solid #0d6efd; padding:6px 12px; border-radius:8px; font-size:14px; text-decoration:none; cursor:pointer; }
    .btn-add:hover { background:#0b5ed7; border-color:#0b5ed7; }

    .tabla-wrap { overflow:auto; border:1px solid #e5e5e5; border-radius:10px; max-height:70vh; position: relative; contain: paint; }
    /* Reglas locales exclusivas para esta tabla (anulan globales) */
    #tablaAsist { width: max-content !important; table-layout: fixed !important; border-collapse: separate !important; border-spacing:0; background:#fff; color:#000; min-width:330px; position: relative; }
    table.asist th, table.asist td { border:1px solid #e5e5e5; padding:6px 8px; font-size:13px; box-sizing: border-box; }
    /* Compactar celdas de sesiones (30px) para que el select calce perfecto */
    table.asist thead .th-s { padding: 2px !important; }
    table.asist tbody .td-s { padding: 2px !important; text-align: center; }
    /* Fuerza absoluta para la columna 'Apellido y Nombre' */
    #tablaAsist th.sticky-nombre, #tablaAsist td.sticky-nombre { width:270px !important; min-width:270px !important; max-width:270px !important; }
    /* Wrapper de contenido para forzar ancho visual fijo */
    .nombre-wrap { width:270px; max-width:270px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* Fijar el ancho del 2° col (Apellido y Nombre) desde el colgroup */
    #tablaAsist col:nth-child(2) { width:270px !important; }
    /* Columna flexible para absorber ancho sobrante */
    #tablaAsist col.col-filler { width:auto !important; }
    #tablaAsist th.col-filler, #tablaAsist td.col-filler { width:auto !important; border-left:none; }
    /* Fijar explícitamente los 3 primeros anchos por celda (thead/tbody) */
    #tablaAsist thead th:nth-child(1),
    #tablaAsist tbody td:nth-child(1) { width:30px !important; min-width:30px !important; max-width:30px !important; }
    #tablaAsist thead th:nth-child(2),
    #tablaAsist tbody td:nth-child(2) { width:270px !important; min-width:270px !important; max-width:270px !important; }
    #tablaAsist thead th:nth-child(3),
    #tablaAsist tbody td:nth-child(3) { width:30px !important; min-width:30px !important; max-width:30px !important; }
    /* Header sticky (1ra fila) */
    table.asist thead tr.head-1 th {
      position: sticky;
      top: 0;
      background: #f7f7f9;
      z-index: 12;
      /* Evitar doble borde que genera saltos: usar sombra en vez de borde */
      border-bottom: 0;
      box-shadow: 0 2px 4px rgba(0,0,0,0.06);
      white-space: normal;
      height: var(--thead1-h);
      background-clip: padding-box;
      will-change: auto;
      transform: none !important;
    }
    /* Header sticky (2da fila) */
    table.asist thead tr.head-2 th {
      position: sticky;
      top: var(--thead1-h); /* posición exacta debajo de la fila 1 */
      background: #f7f7f9;
      z-index: 11;
      border-top: none;
      border-bottom: 2px solid var(--verde);
      box-shadow: 0 2px 4px rgba(0,0,0,0.06);
      white-space: normal;
      height: 64px;
      background-clip: padding-box;
      will-change: auto;
      transform: none !important;
    }
    /* Ancho mínimo visual de cada cuatrimestre: 17 columnas x 30px = 510px */
    #thQ1, #thQ2 { min-width: 510px; }

    /* Fecha (todas menos # y nombre) al fondo explícito */
    table.asist thead tr:first-child th:not(.sticky-num):not(.sticky-nombre) {
      z-index: 1 !important;
    }

    /* Sticky columnas */
    .sticky-num { position: sticky; left: 0; width: var(--w-num); min-width: var(--w-num); max-width: var(--w-num); text-align:center; background:#fff; z-index: 2001; padding: 0 !important; box-shadow: none; border-right: 0; }
    .sticky-num::after { content: ""; position: absolute; right: 0; top: 0; bottom: 0; width: var(--sep); background: var(--verde); }
    .sticky-nombre { position: sticky; left: var(--left-nombre); width: 270px !important; min-width: 270px !important; max-width: 270px !important; text-align: left; background:#fff; z-index: 2000; box-shadow: none; border-right: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sticky-nombre::before { content: none; }

    /* Asegurar fondos para encabezados sticky en columnas sticky */
    thead .sticky-num, thead .sticky-nombre { background:#f7f7f9; }
    thead .sticky-num { position: sticky; top: 0; left: 0; z-index: 3003 !important; background-clip: padding-box; transform: none; }
    thead .sticky-nombre { position: sticky; top: 0; left: var(--left-nombre); z-index: 3002 !important; background-clip: padding-box; transform: none; }
    tbody .sticky-num { z-index: 3001; background:#fff; background-clip: padding-box; }
    tbody .sticky-nombre { z-index: 3000; background:#fff; background-clip: padding-box; }
    /* Línea inferior verde específica para encabezados solicitados, sin afectar layout */
    #thQ1, #thQ2, #tablaAsist th.sticky-nombre, #tablaAsist thead th.sticky-num {
      box-shadow: inset 0 -2px 0 var(--verde);
    }
    /* Headers de sesiones: dibujar una sola línea gris a la izquierda para separar columnas */
    #tablaAsist thead th.col-s.th-s { box-shadow: none; border-left: 1px solid #e5e5e5; border-right: 0; }
    /* Mantener la línea inferior en Q1/Q2; solo Q1 lleva borde derecho.
       Para el borde final derecho usaremos la columna % Anual (no #thQ2) */
    #thQ1, #thQ2 { box-shadow: inset 0 -2px 0 var(--verde); }
    #thQ1 { border-right: 2px solid var(--verde); }
    #thQ2 { border-right: 2px solid var(--verde); }
    /* Agregar también línea derecha al header de 'Apellido y Nombre' y a sus celdas del cuerpo
       con borde real para evitar subpíxeles */
    #tablaAsist thead th.sticky-nombre { box-shadow: inset 0 -2px 0 var(--verde); border-right: 2px solid var(--verde); }
    #tablaAsist tbody td.sticky-nombre { box-shadow: none; border-right: 2px solid var(--verde); }
    thead .th-s { z-index: 50 !important; overflow:hidden; pointer-events: auto; }
    thead .th-s * { pointer-events: auto; }

    /* Columna de sesión (select P/A) */
    .col-s { width: 30px; min-width: 30px; max-width: 30px; text-align:center; }
    .th-s { position: relative; height: 64px; overflow: hidden; background: #f7f7f9 !important; background-clip: padding-box; }
    /* Etiqueta vertical (fecha) rotada 90° */
    .th-s .vlabel { 
      writing-mode: vertical-rl; 
      transform: rotate(180deg); /* para leer de abajo hacia arriba */
      display: inline-block; 
      font-size: 13px; 
      color:#000; 
      line-height: 1; 
      cursor: pointer;
    }
    /* Fuerza extra para que TODOS los TH/TD de sesión tengan el mismo ancho exacto */
    #tablaAsist th.col-s.th-s,
    #tablaAsist td.td-s { width: 30px !important; min-width: 30px !important; max-width: 30px !important; box-sizing: border-box; }
    /* Evitar que el contenido altere el ancho */
    #tablaAsist th.col-s.th-s .vlabel { display: block; width: 100%; }
    /* Centrado: sobreescribir ancho para permitir centrado efectivo */
    #tablaAsist th.col-s.th-s .vlabel { width: auto !important; margin: 0 auto; text-align: center; }
    #tablaAsist th.th-perc .vlabel { margin: 0 auto; text-align: center; }
    .td-s { text-align:center; }

    /* Notas: input ocupa exactamente el ancho de la celda y sin flechas */
    input.nota-input {
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      height: 24px;
      font-size: 12px;
      padding: 0 2px;
      text-align: center;
      border: 1px solid #cfd3d7;
      border-radius: 4px;
      background: #fff;
    }
    /* Quitar flechas en Chrome, Safari, Edge (WebKit) */
    input[type=number].nota-input::-webkit-outer-spin-button,
    input[type=number].nota-input::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    /* Quitar flechas en Firefox */
    input[type=number].nota-input {
      -moz-appearance: textfield;
    }
    .th-spacer { width:0 !important; padding:0 !important; border:none !important; }
    .td-spacer { width:30px !important; min-width:30px !important; max-width:30px !important; border:none !important; background:#f7f7f9; }
    .sel-estado { 
      width: 100%; height: 24px; font-size: 12px; 
      border:1px solid #cfd3d7; border-radius:4px; 
      padding:0; box-sizing: border-box; 
      text-align-last: center; -moz-text-align-last: center; 
      appearance: none; -webkit-appearance: none; -moz-appearance: none;
      background: #fff; background-image: none;
    }
    /* Ocultar flecha en IE/Edge heredado */
    .sel-estado::-ms-expand { display: none; }
    .mark { width: 14px; height: 14px; border-radius: 50%; display:inline-block; border:1px solid #cfd3d7; background:#fff; cursor:pointer; }
    .mark.p.on { background:#0a7c2f; border-color:#0a7c2f; }
    .mark.a.on { background:#a10000; border-color:#a10000; }

    .pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:16px; border:1px solid #cfd3d7; cursor:pointer; user-select:none; }
    .pill input { display:none; }
    .pill.present { background:#e6f4ea; color:#0a7c2f; border-color:#b7e1c3; }
    .pill.absent { background:#fdecea; color:#a10000; border-color:#f5c2c7; }
    .pill .dot { width:8px; height:8px; border-radius:50%; }
    .pill.present .dot { background:#0a7c2f; }
    .pill.absent .dot { background:#a10000; }

    .badge-ok { color:#0a7c2f; background:#dff3e3; padding:6px 10px; border-radius:8px; font-size:12px; }
    /* Resaltar la celda de Nombre de la fila activa (cuando algún control tiene foco) */
    #tablaAsist tbody tr:focus-within td.sticky-nombre {
      background: #fff8cc !important; /* amarillo suave */
    }
    /* Columnas de porcentaje por cuatrimestre */
    #tablaAsist th.th-perc, #tablaAsist td.td-perc { width: 40px !important; min-width: 40px !important; max-width: 40px !important; text-align: center; }
    #tablaAsist th.th-perc { background:#f7f7f9; font-weight:700; padding: 2px !important; }
    #tablaAsist td.td-perc { padding: 2px !important; white-space: nowrap; vertical-align: middle; font-variant-numeric: tabular-nums; }
    /* Bordes verdes solicitados */
    /* Evitar doble borde entre % Q1 y la primera fecha: sin borde en % Q1, usar el borde izquierdo de la fecha */
    #tablaAsist thead th.th-perc.th-perc-q1 { box-shadow: none; }
    /* Separador entre % Q2 y % Anual: pintar borde derecho en % Q2 (thead/tbody) */
    #tablaAsist thead th.th-perc.th-perc-q2 { border-right: 2px solid var(--verde) !important; }
    #tablaAsist tbody td.td-perc[data-perc="q2"] { border-right: 2px solid var(--verde) !important; }
    /* % Anual: sin borde inferior extra (evitar doble), sin borde izquierdo y con borde derecho final */
    #tablaAsist thead th.th-perc.th-perc-anual { box-shadow: none; border-left: 0 !important; border-right: 2px solid var(--verde) !important; }
    #tablaAsist tbody td.td-perc[data-perc="anual"] { border-left: 0 !important; border-right: 2px solid var(--verde) !important; }
    /* Alinear separador verde ENTRE % Q1 y la primera fecha: usar borde derecho en % Q1
       (tanto en thead como en tbody) y NO pintar en la primera fecha. Esto evita el desfasaje
       producido por box-shadow con border-collapse: separate. */
    #tablaAsist thead th.th-perc.th-perc-q1 { border-right: 2px solid var(--verde) !important; }
    #tablaAsist tbody td.td-perc[data-perc="q1"] { border-right: 2px solid var(--verde) !important; }
    /* Quitar borde inset en la primera fecha para que no duplique ni desalineé */
    #tablaAsist thead th.th-perc.th-perc-q1 + th.th-s { box-shadow: none !important; }
    /* Y quitar también el borde izquierdo gris en esa primera fecha (thead y tbody) */
    #tablaAsist thead th.th-perc.th-perc-q1 + th.th-s { border-left: 0 !important; }
    #tablaAsist tbody td.td-perc[data-perc="q1"] + td.td-s { border-left: 0 !important; }
    /* Doble línea entre Q1 y Q2: desactivada */
    .sep-double-left { position: relative; }
    .sep-double-left::before { content: none !important; display: none !important; }
    .sep-double-right { position: relative; overflow: visible; }
    .sep-double-right::after { content: none !important; display: none !important; }
    /* Dejar espacio interno para que el texto no toque la doble línea */
    #tablaAsist td.td-perc.sep-double-right { padding-right: 10px !important; }
    #tablaAsist thead th.th-perc.th-perc-q1.sep-double-right { padding-right: 8px !important; }
    /* Colores por umbral de asistencia */
    #tablaAsist td.td-perc.perc-ok { background:#e6f4ea !important; color:#0a7c2f; font-weight:600; }
    #tablaAsist td.td-perc.perc-bad { background:#fdecea !important; color:#a10000; font-weight:600; }
    /* Etiqueta vertical para headers de % como las fechas */
    #tablaAsist th.th-perc .vlabel {
      writing-mode: vertical-rl;
      transform: rotate(180deg);
      display: inline-block;
      font-size: 13px;
      color:#000;
      line-height: 1;
    }
    /* Modal simple para edición de fecha */
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.35); display:none; align-items: center; justify-content: center; z-index: 5000; }
    .modal-box { background:#fff; border-radius:12px; padding:16px; width: 100%; max-width: 520px; box-shadow: 0 8px 30px rgba(0,0,0,0.2); position: relative; }
    .modal-box h3 { margin: 0 0 8px; }
    .modal-box .cerrar { position:absolute; right:10px; top:8px; font-size:20px; cursor:pointer; }
    .modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }
    .btn { padding:8px 12px; border-radius:8px; border:1px solid #cfd3d7; cursor:pointer; }
    .btn-primary { background:#0d6efd; border-color:#0d6efd; color:#fff; }
    .btn-primary:hover { background:#0b5ed7; border-color:#0b5ed7; }
    .btn-light { background:#f7f7f9; }
    /* Tooltip global para encabezados (dos líneas, borde redondeado) */
    .th-tip-global {
      position: fixed;
      transform: translate(-50%, -8px);
      background: rgba(0,0,0,0.86);
      color: #fff;
      padding: 6px 10px;
      border-radius: 8px; /* bordes redondeados */
      font-size: 12px;
      line-height: 1.25;
      white-space: normal; /* permitir múltiples líneas */
      max-width: 250px; /* límite de ancho */
      word-break: break-word;
      overflow-wrap: anywhere;
      z-index: 99999;
      pointer-events: none;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
      display: flex; align-items: center; gap: 8px;
    }
    .th-tip-global strong { font-weight: 700; display: block; }
    .th-tip-global .hint { opacity: 0.9; }
    .th-tip-global .tip-date {
      background: rgba(255,255,255,0.12);
      border-radius: 6px;
      padding: 4px 3px;
      font-weight: 700;
      letter-spacing: 0.3px;
      writing-mode: vertical-rl;
      transform: rotate(180deg);
      font-size: 11px;
      line-height: 1;
    }
    .th-tip-global .tip-body { display: flex; flex-direction: column; min-width: 0; }
    .th-tip-global .tip-body strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    /* Tooltip para inputs de nota */
    .input-tip {
      position: fixed; /* relativo a viewport para posicionar con getBoundingClientRect */
      background: rgba(0,0,0,0.86);
      color: #fff;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 12px;
      line-height: 1.25;
      white-space: nowrap;
      z-index: 10000;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
      pointer-events: none;
    }
    /* Ajustes del modal de mensajes: mitad del ancho original (520px -> 260px) y título con icono */
    #modalMsg .modal-box { max-width: 260px; }
    #modalMsg h3 { display:flex; align-items:center; gap:8px; margin: 0 0 6px; }
    #modalMsg h3 .ico { color: #D43F22; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="container">
    <div class="titulo-bar">
      <h2 class="titulo"><i class="fa fa-pen"></i> Notas Parciales - <?php echo htmlspecialchars($info['nombre_materia']); ?> (<?php echo htmlspecialchars($info['nombre_curso']); ?>) <span style="font-size: 0.8rem; opacity: 0.7; font-weight: normal; margin-left: 10px;">[<?php echo htmlspecialchars($info['tipo'] ?: 'Programática'); ?>]</span></h2>
      <?php 
      $url_back = ($perfilActual === 4) ? 'materias.php' : 'mismaterias.php';
      ?>
      <a href="<?php echo $url_back; ?>" class="btn-volver"><i class="fa fa-arrow-left"></i> Volver</a>
    </div>

    <?php if (!$puedeEditar && $perfilActual !== 1): ?>
      <div class="badge-ok" style="background:#fde2e2; color:#a10000; margin-bottom:8px;"><i class="fa fa-triangle-exclamation"></i> No tenés permisos para editar esta materia.</div>
    <?php endif; ?>

    <div class="toolbar">
      <label for="fecha">Fecha:</label>
      <input type="date" id="fecha">
      <label for="detalleSesion">Detalle:</label>
      <input type="text" id="detalleSesion" placeholder="Ej: Parcial 1, TP 2..." style="padding:6px 8px; border:1px solid #cfd3d7; border-radius:6px; min-width:220px;">
      <button type="button" id="btnAdd" class="btn-add"><i class="fa fa-plus"></i> Agregar columna</button>
      <span id="status" style="font-size:12px; color:#6c757d;"></span>
      <div class="toolbar-right">
        <label>Umbral asistencia:</label>
        <span id="umbralLabel" class="badge-ok" style="background:#eef6ee; color:#16682b;"><?php echo htmlspecialchars(($umbralAsistencia !== null && $umbralAsistencia>0) ? ($umbralAsistencia.'%') : 'No configurado'); ?></span>
        <label>Fecha corte Q1/Q2:</label>
        <span id="fechaCorteLabel" class="badge-ok" style="background:#eef6ee; color:#16682b;"><?php echo htmlspecialchars($fechaCorteQ2 ?: 'No configurada'); ?></span>
      </div>
    </div>
    <!-- Modal genérico de mensajes -->
    <div id="modalMsg" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true" onclick="if(event.target===this) closeMsg()">
      <div class="modal-box">
        <span class="cerrar" onclick="closeMsg()">×</span>
        <h3 id="modalMsgTitle">Aviso</h3>
        <div id="modalMsgBody" style="margin-top:6px; color:#333; font-size:14px;"></div>
        <div class="modal-actions">
          <button type="button" id="modalMsgOk" class="btn btn-primary" onclick="closeMsg()">Aceptar</button>
        </div>
      </div>
    </div>

    <div class="tabla-wrap">
      <table class="asist" id="tablaAsist">
        <colgroup>
          <col style="width: var(--w-num);">  <!-- # 30px -->
          <col style="width: 270px;">        <!-- Apellido y Nombre 270px -->
          <col class="col-filler">            <!-- Filler flexible SIEMPRE al final -->
        </colgroup>
        <thead>
          <tr id="trHead1" class="head-1">
            <th class="sticky-num" rowspan="2">#</th>
            <th class="sticky-nombre" rowspan="2" style="text-align:left; width:270px;"><div class="nombre-wrap">Apellido y Nombre</div></th>
            <th id="thQ1" colspan="0" class="left-sep" style="text-align:center;">1° CUATRIMESTRE</th>
            <th id="thQ2" colspan="0" class="left-sep" style="text-align:center;">2° CUATRIMESTRE</th>
            <th class="col-filler" rowspan="2"></th>
          </tr>
          <tr id="trHead2" class="head-2">
            <!-- Aquí se insertan th de cada sesión: primero Q1, luego Q2 -->
          </tr>
        </thead>
        <tbody>
        <?php $i=1; foreach($alumnos as $al): ?>
          <tr data-alumno="<?php echo (int)$al['id_alumno']; ?>">
            <td class="sticky-num"><?php echo $i++; ?></td>
            <td class="sticky-nombre" style="text-align:left; width:270px;">
              <div class="nombre-wrap">
                <span><?php echo htmlspecialchars($al['apellido'].', '.$al['nombre']); ?></span>
                <?php if ($al['es_recursante'] == 1): ?>
                  <span class="badge-recursante" title="Recursante">R</span>
                <?php endif; ?>
              </div>
            </td>
            <!-- aquí se insertarán los td de sesiones en el orden Q1 -> Q2 -->
            <td class="col-filler"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Modal de edición de fecha y detalle -->
    <div id="modalFecha" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true" onclick="if(event.target===this) closeHeaderDateModal()">
      <div class="modal-box">
        <span class="cerrar" onclick="closeHeaderDateModal()">×</span>
        <h3>Editar sesión</h3>
        <div style="margin-bottom:10px; color:#6c757d; font-size:13px;">Cambiá la fecha y el detalle (opcional). Se reubicará en Q1/Q2 si corresponde.</div>
        <div style="margin-bottom:12px;">
          <label for="modalFechaInput" style="display:block; font-weight:600; margin-bottom:6px;">Fecha</label>
          <input type="date" id="modalFechaInput" style="width:100%; padding:8px; border:1px solid #cfd3d7; border-radius:6px;" />
        </div>
        <div style="margin-bottom:12px;">
          <label for="modalDetalleInput" style="display:block; font-weight:600; margin-bottom:6px;">Detalle (opcional)</label>
          <input type="text" id="modalDetalleInput" placeholder="Ej: Parcial 1, Trabajo Práctico..." style="width:100%; padding:8px; border:1px solid #cfd3d7; border-radius:6px;" />
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-light" onclick="closeHeaderDateModal()">Cancelar</button>
          <button type="button" class="btn btn-primary" onclick="saveHeaderDateModal()">Guardar</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    const ID_CM = <?php echo (int)$idCursoMateria; ?>;
    const puedeEditar = <?php echo $puedeEditar ? 'true':'false'; ?>;
    // Fecha de corte de cuatrimestres configurada en BD (inicio del 2° cuatrimestre)
    const FECHA_CORTE_Q2 = <?php echo $fechaCorteQ2 ? ('"'.addslashes($fechaCorteQ2).'"') : 'null'; ?>;
    // Umbral de asistencia (porcentaje) configurado en BD
    const PERC_UMBRAL = <?php echo ($umbralAsistencia !== null ? intval($umbralAsistencia) : 0); ?>; // no usado en notas
    // Estado local para permitir agregar columnas en cliente
    let sesionesLocal = [];
    let dataLocal = {};
    // Últimas separaciones de sesiones por cuatrimestre para recálculo de %
    let lastQ1 = [];
    let lastQ2 = [];

    // Funciones GLOBALES para controlar el modal desde atributos onclick
    // Nota: duplicadas fuera de renderSesiones para que existan en window
    window.modalTargetSession = null;
    window.openHeaderDateModal = function(ses){
      window.modalTargetSession = ses;
      const el = document.getElementById('modalFecha');
      const inp = document.getElementById('modalFechaInput');
      const inpDet = document.getElementById('modalDetalleInput');
      const info = document.getElementById('modalFechaInfo');
      inp.value = (ses.fecha && /^\d{4}-\d{2}-\d{2}$/.test(ses.fecha)) ? ses.fecha : '';
      if (inpDet) inpDet.value = (ses.detalle || '');
      if (info) info.textContent = `ID:${ID_CM} · Sesión:${ses.sesion || '(sin)'} · Fecha actual:${ses.fecha || '(?)'}`;
      el.style.display = 'flex';
      el.setAttribute('aria-hidden', 'false');
      setTimeout(()=> inp.focus(), 0);
    };
    window.closeHeaderDateModal = function(){
      const el = document.getElementById('modalFecha');
      el.style.display = 'none';
      el.setAttribute('aria-hidden', 'true');
      window.modalTargetSession = null;
    };
    // Modal de mensajes genérico
    window.openMsg = function(title, bodyHtml){
      const el = document.getElementById('modalMsg');
      const t = document.getElementById('modalMsgTitle');
      const b = document.getElementById('modalMsgBody');
      if (t) t.textContent = title || 'Aviso';
      if (b) b.innerHTML = bodyHtml || '';
      el.style.display = 'flex';
      el.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      // Enfocar el botón Aceptar para permitir Enter instantáneo
      setTimeout(() => {
        const ok = document.getElementById('modalMsgOk') || el.querySelector('.btn.btn-primary');
        if (ok && typeof ok.focus === 'function') ok.focus();
      }, 0);
    };
    window.closeMsg = function(){
      const el = document.getElementById('modalMsg');
      el.style.display = 'none';
      el.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    };
    window.showMsg = function(msg, title){
      const t = (title || 'Atención');
      const htmlTitle = '<i class="fa fa-triangle-exclamation ico"></i>' + t;
      openMsg(htmlTitle, (msg||'').replace(/\n/g,'<br>'));
      const th = document.getElementById('modalMsgTitle');
      if (th) th.innerHTML = htmlTitle;
    };
    window.saveHeaderDateModal = async function(){
      if (!window.modalTargetSession) return window.closeHeaderDateModal();
      const inp = document.getElementById('modalFechaInput');
      const inpDet = document.getElementById('modalDetalleInput');
      const v = inp.value;
      const detalle = inpDet ? inpDet.value : '';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { showMsg('Seleccioná una fecha válida', 'Atención'); return; }
      try{
        // UI: deshabilitar botones y mostrar estado
        const btns = document.querySelectorAll('#modalFecha .btn');
        btns.forEach(b => b.disabled = true);
        document.getElementById('status').textContent = 'Guardando fecha...';

        const ses = window.modalTargetSession;
        const isLegacy = typeof ses.sesion === 'string' && ses.sesion.startsWith('LEGACY-');
        const oldFecha = isLegacy ? (ses.fecha || ses.sesion.slice(7)) : '';
        const params = new URLSearchParams({ id: ID_CM, sesion: ses.sesion, nueva_fecha: v });
        if (detalle && detalle.trim() !== '') params.append('detalle', detalle.trim());
        if (isLegacy) params.append('old_fecha', oldFecha);
        console.log('POST cambiar_fecha_sesion', Object.fromEntries(params));
        const res = await fetch('notas_ajax.php?action=cambiar_fecha_sesion', {
          method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString()
        });
        let json = null;
        try { json = await res.json(); } catch(_) { json = null; }
        console.log('RESP cambiar_fecha_sesion', res.status, json);
        if (!res.ok || !json.ok) {
          const msg = (json && (json.error || json.detail)) ? (json.error + (json.detail?(' · '+json.detail):'')) : 'Error desconocido';
          showMsg('No se pudo actualizar la fecha: ' + msg, 'Error');
          document.getElementById('status').textContent = 'No se pudo actualizar la fecha';
          return;
        }
        if (isLegacy) {
          const oldKey = ses.sesion;
          const newKey = 'LEGACY-' + v;
          if (oldKey !== newKey) {
            if (!dataLocal[newKey]) dataLocal[newKey] = {};
            if (dataLocal[oldKey]) { Object.assign(dataLocal[newKey], dataLocal[oldKey]); delete dataLocal[oldKey]; }
            ses.sesion = newKey;
          }
        }
        ses.fecha = v;
        ses.detalle = detalle;
        window.closeHeaderDateModal();
        renderSesiones(sesionesLocal, dataLocal);
        document.getElementById('status').textContent = 'Fecha actualizada';
      }catch(err){ console.warn(err); showMsg('Error al actualizar la fecha', 'Error'); document.getElementById('status').textContent = 'Error al actualizar la fecha'; }
      finally {
        const btns = document.querySelectorAll('#modalFecha .btn');
        btns.forEach(b => b.disabled = false);
      }
    };

    function fmt(d){
      const pad = n => String(n).padStart(2,'0');
      return d.getFullYear()+ '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
    }

    // Setear fecha recordada o, si no hay, hoy por defecto
    const inpFecha = document.getElementById('fecha');
    const LS_KEY_FECHA = `notas_fecha_${ID_CM}`;
    const saved = localStorage.getItem(LS_KEY_FECHA);
    if (saved && /^\d{4}-\d{2}-\d{2}$/.test(saved)) {
      inpFecha.value = saved;
    } else {
      const today = new Date();
      inpFecha.value = fmt(today);
    }
    // Mostrar fecha de corte si existe
    (function(){
      const lab = document.getElementById('fechaCorteLabel');
      if (lab && FECHA_CORTE_Q2){ lab.textContent = FECHA_CORTE_Q2; }
    })();

    function uuid(){
      return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random()*16|0, v = c === 'x' ? r : (r&0x3|0x8);
        return v.toString(16);
      });
    }

    function fechaLabel(fechaStr){
      // Mostrar la fecha en formato dd/mm (sin año)
      const f = fechaStr; // no usar inpFecha como fallback, para no cambiar la visual
      if(!f) return '';
      const [Y,M,D] = f.split('-');
      return `${D}/${M}`;
    }

    async function cargarTodasNotas() {
      document.getElementById('status').textContent = 'Cargando todas las fechas...';
      try{
        const res = await fetch('notas_ajax.php?action=listar_todas', {
          method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({ id: ID_CM })
        });
        const json = await res.json();
        const sesiones = Array.isArray(json.sesiones) ? json.sesiones : [];
        const data = json.data || {};

        // Normalizar y mergear sesiones por clave 'sesion'
        const nuevasSes = sesiones.map(s => ({ ...s, fecha: s.fecha }));
        const mapExist = new Map(sesionesLocal.map(s => [s.sesion, s]));
        for (const s of nuevasSes) {
          if (!mapExist.has(s.sesion)) {
            mapExist.set(s.sesion, s);
          } else {
            const cur = mapExist.get(s.sesion);
            cur.fecha = s.fecha || cur.fecha;
            if (s.created_at && !cur.created_at) cur.created_at = s.created_at;
          }
        }
        sesionesLocal = Array.from(mapExist.values());

        // Merge de data
        for (const [ses, alumnos] of Object.entries(data)) {
          if (!dataLocal[ses]) dataLocal[ses] = {};
          Object.assign(dataLocal[ses], alumnos);
        }

        if (sesionesLocal.length > 0) {
          renderSesiones(sesionesLocal, dataLocal);
          document.getElementById('status').textContent = `Listo · Fechas: ${sesionesLocal.length}`;
        } else {
          document.getElementById('status').textContent = 'No hay sesiones globales, cargo la fecha seleccionada...';
          await cargarNotasPorFecha();
        }
      }catch(err){ document.getElementById('status').textContent = 'Error al cargar todo'; console.warn(err); }
    }

    async function cargarNotasPorFecha() {
      const fecha = inpFecha.value; if(!fecha) return;
      document.getElementById('status').textContent = 'Cargando...';
      try{
        const res = await fetch('notas_ajax.php?action=listar_todo', {
          method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({ id: ID_CM, fecha })
        });
        const json = await res.json();
        const sesiones = Array.isArray(json.sesiones) ? json.sesiones : [];
        const data = json.data || {};
        // Normalizar sesiones con fecha si el backend no la provee
        const nuevasSes = sesiones.map(s => ({ ...s, fecha: s.fecha || inpFecha.value }));

        // Merge de sesiones por clave 'sesion'
        const mapExist = new Map(sesionesLocal.map(s => [s.sesion, s]));
        for (const s of nuevasSes) {
          if (!mapExist.has(s.sesion)) {
            mapExist.set(s.sesion, s);
          } else {
            // actualizar fecha/created_at si vienen
            const cur = mapExist.get(s.sesion);
            cur.fecha = s.fecha || cur.fecha;
            if (s.created_at && !cur.created_at) cur.created_at = s.created_at;
          }
        }
        sesionesLocal = Array.from(mapExist.values());

        // Merge de data: dataLocal[sesion][idAlumno] = estado
        for (const [ses, alumnos] of Object.entries(data)) {
          if (!dataLocal[ses]) dataLocal[ses] = {};
          Object.assign(dataLocal[ses], alumnos);
        }

        renderSesiones(sesionesLocal, dataLocal);
        document.getElementById('status').textContent = 'Listo';
      }catch(err){ document.getElementById('status').textContent = 'Error al cargar'; console.warn(err); }
    }

    function parseISO(d){ const [Y,M,D] = d.split('-'); return new Date(+Y, +M-1, +D); }

    function renderSesiones(sesiones, data){
      const table = document.getElementById('tablaAsist');
      const colgroup = table.querySelector('colgroup');
      const head1 = document.getElementById('trHead1');
      const head2 = document.getElementById('trHead2');

      // Limpiar cabeceras (solo mantener #, Nombre y Filler)
      // head1: [#, Nombre, Q1, Q2, Filler] -> reset colspans
      const thQ1 = document.getElementById('thQ1');
      const thQ2 = document.getElementById('thQ2');
      thQ1.setAttribute('colspan','0');
      thQ2.setAttribute('colspan','0');
      head2.innerHTML = '';
      // Agregar doble línea a la izquierda del título de Q2 (fila de títulos grandes)
      thQ2.classList.add('sep-double-left');

      // Limpiar cuerpo: quitar todas las celdas de sesión dejando solo [#, Nombre, Filler]
      document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
        // eliminar todo entre la 3ra celda (índice 2) y la última - 1 (filler)
        while(tr.children.length > 3){
          tr.removeChild(tr.children[2]);
        }
      });

      // Determinar fecha de corte (default 30-08 del año de la fecha seleccionada)
      // Usar fecha de corte desde BD; si no hubiera, fallback a 30-08 del año actual
      const y = new Date().getFullYear().toString();
      const fCorte = FECHA_CORTE_Q2 || `${y}-08-30`;
      const corte = parseISO(fCorte);

      // Separar por cuatrimestre y ordenar por fecha ASC (y created_at como desempate)
      const q1 = [];
      const q2 = [];
      sesiones.forEach(s => {
        // Usar la fecha propia de la sesión (si no, fallback al corte)
        const f = parseISO(s.fecha || fCorte);
        if (f < corte) q1.push(s); else q2.push(s);
      });
      const cmp = (a,b) => {
        const da = parseISO(a.fecha || fCorte);
        const db = parseISO(b.fecha || fCorte);
        if (da - db !== 0) return da - db;
        // desempate estable por created_at si existe
        if (a.created_at && b.created_at) return (a.created_at > b.created_at) ? 1 : (a.created_at < b.created_at ? -1 : 0);
        return 0;
      };
      q1.sort(cmp);
      q2.sort(cmp);

      // Sin columnas virtuales adicionales: solo las sesiones reales + placeholders para ancho mínimo

      function addHeaderTH(grupo, ses){
        const th = document.createElement('th'); th.className = 'col-s th-s';
        const lab = document.createElement('span'); lab.className = 'vlabel'; lab.textContent = fechaLabel(ses.fecha);
        th.appendChild(lab);
        head2.appendChild(th);
        if(grupo==='q1') thQ1.setAttribute('colspan', String((parseInt(thQ1.getAttribute('colspan'))||0)+1));
        else thQ2.setAttribute('colspan', String((parseInt(thQ2.getAttribute('colspan'))||0)+1));

        // Guardar metadata y permitir edición con doble clic si hay permisos
        th.dataset.sesion = ses.sesion;
        th.dataset.fecha = ses.fecha || '';
        // Tooltip custom de dos líneas (sin fecha): 1) detalle (o 'Sin detalle'), 2) 'Doble clic para editar' si corresponde
        th.removeAttribute('title');
        let _tip;
        const safe = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c]));
        const showTip = () => {
          if (_tip) return;
          const rect = th.getBoundingClientRect();
          const x = Math.round(rect.left + rect.width/2);
          const y = Math.round(rect.top);
          _tip = document.createElement('div');
          _tip.className = 'th-tip-global';
          const first = (ses.detalle && String(ses.detalle).trim() !== '') ? safe(ses.detalle) : 'Sin detalle';
          const dateLbl = (typeof fechaLabel === 'function') ? fechaLabel(ses.fecha || '') : '';
          _tip.innerHTML = `<div class="tip-date">${safe(dateLbl)}</div><div class="tip-body"><strong>${first}</strong>` + (puedeEditar ? `<div class=\"hint\">Doble clic para editar</div>` : '') + `</div>`;
          _tip.style.left = x + 'px';
          _tip.style.top = y + 'px';
          document.body.appendChild(_tip);
        };
        const hideTip = () => { if (_tip && _tip.parentNode) _tip.parentNode.removeChild(_tip); _tip = null; };
        th.addEventListener('mouseenter', showTip);
        th.addEventListener('mouseleave', hideTip);
        window.addEventListener('scroll', hideTip, { passive: true });
        window.addEventListener('resize', hideTip, { passive: true });
        if (puedeEditar) {
          th.style.cursor = 'pointer';
          // Doble clic en th
          th.addEventListener('dblclick', (e) => { e.preventDefault(); e.stopPropagation(); openHeaderDateModal(ses, th); });
          // Doble clic en el label, por si el th no captura
          lab.addEventListener('dblclick', (e) => { e.preventDefault(); e.stopPropagation(); openHeaderDateModal(ses, th); });
          // Alternativa: Alt + clic simple
          th.addEventListener('click', (e) => { if (e.altKey) { e.preventDefault(); e.stopPropagation(); openHeaderDateModal(ses, th); }});
        }
      }
      
      // Validación: 1 a 10 o vacío
      function validateNotaInput(input){
        const s = String(input.value || '').trim();
        if (s === '') return { ok: true, val: null };
        if (!/^\d+$/.test(s)) return { ok: false, msg: 'Solo números enteros entre 1 y 10.' };
        const n = parseInt(s, 10);
        if (isNaN(n) || n < 1 || n > 10) return { ok: false, msg: 'La nota debe estar entre 1 y 10.' };
        return { ok: true, val: n };
      }

      // Tooltip discreto cerca del input activo
      let __inputTipEl = null;
      function hideInputTip(){
        if (__inputTipEl && __inputTipEl.parentNode) __inputTipEl.parentNode.removeChild(__inputTipEl);
        __inputTipEl = null;
      }
      function showInputTip(input, msg){
        hideInputTip();
        const rect = input.getBoundingClientRect();
        const tip = document.createElement('div');
        tip.className = 'input-tip';
        tip.textContent = msg;
        // Posicionar justo encima del input, alineado a la izquierda
        const pad = 6;
        tip.style.left = Math.round(rect.left) + 'px';
        tip.style.top = Math.max(0, Math.round(rect.top - (rect.height + 8))) + 'px';
        document.body.appendChild(tip);
        __inputTipEl = tip;
        // Ocultar automáticamente tras 2.5s
        setTimeout(() => { if (tip === __inputTipEl) hideInputTip(); }, 2500);
      }

      // Bloqueo global de teclado cuando hay algún modal abierto
      function isModalOpen(){
        const mm = document.getElementById('modalMsg');
        const mf = document.getElementById('modalFecha');
        const m1 = mm && mm.style.display === 'flex' && mm.getAttribute('aria-hidden') === 'false';
        const m2 = mf && mf.style.display === 'flex' && mf.getAttribute('aria-hidden') === 'false';
        return !!(m1 || m2);
      }
      document.addEventListener('keydown', (e) => {
        if (!isModalOpen()) return;
        const key = e.key;
        // Enter acepta el modal de mensaje si está visible
        if (key === 'Enter') {
          const mm = document.getElementById('modalMsg');
          if (mm && mm.style.display === 'flex' && mm.getAttribute('aria-hidden') === 'false') {
            const ok = document.getElementById('modalMsgOk') || (mm.querySelector && mm.querySelector('.btn.btn-primary'));
            if (ok && typeof ok.click === 'function') ok.click();
            e.preventDefault(); e.stopPropagation();
            return;
          }
        }
        // Escape cierra el modal activo (mensaje o fecha)
        if (key === 'Escape') {
          const mm = document.getElementById('modalMsg');
          const mf = document.getElementById('modalFecha');
          if (mm && mm.style.display === 'flex' && mm.getAttribute('aria-hidden') === 'false') { try{ closeMsg(); }catch(_){} }
          else if (mf && mf.style.display === 'flex' && mf.getAttribute('aria-hidden') === 'false') { try{ closeHeaderDateModal(); }catch(_){} }
          e.preventDefault(); e.stopPropagation();
          return;
        }
        // Bloquear el resto de teclas (flechas, Tab, etc.) para evitar mover/editar celdas
        e.preventDefault();
        e.stopPropagation();
      }, true);

      function addBodyTD(tr, sesion, extraClass){
        const td = document.createElement('td'); td.className = 'td-s';
        const inp = document.createElement('input');
        // Usamos type="text" para poder leer selectionStart/End y detectar bordes con flechas
        inp.type = 'text';
        inp.inputMode = 'numeric';
        inp.setAttribute('pattern', '[0-9]*');
        // Guardamos min/max en data-attrs para validación lógica (no nativa)
        inp.dataset.min = '1';
        inp.dataset.max = '10';
        inp.className = 'nota-input';
        inp.placeholder = '-';
        const idAlumno = parseInt(tr.getAttribute('data-alumno'),10);
        const notaActual = (data[sesion.sesion] && data[sesion.sesion][idAlumno] != null) ? data[sesion.sesion][idAlumno] : null;
        if (notaActual !== null) inp.value = notaActual;
        inp.disabled = !puedeEditar;
        // Guardar valor previo y validar al salir de la celda
        inp.addEventListener('focus', () => { inp.dataset.prev = inp.value; });
        inp.addEventListener('blur', () => {
          if (inp.disabled) return;
          const r = validateNotaInput(inp);
          if (!r.ok) {
            if (typeof showMsg === 'function') { showMsg(r.msg, 'Atención'); }
            setTimeout(() => { inp.focus(); inp.select(); }, 0);
          }
        });
        inp.addEventListener('change', async () => {
          const r = validateNotaInput(inp);
          if (!r.ok) {
            if (typeof showMsg === 'function') { showMsg(r.msg, 'Atención'); }
            inp.value = inp.dataset.prev || '';
            setTimeout(() => { inp.focus(); inp.select(); }, 0);
            return;
          }
          const val = r.val; // puede ser null si está vacío
          try{
            document.getElementById('status').textContent = 'Guardando...';
            const params = new URLSearchParams({ id: ID_CM, alumno: idAlumno, fecha: (sesion.fecha || inpFecha.value), sesion: sesion.sesion });
            if (val!==null) params.append('nota', String(val));
            console.debug('POST notas_guardar', Object.fromEntries(params));
            const res = await fetch('notas_ajax.php?action=guardar', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString() });
            const text = await res.text();
            let json = null;
            try { json = JSON.parse(text); } catch(_) { json = null; }
            console.debug('RESP notas_guardar', res.status, json || text);
            if (!res.ok) {
              throw new Error(`HTTP ${res.status} · ${text.slice(0,200)}`);
            }
            if (!json || json.ok !== true) {
              const msg = json ? ((json.error || json.detail || 'Respuesta inválida') + (json.detail?(' · '+json.detail):''))
                               : `Respuesta no JSON: ${text.slice(0,200)}`;
              throw new Error(msg);
            }
            if (!dataLocal[sesion.sesion]) dataLocal[sesion.sesion] = {};
            dataLocal[sesion.sesion][idAlumno] = val;
            document.getElementById('status').textContent = 'Guardado';
            updateRowPromedios(tr);
            // Si Enter dejó indicado mover después de guardar, hacerlo ahora
            const dirStr = inp.dataset.moveAfterSave;
            if (dirStr) {
              delete inp.dataset.moveAfterSave;
              const dir = parseInt(dirStr, 10);
              if (!isNaN(dir) && (dir === 1 || dir === -1)) {
                if (window.moveVerticalInput) {
                  window.moveVerticalInput(inp, dir);
                } else {
                  // Fallback inline por si no está disponible
                  const td = (function findTd(n){ while(n && n.tagName !== 'TD') n = n.parentElement; return n; })(inp);
                  if (td) {
                    const idx = td.cellIndex;
                    let r = td.parentElement;
                    while (r) {
                      r = dir > 0 ? r.nextElementSibling : r.previousElementSibling;
                      if (!r) break;
                      if (idx >= r.children.length) continue;
                      const targetTd = r.children[idx];
                      if (!targetTd || targetTd.classList.contains('td-spacer') || targetTd.classList.contains('col-filler')) continue;
                      const nextInp = targetTd.querySelector('input.nota-input');
                      if (nextInp && !nextInp.disabled) { nextInp.focus(); nextInp.select(); break; }
                    }
                  }
                }
              }
            }
          }catch(err){ console.warn('Error al guardar nota:', err); document.getElementById('status').textContent = 'Error al guardar'; alert('Error al guardar: ' + (err && err.message ? err.message : err)); }
        });
        td.appendChild(inp);
        if (extraClass) td.classList.add(extraClass);
        tr.insertBefore(td, tr.lastElementChild);
      }

      // Editar fecha de una sesión desde el encabezado
      function normalizeInputDate(str){
        if (!str) return null;
        const s = String(str).trim();
        // ISO: YYYY-MM-DD
        let m = s.match(/^([0-9]{4})[-\/ ]([0-9]{2})[-\/ ]([0-9]{2})$/);
        if (m) {
          const [_, Y, M, D] = m; return `${Y}-${M}-${D}`;
        }
        // DD-MM-YYYY o DD/MM/YYYY
        m = s.match(/^([0-9]{2})[-\/]([0-9]{2})[-\/]([0-9]{4})$/);
        if (m) {
          const [_, d1, d2, d3] = m;
          // Ambiguo: si es DD-MM-YYYY o MM-DD-YYYY, sin contexto usamos DD-MM-YYYY por display actual (15/09)
          // Intentamos inferir: si d1 > 12, es DD; si d1 <= 12 y d2 > 12, asumimos MM-DD-YYYY -> swap
          let DD = d1, MM = d2, YYYY = d3;
          if (parseInt(d1,10) <= 12 && parseInt(d2,10) > 12) { // MM-DD-YYYY
            MM = d1; DD = d2;
          }
          return `${YYYY}-${MM}-${DD}`;
        }
        return null;
      }

      async function editHeaderDate(ses){
        try{
          const actual = ses.fecha || '';
          const entrada = prompt('Nueva fecha (preferido: DD-MM-YYYY; acepta: YYYY-MM-DD o MM-DD-YYYY):', actual);
          if (entrada === null) return; // cancelado
          const nuevaISO = normalizeInputDate(entrada);
          if (!nuevaISO) { showMsg('Formato inválido. Usa YYYY-MM-DD, DD-MM-YYYY o MM-DD-YYYY.', 'Atención'); return; }

          const isLegacy = typeof ses.sesion === 'string' && ses.sesion.startsWith('LEGACY-');
          const oldFecha = isLegacy ? (ses.fecha || ses.sesion.slice(7)) : '';

          const params = new URLSearchParams({ id: ID_CM, sesion: ses.sesion, nueva_fecha: nuevaISO });
          if (isLegacy) params.append('old_fecha', oldFecha);

          const res = await fetch('notas_ajax.php?action=cambiar_fecha_sesion', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params
          });
          const json = await res.json();
          if (!res.ok || !json.ok) {
            const msg = (json && (json.error || json.detail)) ? (json.error + (json.detail?(' · '+json.detail):'')) : 'Error desconocido';
            showMsg('No se pudo actualizar la fecha: ' + msg, 'Error');
            return;
          }

          // Actualizar cache local
          if (isLegacy) {
            const oldKey = ses.sesion;
            const newKey = 'LEGACY-' + nuevaISO;
            if (oldKey !== newKey) {
              if (!dataLocal[newKey]) dataLocal[newKey] = {};
              if (dataLocal[oldKey]) { Object.assign(dataLocal[newKey], dataLocal[oldKey]); delete dataLocal[oldKey]; }
              ses.sesion = newKey;
            }
          }
          ses.fecha = nuevaISO;
          renderSesiones(sesionesLocal, dataLocal);
          document.getElementById('status').textContent = 'Fecha actualizada';
        }catch(err){ console.warn(err); showMsg('Error al actualizar la fecha', 'Error'); }
      }


      // Delegación global por si algún handler no se adjunta
      document.addEventListener('dblclick', (e) => {
        if (!puedeEditar) return;
        const th = e.target.closest && e.target.closest('th.th-s');
        if (!th) return;
        const sesId = th.dataset && th.dataset.sesion;
        if (!sesId) return;
        const sesObj = (Array.isArray(sesionesLocal) ? sesionesLocal.find(s => s.sesion === sesId) : null);
        if (sesObj) {
          console.log('Abrir modal por delegación para sesión:', sesId);
          openHeaderDateModal(sesObj);
        }
      });

      // Cerrar modal con Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          const el = document.getElementById('modalFecha');
          if (el && el.style.display === 'flex') closeHeaderDateModal();
        }
      });

      const MIN_GROUP_COLS = 17; // 17 * 30px = 510px (420 + 90px extra)

      // Reconstruir colgroup: [#][Nombre] + Q1 cols + Q2 cols + [Filler]
      // 1) Guardar referencia al primer par de columnas (#, Nombre)
      // Limpiar todo y re-crear base
      colgroup.innerHTML = '';
      const cNum = document.createElement('col'); cNum.style.width = 'var(--w-num)';
      const cNom = document.createElement('col'); cNom.style.width = '270px';
      colgroup.appendChild(cNum);
      colgroup.appendChild(cNom);

      // Guardar grupos para recálculo posterior
      lastQ1 = q1.slice();
      lastQ2 = q2.slice();

      // Pintar Q1
      q1.forEach(s => {
        addHeaderTH('q1', s);
        document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => addBodyTD(tr, s));
        const c = document.createElement('col'); c.style.width = '30px'; colgroup.appendChild(c);
      });
      // Relleno placeholders Q1 si faltan
      const faltanQ1 = Math.max(0, MIN_GROUP_COLS - q1.length);
      for(let i=0;i<faltanQ1;i++){
        const th = document.createElement('th'); th.className = 'col-s th-s';
        const lab = document.createElement('span'); lab.className = 'vlabel'; lab.textContent = '';
        th.appendChild(lab);
        head2.appendChild(th);
        thQ1.setAttribute('colspan', String((parseInt(thQ1.getAttribute('colspan'))||0)+1));
        document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
          const td = document.createElement('td'); td.className = 'td-s td-spacer';
          tr.insertBefore(td, tr.lastElementChild);
        });
        const c = document.createElement('col'); c.style.width = '30px'; colgroup.appendChild(c);
      }

      // Agregar columna de promedio Q1
      (function(){
        const th = document.createElement('th'); th.className = 'th-perc th-perc-q1';
        const lab = document.createElement('span'); lab.className = 'vlabel'; lab.textContent = 'Prom Q1';
        th.appendChild(lab);
        head2.appendChild(th);
        thQ1.setAttribute('colspan', String((parseInt(thQ1.getAttribute('colspan'))||0)+1));
        // celdas por fila
        document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
          const td = document.createElement('td'); td.className = 'td-perc'; td.setAttribute('data-perc','q1');
          const idAlumno = parseInt(tr.getAttribute('data-alumno'),10);
          td.textContent = promedioParaFila(q1, idAlumno, data);
          // Insertar antes del filler (y antes de las columnas de Q2 que vienen luego)
          tr.insertBefore(td, tr.lastElementChild);
        });
        const c = document.createElement('col'); c.style.width = '30px'; colgroup.appendChild(c);
      })();
      // Pintar Q2
      q2.forEach((s, idx) => {
        addHeaderTH('q2', s);
        // En el inicio de Q2, marcar el borde derecho de % Q1 como doble línea
        if (idx === 0) {
          const thPercQ1 = head2.querySelector('th.th-perc.th-perc-q1');
          if (thPercQ1) thPercQ1.classList.add('sep-double-right');
          document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
            const tdPercQ1 = tr.querySelector('td.td-perc[data-perc="q1"]');
            if (tdPercQ1) tdPercQ1.classList.add('sep-double-right');
          });
          // Las columnas de Q2 ya no necesitan el separador encima del contenido
          document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => addBodyTD(tr, s));
        } else {
          document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => addBodyTD(tr, s));
        }
        const c = document.createElement('col'); c.style.width = '30px'; colgroup.appendChild(c);
      });
      // Relleno placeholders Q2 si faltan
      const faltanQ2 = Math.max(0, MIN_GROUP_COLS - q2.length);
      for(let i=0;i<faltanQ2;i++){
        const th = document.createElement('th'); th.className = 'col-s th-s';
        const lab = document.createElement('span'); lab.className = 'vlabel'; lab.textContent = '';
        th.appendChild(lab);
        head2.appendChild(th);
        thQ2.setAttribute('colspan', String((parseInt(thQ2.getAttribute('colspan'))||0)+1));
        document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
          const td = document.createElement('td'); td.className = 'td-s td-spacer';
          tr.insertBefore(td, tr.lastElementChild);
        });
        const c = document.createElement('col'); c.style.width = '30px'; colgroup.appendChild(c);
      }

      // Agregar columna de promedio Q2
      (function(){
        const th = document.createElement('th'); th.className = 'th-perc th-perc-q2';
        const lab = document.createElement('span'); lab.className = 'vlabel'; lab.textContent = 'Prom Q2';
        th.appendChild(lab);
        head2.appendChild(th);
        thQ2.setAttribute('colspan', String((parseInt(thQ2.getAttribute('colspan'))||0)+1));
        document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
          const td = document.createElement('td'); td.className = 'td-perc'; td.setAttribute('data-perc','q2');
          const idAlumno = parseInt(tr.getAttribute('data-alumno'),10);
          td.textContent = promedioParaFila(q2, idAlumno, data);
          tr.insertBefore(td, tr.lastElementChild);
        });
        const c = document.createElement('col'); c.style.width = '30px'; colgroup.appendChild(c);
      })();
      // Filler al final
      // Agregar columna de promedio ANUAL (promedio de Prom Q1 y Prom Q2)
      (function(){
        const th = document.createElement('th'); th.className = 'th-perc th-perc-anual';
        const lab = document.createElement('span'); lab.className = 'vlabel'; lab.textContent = 'Prom Anual';
        th.appendChild(lab);
        head2.appendChild(th);
        // Contabilizar esta columna dentro del grupo Q2 para mantener el filler al final
        thQ2.setAttribute('colspan', String((parseInt(thQ2.getAttribute('colspan'))||0)+1));
        document.querySelectorAll('#tablaAsist tbody tr').forEach(tr => {
          const td = document.createElement('td'); td.className = 'td-perc'; td.setAttribute('data-perc','anual');
          const idAlumno = parseInt(tr.getAttribute('data-alumno'),10);
          td.textContent = promedioAnualParaFila(idAlumno, data);
          tr.insertBefore(td, tr.lastElementChild);
        });
        const c = document.createElement('col'); c.style.width = '40px'; colgroup.appendChild(c);
      })();

      const cFill = document.createElement('col'); cFill.className = 'col-filler'; colgroup.appendChild(cFill);
    }

    function promedioParaFila(groupSessions, idAlumno, data){
      let sum = 0, cnt = 0;
      for (const s of groupSessions) {
        const ses = s.sesion;
        const val = data[ses] && data[ses][idAlumno] != null ? parseInt(data[ses][idAlumno],10) : null;
        // Solo contar notas numéricas. Ignorar vacías/null.
        if (val !== null && !Number.isNaN(val)) { sum += val; cnt++; }
      }
      if (cnt === 0) return '';
      return (Math.round((sum/cnt)*10)/10).toFixed(1);
    }

    function promedioAnualParaFila(idAlumno, data){
      const q1 = promedioParaFila(lastQ1, idAlumno, data);
      const q2 = promedioParaFila(lastQ2, idAlumno, data);
      const n1 = q1===''? null : parseFloat(q1);
      const n2 = q2===''? null : parseFloat(q2);
      if (n1===null && n2===null) return '';
      if (n1===null) return n2.toFixed(1);
      if (n2===null) return n1.toFixed(1);
      return (Math.round(((n1+n2)/2)*10)/10).toFixed(1);
    }

    function updateRowPromedios(tr){
      const idAlumno = parseInt(tr.getAttribute('data-alumno'),10);
      const tdQ1 = tr.querySelector('td.td-perc[data-perc="q1"]');
      if (tdQ1) { tdQ1.textContent = promedioParaFila(lastQ1, idAlumno, dataLocal); }
      const tdQ2 = tr.querySelector('td.td-perc[data-perc="q2"]');
      if (tdQ2) { tdQ2.textContent = promedioParaFila(lastQ2, idAlumno, dataLocal); }
      const tdAnual = tr.querySelector('td.td-perc[data-perc="anual"]');
      if (tdAnual) { tdAnual.textContent = promedioAnualParaFila(idAlumno, dataLocal); }
    }

    // ya no se usa setEstado en notas; el guardado se hace en el change del input

    // Navegación tipo Excel para inputs de nota (1-10)
    (function(){
      function findTd(el){
        let n = el;
        while(n && n.tagName !== 'TD') n = n.parentElement;
        return n;
      }

      function focusSameColInRow(row, cellIndex, dir){
        let r = row;
        while (r) {
          r = dir > 0 ? r.nextElementSibling : r.previousElementSibling;
          if (!r) return;
          const tds = r.children;
          if (cellIndex < tds.length) {
            const td = tds[cellIndex];
            if (td && !td.classList.contains('td-spacer')) {
              const sel = td.querySelector('select.sel-estado');
              if (sel) { sel.focus(); return; }
            }
          }
        }
      }

      function moveVertical(selectEl, dir){
        const td = findTd(selectEl);
        if (!td) return;
        const row = td.parentElement;
        const idx = td.cellIndex; // índice absoluto en la fila (incluye # y Nombre)
        focusSameColInRow(row, idx, dir);
      }

      function moveHorizontal(selectEl, dir){
        const td = findTd(selectEl);
        if (!td) return;
        let cur = td;
        while (cur) {
          cur = dir > 0 ? cur.nextElementSibling : cur.previousElementSibling;
          if (!cur) return;
          if (cur.classList && (cur.classList.contains('td-spacer') || cur.classList.contains('col-filler'))) continue;
          const inp = cur.querySelector && cur.querySelector('input.nota-input');
          if (inp) { inp.focus(); inp.select(); return; }
        }
      }

      // Navegación vertical entre inputs .nota-input en la misma columna
      function moveVerticalInput(inputEl, dir){
        const td = findTd(inputEl);
        if (!td) return;
        const row = td.parentElement;
        const idx = td.cellIndex; // índice absoluto en la fila
        let r = row;
        while (r) {
          r = dir > 0 ? r.nextElementSibling : r.previousElementSibling;
          if (!r) return;
          if (idx >= r.children.length) continue;
          const targetTd = r.children[idx];
          if (!targetTd || targetTd.classList.contains('td-spacer') || targetTd.classList.contains('col-filler')) continue;
          const nextInp = targetTd.querySelector('input.nota-input');
          if (nextInp && !nextInp.disabled) { nextInp.focus(); nextInp.select(); return; }
        }
      }
      // Exponer como global por si otros handlers lo requieren luego
      window.moveVerticalInput = moveVerticalInput;

      document.addEventListener('keydown', (e) => {
        const el = document.activeElement;
        if (!(el && el.tagName === 'INPUT' && el.classList.contains('nota-input'))) return;

        const key = e.key;

        // Enter: confirmar valor (disparar change) y programar movimiento vertical tras guardar
        if (key === 'Enter') {
          e.preventDefault();
          try {
            // Guardar dirección: Shift+Enter sube (-1), Enter baja (+1)
            const dir = e.shiftKey ? -1 : 1;
            el.dataset.moveAfterSave = String(dir);
            // Disparar "change" para que se guarde antes de mover el foco
            const evt = new Event('change', { bubbles: true });
            el.dispatchEvent(evt);
            // Fallback: si no hubo cambio (o no se disparó guardado), mover igual tras un breve delay
            setTimeout(() => {
              // Si todavía seguimos en el mismo input, forzar movimiento
              if (document.activeElement === el && typeof window.moveVerticalInput === 'function') {
                window.moveVerticalInput(el, dir);
              }
            }, 180);
          } catch(_) { /* noop */ }
          return;
        }

        // Flechas verticales: navegación por filas (entre inputs)
        if (key === 'ArrowDown') { e.preventDefault(); moveVerticalInput(el, +1); return; }
        if (key === 'ArrowUp')   { e.preventDefault(); moveVerticalInput(el, -1); return; }

        // Flechas horizontales: mover de celda solo si el cursor está en el borde
        if (key === 'ArrowLeft' || key === 'ArrowRight') {
          const start = el.selectionStart, end = el.selectionEnd, len = el.value.length;
          const hasSelection = (start !== end);
          if (!hasSelection) {
            if (key === 'ArrowLeft' && start === 0) { e.preventDefault(); return moveHorizontal(el, -1); }
            if (key === 'ArrowRight' && start === len) { e.preventDefault(); return moveHorizontal(el, +1); }
          }
          return; // comportamiento nativo cuando no estamos en el borde o hay selección
        }
      }, true);
    })();

    inpFecha.addEventListener('change', () => {
      const v = inpFecha.value;
      if (v && /^\d{4}-\d{2}-\d{2}$/.test(v)) localStorage.setItem(LS_KEY_FECHA, v);
      // No recargar ni re-renderizar: este input solo define la fecha de la nueva columna
    });
    document.getElementById('btnAdd').addEventListener('click', async function(){
      if (!puedeEditar) return;
      const f = inpFecha.value; if(!/^\d{4}-\d{2}-\d{2}$/.test(f)) { showMsg('Seleccioná una fecha válida', 'Atención'); return; }
      const inpDet = document.getElementById('detalleSesion');
      const detalle = inpDet && inpDet.value ? inpDet.value.trim() : '';
      const key = uuid();
      try{
        document.getElementById('status').textContent = 'Creando sesión...';
        const params = new URLSearchParams({ id: ID_CM, fecha: f, sesion: key });
        if (detalle !== '') params.append('detalle', detalle);
        const res = await fetch('notas_ajax.php?action=crear_sesion', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString() });
        let json = null; try { json = await res.json(); } catch(_) {}
        if (!res.ok || !(json && json.ok)) { throw new Error((json && json.error) || 'Error'); }
        // Agregar en UI, incluyendo 'detalle' para que el tooltip lo muestre sin recargar
        sesionesLocal.push({ sesion: key, fecha: f, detalle: (detalle || null), created_at: new Date().toISOString().slice(0,19).replace('T',' ') });
        renderSesiones(sesionesLocal, dataLocal);
        document.getElementById('status').textContent = 'Columna agregada';
      }catch(err){ console.warn(err); showMsg('No se pudo crear la sesión', 'Error'); document.getElementById('status').textContent = 'No se pudo crear la sesión'; }
    });

    // La fecha de corte proviene de BD; no editable aquí.

    // Primera carga: traer TODAS las fechas
    cargarTodasNotas();
  </script>
</body>
</html>
