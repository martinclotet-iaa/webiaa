<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/config_menus.php';

// Solo Administrador (perfil 4)
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : (isset($_SESSION['perfiles'][0]) ? intval($_SESSION['perfiles'][0]) : 1);
if ($perfilActual !== 4) {
    header('Location: menu.php');
    exit;
}

// Forzar cabecera HTTP en UTF-8
header('Content-Type: text/html; charset=UTF-8');
// Forzar configuración interna de PHP a UTF-8
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }

// Garantizar que exista la tabla de configuraciones (segura si ya existe)
$conexion->query("CREATE TABLE IF NOT EXISTS configuraciones (
  clave VARCHAR(100) NOT NULL PRIMARY KEY,
  valor_date DATE NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Asegurar columna para valores numéricos generales (por ejemplo, porcentaje_asistencia)
try {
  $dbRow = $conexion->query("SELECT DATABASE() AS db");
  $dbName = $dbRow ? ($dbRow->fetch_assoc()['db'] ?? null) : null;
  if ($dbName) {
    $dbEsc = $conexion->real_escape_string($dbName);
    $col = $conexion->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbEsc' AND TABLE_NAME='configuraciones' AND COLUMN_NAME='valor_int' LIMIT 1");
    if (!$col || $col->num_rows === 0) {
      $conexion->query("ALTER TABLE configuraciones ADD COLUMN valor_int INT NULL");
    }
  }
} catch (Exception $e) { /* ignorar */ }

// Detectar si valor_date permite NULL; si no, usar una fecha placeholder
$valorDateNullable = true;
$valorDateDefault = null;
try {
  $dbRow3 = $conexion->query("SELECT DATABASE() AS db");
  $dbName3 = $dbRow3 ? ($dbRow3->fetch_assoc()['db'] ?? null) : null;
  if ($dbName3) {
    $dbEsc3 = $conexion->real_escape_string($dbName3);
    $col3 = $conexion->query("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbEsc3' AND TABLE_NAME='configuraciones' AND COLUMN_NAME='valor_date' LIMIT 1");
    if ($col3 && $col3->num_rows > 0) {
      $row3 = $col3->fetch_assoc();
      $valorDateNullable = (isset($row3['IS_NULLABLE']) && strtoupper($row3['IS_NULLABLE']) === 'YES');
      $valorDateDefault = $row3['COLUMN_DEFAULT'] ?? null;
    }
  }
} catch (Exception $e) { /* ignorar */ }
// Valor a usar cuando no tenemos una fecha real
$VD_PLACEHOLDER = $valorDateNullable ? 'NULL' : "'1970-01-01'";

$mensaje = '';
$estado = '';

// Detectar si existe columna 'valor' (legado) y podría ser NOT NULL
$hasValor = false;
try {
  $dbRow2 = $conexion->query("SELECT DATABASE() AS db");
  $dbName2 = $dbRow2 ? ($dbRow2->fetch_assoc()['db'] ?? null) : null;
  if ($dbName2) {
    $dbEsc2 = $conexion->real_escape_string($dbName2);
    $col2 = $conexion->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbEsc2' AND TABLE_NAME='configuraciones' AND COLUMN_NAME='valor' LIMIT 1");
    $hasValor = ($col2 && $col2->num_rows > 0);
  }
} catch (Exception $e) { /* ignorar */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $p1 = isset($_POST['inicio_primer_cuatrimestre']) ? trim($_POST['inicio_primer_cuatrimestre']) : null;
  $p2 = isset($_POST['inicio_segundo_cuatrimestre']) ? trim($_POST['inicio_segundo_cuatrimestre']) : null;
  $p3 = isset($_POST['fin_ciclo_lectivo']) ? trim($_POST['fin_ciclo_lectivo']) : null;
  $porc = isset($_POST['porcentaje_asistencia']) ? trim($_POST['porcentaje_asistencia']) : null;
    $cicloLectivoPost = isset($_POST['ciclo_lectivo']) ? trim($_POST['ciclo_lectivo']) : null;
    $diasHabilesQ1Post = isset($_POST['dias_habiles_q1']) ? trim($_POST['dias_habiles_q1']) : null;
    $diasHabilesQ2Post = isset($_POST['dias_habiles_q2']) ? trim($_POST['dias_habiles_q2']) : null;
    $otrosPost = isset($_POST['otros']) && is_array($_POST['otros']) ? $_POST['otros'] : [];


    // Validar formato YYYY-MM-DD simple
    $re = '/^\d{4}-\d{2}-\d{2}$/';
    $ok1 = (!$p1 || preg_match($re, $p1));
    $ok2 = (!$p2 || preg_match($re, $p2));
    $ok3 = (!$p3 || preg_match($re, $p3));
    $okPorc = true;
    if ($porc !== null && $porc !== '') {
      $okPorc = ctype_digit($porc) && intval($porc) >= 1 && intval($porc) <= 100;
    }
    // Validar ciclo lectivo (año)
    $okCiclo = true;
    if ($cicloLectivoPost !== null && $cicloLectivoPost !== '') {
      $okCiclo = ctype_digit($cicloLectivoPost) && intval($cicloLectivoPost) >= 1900 && intval($cicloLectivoPost) <= 2100;
    }

    // Validar tambien los 'otros' (si vienen)
    $okOtros = true;
    foreach ($otrosPost as $k => $v) {
        if ($v === '') continue; // permitir vacío (NULL)
        if (!preg_match($re, $v)) { $okOtros = false; break; }
    }

    if ($ok1 && $ok2 && $ok3 && $okOtros && $okPorc && $okCiclo) {
        // Días hábiles Q1
        if ($diasHabilesQ1Post !== null && $diasHabilesQ1Post !== '') {
            $val = intval($diasHabilesQ1Post);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor_int, valor) VALUES ('dias_habiles_q1', CURDATE(), $val, 0)
                               ON DUPLICATE KEY UPDATE valor_int=VALUES(valor_int), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor_int) VALUES ('dias_habiles_q1', CURDATE(), $val)
                               ON DUPLICATE KEY UPDATE valor_int=VALUES(valor_int), actualizado_en=CURRENT_TIMESTAMP");
            }
        }
        // Días hábiles Q2
        if ($diasHabilesQ2Post !== null && $diasHabilesQ2Post !== '') {
            $val = intval($diasHabilesQ2Post);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor_int, valor) VALUES ('dias_habiles_q2', CURDATE(), $val, 0)
                               ON DUPLICATE KEY UPDATE valor_int=VALUES(valor_int), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor_int) VALUES ('dias_habiles_q2', CURDATE(), $val)
                               ON DUPLICATE KEY UPDATE valor_int=VALUES(valor_int), actualizado_en=CURRENT_TIMESTAMP");
            }
        }

        // Usar INSERT ... ON DUPLICATE KEY UPDATE
        if ($p1 !== null) {
            $p1s = $conexion->real_escape_string($p1);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('inicio_primer_cuatrimestre', '$p1s', 0)
                                 ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date) VALUES ('inicio_primer_cuatrimestre', '$p1s')
                                 ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
            }
        // Guardar Ciclo Lectivo si vino
        if ($cicloLectivoPost !== null && $cicloLectivoPost !== '') {
            $anio = intval($cicloLectivoPost);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor, valor_int) VALUES ('ciclo_lectivo', CURDATE(), 0, $anio)
                               ON DUPLICATE KEY UPDATE valor_int=VALUES(valor_int), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor_int) VALUES ('ciclo_lectivo', CURDATE(), $anio)
                               ON DUPLICATE KEY UPDATE valor_int=VALUES(valor_int), actualizado_en=CURRENT_TIMESTAMP");
            }
        }
        }
        if ($p2 !== null) {
            $p2s = $conexion->real_escape_string($p2);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('inicio_segundo_cuatrimestre', '$p2s', 0)
                                 ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date) VALUES ('inicio_segundo_cuatrimestre', '$p2s')
                                 ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
            }
        }
        if ($p3 !== null) {
            $p3s = $conexion->real_escape_string($p3);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('fin_ciclo_lectivo', '$p3s', 0)
                                 ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date) VALUES ('fin_ciclo_lectivo', '$p3s')
                                 ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
            }
        }
        // Guardar porcentaje_asistencia si vino (en columna 'valor')
        if ($porc !== null && $porc !== '') {
            $porcI = intval($porc);
            if ($hasValor) {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('porcentaje_asistencia', CURDATE(), $porcI)
                               ON DUPLICATE KEY UPDATE valor=VALUES(valor), actualizado_en=CURRENT_TIMESTAMP");
            } else {
              $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('porcentaje_asistencia', CURDATE(), $porcI)
                               ON DUPLICATE KEY UPDATE valor=VALUES(valor), actualizado_en=CURRENT_TIMESTAMP");
            }
        }
        // Guardar otros
        foreach ($otrosPost as $clave => $valor) {
            $clave = trim($clave);
            if ($clave === '') continue;
            if ($valor === '') {
                // Guardar como NULL en valor_date y valor vacío si existe
                $claveS = $conexion->real_escape_string($clave);
                if ($hasValor) {
                  $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('$claveS', $VD_PLACEHOLDER, 0)
                                     ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
                } else {
                  $conexion->query("INSERT INTO configuraciones (clave, valor_date) VALUES ('$claveS', $VD_PLACEHOLDER)
                                     ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
                }
            } else {
                $valorS = $conexion->real_escape_string($valor);
                $claveS = $conexion->real_escape_string($clave);
                if ($hasValor) {
                  $conexion->query("INSERT INTO configuraciones (clave, valor_date, valor) VALUES ('$claveS', '$valorS', 0)
                                     ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
                } else {
                  $conexion->query("INSERT INTO configuraciones (clave, valor_date) VALUES ('$claveS', '$valorS')
                                     ON DUPLICATE KEY UPDATE valor_date=VALUES(valor_date), actualizado_en=CURRENT_TIMESTAMP");
                }
            }
        }
        $mensaje = 'Configuraciones guardadas correctamente.';
        $estado = 'ok';
    } else {
        $mensaje = 'Por favor, ingresá valores válidos (fechas YYYY-MM-DD y porcentaje 1..100).';
        $estado = 'error';
    }
}

// Cargar valores actuales
$inic1 = null; $inic2 = null; $finCiclo = null; $porcentajeAsistencia = null; $cicloLectivo = null; $otras = [];
// Cargar todas las configuraciones y separar las principales (Q1/Q2) del resto
$res = $conexion->query("SELECT clave, valor_date, valor, valor_int FROM configuraciones ORDER BY clave");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['clave'] === 'inicio_primer_cuatrimestre') { $inic1 = $r['valor_date']; continue; }
        if ($r['clave'] === 'inicio_segundo_cuatrimestre') { $inic2 = $r['valor_date']; continue; }
        if ($r['clave'] === 'fin_ciclo_lectivo') { $finCiclo = $r['valor_date']; continue; }
        if ($r['clave'] === 'porcentaje_asistencia') { $porcentajeAsistencia = $r['valor']; continue; }
        if ($r['clave'] === 'ciclo_lectivo') { $cicloLectivo = $r['valor_int']; continue; }
        if ($r['clave'] === 'dias_habiles_q1') { $diasHabilesQ1 = $r['valor_int']; continue; }
        if ($r['clave'] === 'dias_habiles_q2') { $diasHabilesQ2 = $r['valor_int']; continue; }
        $otras[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <title>Configuraci&oacute;n</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
  <style>
    .container { padding: 12px; }
    .titulo { margin: 12px 0 8px; }
    .cfg-form { display: grid; grid-template-columns: 220px 200px; gap: 10px 16px; align-items: center; max-width: 520px; }
    .cfg-form input[type="number"] { padding:6px 8px; border:1px solid #cfd3d7; border-radius:6px; }
    .cfg-form label { font-weight: 600; }
    .cfg-form input[type="date"] { padding:6px 8px; border:1px solid #cfd3d7; border-radius:6px; }
    .cfg-actions { margin-top: 12px; }
    .btn-prim { display:inline-flex; align-items:center; gap:8px; background:#16682b; color:#fff; border:1px solid #16682b; padding:8px 14px; border-radius:8px; cursor:pointer; text-decoration:none; }
    .btn-prim:hover { background:#1a7530; border-color:#1a7530; }
    .alert-ok { color:#0a7c2f; background:#dff3e3; padding:8px 10px; border-radius:8px; font-size:13px; margin: 8px 0; display:inline-block; }
    .alert-err { color:#a10000; background:#fde2e2; padding:8px 10px; border-radius:8px; font-size:13px; margin: 8px 0; display:inline-block; }
    .alert-pending { color:#6b5b00; background:#fff6cc; padding:8px 10px; border-radius:8px; font-size:13px; margin: 8px 0; display:inline-block; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="container">
    <h2 class="titulo"><i class="fa fa-cogs"></i> Configuraci&oacute;n del ciclo lectivo</h2>

    <form method="post" class="cfg-form">
      <label for="inicio_primer_cuatrimestre">Inicio 1&ordm; cuatrimestre:</label>
      <input type="date" id="inicio_primer_cuatrimestre" name="inicio_primer_cuatrimestre" value="<?php echo htmlspecialchars($inic1 ?? ''); ?>">

      <label for="inicio_segundo_cuatrimestre">Inicio 2&ordm; cuatrimestre:</label>
      <input type="date" id="inicio_segundo_cuatrimestre" name="inicio_segundo_cuatrimestre" value="<?php echo htmlspecialchars($inic2 ?? ''); ?>">

      <label for="fin_ciclo_lectivo">Fin ciclo lectivo:</label>
      <input type="date" id="fin_ciclo_lectivo" name="fin_ciclo_lectivo" value="<?php echo htmlspecialchars($finCiclo ?? ''); ?>">

      <label for="porcentaje_asistencia">Porcentaje de asistencia (%):</label>
      <input type="number" id="porcentaje_asistencia" name="porcentaje_asistencia" min="1" max="100" step="1" value="<?php echo htmlspecialchars($porcentajeAsistencia ?? ''); ?>" placeholder="Ej: 75">

      <label for="ciclo_lectivo">Ciclo Lectivo (año):</label>
      <input type="number" id="ciclo_lectivo" name="ciclo_lectivo" min="1900" max="2100" step="1" value="<?php echo htmlspecialchars($cicloLectivo ?? ''); ?>" placeholder="Ej: 2025">

      <label for="dias_habiles_q1">Días Hábiles 1º Cuat.:</label>
      <input type="number" id="dias_habiles_q1" name="dias_habiles_q1" min="0" max="200" step="1" value="<?php echo htmlspecialchars($diasHabilesQ1 ?? ''); ?>" placeholder="Ej: 86">

      <label for="dias_habiles_q2">Días Hábiles 2º Cuat.:</label>
      <input type="number" id="dias_habiles_q2" name="dias_habiles_q2" min="0" max="200" step="1" value="<?php echo htmlspecialchars($diasHabilesQ2 ?? ''); ?>" placeholder="Ej: 93">


      <?php if ($mensaje && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="<?php echo $estado==='ok' ? 'alert-ok' : 'alert-err'; ?>" style="grid-column: 1 / -1;">
          <i class="fa fa-circle-info"></i> <?php echo htmlspecialchars($mensaje); ?>
        </div>
      <?php endif; ?>

      <div id="pendingNote" class="alert-pending" style="grid-column: 1 / -1; display:none;">
        <i class="fa fa-pen"></i> Cambios sin guardar
      </div>

      <div class="cfg-actions" style="grid-column: 1 / -1;">
        <button type="submit" class="btn-prim"><i class="fa fa-floppy-disk"></i> Guardar</button>
        <a href="menu.php" class="btn-volver" style="margin-left:8px;"><i class="fa fa-arrow-left"></i> Volver</a>
      </div>
    <p style="font-size:12px; color:#6c757d; margin-top:10px;">
      Estas fechas impactan en el agrupamiento de sesiones (Q1/Q2) en pantallas como <strong>Asistencias</strong>.
    </p>

    <?php if (!empty($otras)): ?>
      <h3 style="margin-top:20px;">Otras configuraciones</h3>
      <div style="overflow:auto; max-width: 560px;">
        <table class="tabla-lista" style="width:100%; border-collapse: collapse;">
          <thead>
            <tr>
              <th style="text-align:left; border-bottom:1px solid #e5e5e5; padding:6px 8px;">Clave</th>
              <th style="text-align:left; border-bottom:1px solid #e5e5e5; padding:6px 8px;">Valor (date)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($otras as $row): ?>
              <tr>
                <td style="border-bottom:1px solid #f0f0f0; padding:6px 8px;">&nbsp;<?php echo htmlspecialchars($row['clave']); ?></td>
                <td style="border-bottom:1px solid #f0f0f0; padding:6px 8px;">
                  <input type="date" name="otros[<?php echo htmlspecialchars($row['clave']); ?>]" value="<?php echo htmlspecialchars($row['valor_date'] ?? ''); ?>" style="padding:6px 8px; border:1px solid #cfd3d7; border-radius:6px;">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    </form>
  </div>
</body>
</html>

<script>
  document.addEventListener('DOMContentLoaded', function(){
    var form = document.querySelector('form.cfg-form');
    if (!form) return;
    var msg = form.querySelector('.alert-ok, .alert-err');
    var pending = document.getElementById('pendingNote');
    function onEdit(){
      if (msg) msg.style.display = 'none';
      if (pending) pending.style.display = 'inline-block';
    }
    var fields = form.querySelectorAll('input, select, textarea');
    fields.forEach(function(el){
      el.addEventListener('focus', onEdit);
      el.addEventListener('input', onEdit);
      el.addEventListener('change', onEdit);
    });
    form.addEventListener('submit', function(){ if (pending) pending.style.display = 'none'; });
  });
</script>
