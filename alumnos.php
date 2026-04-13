<?php  
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}
require_once __DIR__ . '/acceso.php';
require_once "conexion.php";

$cursos = $conexion->query("SELECT * FROM cursos ORDER BY nombre_curso")->fetch_all(MYSQLI_ASSOC);
$mapa_cursos = [];
foreach ($cursos as $c) {
    if (!empty($c['nombre_curso'])) {
        $mapa_cursos[strtoupper(trim($c['nombre_curso']))] = (int)$c['id_curso'];
    }
}

// Lista de tutores para el modal de asignación
$tutores_db = $conexion->query("
    SELECT t.id_tutor, CONCAT(u.apellido, ', ', u.nombre) as nombre_completo
    FROM tutores t
    INNER JOIN usuarios u ON t.id_usuario = u.id_usuario
    ORDER BY u.apellido, u.nombre
")->fetch_all(MYSQLI_ASSOC);

$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1;
$esAdmin = ($perfilActual === 4);
$puedeGestionar = ($perfilActual === 4 || $perfilActual === 5);

// --- ACCIÓN: CARGAR CSV ---
if (isset($_POST['accion']) && $_POST['accion'] == "cargar_csv") {
    if (!$esAdmin) { die("Acceso denegado"); }
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        $conexion->begin_transaction();
        $success = 0;
        $duplicates = 0;
        $invalid_courses = 0;
        $esCabeza = true;
        try {
            while (($raw = fgetcsv($handle, 0, ",")) !== FALSE) {
                if (count($raw) == 1 && strpos($raw[0], ';') !== false) {
                    $data = str_getcsv($raw[0], ';');
                } else {
                    $data = $raw;
                }

                // Convertir a UTF-8 si es necesario (para acentos y ñ)
                foreach ($data as $k => $v) {
                    if (!mb_check_encoding($v, 'UTF-8')) {
                        $data[$k] = function_exists('mb_convert_encoding') 
                            ? mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1') 
                            : utf8_encode($v);
                    }
                }

                if (empty($data) || empty($data[0])) continue;

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
                $dni = $conexion->real_escape_string(trim($data[4] ?? ''));
                
                $nombre_c = strtoupper(trim($data[5] ?? ''));
                if (!isset($mapa_cursos[$nombre_c])) {
                    $invalid_courses++;
                    continue; 
                }
                $idc = $mapa_cursos[$nombre_c];
                
                // Verificar si el email ya existe para evitar errores
                $check = $conexion->query("SELECT id_usuario FROM usuarios WHERE email='$eml' LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $duplicates++;
                    continue;
                }

                $lib = isset($data[6]) && $data[6] !== '' ? intval($data[6]) : "NULL";
                $fol = isset($data[7]) && $data[7] !== '' ? intval($data[7]) : "NULL";
                
                // Sanitizar DNI: solo números
                $dni_raw = trim($data[4] ?? '');
                $dni_clean = preg_replace('/[^0-9]/', '', $dni_raw);
                $dni_sql = ($dni_clean === '') ? "NULL" : "'$dni_clean'";
                
                $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nom','$ape','$eml','$clv')");
                $id_u = $conexion->insert_id;
                $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u, 1)");
                $conexion->query("INSERT INTO alumnos (id_usuario, id_curso, dni, libro, folio) VALUES ($id_u, $idc, $dni_sql, $lib, $fol)");
                $success++;
            }
            $conexion->commit();
            fclose($handle);
            header("Location: alumnos.php?success_csv=$success&duplicates=$duplicates&invalid_courses=$invalid_courses"); exit;
        } catch (Throwable $t) {
            $conexion->rollback();
            if(isset($handle)) fclose($handle);
            $msg = urlencode($t->getMessage());
            header("Location: alumnos.php?error_csv=1&msg=$msg"); exit;
        }
    }
}

// Acciones (Crear/Editar/Eliminar)
if (isset($_POST['accion']) && $_POST['accion'] == "crear") {
    if (!$esAdmin) { die("Acceso denegado"); }
    $nombre = $_POST['nombre']; $apellido = $_POST['apellido']; $email = $_POST['email']; $clave = $_POST['clave']; 
    $id_curso = intval($_POST['id_curso']); $dni = $_POST['dni'] ?? '';
    $dni_sql = ($dni === '') ? "NULL" : "'" . $conexion->real_escape_string($dni) . "'";
    $libro = !empty($_POST['libro']) ? intval($_POST['libro']) : "NULL";
    $folio = !empty($_POST['folio']) ? intval($_POST['folio']) : "NULL";

    $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nombre','$apellido','$email','$clave')");
    $id_u = $conexion->insert_id;
    $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u, 1)");
    $conexion->query("INSERT INTO alumnos (id_usuario, id_curso, dni, libro, folio) VALUES ($id_u, $id_curso, $dni_sql, $libro, $folio)");
    header("Location: alumnos.php"); exit;
}
if (isset($_POST['accion']) && $_POST['accion'] == "editar") {
    if (!$puedeGestionar) { die("Acceso denegado"); }
    $id_u = intval($_POST['id_usuario']); $nombre = $_POST['nombre']; $apellido = $_POST['apellido']; 
    $email = $_POST['email']; $clave = $_POST['clave']; $id_curso = intval($_POST['id_curso']); $dni = $_POST['dni'] ?? '';
    $dni_sql = ($dni === '') ? "NULL" : "'" . $conexion->real_escape_string($dni) . "'";
    $libro = !empty($_POST['libro']) ? intval($_POST['libro']) : "NULL";
    $folio = !empty($_POST['folio']) ? intval($_POST['folio']) : "NULL";

    $sql_u = !empty($clave) ? "UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email', clave='$clave' WHERE id_usuario=$id_u" : "UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email' WHERE id_usuario=$id_u";
    $conexion->query($sql_u); 
    $conexion->query("UPDATE alumnos SET id_curso=$id_curso, dni=$dni_sql, libro=$libro, folio=$folio WHERE id_usuario=$id_u");
    header("Location: alumnos.php"); exit;
}
if (isset($_GET['eliminar'])) {
    if (!$esAdmin) { die("Acceso denegado"); }
    $id = intval($_GET['eliminar']); $a = $conexion->query("SELECT id_alumno FROM alumnos WHERE id_usuario=$id")->fetch_assoc();
    if($a){ 
        $id_a = $a['id_alumno']; 
        $conexion->query("DELETE FROM alumno_tutor WHERE id_alumno=$id_a"); 
        $conexion->query("DELETE FROM alumnos WHERE id_usuario=$id"); 
        $conexion->query("DELETE FROM usuario_perfil WHERE id_usuario=$id AND id_perfil=1"); 
        $conexion->query("DELETE FROM usuarios WHERE id_usuario=$id"); 
    }
    header("Location: alumnos.php"); exit;
}

$busqueda = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : "";
$filtroCurso = isset($_GET['curso']) ? intval($_GET['curso']) : 0;

$sql = "
    SELECT a.id_alumno, u.id_usuario, u.nombre, u.apellido, u.email, c.nombre_curso, a.id_curso, a.dni, a.libro, a.folio,
           GROUP_CONCAT(DISTINCT CONCAT(IF(ut.apellido IS NOT NULL AND ut.apellido != '', CONCAT(ut.apellido, ', '), ''), ut.nombre, '|||', t.id_tutor) SEPARATOR ';;') as tutores_list
    FROM alumnos a
    INNER JOIN usuarios u ON a.id_usuario = u.id_usuario
    LEFT JOIN cursos c ON a.id_curso = c.id_curso
    LEFT JOIN alumno_tutor at ON a.id_alumno = at.id_alumno
    LEFT JOIN tutores t ON at.id_tutor = t.id_tutor
    LEFT JOIN usuarios ut ON t.id_usuario = ut.id_usuario
    WHERE 1=1
";

if ($filtroCurso > 0) {
    $sql .= " AND a.id_curso=$filtroCurso";
}
if ($busqueda != "") {
    $sql .= " AND (u.nombre LIKE '%$busqueda%' OR u.apellido LIKE '%$busqueda%' OR u.email LIKE '%$busqueda%' OR a.dni LIKE '%$busqueda%' OR c.nombre_curso LIKE '%$busqueda%')";
}
$sql .= " GROUP BY a.id_alumno ORDER BY u.apellido, u.nombre";
$res_sql = $conexion->query($sql);
$alumnos_list = $res_sql ? $res_sql->fetch_all(MYSQLI_ASSOC) : [];

if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    ob_start(); ?>
    <div class="alumnos-lista">
      <?php if (empty($alumnos_list)): ?>
        <p style="color:#888; text-align:center; padding:30px;">No se encontraron alumnos.</p>
      <?php else: ?>
      <?php foreach ($alumnos_list as $a): ?>
        <div class="alumno-row">
          <div class="alumno-row-content">
            <div class="alumno-nombre">
                <?= htmlspecialchars(($a['apellido'] ?? '').', '.($a['nombre'] ?? '')) ?> 
                <span class="curso-pill"><?= htmlspecialchars($a['nombre_curso'] ?? 'Sin Curso') ?></span>
            </div>
            <div class="alumno-sub">
                <?php if(!empty($a['dni'])): ?><span>DNI: <?= htmlspecialchars($a['dni']) ?></span><?php endif; ?>
                <?php if(!empty($a['libro'])): ?><span>L: <?= htmlspecialchars($a['libro']) ?></span><?php endif; ?>
                <?php if(!empty($a['folio'])): ?><span>F: <?= htmlspecialchars($a['folio']) ?></span><?php endif; ?>
                <span><i class="fa fa-envelope" style="color:#6c757d;"></i> <a href="https://mail.google.com/mail/?view=cm&fs=1&to=<?= htmlspecialchars($a['email'] ?? '') ?>" target="_blank" style="color:inherit; text-decoration:none;" title="Enviar mail via Gmail"><?= htmlspecialchars($a['email'] ?? '') ?></a></span>
            </div>
            <div class="alumno-tutores" id="tutores-wrapper-<?= $a['id_alumno'] ?>">
                <?php if($a['tutores_list']): 
                    $tuts = explode(";;", $a['tutores_list']); 
                    foreach($tuts as $t_str): 
                        if(empty(trim($t_str))) continue;
                        list($nom_t, $id_t) = explode("|||", $t_str);
                ?>
                    <span class="tutor-badge">
                        <a href="#" onclick="verTutor(<?= $id_t ?>, event)" style="color:inherit; text-decoration:none; display:flex; align-items:center; gap:6px;">
                            <i class="fa fa-user-friends"></i> <?= htmlspecialchars($nom_t) ?>
                        </a>
                        <?php if ($puedeGestionar): ?>
                            <button type="button" onclick="removerTutor(<?= $a['id_alumno'] ?>, <?= $id_t ?>, event)" class="badge-remove" title="Quitar tutor">×</button>
                        <?php endif; ?>
                    </span>
                <?php endforeach; endif; ?>
                <?php if ($puedeGestionar): ?>
                    <button type="button" onclick="abrirModalAsignarTutor(<?= $a['id_alumno'] ?>)" class="btn-agregar-tutor">
                        <i class="fa fa-plus"></i> Tutor
                    </button>
                <?php endif; ?>
            </div>
          </div>
          <div class="alumno-row-actions">
            <?php if ($puedeGestionar): ?>
                <a href="#" onclick="abrirModalEditar(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8') ?>)" class="btn-row btn-edit" title="Editar">
                  <i class="fa-solid fa-pen-to-square"></i> <span class="btn-label">Editar</span>
                </a>
            <?php endif; ?>
            <?php if ($esAdmin): ?>
              <a href="alumnos.php?eliminar=<?= $a['id_usuario'] ?>" class="btn-row btn-delete" title="Eliminar">
                <i class="fa-solid fa-trash"></i> <span class="btn-label">Eliminar</span>
              </a>
            <?php endif; ?>
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
<title>Gestión de Alumnos</title>
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

  .alumnos-lista { width:95%; max-width: 1200px; margin:20px auto; display:flex; flex-direction:column; gap:8px; }
  .alumno-row {
    background:#fff; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08);
    padding:12px 16px; display:flex; justify-content: space-between; align-items: flex-start;
    gap: 15px; transition:box-shadow 0.2s;
  }
  .alumno-row:hover { box-shadow:0 3px 10px rgba(0,0,0,0.12); }
  
  .alumno-row-content { flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .alumno-nombre { font-weight:600; font-size:15px; color:#222; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .curso-pill { background: #e3f2fd; color: #1976d2; border: 1px solid #bbdefb; border-radius: 20px; padding: 1px 10px; font-size: 11px; }
  .alumno-sub { display:flex; flex-wrap:wrap; gap:12px; font-size:13px; color:#666; }
  
  .alumno-tutores { display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin-top: 2px; }
  .tutor-badge { 
    background: #f1f8e9; color: #33691e; border-radius: 4px; padding: 2px 8px; font-size: 11px; 
    display: flex; align-items: center; gap: 6px; border: 1px solid #c5e1a5; 
  }
  .badge-remove { 
    background:none; border:none; color:#ef5350; cursor:pointer; padding:0; 
    font-size:14px; line-height:1; font-weight: bold; 
  }
  
  .btn-agregar-tutor {
    background:transparent; border:1px dashed #aaa; border-radius:4px;
    color:#666; font-size:11px; padding:1px 8px; cursor:pointer;
    display:inline-flex; align-items:center; gap:4px;
  }
  .btn-agregar-tutor:hover { border-color:#2e7d32; color:#2e7d32; background:#f1f8e9; }

  .alumno-row-actions { display: flex; gap: 8px; flex-shrink: 0; }
  .btn-row {
    padding:6px 14px; border:none; border-radius:5px; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center;
    gap:5px; font-size:13px; font-weight:500; color:white !important;
  }
  .btn-edit { background-color:#2196F3; }
  .btn-delete { background-color:#dc3545; }
  
  /* Picker de tutor simple */
  .tutor-option:hover { background: #f0f7f4; }
  .modal-overlay.picker-open .modal-box { min-height: 400px; }

  @media (max-width: 600px) {
    .alumno-row { padding: 10px; gap: 8px; }
    .btn-label { display: none; }
    .btn-row { padding: 8px 10px; }
    .alumno-sub { gap: 8px; font-size: 12px; }
    .alumno-row-actions { flex-direction: column; gap: 5px; }
  }
</style>
</head>
<body>
<?php include "navbar.php"; ?>
<h1 style="text-align:center; color:#fff;">Gestión de Alumnos</h1>

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
      <?php if ($esAdmin): ?>
        <button onclick="abrirModalCargarCSV()" style="padding:10px 20px; border-radius:8px; background:#28a745; color:#fff; border:none; cursor:pointer;">📄 CSV</button>
        <button onclick="abrirModalCrear()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;">➕ Nuevo Alumno</button>
      <?php endif; ?>
    </div>
</div>

<?php if(isset($_GET['success_csv'])): ?>
    <div style="width:95%; max-width:1200px; margin:10px auto; padding:15px; background:#e8f5e9; color:#2e7d32; border-radius:6px; border:1px solid #c8e6c9;">
        <h4 style="margin:0 0 5px 0;">¡Carga completada!</h4>
        <ul style="margin:0; padding-left:20px; font-size:14px;">
            <li>Nuevos alumnos: <b><?= intval($_GET['success_csv']) ?></b></li>
            <?php if(!empty($_GET['duplicates'])): ?><li>Emails ya existentes (omitidos): <b><?= intval($_GET['duplicates']) ?></b></li><?php endif; ?>
            <?php if(!empty($_GET['invalid_courses'])): ?><li>Cursos no encontrados (omitidos): <b><?= intval($_GET['invalid_courses']) ?></b></li><?php endif; ?>
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

<!-- Modales -->
<div id="modalCrear" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:500px;"><span class="cerrar" onclick="cerrarModalCrear()">&times;</span><h3>Registrar Alumno</h3><form method="post"><input type="hidden" name="accion" value="crear"><input type="text" name="nombre" placeholder="Nombre" required><input type="text" name="apellido" placeholder="Apellido" required><input type="text" name="dni" placeholder="DNI"><div style="display:flex; gap:10px;"><input type="number" name="libro" placeholder="Libro"><input type="number" name="folio" placeholder="Folio"></div><input type="email" name="email" placeholder="Email" required><input type="password" name="clave" placeholder="Contraseña" required><label>Curso:</label><select name="id_curso" required><?php foreach ($cursos as $c): ?><option value="<?= $c['id_curso']?>" <?= ($filtroCurso == $c['id_curso']) ? 'selected' : '' ?>><?= $c['nombre_curso']?></option><?php endforeach; ?></select><button type="submit">Guardar</button></form></div></div>
<div id="modalEditar" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:500px;"><span class="cerrar" onclick="cerrarModalEditar()">&times;</span><h3>Editar Alumno</h3><form method="post"><input type="hidden" name="accion" value="editar"><input type="hidden" name="id_usuario" id="edit_id_usuario"><input type="text" name="nombre" id="edit_nombre" required><input type="text" name="apellido" id="edit_apellido" required><input type="text" name="dni" id="edit_dni"><div style="display:flex; gap:10px;"><input type="number" name="libro" id="edit_libro" placeholder="Libro"><input type="number" name="folio" id="edit_folio" placeholder="Folio"></div><input type="email" name="email" id="edit_email" required><input type="password" name="clave" placeholder="Cambiar Contraseña (opcional)"><label>Curso:</label><select name="id_curso" id="edit_id_curso" required><?php foreach ($cursos as $c): ?><option value="<?= $c['id_curso']?>"><?= $c['nombre_curso']?></option><?php endforeach; ?></select><button type="submit">Guardar Cambios</button></form></div></div>

<!-- Modal CSV -->
<div id="modalCargarCSV" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:450px;">
        <span class="cerrar" onclick="cerrarModalCargarCSV()">&times;</span>
        <h3>Cargar Alumnos desde CSV</h3>
        <p style="font-size:13px; color:#666;">El archivo puede usar coma (,) o punto y coma (;) como separador.<br>Orden de columnas: <b>nombre, apellido, email, clave, dni, nombre_curso, libro, folio</b></p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="cargar_csv">
            <input type="file" name="csv_file" accept=".csv" required style="margin:15px 0; width:100%;">
            <button type="submit" style="width:100%; padding:10px; background:#28a745; color:white; border:none; border-radius:4px; cursor:pointer;">Subir y Cargar</button>
        </form>
    </div>
</div>

<div id="modalAsignarTutor" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:400px; max-height:85vh; overflow:visible;">
        <span class="cerrar" onclick="cerrarModalAsignarTutor()">&times;</span>
        <h3>Asignar Tutor</h3>
        <div style="position:relative; margin-bottom:15px; text-align:left;">
            <label style="color:#000; font-size:13px; margin-bottom:6px; display:block;">Seleccionar Tutor:</label>
            <!-- Selector visual -->
            <div id="tutorPicker" role="button" tabindex="0" onclick="toggleTutorOptions()" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border:1px solid #ccc; border-radius:8px; background:#fff; color:#000; cursor:pointer;">
                <span id="tutorPickerLabel" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#333;">-- Seleccione un tutor --</span>
                <i class="fa-solid fa-caret-down" style="color:#777;"></i>
            </div>
            <!-- Dropdown oculto -->
            <div id="tutorDropdown" style="display:none; position:absolute; left:0; top:calc(100% + 6px); z-index:3000; width:100%; background:#fff; color:#000; border:1px solid #ccc; border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,0.15);">
                <div style="padding:8px 10px; border-bottom:1px solid #eee;">
                    <input id="tutorSearch" type="text" placeholder="Buscar por nombre..." oninput="filtrarTutores(this.value)" style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; outline:none; font-size:14px;">
                </div>
                <div id="tutorOptions" style="max-height:220px; overflow-y:auto;">
                    <?php foreach ($tutores_db as $t): ?>
                        <div class="tutor-option" data-id="<?= $t['id_tutor'] ?>" data-text="<?= htmlspecialchars(strtoupper($t['nombre_completo'])) ?>" onclick="seleccionarTutor(<?= $t['id_tutor'] ?>, '<?= htmlspecialchars($t['nombre_completo']) ?>')" style="padding:10px 12px; cursor:pointer; font-size:14px; border-bottom:1px solid #f8fafc; transition:background 0.2s;">
                            <?= htmlspecialchars($t['nombre_completo']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Input oculto para el valor real -->
            <input type="hidden" id="selectTutor" value="">
        </div>
        <button onclick="asignarTutor()" style="width:100%; padding:11px; background:#16682b; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:15px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">Asignar</button>
    </div>
</div>

<!-- Modal Ver Info Tutor -->
<div id="modalVerTutor" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:450px;">
        <span class="cerrar" onclick="cerrarModalVerTutor()">&times;</span>
        <h3>Información del Tutor</h3>
        <div id="tutorDetalleContent" style="margin-top:15px; text-align:left; color:#000;">
            <p>Cargando datos...</p>
        </div>
        <button onclick="cerrarModalVerTutor()" style="margin-top:20px; width:100%;">Cerrar</button>
    </div>
</div>

<div id="confirm-dialog"><div class="confirm-dialog-content"><div class="row"><i class="fa-solid fa-exclamation-triangle confirm-dialog-icon"></i><p id="confirm-msg" class="confirm-dialog-message"></p></div><div class="confirm-dialog-buttons"><button onclick="closeConfirm(false)" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button><button onclick="closeConfirm(true)" class="confirm-dialog-button confirm-dialog-confirm">Aceptar</button></div></div></div>

<script>
let confirmResolve = null; let alumnoActual = null;
function abrirModalCrear(){ document.getElementById("modalCrear").style.display="flex"; }
function cerrarModalCrear(){ document.getElementById("modalCrear").style.display="none"; }
function abrirModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="flex"; }
function cerrarModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="none"; }
function abrirModalEditar(a){
    document.getElementById("modalEditar").style.display="flex";
    document.getElementById("edit_id_usuario").value = a.id_usuario;
    document.getElementById("edit_nombre").value = a.nombre;
    document.getElementById("edit_apellido").value = a.apellido;
    document.getElementById("edit_email").value = a.email;
    document.getElementById("edit_dni").value = a.dni || '';
    document.getElementById("edit_libro").value = a.libro || '';
    document.getElementById("edit_folio").value = a.folio || '';
    document.getElementById("edit_id_curso").value = a.id_curso;
}
function cerrarModalEditar(){ document.getElementById("modalEditar").style.display="none"; }
function abrirModalAsignarTutor(id){ 
  alumnoActual = id; 
  document.getElementById("modalAsignarTutor").style.display="flex"; 
  // Reset picker
  document.getElementById("selectTutor").value = "";
  document.getElementById("tutorPickerLabel").textContent = "-- Seleccione un tutor --";
}
function cerrarModalAsignarTutor(){ 
  document.getElementById("modalAsignarTutor").style.display="none"; 
  toggleTutorOptions(false);
}

function toggleTutorOptions(force) {
    const dropdown = document.getElementById("tutorDropdown");
    const show = (force !== undefined) ? force : (dropdown.style.display === "none");
    dropdown.style.display = show ? "block" : "none";
    if (show) {
        const search = document.getElementById("tutorSearch");
        search.value = "";
        filtrarTutores("");
        setTimeout(() => search.focus(), 50);
    }
}

function filtrarTutores(q) {
    q = q.toUpperCase();
    const options = document.querySelectorAll(".tutor-option");
    options.forEach(opt => {
        const text = opt.dataset.text || "";
        opt.style.display = text.includes(q) ? "block" : "none";
    });
}

function seleccionarTutor(id, nombre) {
    document.getElementById("selectTutor").value = id;
    document.getElementById("tutorPickerLabel").textContent = nombre;
    toggleTutorOptions(false);
}

// Cerrar dropdown al hacer clic fuera
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById("tutorDropdown");
    const picker = document.getElementById("tutorPicker");
    if (dropdown && picker && !picker.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = "none";
    }
});

async function asignarTutor(){
    const idT = document.getElementById("selectTutor").value;
    if(!idT || !alumnoActual) return;
    const res = await fetch(`tutores_ajax.php?accion=asignar_alumno&id_tutor=${idT}&id_alumno=${alumnoActual}`);
    if((await res.text()).includes("OK")){ cerrarModalAsignarTutor(); actualizarTabla(); }
}

async function removerTutor(idA, idT, e){
    if(e) { e.preventDefault(); e.stopPropagation(); }
    if(await showConfirm("¿Quitar este tutor del alumno?")){
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

async function verTutor(id, e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const modal = document.getElementById("modalVerTutor");
    const content = document.getElementById("tutorDetalleContent");
    modal.style.display = "flex";
    content.innerHTML = "<p><i class='fa fa-spinner fa-spin'></i> Cargando datos...</p>";
    
    try {
        const res = await fetch(`tutores.php?obtener_tutor=${id}`);
        if (!res.ok) throw new Error("No se pudo cargar la información");
        const t = await res.json();
        
        let html = `
            <div style="display:flex; flex-direction:column; gap:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fa fa-user" style="color:#16682b; width:20px;"></i>
                    <span style="font-weight:700; font-size:16px;">${t.apellido}, ${t.nombre}</span>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fa fa-envelope" style="color:#6c757d; width:20px;"></i>
                    <a href="https://mail.google.com/mail/?view=cm&fs=1&to=${t.email}" target="_blank" style="color:#007bff; text-decoration:none;" title="Enviar mail via Gmail">${t.email}</a>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fa fa-phone" style="color:#6c757d; width:20px;"></i>
                    <span>${t.telefono || 'No registrado'}</span>
                </div>
                <div style="margin-top:10px; border-top:1px solid #eee; padding-top:10px;">
                    <span style="font-size:12px; color:#666; font-weight:700; display:block; margin-bottom:5px;">Alumnos a cargo:</span>
                    <ul style="margin:0; padding-left:20px; font-size:14px; color:#333;">
        `;
        
        if (t.alumnos && t.alumnos.length > 0) {
            t.alumnos.forEach(al => {
                html += `<li>${al.apellido}, ${al.nombre} (<span style="color:#16682b;">${al.nombre_curso}</span>)</li>`;
            });
        } else {
            html += `<li>Sin alumnos asignados</li>`;
        }
        
        html += `</ul></div></div>`;
        content.innerHTML = html;
        
    } catch (err) {
        content.innerHTML = `<p style="color:#dc3545;"><i class="fa fa-exclamation-circle"></i> ${err.message}</p>`;
    }
}
function cerrarModalVerTutor() { document.getElementById("modalVerTutor").style.display = "none"; }

async function actualizarTabla(){
    const p = new URLSearchParams(new FormData(document.getElementById('formFiltros'))); 
    p.append('partial','1');
    const cursoParam = new URLSearchParams(window.location.search).get('curso');
    if (cursoParam) p.append('curso', cursoParam);
    const r = await fetch('alumnos.php?' + p.toString(), { cache:'no-store' });
    document.getElementById('tablaWrapper').innerHTML = await r.text();
}
document.getElementById('formFiltros').querySelector('input').addEventListener('input', () => { clearTimeout(window.t); window.t = setTimeout(actualizarTabla, 300); });
document.addEventListener("DOMContentLoaded", actualizarTabla);
document.addEventListener('click', e => {
    const a = e.target.closest('a[href*="alumnos.php?eliminar="]');
    if(a){ e.preventDefault(); showConfirm("¿Eliminar este alumno?").then(v => { if(v) window.location.href = a.href; }); }
}, true);
</script>
</body>
</html>
