<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }
require_once "conexion.php";
require_once "acceso.php";

$id_curso = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_curso <= 0) { header("Location: miscursos.php"); exit; }

// Permisos: Administrador (4) o Preceptor (5) vinculado a este curso
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1;
$esAdmin = ($perfilActual === 4);
if (!$esAdmin && $perfilActual !== 5) { header("Location: menu.php"); exit; }

if ($perfilActual === 5) {
    $preceptorCursos = [];
    // Revisar ambas columnas según lo hecho en miscursos
    $resP = $conexion->query("
        SELECT id_curso 
        FROM curso_preceptor 
        WHERE id_preceptor = {$_SESSION['id_usuario']}
    ");
    if ($resP) {
        while($rp = $resP->fetch_assoc()) { $preceptorCursos[] = (int)$rp['id_curso']; }
    }
    
    // Legacy support check
    $hasPreceptorCol = false;
    if ($rs = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'")) { 
        $hasPreceptorCol = ($rs->num_rows > 0); 
    }
    if ($hasPreceptorCol) {
        $resL = $conexion->query("SELECT id_curso FROM cursos WHERE id_preceptor = {$_SESSION['id_usuario']}");
        if ($resL) {
            while($rl = $resL->fetch_assoc()) { $preceptorCursos[] = (int)$rl['id_curso']; }
        }
    }
    
    if (!in_array($id_curso, $preceptorCursos)) { header("Location: miscursos.php?error=no_assigned"); exit; }
}

$curso = $conexion->query("SELECT nombre_curso FROM cursos WHERE id_curso = $id_curso")->fetch_assoc();

// Cargar configuración de días hábiles
$diasQ1 = 0; $diasQ2 = 0; $ciclo = date('Y');
$resCfg = $conexion->query("SELECT clave, valor_int FROM configuraciones WHERE clave IN ('dias_habiles_q1', 'dias_habiles_q2', 'ciclo_lectivo')");
while($rc = $resCfg->fetch_assoc()){
    if($rc['clave'] === 'dias_habiles_q1') $diasQ1 = (int)$rc['valor_int'];
    if($rc['clave'] === 'dias_habiles_q2') $diasQ2 = (int)$rc['valor_int'];
    if($rc['clave'] === 'ciclo_lectivo') $ciclo = (int)$rc['valor_int'];
}

// Crear tabla si no existe
$conexion->query("CREATE TABLE IF NOT EXISTS inasistencias_resumen (
    id_alumno INT NOT NULL,
    cuatrimestre TINYINT NOT NULL,
    inasistencias DECIMAL(5,2) NOT NULL DEFAULT 0,
    ciclo_lectivo INT NOT NULL,
    PRIMARY KEY (id_alumno, cuatrimestre, ciclo_lectivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Acción AJAX: Guardar dinámicamente un alumno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_ajax') {
    $id_alumno = intval($_POST['id_alumno']);
    $v1 = floatval($_POST['v1']);
    $v2 = floatval($_POST['v2']);

    $q1 = "INSERT INTO inasistencias_resumen (id_alumno, cuatrimestre, inasistencias, ciclo_lectivo) 
           VALUES ($id_alumno, 1, $v1, $ciclo) 
           ON DUPLICATE KEY UPDATE inasistencias = VALUES(inasistencias)";
    $conexion->query($q1);

    $q2 = "INSERT INTO inasistencias_resumen (id_alumno, cuatrimestre, inasistencias, ciclo_lectivo) 
           VALUES ($id_alumno, 2, $v2, $ciclo) 
           ON DUPLICATE KEY UPDATE inasistencias = VALUES(inasistencias)";
    $conexion->query($q2);

    echo "OK";
    exit;
}

// Obtener alumnos
$alumnos = $conexion->query("
    SELECT a.id_alumno, u.nombre, u.apellido 
    FROM alumnos a 
    INNER JOIN usuarios u ON a.id_usuario = u.id_usuario 
    WHERE a.id_curso = $id_curso 
    ORDER BY u.apellido, u.nombre
")->fetch_all(MYSQLI_ASSOC);

// Cargar inasistencias existentes
$existentes = [];
$resE = $conexion->query("SELECT id_alumno, cuatrimestre, inasistencias FROM inasistencias_resumen WHERE ciclo_lectivo = $ciclo");
while($re = $resE->fetch_assoc()){
    $existentes[$re['id_alumno']][$re['cuatrimestre']] = (float)$re['inasistencias'];
}

function formatInasistencia($val) {
    if ($val == 0) return "";
    $entero = floor($val);
    $decimal = $val - $entero;
    $frac = "";
    if ($decimal == 0.25) $frac = " 1/4";
    if ($decimal == 0.50) $frac = " 1/2";
    if ($decimal == 0.75) $frac = " 3/4";
    return ($entero > 0 ? $entero : "0") . ($frac ? " y" . $frac : "");
}

function getParts($val) {
    $entero = floor($val);
    $decimal = $val - $entero;
    return ['e' => $entero, 'd' => $decimal];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inasistencias - <?= htmlspecialchars($curso['nombre_curso']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="estilos.css">
    <style>
        .container { padding: 20px; max-width: 1100px; margin: 0 auto; }
        .titulo-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom: 20px; background: #16682b; padding: 15px 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .titulo { margin: 0; color: #ffffff; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }
        .titulo i { color: #ffffff !important; }
        .info-bar { display: flex; gap: 20px; margin-bottom: 20px; background: #f8f9fa; padding: 12px 15px; border-radius: 8px; border: 1px solid #dee2e6; font-size: 0.95rem; color: #495057; }
        
        .tabla-wrap { overflow:auto; border:1px solid #e5e5e5; border-radius:10px; max-height:70vh; position: relative; contain: paint; background:#fff; }
        table { width: 100%; border-collapse: separate; border-spacing:0; background:#fff; color:#000; }
        th, td { border:1px solid #e5e5e5; padding:6px 8px; font-size:13px; box-sizing: border-box; vertical-align: middle; }
        
        thead tr:nth-child(1) th {
            position: sticky; top: 0; background: #f7f7f9; z-index: 12;
            border-bottom: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            height: 40px; background-clip: padding-box; color: #000;
        }
        thead tr:nth-child(2) th {
            position: sticky; top: 40px; background: #f7f7f9; z-index: 11;
            border-top: none; border-bottom: 2px solid #16682b;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            height: 30px; background-clip: padding-box; color: #000;
        }
        
        /* Sticky Column for names simulating asistencias_curso */
        th:first-child, td:first-child { position: sticky; left: 0; background: #fff; z-index: 10; box-shadow: inset -1px 0 0 #e5e5e5; }
        thead tr:nth-child(1) th:first-child, thead tr:nth-child(2) th:first-child { z-index: 13; background: #f7f7f9; }
        
        .col-name { text-align: left; font-weight: 500; min-width: 250px; width: 250px; color: #000000; }
        .input-group { display: flex; align-items: center; justify-content: center; gap: 4px; }
        .input-num { width: 60px; padding: 6px; border: 1px solid #cfd3d7; border-radius: 6px; text-align: center; outline: none; transition: border-color 0.2s; font-size:13px; }
        .input-num:focus { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.25); }
        .input-frac { padding: 6px 4px; border: 1px solid #cfd3d7; border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
        .input-frac:focus { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.25); }
        .total-cell { font-weight: 700; color: #16682b; text-align: center; background: #f8f9fa; font-size: 14px;}
        
        .btn-save { background: #16682b; color: #fff; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s; margin-top: 20px; font-size: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn-save:hover { background: #115322; }
        .btn-volver { display:inline-flex; align-items:center; gap:8px; background:#6c757d; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:14px; text-decoration:none; box-shadow:0 2px 4px rgba(0,0,0,0.15); transition: opacity 0.2s; }
        .btn-volver:hover { opacity: 0.9; }
        
        .header-q { text-transform: uppercase; font-size: 0.85rem; }
        
        .badge-ok { background:#d4edda; color:#155724; padding:10px 15px; border-radius:8px; margin-bottom:20px; border:1px solid #c3e6cb; }
    </style>
</head>
<body>
    <?php include "navbar.php"; ?>
    
    <div class="container">
        <div class="titulo-bar">
            <h2 class="titulo"><i class="fa fa-calendar-times"></i> Inasistencias: <?= htmlspecialchars($curso['nombre_curso']) ?></h2>
            <a href="miscursos.php" class="btn-volver"><i class="fa fa-arrow-left"></i> Volver</a>
        </div>

        <div class="info-bar">
            <span><strong>Ciclo Lectivo:</strong> <?= $ciclo ?></span>
            <span><strong>Días Hábiles Q1:</strong> <?= $diasQ1 ?></span>
            <span><strong>Días Hábiles Q2:</strong> <?= $diasQ2 ?></span>
            <span id="save-status" style="margin-left:auto; font-weight:600; color:#16682b;"></span>
        </div>

        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" class="col-name">APELLIDO Y NOMBRE</th>
                        <th colspan="2" class="header-q">1º CUATRIMESTRE</th>
                        <th colspan="2" class="header-q">2º CUATRIMESTRE</th>
                        <th rowspan="2">TOTAL INASIST.</th>
                    </tr>
                    <tr>
                        <th style="font-size:0.75rem;">D. HÁBILES</th>
                        <th style="font-size:0.75rem;">INASISTENCIAS</th>
                        <th style="font-size:0.75rem;">D. HÁBILES</th>
                        <th style="font-size:0.75rem;">INASISTENCIAS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($alumnos)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#888;">No hay alumnos en este curso.</td></tr>
                    <?php else: ?>
                        <?php foreach($alumnos as $a): 
                            $v1 = $existentes[$a['id_alumno']][1] ?? 0;
                            $v2 = $existentes[$a['id_alumno']][2] ?? 0;
                            $p1 = getParts($v1);
                            $p2 = getParts($v2);
                        ?>
                            <tr>
                                <td class="col-name"><?= htmlspecialchars(strtoupper($a['apellido'].', '.$a['nombre'])) ?></td>
                                <td style="text-align:center; color:#718096; font-size:0.9rem;"><?= $diasQ1 ?></td>
                                <td>
                                    <div class="input-group">
                                        <input type="number" class="input-num" min="0" step="1" 
                                               value="<?= (int)$p1['e'] ?>"
                                               oninput="calcTotal(<?= $a['id_alumno'] ?>)"
                                               id="num_<?= $a['id_alumno'] ?>_1">
                                        <select class="input-frac" onchange="calcTotal(<?= $a['id_alumno'] ?>)" id="frac_<?= $a['id_alumno'] ?>_1">
                                            <option value="0" <?= $p1['d'] == 0 ? 'selected' : '' ?>>-</option>
                                            <option value="0.25" <?= abs($p1['d'] - 0.25) < 0.01 ? 'selected' : '' ?>>1/4</option>
                                            <option value="0.5" <?= abs($p1['d'] - 0.5) < 0.01 ? 'selected' : '' ?>>1/2</option>
                                            <option value="0.75" <?= abs($p1['d'] - 0.75) < 0.01 ? 'selected' : '' ?>>3/4</option>
                                        </select>
                                        <input type="hidden" name="nasist[<?= $a['id_alumno'] ?>][1]" id="val_<?= $a['id_alumno'] ?>_1" value="<?= $v1 ?>">
                                    </div>
                                </td>
                                <td style="text-align:center; color:#718096; font-size:0.9rem;"><?= $diasQ2 ?></td>
                                <td>
                                    <div class="input-group">
                                        <input type="number" class="input-num" min="0" step="1" 
                                               value="<?= (int)$p2['e'] ?>"
                                               oninput="calcTotal(<?= $a['id_alumno'] ?>)"
                                               id="num_<?= $a['id_alumno'] ?>_2">
                                        <select class="input-frac" onchange="calcTotal(<?= $a['id_alumno'] ?>)" id="frac_<?= $a['id_alumno'] ?>_2">
                                            <option value="0" <?= $p2['d'] == 0 ? 'selected' : '' ?>>-</option>
                                            <option value="0.25" <?= abs($p2['d'] - 0.25) < 0.01 ? 'selected' : '' ?>>1/4</option>
                                            <option value="0.5" <?= abs($p2['d'] - 0.5) < 0.01 ? 'selected' : '' ?>>1/2</option>
                                            <option value="0.75" <?= abs($p2['d'] - 0.75) < 0.01 ? 'selected' : '' ?>>3/4</option>
                                        </select>
                                        <input type="hidden" name="nasist[<?= $a['id_alumno'] ?>][2]" id="val_<?= $a['id_alumno'] ?>_2" value="<?= $v2 ?>">
                                    </div>
                                </td>
                                <td class="total-cell" id="total_<?= $a['id_alumno'] ?>"><?= formatInasistencia($v1 + $v2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function formatVal(val) {
            if (val === 0) return "0";
            const entero = Math.floor(val);
            const decimal = val - entero;
            let frac = "";
            if (Math.abs(decimal - 0.25) < 0.01) frac = " 1/4";
            if (Math.abs(decimal - 0.50) < 0.01) frac = " 1/2";
            if (Math.abs(decimal - 0.75) < 0.01) frac = " 3/4";
            return (entero > 0 || !frac ? entero : "0") + (frac ? " y" + frac : "");
        }

        let timeoutAutosave = {};

        function calcTotal(id) {
            const n1 = parseFloat(document.getElementById('num_' + id + '_1').value) || 0;
            const f1 = parseFloat(document.getElementById('frac_' + id + '_1').value) || 0;
            const n2 = parseFloat(document.getElementById('num_' + id + '_2').value) || 0;
            const f2 = parseFloat(document.getElementById('frac_' + id + '_2').value) || 0;

            const v1 = n1 + f1;
            const v2 = n2 + f2;

            document.getElementById('val_' + id + '_1').value = v1;
            document.getElementById('val_' + id + '_2').value = v2;

            const tdTotal = document.getElementById('total_' + id);
            tdTotal.textContent = formatVal(v1 + v2);
            
            // Auto-guardado
            if (timeoutAutosave[id]) clearTimeout(timeoutAutosave[id]);
            
            tdTotal.style.opacity = '0.5';
            document.getElementById('save-status').innerHTML = '<i class="fa fa-sync fa-spin"></i> Guardando...';

            timeoutAutosave[id] = setTimeout(() => {
                const formData = new URLSearchParams();
                formData.append('accion', 'guardar_ajax');
                formData.append('id_alumno', id);
                formData.append('v1', v1);
                formData.append('v2', v2);

                fetch('inasistencias_curso.php?id=<?= $id_curso ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.text())
                .then(txt => {
                    tdTotal.style.opacity = '1';
                    document.getElementById('save-status').innerHTML = '<i class="fa fa-check"></i> Guardado';
                    setTimeout(() => { document.getElementById('save-status').innerHTML = ''; }, 2000);
                })
                .catch(e => {
                    tdTotal.style.opacity = '1';
                    document.getElementById('save-status').innerHTML = '<i class="fa fa-times" style="color:red;"></i> Error al guardar';
                });
            }, 600);
        }
    </script>
</body>
</html>
