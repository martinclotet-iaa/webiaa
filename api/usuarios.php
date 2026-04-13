<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}
header('Content-Type: text/html; charset=UTF-8');

// Acceso y conexión
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

// Datos base
$perfiles = $conexion->query("SELECT * FROM perfiles ORDER BY id_perfil")->fetch_all(MYSQLI_ASSOC);
$cursos   = $conexion->query("SELECT * FROM cursos ORDER BY nombre_curso")->fetch_all(MYSQLI_ASSOC);
// Materias por curso para asignación a Docentes
$cursos_materias = $conexion->query(
    "SELECT cm.id_curso_materia, c.nombre_curso, m.nombre_materia
     FROM curso_materia cm
     INNER JOIN cursos c ON cm.id_curso = c.id_curso
     INNER JOIN materias m ON cm.id_materia = m.id_materia
     ORDER BY c.nombre_curso, m.nombre_materia"
)->fetch_all(MYSQLI_ASSOC);

// Crear usuario
if (isset($_POST['accion']) && $_POST['accion'] === 'crear') {
    $nombre   = $conexion->real_escape_string(trim($_POST['nombre'] ?? ''));
    $apellido = $conexion->real_escape_string(trim($_POST['apellido'] ?? ''));
    $email    = $conexion->real_escape_string(trim($_POST['email'] ?? ''));
    $claveRaw = trim($_POST['clave'] ?? '');

    if ($nombre === '' || $apellido === '' || $email === '' || $claveRaw === '') {
        echo "<script>alert('Complete todos los campos obligatorios');location.href='usuarios.php';</script>"; exit;
    }

    $dup = $conexion->query("SELECT 1 FROM usuarios WHERE email='$email' LIMIT 1");
    if ($dup && $dup->num_rows > 0) {
        $msg = 'Error: El email ya está registrado. Utilice otro email.';
        echo "<script>alert(".json_encode($msg).");location.href='usuarios.php';</script>"; exit;
    }

    // Nota: si tu sistema espera claves ya con hash, descomentar:
    // $clave = password_hash($claveRaw, PASSWORD_DEFAULT);
    $clave = $conexion->real_escape_string($claveRaw);

    $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nombre','$apellido','$email','$clave')");
    $id_usuario = (int)$conexion->insert_id;

    // Perfiles
    $selPerfiles = isset($_POST['perfiles']) && is_array($_POST['perfiles']) ? array_map('intval', $_POST['perfiles']) : [];
    foreach ($selPerfiles as $pid) {
        $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_usuario, $pid)");
        if ($pid === 1) { // Alumno
            $dni   = isset($_POST['dni']) && $_POST['dni']!=='' ? (int)$_POST['dni'] : null;
            $curso = isset($_POST['id_curso']) && $_POST['id_curso']!=='' ? (int)$_POST['id_curso'] : null;
            $libro = isset($_POST['libro']) && $_POST['libro']!=='' ? (int)$_POST['libro'] : null;
            $folio = isset($_POST['folio']) && $_POST['folio']!=='' ? (int)$_POST['folio'] : null;
            if (!$curso) { echo "<script>alert('Debe seleccionar un curso válido para Alumno');location.href='usuarios.php';</script>"; exit; }
            $dniVal = $dni!==null ? $dni : 'NULL';
            $libroVal = $libro!==null ? $libro : 'NULL';
            $folioVal = $folio!==null ? $folio : 'NULL';
            $conexion->query("INSERT INTO alumnos (id_usuario,dni,id_curso,libro,folio) VALUES ($id_usuario,$dniVal,$curso,$libroVal,$folioVal)");
        } elseif ($pid === 2) { // Tutor
            $tel = $conexion->real_escape_string($_POST['telefono'] ?? '');
            $conexion->query("INSERT INTO tutores (id_usuario,telefono) VALUES ($id_usuario,'$tel')");
        } elseif ($pid === 3) { // Docente
            $tel = $conexion->real_escape_string($_POST['telefono_docente'] ?? '');
            $conexion->query("INSERT INTO docentes (id_usuario, telefono) VALUES ($id_usuario,'$tel')");
            $id_docente = (int)$conexion->insert_id;
            if (!empty($_POST['docente_materias']) && is_array($_POST['docente_materias'])) {
                foreach ($_POST['docente_materias'] as $id_cm) {
                    $id_cm = (int)$id_cm;
                    if ($id_cm > 0) {
                        $conexion->query("INSERT INTO docente_materia (id_docente, id_curso_materia) VALUES ($id_docente, $id_cm)");
                    }
                }
            }
        } elseif ($pid === 4) { // Admin
            $conexion->query("INSERT INTO administradores (id_usuario) VALUES ($id_usuario)");
        } elseif ($pid === 5) { // Preceptor → asignación opcional de cursos
            $hasJoinTbl = $conexion->query("SHOW TABLES LIKE 'curso_preceptor'");
            $hasPreCol  = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'");
            if (!empty($_POST['preceptor_cursos'])) {
                $ids = array_map('intval', (array)$_POST['preceptor_cursos']);
                if (count($ids)) {
                    if ($hasJoinTbl && $hasJoinTbl->num_rows>0) {
                        // Insertar relaciones múltiples evitando duplicados
                        $stmt = $conexion->prepare("INSERT IGNORE INTO curso_preceptor (id_curso, id_preceptor) VALUES (?, ?)");
                        foreach ($ids as $idc) { $stmt->bind_param('ii', $idc, $id_usuario); $stmt->execute(); }
                    } elseif ($hasPreCol && $hasPreCol->num_rows>0) {
                        // Fallback legacy: asignar uno a uno (último gana)
                        $in = implode(',', $ids);
                        $conexion->query("UPDATE cursos SET id_preceptor=$id_usuario WHERE id_curso IN ($in)");
                    }
                }
            }
            // Guardar teléfono del preceptor (idempotente)
            $tel = $conexion->real_escape_string($_POST['telefono_preceptor'] ?? '');
            $conexion->query("INSERT INTO preceptores (id_usuario, telefono) VALUES ($id_usuario, '$tel') ON DUPLICATE KEY UPDATE telefono='$tel'");
        }
    }

    $redirect = !empty($_POST['redirect']) ? $_POST['redirect'] : 'usuarios.php';
    header('Location: ' . $redirect); exit;
}

// Editar usuario
if (isset($_POST['accion']) && $_POST['accion'] === 'editar') {
    $id_usuario = (int)$_POST['id_usuario'];
    $nombre   = $conexion->real_escape_string(trim($_POST['nombre'] ?? ''));
    $apellido = $conexion->real_escape_string(trim($_POST['apellido'] ?? ''));
    $email    = $conexion->real_escape_string(trim($_POST['email'] ?? ''));
    $claveRaw = trim($_POST['clave'] ?? '');

    if ($nombre === '' || $apellido === '' || $email === '') {
        echo "<script>alert('Complete todos los campos obligatorios');location.href='usuarios.php';</script>"; exit;
    }

    // Verificar email duplicado (excepto el mismo usuario)
    $dup = $conexion->query("SELECT 1 FROM usuarios WHERE email='$email' AND id_usuario!=$id_usuario LIMIT 1");
    if ($dup && $dup->num_rows > 0) {
        $msg = 'Error: El email ya está registrado por otro usuario.';
        echo "<script>alert(".json_encode($msg).");location.href='usuarios.php';</script>"; exit;
    }

    // Actualizar datos básicos
    $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email' WHERE id_usuario=$id_usuario");
    
    // Si se proporciona nueva contraseña, actualizarla
    if ($claveRaw !== '') {
        $clave = $conexion->real_escape_string($claveRaw);
        $conexion->query("UPDATE usuarios SET clave='$clave' WHERE id_usuario=$id_usuario");
    }

    // Eliminar perfiles anteriores y datos relacionados
    $conexion->query("DELETE FROM alumno_tutor WHERE id_alumno IN (SELECT id_alumno FROM alumnos WHERE id_usuario=$id_usuario)");
    $conexion->query("DELETE FROM alumno_tutor WHERE id_tutor IN (SELECT id_tutor FROM tutores WHERE id_usuario=$id_usuario)");
    $conexion->query("DELETE FROM docente_materia WHERE id_docente IN (SELECT id_docente FROM docentes WHERE id_usuario=$id_usuario)");
    $conexion->query("DELETE FROM alumnos WHERE id_usuario=$id_usuario");
    $conexion->query("DELETE FROM tutores WHERE id_usuario=$id_usuario");
    $conexion->query("DELETE FROM docentes WHERE id_usuario=$id_usuario");
    $conexion->query("DELETE FROM administradores WHERE id_usuario=$id_usuario");
    $conexion->query("DELETE FROM preceptores WHERE id_usuario=$id_usuario");
    $conexion->query("DELETE FROM curso_preceptor WHERE id_preceptor=$id_usuario");
    $conexion->query("UPDATE cursos SET id_preceptor=NULL WHERE id_preceptor=$id_usuario");
    $conexion->query("DELETE FROM usuario_perfil WHERE id_usuario=$id_usuario");

    // Insertar nuevos perfiles
    $selPerfiles = isset($_POST['perfiles']) && is_array($_POST['perfiles']) ? array_map('intval', $_POST['perfiles']) : [];
    foreach ($selPerfiles as $pid) {
        $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_usuario, $pid)");
        if ($pid === 1) { // Alumno
            $dni   = isset($_POST['dni']) && $_POST['dni']!=='' ? (int)$_POST['dni'] : null;
            $curso = isset($_POST['id_curso']) && $_POST['id_curso']!=='' ? (int)$_POST['id_curso'] : null;
            $libro = isset($_POST['libro']) && $_POST['libro']!=='' ? (int)$_POST['libro'] : null;
            $folio = isset($_POST['folio']) && $_POST['folio']!=='' ? (int)$_POST['folio'] : null;
            if (!$curso) { echo "<script>alert('Debe seleccionar un curso válido para Alumno');location.href='usuarios.php';</script>"; exit; }
            $dniVal = $dni!==null ? $dni : 'NULL';
            $libroVal = $libro!==null ? $libro : 'NULL';
            $folioVal = $folio!==null ? $folio : 'NULL';
            $conexion->query("INSERT INTO alumnos (id_usuario,dni,id_curso,libro,folio) VALUES ($id_usuario,$dniVal,$curso,$libroVal,$folioVal)");
        } elseif ($pid === 2) { // Tutor
            $tel = $conexion->real_escape_string($_POST['telefono'] ?? '');
            $conexion->query("INSERT INTO tutores (id_usuario,telefono) VALUES ($id_usuario,'$tel')");
        } elseif ($pid === 3) { // Docente
            $tel = $conexion->real_escape_string($_POST['telefono_docente'] ?? '');
            $conexion->query("INSERT INTO docentes (id_usuario, telefono) VALUES ($id_usuario,'$tel')");
        } elseif ($pid === 4) { // Admin
            $conexion->query("INSERT INTO administradores (id_usuario) VALUES ($id_usuario)");
        } elseif ($pid === 5) { // Preceptor
            $hasJoinTbl = $conexion->query("SHOW TABLES LIKE 'curso_preceptor'");
            $hasPreCol  = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'");
            if (!empty($_POST['preceptor_cursos'])) {
                $ids = array_map('intval', (array)$_POST['preceptor_cursos']);
                if (count($ids)) {
                    if ($hasJoinTbl && $hasJoinTbl->num_rows>0) {
                        $stmt = $conexion->prepare("INSERT IGNORE INTO curso_preceptor (id_curso, id_preceptor) VALUES (?, ?)");
                        foreach ($ids as $idc) { $stmt->bind_param('ii', $idc, $id_usuario); $stmt->execute(); }
                    } elseif ($hasPreCol && $hasPreCol->num_rows>0) {
                        $in = implode(',', $ids);
                        $conexion->query("UPDATE cursos SET id_preceptor=$id_usuario WHERE id_curso IN ($in)");
                    }
                }
            }
            // Actualizar teléfono del preceptor
            $tel = $conexion->real_escape_string($_POST['telefono_preceptor'] ?? '');
            $conexion->query("INSERT INTO preceptores (id_usuario, telefono) VALUES ($id_usuario, '$tel') ON DUPLICATE KEY UPDATE telefono = '$tel'");
        }
    }

    $redirect = !empty($_POST['redirect']) ? $_POST['redirect'] : 'usuarios.php';
    header('Location: ' . $redirect); exit;
}

// Función auxiliar para eliminar TODO lo relacionado a un usuario
function eliminarUsuarioCompleto($id, $conexion) {
    if ($id <= 0) return false;
    
    // 1. Relaciones de Alumno
    $conexion->query("DELETE FROM asistencias WHERE id_alumno IN (SELECT id_alumno FROM alumnos WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM asistencias_curso WHERE id_alumno IN (SELECT id_alumno FROM alumnos WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM notas WHERE id_alumno IN (SELECT id_alumno FROM alumnos WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM notasparciales WHERE id_alumno IN (SELECT id_alumno FROM alumnos WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM alumno_tutor WHERE id_alumno IN (SELECT id_alumno FROM alumnos WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM alumnos WHERE id_usuario=$id");

    // 2. Relaciones de Tutor
    $conexion->query("DELETE FROM alumno_tutor WHERE id_tutor IN (SELECT id_tutor FROM tutores WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM tutores WHERE id_usuario=$id");

    // 3. Relaciones de Docente
    $conexion->query("DELETE FROM docente_materia WHERE id_docente IN (SELECT id_docente FROM docentes WHERE id_usuario=$id)");
    $conexion->query("DELETE FROM docentes WHERE id_usuario=$id");

    // 4. Otros perfiles
    $conexion->query("DELETE FROM administradores WHERE id_usuario=$id");
    $conexion->query("DELETE FROM preceptores WHERE id_usuario=$id");
    $conexion->query("DELETE FROM curso_preceptor WHERE id_preceptor=$id");
    $conexion->query("UPDATE cursos SET id_preceptor=NULL WHERE id_preceptor=$id");

    // 5. Base
    $conexion->query("DELETE FROM usuario_perfil WHERE id_usuario=$id");
    return $conexion->query("DELETE FROM usuarios WHERE id_usuario=$id");
}

// Eliminar individual
if (isset($_GET['eliminar'])) {
    $id = (int)$_GET['eliminar'];
    eliminarUsuarioCompleto($id, $conexion);
    $redirect = !empty($_REQUEST['redirect']) ? $_REQUEST['redirect'] : 'usuarios.php';
    header('Location: ' . $redirect); exit;
}

// Eliminar múltiple (AJAX/POST)
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar_multiple') {
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $count = 0;
    foreach ($ids as $id) {
        if (eliminarUsuarioCompleto($id, $conexion)) $count++;
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

// Filtros
$filtroPerfil = isset($_GET['perfil']) ? (int)$_GET['perfil'] : 0;
$busqueda     = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : '';

// Listado
$sql = "
    SELECT u.id_usuario,u.nombre,u.apellido,u.email,
           GROUP_CONCAT(p.nombre_perfil SEPARATOR ', ') as perfiles,
           GROUP_CONCAT(up.id_perfil SEPARATOR ',') AS id_perfiles
    FROM usuarios u
    LEFT JOIN usuario_perfil up ON u.id_usuario=up.id_usuario
    LEFT JOIN perfiles p ON up.id_perfil=p.id_perfil
    WHERE 1=1
";
if ($filtroPerfil>0) { $sql .= " AND u.id_usuario IN (SELECT id_usuario FROM usuario_perfil WHERE id_perfil=$filtroPerfil)"; }
if ($busqueda!=='') { $sql .= " AND (u.nombre LIKE '%$busqueda%' OR u.apellido LIKE '%$busqueda%' OR u.email LIKE '%$busqueda%')"; }
$sql .= " GROUP BY u.id_usuario ORDER BY u.apellido, u.nombre";
$usuarios = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

// Respuesta parcial
if (isset($_GET['partial']) && $_GET['partial']=='1') {
    ob_start();
    // Colores de badge por perfil
    $badgeColors = [1=>'#007bff',2=>'#fd7e14',3=>'#6f42c1',4=>'#dc3545',5=>'#20c997'];
    ?>
    <div class="usuarios-lista">
      <?php if (empty($usuarios)): ?>
        <p style="color:#888; text-align:center; padding:30px;">No se encontraron usuarios.</p>
      <?php else: ?>
      <?php foreach ($usuarios as $u):
        $perfilesArr = array_filter(explode(', ', $u['perfiles'] ?? ''));
        $idsArr  = array_values(array_filter(explode(',', $u['id_perfiles'] ?? '')));
      ?>
        <div class="usuario-row">
          <div class="usuario-row-check">
            <input type="checkbox" class="check-select" value="<?= $u['id_usuario'] ?>" title="Seleccionar" onclick="event.stopPropagation(); actualizarBulkBar();">
          </div>
          <div class="usuario-row-info">
            <div class="usuario-nombre"><?= htmlspecialchars($u['apellido'].', '.$u['nombre']) ?></div>
            <div class="usuario-email">
              <?php foreach ($perfilesArr as $i => $pnombre):
                $pid = isset($idsArr[$i]) ? (int)$idsArr[$i] : 0;
                $bg = $badgeColors[$pid] ?? '#6c757d';
              ?>
                <span class="perfil-badge" style="background:<?= $bg ?>;"><?= htmlspecialchars(ucfirst($pnombre)) ?></span>
              <?php endforeach; ?>
              <i class="fa fa-envelope" style="margin-left:4px;"></i> <?= htmlspecialchars($u['email']) ?>
            </div>
          </div>
          <div class="usuario-row-actions">
            <a href="javascript:void(0)" onclick="abrirModalEditar(<?= $u['id_usuario'] ?>)" class="btn-row btn-edit" title="Editar">
              <i class="fa-solid fa-pen-to-square"></i> <span class="btn-label">Editar</span>
            </a>
            <a href="usuarios.php?eliminar=<?= $u['id_usuario'] ?>" class="btn-row btn-delete delete-link" title="Eliminar">
              <i class="fa-solid fa-trash"></i> <span class="btn-label">Eliminar</span>
            </a>
          </div>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
    echo ob_get_clean();
    exit;
}

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Usuarios</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="estilos.css?v=<?php echo time(); ?>">
<style>
  /* Confirm dialog */
  #confirm-dialog { display:none; position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; }
  #confirm-dialog .confirm-dialog-content { background:#fff; color:#000; border-radius:8px; width:90%; max-width:420px; padding:20px; box-shadow:0 6px 20px rgba(0,0,0,0.5); }
  .confirm-dialog-content .row { display:flex; gap:12px; align-items:flex-start; }
  .confirm-dialog-icon { color:#ffc107; font-size:22px; margin-top:2px; }
  .confirm-dialog-message { margin:0; color:#000; font-size:16px; line-height:1.4; }
  .confirm-dialog-buttons { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
  .confirm-dialog-button { padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-weight:600; }
  .confirm-dialog-cancel { background:#6c757d; color:#fff; }
  .confirm-dialog-confirm { background:#dc3545; color:#fff; }
  /* Modales */
  .modal-box h3, .modal-box h4 { color:#000; }
  .modal-box label, .modal-box .checkbox-item { color:#000; }
  /* Lista de usuarios */
  .usuarios-lista {
    width: 90%;
    margin: 16px auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .usuario-row {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.09);
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    transition: box-shadow 0.15s;
    flex-wrap: wrap;
  }
  .usuario-row:hover { box-shadow: 0 3px 10px rgba(0,0,0,0.13); }
  .usuario-row-check { flex-shrink: 0; }
  .check-select { width: 18px; height: 18px; cursor: pointer; accent-color: #dc3545; }
  .usuario-row-info {
    flex: 1;
    min-width: 180px;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .usuario-nombre { font-weight: 600; font-size: 15px; color: #222; }
  .usuario-email { font-size: 13px; color: #666; display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
  .usuario-email i { color: #6c757d; }
  .perfil-badge {
    color: #fff;
    padding: 2px 9px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
  }
  .usuario-row-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    margin-left: auto;
  }
  .btn-row {
    padding: 6px 14px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
    font-weight: 500;
    color: white !important;
    transition: opacity 0.2s;
  }
  .btn-row:hover { opacity: 0.85; }
  .btn-edit { background-color: #2196F3; }
  .btn-delete { background-color: #dc3545; }
  /* En celulares pequeños: acciones van abajo del nombre */
  @media (max-width: 480px) {
    .usuario-row { gap: 8px; }
    .usuario-row-actions { margin-left: 0; width: 100%; justify-content: flex-end; }
    .btn-label { display: none; }
  }
  /* Barra de selección masiva */
  #bulkBar {
    display: none;
    width: 90%;
    margin: 0 auto;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 10px 16px;
    align-items: center;
    gap: 12px;
    animation: slideDown 0.2s ease;
  }
  #bulkBar.visible { display: flex; }
  @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
</style>
</head>
<body>
<?php include 'navbar.php'; ?>

<h1>Usuarios</h1>

<div style="width:90%; margin:20px auto; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
  <form id="formFiltros" method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <select name="perfil" id="filtroPerfil" style="padding:8px; border-radius:6px; border:none;">
      <option value="0">-- Todos los perfiles --</option>
      <?php foreach ($perfiles as $p): ?>
        <option value="<?= $p['id_perfil'] ?>" <?= ($filtroPerfil==$p['id_perfil'] ? 'selected' : '') ?>><?= ucfirst($p['nombre_perfil']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar nombre, apellido o email..." style="padding:8px; border-radius:6px; border:none; width:220px;">
  </form>
  <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
    <a href="menu.php" style="text-decoration:none;">
      <button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Volver al Menú</button>
    </a>
    <button onclick="abrirModalCrear()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;"><i class="fa-solid fa-plus"></i> Agregar Usuario</button>
  </div>
</div>

<!-- Barra de selección masiva -->
<div id="bulkBar">
  <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:#333; white-space:nowrap;">
    <input type="checkbox" id="checkAll" style="width:18px; height:18px; accent-color:#dc3545;"> Seleccionar todos
  </label>
  <span id="bulkCount" style="color:#555; font-size:14px;"></span>
  <button onclick="borrarSeleccionados()" style="margin-left:auto; padding:8px 18px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; display:flex; align-items:center; gap:6px;">
    <i class="fa-solid fa-trash"></i> Eliminar seleccionados
  </button>
</div>

<div id="tablaWrapper"></div>

<!-- Modal Crear mínimo -->
<div id="modalCrear" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:600px;">
    <span class="cerrar" onclick="cerrarModalCrear()">&times;</span>
    <h3>Registrar Nuevo Usuario</h3>
    <form id="formCrear" method="post">
      <input type="hidden" name="accion" value="crear">
      <input type="text" name="nombre" placeholder="Nombre" required>
      <input type="text" name="apellido" placeholder="Apellido" required>
      <input type="email" name="email" placeholder="Email" required>
      <input type="password" name="clave" placeholder="Contraseña" required>

      <div class="perfiles-box">
        <h4>Perfiles:</h4>
        <div class="checkbox-grid" style="display:flex; gap:6px; flex-wrap:wrap;">
          <?php foreach ($perfiles as $p): ?>
            <label class="checkbox-item"><input type="checkbox" name="perfiles[]" value="<?= $p['id_perfil'] ?>" onchange="mostrarExtra(this)"> <?= ucfirst($p['nombre_perfil']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="extraAlumno" style="display:none;" class="extra-box">
        <h4>Datos Alumno</h4>
        <label>DNI: <input type="text" name="dni" placeholder="Ingrese DNI"></label>
        <label>Curso:
          <select name="id_curso">
            <option value="">-- Seleccione un curso --</option>
            <?php foreach ($cursos as $c): ?>
              <option value="<?= $c['id_curso'] ?>"><?= $c['nombre_curso'] ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Libro: <input type="number" name="libro" min="0" placeholder="Libro (opcional)"></label>
        <label>Folio: <input type="number" name="folio" min="0" placeholder="Folio (opcional)"></label>
      </div>

      <div id="extraTutor" style="display:none;" class="extra-box">
        <h4>Datos Tutor</h4>
        <label>Teléfono: <input type="text" name="telefono" placeholder="Ingrese Teléfono"></label>
      </div>

      <div id="extraDocente" style="display:none;" class="extra-box">
        <h4>Datos Docente</h4>
        <label>Teléfono: <input type="text" name="telefono_docente" placeholder="Ingrese Teléfono"></label>
        <div class="form-group" style="margin-top:8px;">
          <label>Materias que dicta (por curso):</label>
          <select name="docente_materias[]" multiple size="8" style="min-width:260px;">
            <?php foreach ($cursos_materias as $cm): ?>
              <option value="<?= $cm['id_curso_materia'] ?>"><?=
                htmlspecialchars($cm['nombre_curso'].' - '.$cm['nombre_materia'])
              ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:#000; display:block; margin-top:4px;">Use Ctrl/Cmd para seleccionar múltiples</small>
        </div>
      </div>

      <div id="extraPreceptor" style="display:none;" class="extra-box">
        <h4>Datos Preceptor</h4>
        <label>Teléfono: <input type="text" name="telefono_preceptor" placeholder="Ingrese Teléfono"></label>
        <div style="margin-top:8px;">
          <label>Asignar Cursos (opcional):</label>
          <select name="preceptor_cursos[]" multiple size="6" style="min-width:240px;">
            <?php foreach ($cursos as $c): ?>
              <option value="<?= $c['id_curso'] ?>"><?= $c['nombre_curso'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-top: 10px;">
        <button type="submit">Guardar Usuario</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar -->
<div id="modalEditar" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:600px;">
    <span class="cerrar" onclick="cerrarModalEditar()">&times;</span>
    <h3>Editar Usuario</h3>
    <form id="formEditar" method="post">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id_usuario" id="edit_id_usuario">
      <input type="text" name="nombre" id="edit_nombre" placeholder="Nombre" required>
      <input type="text" name="apellido" id="edit_apellido" placeholder="Apellido" required>
      <input type="email" name="email" id="edit_email" placeholder="Email" required>
      <input type="password" name="clave" id="edit_clave" placeholder="Nueva Contraseña (dejar vacío para no cambiar)">

      <div class="perfiles-box">
        <h4>Perfiles:</h4>
        <div class="checkbox-grid" style="display:flex; gap:6px; flex-wrap:wrap;">
          <?php foreach ($perfiles as $p): ?>
            <label class="checkbox-item"><input type="checkbox" name="perfiles[]" value="<?= $p['id_perfil'] ?>" onchange="mostrarExtraEditar(this)"> <?= ucfirst($p['nombre_perfil']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="extraAlumnoEditar" style="display:none;" class="extra-box">
        <h4>Datos Alumno</h4>
        <label>DNI: <input type="text" name="dni" id="edit_dni" placeholder="Ingrese DNI"></label>
        <label>Curso:
          <select name="id_curso" id="edit_id_curso">
            <option value="">-- Seleccione un curso --</option>
            <?php foreach ($cursos as $c): ?>
              <option value="<?= $c['id_curso'] ?>"><?= $c['nombre_curso'] ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Libro: <input type="number" name="libro" id="edit_libro" min="0" placeholder="Libro (opcional)"></label>
        <label>Folio: <input type="number" name="folio" id="edit_folio" min="0" placeholder="Folio (opcional)"></label>
      </div>

      <div id="extraTutorEditar" style="display:none;" class="extra-box">
        <h4>Datos Tutor</h4>
        <label>Teléfono: <input type="text" name="telefono" id="edit_telefono" placeholder="Ingrese Teléfono"></label>
      </div>

      <div id="extraDocenteEditar" style="display:none;" class="extra-box">
        <h4>Datos Docente</h4>
        <label>Teléfono: <input type="text" name="telefono_docente" id="edit_telefono_docente" placeholder="Ingrese Teléfono"></label>
        <div class="form-group" style="margin-top:8px;">
          <label>Materias que dicta (por curso):</label>
          <select name="docente_materias[]" id="edit_docente_materias" multiple size="8" style="min-width:260px;">
            <?php foreach ($cursos_materias as $cm): ?>
              <option value="<?= $cm['id_curso_materia'] ?>"><?=
                htmlspecialchars($cm['nombre_curso'].' - '.$cm['nombre_materia'])
              ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:#000; display:block; margin-top:4px;">Use Ctrl/Cmd para seleccionar múltiples</small>
        </div>
      </div>

      <div id="extraPreceptorEditar" style="display:none;" class="extra-box">
        <h4>Datos Preceptor</h4>
        <label>Teléfono: <input type="text" name="telefono_preceptor" id="edit_telefono_preceptor" placeholder="Ingrese Teléfono"></label>
        <div style="margin-top:8px;">
          <label>Asignar Cursos (opcional):</label>
          <select name="preceptor_cursos[]" id="edit_preceptor_cursos" multiple size="6" style="min-width:240px;">
            <?php foreach ($cursos as $c): ?>
              <option value="<?= $c['id_curso'] ?>"><?= $c['nombre_curso'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-top: 10px;">
        <button type="submit">Actualizar Usuario</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Confirmación -->
<div id="confirm-dialog">
  <div class="confirm-dialog-content">
    <div class="row">
      <i class="fa-solid fa-exclamation-triangle confirm-dialog-icon"></i>
      <p class="confirm-dialog-message" id="confirm-dialog-message">¿Está seguro de eliminar este usuario?</p>
    </div>
    <div class="confirm-dialog-buttons">
      <button id="confirm-dialog-cancel" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button>
      <button id="confirm-dialog-confirm" class="confirm-dialog-button confirm-dialog-confirm">Aceptar</button>
    </div>
  </div>
</div>

<script>
function mostrarExtra(chk){
  if (chk.value==1) document.getElementById('extraAlumno').style.display = chk.checked ? 'block' : 'none';
  if (chk.value==2) document.getElementById('extraTutor').style.display  = chk.checked ? 'block' : 'none';
  if (chk.value==3) document.getElementById('extraDocente').style.display = chk.checked ? 'block' : 'none';
  if (chk.value==5) document.getElementById('extraPreceptor').style.display = chk.checked ? 'block' : 'none';
}
function mostrarExtraEditar(chk){
  if (chk.value==1) document.getElementById('extraAlumnoEditar').style.display = chk.checked ? 'block' : 'none';
  if (chk.value==2) document.getElementById('extraTutorEditar').style.display  = chk.checked ? 'block' : 'none';
  if (chk.value==3) document.getElementById('extraDocenteEditar').style.display = chk.checked ? 'block' : 'none';
  if (chk.value==5) document.getElementById('extraPreceptorEditar').style.display = chk.checked ? 'block' : 'none';
}
function abrirModalCrear(){ document.getElementById('modalCrear').style.display='flex'; }
function cerrarModalCrear(){ document.getElementById('modalCrear').style.display='none'; }
function cerrarModalEditar(){ document.getElementById('modalEditar').style.display='none'; }

async function abrirModalEditar(id){
  // Cargar datos del usuario
  const res = await fetch('get_usuario.php?id='+id);
  const data = await res.json();
  
  // Llenar campos básicos
  document.getElementById('edit_id_usuario').value = data.id_usuario;
  document.getElementById('edit_nombre').value = data.nombre;
  document.getElementById('edit_apellido').value = data.apellido;
  document.getElementById('edit_email').value = data.email;
  document.getElementById('edit_clave').value = '';
  
  // Ocultar todas las secciones extra por defecto en modo edición
  ['extraAlumnoEditar','extraTutorEditar','extraDocenteEditar','extraPreceptorEditar'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  
  // Resetear checkboxes
  document.querySelectorAll('#formEditar input[name="perfiles[]"]').forEach(cb => cb.checked = false);
  
  // Marcar perfiles actuales
  if(data.perfiles){
    data.perfiles.forEach(pid => {
      const cb = document.querySelector('#formEditar input[name="perfiles[]"][value="'+pid+'"]');
      if(cb){
        cb.checked = true;
        mostrarExtraEditar(cb);
      }
    });
  }
  
  // Llenar datos específicos de perfil
  if(data.alumno){
    document.getElementById('edit_dni').value = data.alumno.dni || '';
    document.getElementById('edit_id_curso').value = data.alumno.id_curso || '';
    document.getElementById('edit_libro').value = data.alumno.libro || '';
    document.getElementById('edit_folio').value = data.alumno.folio || '';
  }
  if(data.tutor){
    document.getElementById('edit_telefono').value = data.tutor.telefono || '';
  }
  if(data.docente){
    document.getElementById('edit_telefono_docente').value = data.docente.telefono || '';
  }
  if(Array.isArray(data.docente_materias)){
    const sel = document.getElementById('edit_docente_materias');
    if (sel){
      Array.from(sel.options).forEach(opt => {
        opt.selected = data.docente_materias.includes(parseInt(opt.value));
      });
    }
  }
  if(data.preceptor_cursos){
    const sel = document.getElementById('edit_preceptor_cursos');
    Array.from(sel.options).forEach(opt => {
      opt.selected = data.preceptor_cursos.includes(parseInt(opt.value));
    });
  }
  if(data.preceptor){
    document.getElementById('edit_telefono_preceptor').value = data.preceptor.telefono || '';
  }
  
  document.getElementById('modalEditar').style.display='flex';
}

const form = document.getElementById('formFiltros');
const wrap = document.getElementById('tablaWrapper');
const inpQ = form.querySelector('input[name="q"]');
const selP = form.querySelector('select[name="perfil"]');
const bulkBar  = document.getElementById('bulkBar');
const checkAll = document.getElementById('checkAll');
const bulkCount= document.getElementById('bulkCount');

function debounce(fn, delay=300){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),delay); }; }

async function actualizarTabla(){
  const params = new URLSearchParams(new FormData(form));
  params.append('partial','1');
  const res = await fetch('usuarios.php?'+params.toString(), { cache:'no-store' });
  const html = await res.text();
  wrap.innerHTML = html;
  // Reiniciar selección al recargar
  if (checkAll) checkAll.checked = false;
  actualizarBulkBar();
}
selP.addEventListener('change', actualizarTabla);
inpQ.addEventListener('input', debounce(actualizarTabla, 350));
document.addEventListener('DOMContentLoaded', actualizarTabla);

// Seleccionar todos
if (checkAll) {
  checkAll.addEventListener('change', function(){
    document.querySelectorAll('.check-select').forEach(cb => cb.checked = this.checked);
    actualizarBulkBar();
  });
}

function actualizarBulkBar(){
  const total = document.querySelectorAll('.check-select:checked').length;
  if (total > 0) {
    bulkBar.classList.add('visible');
    bulkCount.textContent = total + (total === 1 ? ' usuario seleccionado' : ' usuarios seleccionados');
  } else {
    bulkBar.classList.remove('visible');
    bulkCount.textContent = '';
  }
  // Sincronizar checkAll
  const todosCbx = document.querySelectorAll('.check-select');
  checkAll.checked = todosCbx.length > 0 && total === todosCbx.length;
  checkAll.indeterminate = total > 0 && total < todosCbx.length;
}

// Confirmación y Acción de Borrado
let pendingAction = null; // 'single' o 'multiple'
let pendingHref   = null;
let pendingIds    = [];

const dlg       = document.getElementById('confirm-dialog');
const btnOk     = document.getElementById('confirm-dialog-confirm');
const btnCancel = document.getElementById('confirm-dialog-cancel');
const msgElem   = document.getElementById('confirm-dialog-message');

// Capturar clicks en enlaces de eliminar individual
document.addEventListener('click', function(e){
  const a = e.target.closest('a.delete-link');
  if (!a) return;
  e.preventDefault();
  
  pendingAction = 'single';
  pendingHref   = a.getAttribute('href');
  pendingIds    = [];
  
  msgElem.textContent = '¿Está seguro de eliminar este usuario?';
  dlg.style.display = 'flex';
});

// Acción de botón borrar seleccionados
function borrarSeleccionados(){
  const ids = Array.from(document.querySelectorAll('.check-select:checked')).map(cb => cb.value);
  if (!ids.length) return;
  
  pendingAction = 'multiple';
  pendingHref   = null;
  pendingIds    = ids;
  
  msgElem.textContent = '¿Está seguro de eliminar ' + ids.length + (ids.length === 1 ? ' usuario' : ' usuarios') + ' seleccionados? Esta acción no se puede deshacer.';
  dlg.style.display = 'flex';
}

// Botón Aceptar del Modal
btnOk.addEventListener('click', async () => {
    const action = pendingAction;
    const href   = pendingHref;
    const ids    = pendingIds;
    
    // Resetear estado del modal
    dlg.style.display = 'none';
    pendingAction = null;
    pendingHref   = null;
    pendingIds    = [];
    
    if (action === 'single' && href) {
        location.href = href;
    } 
    else if (action === 'multiple' && ids.length > 0) {
        const fd = new FormData();
        fd.append('accion', 'eliminar_multiple');
        ids.forEach(id => fd.append('ids[]', id));
        
        try {
            const res = await fetch('usuarios.php', { method:'POST', body: fd });
            const data = await res.json();
            // console.log('Borrados:', data.deleted);
        } catch(e) { 
            console.error('Error en eliminación múltiple:', e); 
        }
        await actualizarTabla();
    }
});

// Botón Cancelar y click fuera
btnCancel.addEventListener('click', () => { 
    dlg.style.display = 'none'; 
    pendingAction = null; 
});
document.addEventListener('click', (e) => { 
    if (e.target === dlg) { 
        dlg.style.display = 'none'; 
        pendingAction = null; 
    } 
});
</script>
</body>
</html>
