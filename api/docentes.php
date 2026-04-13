<?php  
session_start();
if (!isset($_SESSION['id_usuario'])) {
    if (isset($_GET['partial'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sesión expirada']);
        exit;
    }
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/acceso.php';
require_once "conexion.php";

$cursos = $conexion->query("SELECT * FROM cursos ORDER BY nombre_curso")->fetch_all(MYSQLI_ASSOC);
$materias = $conexion->query("SELECT * FROM materias ORDER BY nombre_materia")->fetch_all(MYSQLI_ASSOC);

// Acciones POST (Crear, Editar, CSV) se mantienen igual...
if (isset($_POST['accion']) && $_POST['accion'] == "cargar_csv") {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");
    if ($handle) {
        $header = fgetcsv($handle, 1000, ","); // Skip header
        $conexion->begin_transaction(); 
        $success = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) == 1 && strpos($data[0], ';') !== false) {
                $data = str_getcsv($data[0], ';');
            }
            if (empty($data[0])) continue;
            
            $nombre = $conexion->real_escape_string($data[0]); 
            $apellido = $conexion->real_escape_string($data[1]); 
            $email = $conexion->real_escape_string($data[2]); 
            $clave = $conexion->real_escape_string($data[3]); // PLAIN TEXT
            $tel = isset($data[4]) ? $conexion->real_escape_string($data[4]) : '';
            
            $q1 = "INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nombre','$apellido','$email','$clave')";
            if ($conexion->query($q1)) {
                $id_u = $conexion->insert_id;
                $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u, 3)");
                $conexion->query("INSERT INTO docentes (id_usuario, telefono) VALUES ($id_u, '$tel')");
                $success++;
            }
        }
        $conexion->commit();
        fclose($handle);
        header("Location: docentes.php?success=$success");
    } else {
        header("Location: docentes.php?error=file_open");
    }
    exit;
}

if (isset($_POST['accion']) && $_POST['accion'] == "crear") {
    $nombre = $conexion->real_escape_string($_POST['nombre']); 
    $apellido = $conexion->real_escape_string($_POST['apellido']); 
    $email = $conexion->real_escape_string($_POST['email']); 
    $clave = $conexion->real_escape_string($_POST['clave']); // PLAIN TEXT
    $tel = isset($_POST['telefono']) ? $conexion->real_escape_string($_POST['telefono']) : '';
    
    $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nombre','$apellido','$email','$clave')");
    $id_u = $conexion->insert_id;
    $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_u, 3)");
    $conexion->query("INSERT INTO docentes (id_usuario, telefono) VALUES ($id_u, '$tel')");
    $id_d = $conexion->insert_id;
    if (!empty($_POST['materias'])) {
        foreach ($_POST['materias'] as $id_cm) $conexion->query("INSERT INTO docente_materia (id_docente, id_curso_materia) VALUES ($id_d, $id_cm)");
    }
    header("Location: docentes.php"); exit;
}

if (isset($_POST['accion']) && $_POST['accion'] == "editar") {
    $id_u = intval($_POST['id_usuario']); 
    $nombre = $conexion->real_escape_string($_POST['nombre']); 
    $apellido = $conexion->real_escape_string($_POST['apellido']); 
    $email = $conexion->real_escape_string($_POST['email']); 
    $tel = isset($_POST['telefono']) ? $conexion->real_escape_string($_POST['telefono']) : '';
    
    if (!empty($_POST['clave'])) {
        $clave = $conexion->real_escape_string($_POST['clave']); // PLAIN TEXT
        $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email', clave='$clave' WHERE id_usuario=$id_u");
    } else {
        $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email' WHERE id_usuario=$id_u");
    }
    $conexion->query("UPDATE docentes SET telefono='$tel' WHERE id_usuario=$id_u");
    header("Location: docentes.php"); exit;
}

if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $d = $conexion->query("SELECT id_docente FROM docentes WHERE id_usuario=$id")->fetch_assoc();
    if($d){
        $id_d = $d['id_docente'];
        $conexion->query("DELETE FROM docente_materia WHERE id_docente=$id_d");
        $conexion->query("DELETE FROM docentes WHERE id_usuario=$id");
        $conexion->query("DELETE FROM usuario_perfil WHERE id_usuario=$id AND id_perfil=3");
        $conexion->query("DELETE FROM usuarios WHERE id_usuario=$id");
    }
    header("Location: docentes.php"); exit;
}

$busqueda = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : "";
$sql = "
    SELECT d.id_docente, u.id_usuario, u.nombre, u.apellido, u.email, d.telefono,
           GROUP_CONCAT(DISTINCT CONCAT(c.nombre_curso, ' - ', m.nombre_materia, '|||', cm.id_curso_materia) ORDER BY c.nombre_curso, m.nombre_materia SEPARATOR ';;') as materias_list
    FROM docentes d
    INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
    LEFT JOIN docente_materia dm ON d.id_docente = dm.id_docente
    LEFT JOIN curso_materia cm ON dm.id_curso_materia = cm.id_curso_materia
    LEFT JOIN materias m ON cm.id_materia = m.id_materia
    LEFT JOIN cursos c ON cm.id_curso = c.id_curso
    WHERE 1=1
";
if ($busqueda != "") {
    $sql .= " AND (u.nombre LIKE '%$busqueda%' OR u.apellido LIKE '%$busqueda%' OR u.email LIKE '%$busqueda%' OR d.telefono LIKE '%$busqueda%' OR m.nombre_materia LIKE '%$busqueda%')";
}
$sql .= " GROUP BY d.id_docente ORDER BY u.apellido, u.nombre";

$docentes = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    ob_start(); ?>
    <div class="docentes-lista">
      <?php if (empty($docentes)): ?>
        <p style="color:#888; text-align:center; padding:30px;">No se encontraron docentes.</p>
      <?php else: ?>
      <?php foreach ($docentes as $d): ?>
        <div class="docente-row">
          <div class="docente-row-content">
            <div class="docente-nombre"><?= htmlspecialchars(($d['apellido'] ?? '').', '.($d['nombre'] ?? '')) ?></div>
            <div class="docente-sub">
                <?php if(!empty($d['telefono'])): ?><span><i class="fa fa-phone"></i> <?= htmlspecialchars($d['telefono'] ?? '') ?></span><?php endif; ?>
                <span><i class="fa fa-envelope"></i> <?= htmlspecialchars($d['email'] ?? '') ?></span>
            </div>
            <div class="docente-materias" id="materias-wrapper-<?= $d['id_docente'] ?>">
                <?php if($d['materias_list']): 
                    $mats = explode(";;", $d['materias_list']); 
                    foreach($mats as $m_str): 
                        if(empty(trim($m_str))) continue;
                        list($nom_m, $id_cm) = explode("|||", $m_str);
                ?>
                    <span class="materia-badge">
                        <i class="fa fa-book"></i> <?= htmlspecialchars($nom_m) ?>
                        <button type="button" onclick="removerMateria(<?= $d['id_docente'] ?>, <?= $id_cm ?>, event)" class="badge-remove" title="Quitar materia">×</button>
                    </span>
                <?php endforeach; endif; ?>
                <button type="button" onclick="abrirModalAsignarMateria(<?= $d['id_docente'] ?>)" class="btn-agregar-badge">
                    <i class="fa fa-plus"></i> Materia
                </button>
            </div>
          </div>
          <div class="docente-row-actions">
            <a href="#" onclick='abrirModalEditar(<?= json_encode($d) ?>)' class="btn-row btn-edit" title="Editar">
              <i class="fa-solid fa-pen-to-square"></i> <span class="btn-label">Editar</span>
            </a>
            <a href="docentes.php?eliminar=<?= $d['id_usuario'] ?>" class="btn-row btn-delete" title="Eliminar">
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
<title>Gestión de Docentes</title>
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
  
  .docente-materias { display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin-top: 2px; }
  .materia-badge { 
    background: #fff8e1; color: #f57f17; border-radius: 4px; padding: 2px 8px; font-size: 11px; 
    display: flex; align-items: center; gap: 6px; border: 1px solid #ffe082; 
  }
  .badge-remove { background:none; border:none; color:#e53935; cursor:pointer; padding:0; font-size:14px; font-weight:bold; }
  
  .btn-agregar-badge {
    background:transparent; border:1px dashed #aaa; border-radius:4px;
    color:#666; font-size:11px; padding:1px 8px; cursor:pointer;
    display:inline-flex; align-items:center; gap:4px;
  }
  .btn-agregar-badge:hover { border-color:#f57f17; color:#f57f17; background:#fff8e1; }

  .docente-row-actions { display: flex; gap: 8px; flex-shrink: 0; }
  .btn-row {
    padding:6px 14px; border:none; border-radius:5px; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center;
    gap:5px; font-size:13px; font-weight:500; color:white !important;
  }
  .btn-edit { background-color:#2196F3; }
  .btn-delete { background-color:#dc3545; }

  @media (max-width: 600px) {
    .docente-row { padding: 10px; gap: 8px; }
    .btn-label { display: none; }
    .btn-row { padding: 8px 10px; }
    .docente-sub { gap: 8px; font-size: 12px; }
    .docente-row-actions { flex-direction: column; gap: 5px; }
  }
</style>
</head>
<body>
<?php include "navbar.php"; ?>
<h1 style="text-align:center; color:#fff;">Gestión de Docentes</h1>

<div style="width:95%; max-width:1200px; margin:10px auto; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <form id="formFiltros" method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar docente..." style="padding:10px; border-radius:6px; border:1px solid #ccc; width:220px;">
    </form>
    <div style="display:flex; gap:10px;">
      <a href="menu.php"><button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Menú</button></a>
      <button onclick="abrirModalCargarCSV()" style="padding:10px 20px; border-radius:8px; background:#28a745; color:#fff; border:none; cursor:pointer;">📄 CSV</button>
      <button onclick="abrirModalCrear()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;">➕ Nuevo Docente</button>
    </div>
</div>

<div id="tablaWrapper"></div>

<!-- Modales -->
<div id="modalCrear" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:500px;"><span class="cerrar" onclick="cerrarModalCrear()">&times;</span><h3>Registrar Docente</h3><form method="post"><input type="hidden" name="accion" value="crear"><input type="text" name="nombre" placeholder="Nombre" required><input type="text" name="apellido" placeholder="Apellido" required><input type="email" name="email" placeholder="Email" required><input type="password" name="clave" placeholder="Contraseña" required><input type="text" name="telefono" placeholder="Teléfono"><label>Materias:</label><div style="max-height:150px; overflow-y:auto; border:1px solid #ddd; padding:10px; background:#f9f9f9; border-radius:4px;"><?php 
$cm_all = $conexion->query("SELECT cm.id_curso_materia, c.nombre_curso, m.nombre_materia FROM curso_materia cm INNER JOIN cursos c ON cm.id_curso=c.id_curso INNER JOIN materias m ON cm.id_materia=m.id_materia ORDER BY c.nombre_curso, m.nombre_materia")->fetch_all(MYSQLI_ASSOC);
foreach ($cm_all as $cm): ?><label style="display:flex; align-items:center; gap:8px; margin:5px 0; background:#fff; padding:5px; border-radius:3px; border:1px solid #eee; cursor:pointer;"><input type="checkbox" name="materias[]" value="<?= $cm['id_curso_materia']?>"><?= $cm['nombre_curso']?> - <?= $cm['nombre_materia']?></label><?php endforeach; ?></div><button type="submit">Guardar</button></form></div></div>

<div id="modalEditar" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:500px;"><span class="cerrar" onclick="cerrarModalEditar()">&times;</span><h3>Editar Docente</h3><form method="post"><input type="hidden" name="accion" value="editar"><input type="hidden" name="id_usuario" id="edit_id_usuario"><input type="text" name="nombre" id="edit_nombre" required><input type="text" name="apellido" id="edit_apellido" required><input type="email" name="email" id="edit_email" required><input type="password" name="clave" placeholder="Nueva Contraseña (opcional)"><input type="text" name="telefono" id="edit_telefono"><button type="submit">Guardar Cambios</button></form></div></div>

<div id="modalAsignarMateria" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:400px;"><span class="cerrar" onclick="cerrarModalAsignarMateria()">&times;</span><h3>Asignar Materia</h3><select id="selectMateria" style="width:100%; padding:8px; margin-bottom:15px;"><option value="">-- Seleccione Materia --</option><?php foreach ($cm_all as $cm): ?><option value="<?= $cm['id_curso_materia']?>"><?= $cm['nombre_curso']?> - <?= $cm['nombre_materia']?></option><?php endforeach; ?></select><button onclick="asignarMateria()" style="width:100%; padding:10px; background:#16682b; color:white; border:none; border-radius:4px; cursor:pointer;">Asignar</button></div></div>

<div id="modalCargarCSV" class="modal-overlay" style="display:none;"><div class="modal-box" style="max-width:500px;"><span class="cerrar" onclick="cerrarModalCargarCSV()">&times;</span><h3>Cargar CSV</h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="accion" value="cargar_csv"><input type="file" name="csv_file" accept=".csv" required style="margin:20px 0;"><button type="submit" style="width:100%; padding:10px; background:#28a745; color:white; border:none; border-radius:4px; cursor:pointer;">Cargar</button></form></div></div>

<div id="confirm-dialog"><div class="confirm-dialog-content"><div class="row"><i class="fa-solid fa-exclamation-triangle confirm-dialog-icon"></i><p id="confirm-msg" class="confirm-dialog-message"></p></div><div class="confirm-dialog-buttons"><button onclick="closeConfirm(false)" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button><button onclick="closeConfirm(true)" class="confirm-dialog-button confirm-dialog-confirm">Aceptar</button></div></div></div>

<script>
let confirmResolve = null; let docenteActual = null;

function abrirModalCrear(){ document.getElementById("modalCrear").style.display="flex"; }
function cerrarModalCrear(){ document.getElementById("modalCrear").style.display="none"; }
function abrirModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="flex"; }
function cerrarModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="none"; }
function abrirModalEditar(d){
    document.getElementById("modalEditar").style.display="flex";
    document.getElementById("edit_id_usuario").value = d.id_usuario;
    document.getElementById("edit_nombre").value = d.nombre;
    document.getElementById("edit_apellido").value = d.apellido;
    document.getElementById("edit_email").value = d.email;
    document.getElementById("edit_telefono").value = d.telefono || '';
}
function cerrarModalEditar(){ document.getElementById("modalEditar").style.display="none"; }
function abrirModalAsignarMateria(id){ docenteActual = id; document.getElementById("modalAsignarMateria").style.display="flex"; }
function cerrarModalAsignarMateria(){ document.getElementById("modalAsignarMateria").style.display="none"; }

async function asignarMateria(){
    const idCM = document.getElementById("selectMateria").value;
    if(!idCM || !docenteActual) return;
    const resp = await fetch(`docentes_ajax.php?accion=asignar_materia`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id_docente=${docenteActual}&id_curso_materia=${idCM}`
    });
    const data = await resp.json();
    if(data.success){ cerrarModalAsignarMateria(); actualizarTabla(); } else { alert(data.message); }
}

async function removerMateria(idD, idCM, e){
    if(e) { e.preventDefault(); e.stopPropagation(); }
    if(await showConfirm("¿Quitar esta materia del docente?")){
        const resp = await fetch(`docentes_ajax.php?accion=quitar_materia`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id_docente=${idD}&id_curso_materia=${idCM}`
        });
        const data = await resp.json();
        if(data.success) actualizarTabla();
    }
}

function showConfirm(msg){
    document.getElementById("confirm-msg").textContent = msg;
    document.getElementById("confirm-dialog").style.display = "flex";
    return new Promise(r => confirmResolve = r);
}
function closeConfirm(v){ document.getElementById("confirm-dialog").style.display = "none"; if(confirmResolve) confirmResolve(v); }
async function actualizarTabla(){
    const p = new URLSearchParams(new FormData(document.getElementById('formFiltros'))); p.append('partial','1');
    const r = await fetch('docentes.php?' + p.toString(), { cache:'no-store' });
    document.getElementById('tablaWrapper').innerHTML = await r.text();
}
document.getElementById('formFiltros').querySelector('input').addEventListener('input', () => { clearTimeout(window.t); window.t = setTimeout(actualizarTabla, 300); });
document.addEventListener("DOMContentLoaded", actualizarTabla);
document.addEventListener('click', e => {
    const a = e.target.closest('a[href*="docentes.php?eliminar="]');
    if(a){ e.preventDefault(); showConfirm("¿Eliminar este docente?").then(v => { if(v) window.location.href = a.href; }); }
}, true);
</script>
</body>
</html>
