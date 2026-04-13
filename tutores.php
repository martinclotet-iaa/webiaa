<?php  
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

// Permitir el endpoint JSON de detalle de tutor para usuarios logueados
if (isset($_GET['obtener_tutor'])) {
    require_once "conexion.php";
    $id_tutor = intval($_GET['obtener_tutor']);
    $tutor = $conexion->query("SELECT t.id_tutor, u.nombre, u.apellido, u.email, t.telefono 
                              FROM tutores t 
                              INNER JOIN usuarios u ON t.id_usuario = u.id_usuario 
                              WHERE t.id_tutor = $id_tutor")->fetch_assoc();
    if ($tutor) {
        $alumnos = $conexion->query("\n            SELECT u.nombre, u.apellido, c.nombre_curso \n            FROM alumno_tutor at\n            INNER JOIN alumnos a ON at.id_alumno = a.id_alumno\n            INNER JOIN usuarios u ON a.id_usuario = u.id_usuario\n            INNER JOIN cursos c ON a.id_curso = c.id_curso\n            WHERE at.id_tutor = $id_tutor\n            ORDER BY u.apellido, u.nombre\n        ")->fetch_all(MYSQLI_ASSOC);
        $tutor['alumnos'] = $alumnos;
        header('Content-Type: application/json');
        echo json_encode($tutor);
        exit;
    } else {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Tutor no encontrado']);
        exit;
    }
}

require_once __DIR__ . '/acceso.php';
require_once "conexion.php";

$cursos = $conexion->query("SELECT * FROM cursos ORDER BY nombre_curso")->fetch_all(MYSQLI_ASSOC);
$alumnos_db = $conexion->query("
    SELECT a.id_alumno, u.id_usuario, CONCAT(u.apellido, ', ', u.nombre) AS nombre_completo, c.nombre_curso
    FROM alumnos a
    INNER JOIN usuarios u ON a.id_usuario=u.id_usuario
    INNER JOIN cursos c ON a.id_curso=c.id_curso
    ORDER BY u.apellido ASC, u.nombre ASC
")->fetch_all(MYSQLI_ASSOC);

// --- ACCIÓN: CARGAR CSV ---
if (isset($_POST['accion']) && $_POST['accion'] == "cargar_csv") {
    // Solo admins pueden cargar CSV (perfil 4)
    if (($_SESSION['perfil_actual'] ?? 1) != 4) { die("Acceso denegado"); }
    
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        $conexion->begin_transaction();
        $success = 0;
        $duplicates = 0;
        $esCabeza = true;
        try {
            while (($raw = fgetcsv($handle, 0, ",")) !== FALSE) {
                if (count($raw) == 1 && strpos($raw[0], ';') !== false) {
                    $data = str_getcsv($raw[0], ';');
                } else {
                    $data = $raw;
                }

                // Convertir a UTF-8 si es necesario
                foreach ($data as $k => $v) {
                    if (!mb_check_encoding($v, 'UTF-8')) {
                        $data[$k] = function_exists('mb_convert_encoding') 
                            ? mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1') 
                            : utf8_encode($v);
                    }
                }

                if (empty($data) || empty($data[0])) continue;

                // Saltar cabecera si existe
                if ($esCabeza) {
                    $c1 = strtolower(trim($data[0]));
                    $c2 = strtolower(isset($data[1]) ? trim($data[1]) : '');
                    if ($c1 == 'nombre' || $c1 == 'nombre/s' || $c2 == 'apellido') {
                        $esCabeza = false;
                        continue;
                    }
                    $esCabeza = false;
                }
                
                $nom = $conexion->real_escape_string(trim($data[0] ?? ''));
                $ape = $conexion->real_escape_string(trim($data[1] ?? ''));
                $eml = $conexion->real_escape_string(trim($data[2] ?? ''));
                $clv = $conexion->real_escape_string(trim($data[3] ?? ''));
                $tel = $conexion->real_escape_string(trim($data[4] ?? ''));
                $dni_alum = trim($data[5] ?? '');
                
                if (empty($eml)) continue;

                // Función auxiliar para asignar alumno
                $asignarAlumno = function($id_t, $dni_c, $con) {
                    if (empty($dni_c)) return;
                    $dni_c = preg_replace('/[^0-9]/', '', $dni_c);
                    if ($dni_c === '') return;
                    
                    $aq = $con->query("SELECT id_alumno FROM alumnos WHERE dni = '$dni_c' LIMIT 1");
                    if ($aq && $aq->num_rows > 0) {
                        $id_a = $aq->fetch_assoc()['id_alumno'];
                        $lc = $con->query("SELECT 1 FROM alumno_tutor WHERE id_alumno=$id_a AND id_tutor=$id_t");
                        if ($lc && $lc->num_rows == 0) {
                            $con->query("INSERT INTO alumno_tutor (id_alumno, id_tutor) VALUES ($id_a, $id_t)");
                        }
                    }
                };

                // Verificar si el usuario/tutor ya existe
                $check = $conexion->query("SELECT u.id_usuario, t.id_tutor FROM usuarios u LEFT JOIN tutores t ON u.id_usuario = t.id_usuario WHERE u.email='$eml' LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $existente = $check->fetch_assoc();
                    $id_t = $existente['id_tutor'];
                    
                    // Si el usuario existe pero no es tutor, le damos el perfil y creamos entrada en tutores
                    if (!$id_t) {
                        $id_u_exists = $existente['id_usuario'];
                        $conexion->query("INSERT IGNORE INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u_exists, 2)");
                        $conexion->query("INSERT INTO tutores (id_usuario, telefono) VALUES ($id_u_exists, '$tel')");
                        $id_t = $conexion->insert_id;
                    }
                    
                    if ($id_t) $asignarAlumno($id_t, $dni_alum, $conexion);
                    $duplicates++;
                    continue;
                }
                
                // Insertar Usuario Nuevo
                $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nom','$ape','$eml','$clv')");
                $id_u = $conexion->insert_id;
                
                // Perfil 2 = Tutor
                $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u, 2)");
                
                // Insertar en tutores
                $conexion->query("INSERT INTO tutores (id_usuario, telefono) VALUES ($id_u, '$tel')");
                $id_t = $conexion->insert_id;

                // Asignar alumno
                $asignarAlumno($id_t, $dni_alum, $conexion);
                
                $success++;


            }
            $conexion->commit();
            fclose($handle);
            header("Location: tutores.php?success_csv=$success&duplicates=$duplicates"); exit;
        } catch (Throwable $t) {
            $conexion->rollback();
            if(isset($handle)) fclose($handle);
            $msg = urlencode($t->getMessage());
            header("Location: tutores.php?error_csv=1&msg=$msg"); exit;
        }
    }
}


// Acciones
if (isset($_POST['accion']) && $_POST['accion'] == "crear") {
    $nombre = $_POST['nombre']; $apellido = $_POST['apellido']; $email = $_POST['email']; $clave = $_POST['clave'];
    $telefono = $_POST['telefono'] ?? '';
    $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nombre','$apellido','$email','$clave')");
    $id_u = $conexion->insert_id;
    $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u, 2)");
    $conexion->query("INSERT INTO tutores (id_usuario, telefono) VALUES ($id_u, '$telefono')");
    $id_t = $conexion->insert_id;
    if (!empty($_POST['alumnos'])) {
        foreach ($_POST['alumnos'] as $id_a) $conexion->query("INSERT INTO alumno_tutor (id_alumno, id_tutor) VALUES ($id_a, $id_t)");
    }
    header("Location: tutores.php"); exit;
}
if (isset($_POST['accion']) && $_POST['accion'] == "editar") {
    $id_u = intval($_POST['id_usuario']); $nombre = $_POST['nombre']; $apellido = $_POST['apellido']; $email = $_POST['email']; $clave = $_POST['clave']; $telefono = $_POST['telefono'] ?? '';
    $sql_u = !empty($clave) ? "UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email', clave='$clave' WHERE id_usuario=$id_u" : "UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email' WHERE id_usuario=$id_u";
    $conexion->query($sql_u);
    $conexion->query("UPDATE tutores SET telefono='$telefono' WHERE id_usuario=$id_u");
    $t = $conexion->query("SELECT id_tutor FROM tutores WHERE id_usuario=$id_u")->fetch_assoc();
    $id_t = $t['id_tutor'];
    $conexion->query("DELETE FROM alumno_tutor WHERE id_tutor=$id_t");
    if (!empty($_POST['alumnos'])) {
        foreach ($_POST['alumnos'] as $id_a) $conexion->query("INSERT INTO alumno_tutor (id_alumno, id_tutor) VALUES ($id_a, $id_t)");
    }
    header("Location: tutores.php"); exit;
}
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $t = $conexion->query("SELECT id_tutor FROM tutores WHERE id_usuario=$id")->fetch_assoc();
    if($t){
        $id_t = $t['id_tutor'];
        $conexion->query("DELETE FROM alumno_tutor WHERE id_tutor=$id_t");
        $conexion->query("DELETE FROM tutores WHERE id_usuario=$id");
        $conexion->query("DELETE FROM usuario_perfil WHERE id_usuario=$id AND id_perfil=2");
        $conexion->query("DELETE FROM usuarios WHERE id_usuario=$id");
    }
    header("Location: tutores.php"); exit;
}

$busqueda = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : "";
$sql = "
    SELECT t.id_tutor, u.id_usuario, u.nombre, u.apellido, u.email, t.telefono,
           GROUP_CONCAT(DISTINCT CONCAT(IF(aa.apellido IS NOT NULL AND aa.apellido != '', CONCAT(aa.apellido, ', '), ''), aa.nombre, ' (', c.nombre_curso, ')', '|||', a.id_alumno) SEPARATOR ';;') as alumnos_list,
           GROUP_CONCAT(DISTINCT a.id_alumno) as id_alumnos
    FROM usuarios u
    INNER JOIN usuario_perfil up ON u.id_usuario = up.id_usuario AND up.id_perfil = 2
    INNER JOIN tutores t ON u.id_usuario = t.id_usuario
    LEFT JOIN alumno_tutor at ON t.id_tutor=at.id_tutor
    LEFT JOIN alumnos a ON at.id_alumno=a.id_alumno
    LEFT JOIN usuarios aa ON a.id_usuario=aa.id_usuario
    LEFT JOIN cursos c ON a.id_curso=c.id_curso
    WHERE 1=1
";
if ($busqueda != "") $sql .= " AND (u.nombre LIKE '%$busqueda%' OR u.apellido LIKE '%$busqueda%' OR u.email LIKE '%$busqueda%' OR t.telefono LIKE '%$busqueda%')";
$sql .= " GROUP BY t.id_tutor ORDER BY u.apellido, u.nombre";
$tutores = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    ob_start(); ?>
    <div class="tutores-lista">
      <?php if (empty($tutores)): ?>
        <p style="color:#888; text-align:center; padding:30px;">No se encontraron tutores.</p>
      <?php else: ?>
      <?php foreach ($tutores as $t): ?>
        <div class="tutor-row">
          <div class="tutor-row-content">
            <div class="tutor-nombre">
              <?php 
                $nombreCompleto = trim(($t['apellido'] ?? '') . ', ' . ($t['nombre'] ?? ''));
                echo htmlspecialchars(trim($nombreCompleto, ', ')); 
              ?>
            </div>
            <div class="tutor-sub">
              <?php if (!empty($t['telefono'])): ?>
                <span><i class="fa fa-phone" style="color:#6c757d;"></i> <?= htmlspecialchars($t['telefono']) ?></span>
              <?php endif; ?>
              <span><i class="fa fa-envelope" style="color:#6c757d;"></i> <?= htmlspecialchars($t['email']) ?></span>
            </div>
            <div class="tutor-alumnos" id="alumnos-<?= $t['id_tutor'] ?>">
              <?php if (!empty($t['alumnos_list'])):
                $lista = explode(";;" , $t['alumnos_list']);
                foreach ($lista as $item):
                  if (empty(trim($item))) continue;
                  list($nombreC, $idA) = explode("|||" , $item);
                  preg_match('/(.*?)\s*\(([^)]+)\)$/', $nombreC, $matches);
                  $nom = isset($matches[1]) ? trim($matches[1]) : $nombreC;
                  $cur = isset($matches[2]) ? $matches[2] : '';
              ?>
                <span class="alumno-pill">
                  <?= htmlspecialchars($nom) ?><?= $cur ? ' <span class="alumno-pill-curso">('.$cur.')</span>' : '' ?>
                  <button type="button" onclick="removerAlumno(<?= $t['id_tutor'] ?>, <?= $idA ?>, event)" title="Quitar" class="pill-remove"><i class="fa fa-times"></i></button>
                </span>
              <?php endforeach; endif; ?>
              <button type="button" onclick="abrirModalAsignar(<?= $t['id_tutor'] ?>)" class="btn-agregar-alumno" title="Agregar alumno">
                <i class="fa fa-plus"></i> Alumno
              </button>
            </div>
          </div>
          <div class="tutor-row-actions">
            <a href="#" onclick="abrirModalEditar(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)" class="btn-row btn-edit" title="Editar">
              <i class="fa-solid fa-pen-to-square"></i> <span class="btn-label">Editar</span>
            </a>
            <a href="tutores.php?eliminar=<?= $t['id_usuario'] ?>" class="btn-row btn-delete" title="Eliminar">
              <i class="fa-solid fa-trash"></i> <span class="btn-label">Eliminar</span>
            </a>
          </div>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php echo ob_get_clean(); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Tutores</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="estilos.css?v=<?php echo time(); ?>"> 
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

  .tutores-lista { width:95%; max-width: 1200px; margin:20px auto; display:flex; flex-direction:column; gap:8px; }
  .tutor-row {
    background:#fff; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08);
    padding:12px 16px; display:flex; justify-content: space-between; align-items: flex-start;
    gap: 15px; transition:box-shadow 0.2s;
  }
  .tutor-row:hover { box-shadow:0 3px 10px rgba(0,0,0,0.12); }
  
  .tutor-row-content { flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .tutor-nombre { font-weight:600; font-size:15px; color:#222; }
  .tutor-sub { display:flex; flex-wrap:wrap; gap:12px; font-size:13px; color:#666; margin-bottom:4px; }
  .tutor-alumnos { display:flex; flex-wrap:wrap; gap:5px; align-items:center; }

  .alumno-pill {
    background:#e8f5e9; border:1px solid #a5d6a7; color:#2e7d32;
    border-radius:20px; padding:2px 8px 2px 10px;
    font-size:11px; display:inline-flex; align-items:center; gap:4px;
    white-space: nowrap;
  }
  .alumno-pill-curso { color:#66bb6a; font-size:10px; }
  .pill-remove { background:none; border:none; color:#e57373; cursor:pointer; padding:0; display:flex; align-items:center; font-size:11px; }
  .btn-agregar-alumno {
    background:transparent; border:1px dashed #aaa; border-radius:20px;
    color:#666; font-size:11px; padding:1px 8px; cursor:pointer;
    display:inline-flex; align-items:center; gap:4px; white-space: nowrap;
  }
  .btn-agregar-alumno:hover { border-color:#28a745; color:#28a745; background: #f8fff9; }

  .tutor-row-actions { display: flex; gap: 8px; flex-shrink: 0; }
  .btn-row {
    padding:6px 14px; border:none; border-radius:5px; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center;
    gap:5px; font-size:13px; font-weight:500; color:white !important;
  }
  .btn-edit { background-color:#2196F3; }
  .btn-delete { background-color:#dc3545; }

  @media (max-width: 600px) {
    .tutor-row { padding: 10px; gap: 8px; }
    .btn-label { display: none; }
    .btn-row { padding: 8px 10px; }
    .tutor-sub { gap: 8px; font-size: 12px; }
    .tutor-row-actions { flex-direction: column; gap: 5px; }
  }
</style>
</head>
<body>
<?php include "navbar.php"; ?>
<h1 style="text-align:center; color: #333;">Gestión de Tutores</h1>

<div style="width:95%; max-width:1200px; margin:10px auto; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <form id="formFiltros" method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar tutor..." style="padding:10px; border-radius:6px; border:1px solid #ccc; width:220px;">
    </form>
    <div style="display:flex; gap:10px;">
      <a href="menu.php"><button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Menú</button></a>
      <?php if (($_SESSION['perfil_actual'] ?? 1) == 4): ?>
        <button onclick="abrirModalCargarCSV()" style="padding:10px 20px; border-radius:8px; background:#28a745; color:#fff; border:none; cursor:pointer;">📄 CSV</button>
      <?php endif; ?>
      <button onclick="abrirModalCrear()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;">➕ Nuevo Tutor</button>
    </div>
</div>

<?php if(isset($_GET['success_csv'])): ?>
    <div style="width:95%; max-width:1200px; margin:10px auto; padding:15px; background:#e8f5e9; color:#2e7d32; border-radius:6px; border:1px solid #c8e6c9;">
        <h4 style="margin:0 0 5px 0;">¡Carga completada!</h4>
        <ul style="margin:0; padding-left:20px; font-size:14px;">
            <li>Nuevos tutores: <b><?= intval($_GET['success_csv']) ?></b></li>
            <?php if(!empty($_GET['duplicates'])): ?><li>Emails ya existentes (omitidos): <b><?= intval($_GET['duplicates']) ?></b></li><?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if(isset($_GET['error_csv'])): ?>
    <div style="width:95%; max-width:1200px; margin:10px auto; padding:15px; background:#ffebee; color:#c62828; border-radius:6px; border:1px solid #ffcdd2;">
        <b>Error al procesar el archivo.</b><br>
        <span style="font-size:13px;"><?= htmlspecialchars($_GET['msg'] ?? 'Ocurrió un error inesperado en el servidor.') ?></span>
    </div>
<?php endif; ?>


<div id="tablaWrapper"></div>

<!-- Modales se mantienen igual (Crear, Editar, Asignar, Confirm)... -->
<div id="modalCrear" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:600px;"><span class="cerrar" onclick="cerrarModalCrear()">&times;</span><h3>Registrar Tutor</h3><form method="post"><input type="hidden" name="accion" value="crear"><input type="text" name="nombre" placeholder="Nombre" required><input type="text" name="apellido" placeholder="Apellido"><input type="email" name="email" placeholder="Email" required><input type="password" name="clave" placeholder="Contraseña" required><input type="text" name="telefono" placeholder="Teléfono"><label>Alumnos:</label><div style="max-height:150px; overflow-y:auto; border:1px solid #ddd; padding:10px; background:#f9f9f9; border-radius:4px;"><?php foreach ($alumnos_db as $a): ?><label style="display:flex; align-items:center; gap:8px; margin:5px 0; background:#fff; padding:5px; border-radius:3px; border:1px solid #eee; cursor:pointer;"><input type="checkbox" name="alumnos[]" value="<?= $a['id_alumno']?>"><?= $a['nombre_completo']?> (<?= $a['nombre_curso']?>)</label><?php endforeach; ?></div><button type="submit">Guardar</button></form></div></div>

<div id="modalEditar" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:600px;"><span class="cerrar" onclick="cerrarModalEditar()">&times;</span><h3>Editar Tutor</h3><form method="post"><input type="hidden" name="accion" value="editar"><input type="hidden" name="id_usuario" id="edit_id_usuario"><input type="text" name="nombre" id="edit_nombre" required><input type="text" name="apellido" id="edit_apellido"><input type="email" name="email" id="edit_email" required><input type="password" name="clave" id="edit_clave" placeholder="Nueva Contraseña"><input type="text" name="telefono" id="edit_telefono"><label>Alumnos:</label><div id="edit_alumnos_container" style="max-height:150px; overflow-y:auto; border:1px solid #ddd; padding:10px; background:#f9f9f9; border-radius:4px;"><?php foreach ($alumnos_db as $a): ?><label style="display:flex; align-items:center; gap:8px; margin:5px 0; background:#fff; padding:5px; border-radius:3px; border:1px solid #eee; cursor:pointer;"><input type="checkbox" name="alumnos[]" value="<?= $a['id_alumno']?>" class="edit-alumno-checkbox"><?= $a['nombre_completo']?> (<?= $a['nombre_curso']?>)</label><?php endforeach; ?></div><button type="submit">Guardar Cambios</button></form></div></div>

<div id="modalAsignar" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:400px;"><span class="cerrar" onclick="cerrarModalAsignar()">&times;</span><h3>Asignar Alumno</h3><select id="selectAlumno" style="width:100%; padding:8px; margin-bottom:15px;"><option value="">-- Alumno --</option><?php foreach ($alumnos_db as $a): ?><option value="<?= $a['id_alumno']?>"><?= $a['nombre_completo']?> (<?= $a['nombre_curso']?>)</option><?php endforeach; ?></select><button onclick="asignarAlumno()" style="width:100%; padding:10px; background:#16682b; color:white; border:none; border-radius:4px; cursor:pointer;">Asignar</button></div></div>

<!-- Modal CSV -->
<div id="modalCargarCSV" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:450px;">
        <span class="cerrar" onclick="cerrarModalCargarCSV()">&times;</span>
        <h3>Cargar Tutores desde CSV</h3>
        <p style="font-size:13px; color:#666;">El archivo puede usar coma (,) o punto y coma (;) como separador.<br>Orden de columnas: <b>nombre, apellido, email, clave, telefono, dni_alumno</b></p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="cargar_csv">
            <input type="file" name="csv_file" accept=".csv" required style="margin:15px 0; width:100%;">
            <button type="submit" style="width:100%; padding:10px; background:#28a745; color:white; border:none; border-radius:4px; cursor:pointer;">Subir y Cargar</button>
        </form>
    </div>
</div>

<div id="confirm-dialog"><div class="confirm-dialog-content"><div class="row"><i class="fa-solid fa-exclamation-triangle confirm-dialog-icon"></i><p id="confirm-msg" class="confirm-dialog-message"></p></div><div class="confirm-dialog-buttons"><button onclick="closeConfirm(false)" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button><button onclick="closeConfirm(true)" class="confirm-dialog-button confirm-dialog-confirm">Aceptar</button></div></div></div>

<script>
let tutorActual = null; let confirmResolve = null;
function abrirModalCrear(){ document.getElementById("modalCrear").style.display="flex"; }
function cerrarModalCrear(){ document.getElementById("modalCrear").style.display="none"; }
function abrirModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="flex"; }
function cerrarModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="none"; }
function abrirModalEditar(t){

    document.getElementById("modalEditar").style.display="flex";
    document.getElementById("edit_id_usuario").value = t.id_usuario;
    document.getElementById("edit_nombre").value = t.nombre;
    document.getElementById("edit_apellido").value = t.apellido;
    document.getElementById("edit_email").value = t.email;
    document.getElementById("edit_telefono").value = t.telefono || '';
    const ids = t.id_alumnos ? t.id_alumnos.split(",") : [];
    document.querySelectorAll(".edit-alumno-checkbox").forEach(c => c.checked = ids.includes(c.value));
}
function cerrarModalEditar(){ document.getElementById("modalEditar").style.display="none"; }
function abrirModalAsignar(id){ tutorActual = id; document.getElementById("modalAsignar").style.display="flex"; }
function cerrarModalAsignar(){ document.getElementById("modalAsignar").style.display="none"; }
async function asignarAlumno(){
    const idA = document.getElementById("selectAlumno").value;
    if(!idA || !tutorActual) return;
    const res = await fetch(`tutores_ajax.php?accion=asignar_alumno&id_tutor=${tutorActual}&id_alumno=${idA}`);
    if(await res.text() === "OK"){ cerrarModalAsignar(); actualizarTabla(); }
}
async function removerAlumno(idT, idA, e){
    if(e) { e.preventDefault(); e.stopPropagation(); }
    if(await showConfirm("¿Quitar este alumno?")){
        const res = await fetch(`tutores_ajax.php?accion=remover_alumno&id_tutor=${idT}&id_alumno=${idA}`);
        const data = await res.json();
        if(data.status === 'success' || data === 'OK') actualizarTabla();
    }
}
function showConfirm(msg){
    document.getElementById("confirm-msg").textContent = msg;
    document.getElementById("confirm-dialog").style.display = "flex";
    return new Promise(r => confirmResolve = r);
}
function closeConfirm(v){ document.getElementById("confirm-dialog").style.display = "none"; if(confirmResolve) confirmResolve(v); }
async function actualizarTabla(){
    const p = new URLSearchParams(new FormData(document.getElementById('formFiltros')));
    p.append('partial','1');
    const r = await fetch('tutores.php?' + p.toString(), { cache:'no-store' });
    document.getElementById('tablaWrapper').innerHTML = await r.text();
}
document.getElementById('formFiltros').querySelector('input').addEventListener('input', () => { clearTimeout(window.t); window.t = setTimeout(actualizarTabla, 300); });
document.addEventListener("DOMContentLoaded", actualizarTabla);
document.addEventListener('click', e => {
    const a = e.target.closest('a[href*="tutores.php?eliminar="]');
    if(a){ e.preventDefault(); showConfirm("¿Eliminar este tutor?").then(v => { if(v) window.location.href = a.href; }); }
}, true);
</script>
</body>
</html>
