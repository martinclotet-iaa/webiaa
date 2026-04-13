<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/html; charset=UTF-8');

// Detección dinámica de relación muchos-a-muchos
$hasJoinTbl = $conexion->query("SHOW TABLES LIKE 'curso_preceptor'");
$useJoinTbl = ($hasJoinTbl && $hasJoinTbl->num_rows > 0);

// Cargar CSV
if (isset($_POST['accion']) && $_POST['accion'] == "cargar_csv") {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != UPLOAD_ERR_OK) {
        header("Location: preceptores.php?error=upload");
        exit;
    }
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");
    if (!$handle) {
        header("Location: preceptores.php?error=file");
        exit;
    }
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    
    $header = fgetcsv($handle, 1000, ",");
    if (count($header) == 1 && strpos($header[0], ';') !== false) {
        rewind($handle);
        $header = fgetcsv($handle, 1000, ";");
        $delimiter = ";";
    } else {
        $delimiter = ",";
    }

    $errors = [];
    $conexion->begin_transaction();
    $success = 0;
    while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
        if (empty($data[0]) && count($data) <= 1) continue;

        $nombre   = isset($data[0]) ? trim($data[0] ?? '') : '';
        $apellido = isset($data[1]) ? trim($data[1] ?? '') : '';
        $email    = isset($data[2]) ? trim($data[2] ?? '') : '';
        $clave    = isset($data[3]) ? trim($data[3] ?? '') : '';
        $telefono = isset($data[4]) ? trim($data[4] ?? '') : '';

        if (empty($nombre) || empty($apellido) || empty($email) || empty($clave)) continue;

        $checkStmt = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) continue;

        $pass_hash = password_hash($clave, PASSWORD_DEFAULT);
        $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $nombre, $apellido, $email, $pass_hash);
        if (!$stmt->execute()) continue;
        $id_usuario = $conexion->insert_id;

        $stmt = $conexion->prepare("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES (?, 5)");
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        
        $stmt = $conexion->prepare("INSERT INTO preceptores (id_usuario, telefono) VALUES (?, ?)");
        $stmt->bind_param("is", $id_usuario, $telefono);
        $stmt->execute();

        $success++;
    }
    fclose($handle);
    $conexion->commit();
    header("Location: preceptores.php?success_csv=$success");
    exit;
}

// Crear Preceptor
if (isset($_POST['accion']) && $_POST['accion'] == "crear") {
    $nombre   = $conexion->real_escape_string($_POST['nombre']);
    $apellido = $conexion->real_escape_string($_POST['apellido']);
    $email    = $conexion->real_escape_string($_POST['email']);
    $clave    = password_hash($_POST['clave'], PASSWORD_DEFAULT);
    $tel      = $conexion->real_escape_string($_POST['telefono'] ?? '');
    
    $conexion->query("INSERT INTO usuarios (nombre, apellido, email, clave) VALUES ('$nombre','$apellido','$email','$clave')");
    $id_usuario = $conexion->insert_id;
    $conexion->query("INSERT INTO usuario_perfil (id_usuario, id_perfil) VALUES ($id_usuario, 5)");
    $conexion->query("INSERT INTO preceptores (id_usuario, telefono) VALUES ($id_usuario, '$tel')");
    
    header("Location: preceptores.php?success=1");
    exit;
}

// Editar Preceptor
if (isset($_POST['accion']) && $_POST['accion'] == "editar") {
    $id_usuario = intval($_POST['id_usuario']);
    $nombre     = $conexion->real_escape_string($_POST['nombre']);
    $apellido   = $conexion->real_escape_string($_POST['apellido']);
    $email      = $conexion->real_escape_string($_POST['email']);
    $telefono   = $conexion->real_escape_string($_POST['telefono'] ?? '');

    if (!empty($_POST['clave'])) {
        $clave = password_hash($_POST['clave'], PASSWORD_DEFAULT);
        $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email', clave='$clave' WHERE id_usuario=$id_usuario");
    } else {
        $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido', email='$email' WHERE id_usuario=$id_usuario");
    }

    $check = $conexion->query("SELECT id_usuario FROM preceptores WHERE id_usuario = $id_usuario");
    if ($check && $check->num_rows > 0) {
        $conexion->query("UPDATE preceptores SET telefono='$telefono' WHERE id_usuario=$id_usuario");
    } else {
        $conexion->query("INSERT INTO preceptores (id_usuario, telefono) VALUES ($id_usuario, '$telefono')");
    }
    header("Location: preceptores.php?success=edit");
    exit;
}

// Eliminar Preceptor
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $conexion->query("DELETE FROM usuario_perfil WHERE id_usuario=$id AND id_perfil=5");
    if ($useJoinTbl) {
        $conexion->query("DELETE FROM curso_preceptor WHERE id_preceptor=$id");
    } else {
        $conexion->query("UPDATE cursos SET id_preceptor=NULL WHERE id_preceptor=$id");
    }
    $conexion->query("DELETE FROM preceptores WHERE id_usuario=$id");
    $conexion->query("DELETE FROM usuarios WHERE id_usuario=$id");
    header("Location: preceptores.php?success=delete");
    exit;
}

// Utilidades
function json_response($arr, $code = 200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($arr); exit; }

// Acciones AJAX
if (isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    if ($accion === 'asignar_curso') {
        $id_preceptor = (int)($_POST['id_preceptor'] ?? 0);
        $id_curso     = (int)($_POST['id_curso'] ?? 0);
        if ($id_preceptor <= 0 || $id_curso <= 0) json_response(['success'=>false,'message'=>'Datos inválidos'],400);

        if ($useJoinTbl) {
            $stmt = $conexion->prepare("INSERT IGNORE INTO curso_preceptor (id_curso, id_preceptor) VALUES (?, ?)");
            $stmt->bind_param('ii', $id_curso, $id_preceptor);
            $ok = $stmt->execute();
        } else {
            $ok = $conexion->query("UPDATE cursos SET id_preceptor=$id_preceptor WHERE id_curso=$id_curso");
        }
        if (!$ok) json_response(['success'=>false,'message'=>'No se pudo asignar el curso']);
        json_response(['success'=>true]);
    }

    if ($accion === 'quitar_curso') {
        $id_preceptor = (int)($_POST['id_preceptor'] ?? 0);
        $id_curso     = (int)($_POST['id_curso'] ?? 0);
        if ($id_preceptor <= 0 || $id_curso <= 0) json_response(['success'=>false,'message'=>'Datos inválidos'],400);
        if ($useJoinTbl) {
            $stmt = $conexion->prepare("DELETE FROM curso_preceptor WHERE id_curso=? AND id_preceptor=?");
            $stmt->bind_param('ii', $id_curso, $id_preceptor);
            $ok = $stmt->execute();
        } else {
            $ok = $conexion->query("UPDATE cursos SET id_preceptor=NULL WHERE id_curso=$id_curso AND id_preceptor=$id_preceptor");
        }
        if (!$ok) json_response(['success'=>false,'message'=>'No se pudo quitar el curso']);
        json_response(['success'=>true]);
    }
}

// Búsqueda
$q = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : '';

// Listado de preceptores
if ($useJoinTbl) {
    $sql = "
        SELECT u.id_usuario, u.nombre, u.apellido, u.email, p_tel.telefono,
               GROUP_CONCAT(DISTINCT CONCAT(cu.nombre_curso, '|||', cu.id_curso) ORDER BY cu.nombre_curso SEPARATOR ';;') AS cursos_list
        FROM usuarios u
        INNER JOIN usuario_perfil up ON up.id_usuario = u.id_usuario AND up.id_perfil = 5
        LEFT JOIN preceptores p_tel ON p_tel.id_usuario = u.id_usuario
        LEFT JOIN curso_preceptor cp ON cp.id_preceptor = u.id_usuario
        LEFT JOIN cursos cu ON cu.id_curso = cp.id_curso
        WHERE 1=1
    ";
} else {
    $sql = "
        SELECT u.id_usuario, u.nombre, u.apellido, u.email, p_tel.telefono,
               GROUP_CONCAT(DISTINCT CONCAT(cu.nombre_curso, '|||', cu.id_curso) ORDER BY cu.nombre_curso SEPARATOR ';;') AS cursos_list
        FROM usuarios u
        INNER JOIN usuario_perfil up ON up.id_usuario = u.id_usuario AND up.id_perfil = 5
        LEFT JOIN preceptores p_tel ON p_tel.id_usuario = u.id_usuario
        LEFT JOIN cursos cu ON cu.id_preceptor = u.id_usuario
        WHERE 1=1
    ";
}
if ($q !== '') { $sql .= " AND (u.nombre LIKE '%$q%' OR u.apellido LIKE '%$q%' OR u.email LIKE '%$q%')"; }
$sql .= " GROUP BY u.id_usuario ORDER BY u.apellido, u.nombre";
$preseptores = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

// Respuesta parcial (solo tabla - Estilo DOCENTES)
if (isset($_GET['partial']) && $_GET['partial'] === '1') {
    ob_start();
    ?>
    <div class="docentes-lista">
        <?php if (empty($preseptores)): ?>
            <p style="color:#888; text-align:center; padding:30px;">No se encontraron preceptores.</p>
        <?php else: ?>
            <?php foreach ($preseptores as $p): ?>
                <div class="docente-row">
                    <div class="docente-row-content">
                        <div class="docente-nombre"><?= htmlspecialchars($p['apellido'].', '.$p['nombre']) ?></div>
                        <div class="docente-sub">
                            <?php if(!empty($p['telefono'])): ?><span><i class="fa fa-phone"></i> <?= htmlspecialchars($p['telefono']) ?></span><?php endif; ?>
                            <span><i class="fa fa-envelope"></i> <?= htmlspecialchars($p['email']) ?></span>
                        </div>
                        <div class="docente-materias">
                            <?php 
                            if (!empty($p['cursos_list'])) {
                                $c_items = explode(';;', $p['cursos_list']);
                                foreach ($c_items as $c_str) {
                                    if(empty(trim($c_str))) continue;
                                    list($nom_c, $id_c) = explode('|||', $c_str);
                                    echo '<span class="materia-badge"><i class="fa fa-graduation-cap"></i> '.htmlspecialchars($nom_c).
                                         ' <button type="button" onclick="removerCurso('.$p['id_usuario'].','.$id_c.',event)" class="badge-remove">×</button></span>';
                                }
                            }
                            ?>
                            <button type="button" onclick="abrirModalAsignarCurso(<?= $p['id_usuario'] ?>)" class="btn-agregar-badge">
                                <i class="fa fa-plus"></i> Curso
                            </button>
                        </div>
                    </div>
                    <div class="docente-row-actions">
                        <a href="#" onclick='abrirModalEditar(<?= json_encode($p) ?>)' class="btn-row btn-edit">
                            <i class="fa-solid fa-pen-to-square"></i> <span class="btn-label">Editar</span>
                        </a>
                        <a href="#" onclick="confirmarEliminar(<?= $p['id_usuario'] ?>)" class="btn-row btn-delete">
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Preceptores</title>
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
            background: #e3f2fd; color: #1976d2; border-radius: 4px; padding: 2px 8px; font-size: 11px; 
            display: flex; align-items: center; gap: 6px; border: 1px solid #bbdefb; 
        }
        .badge-remove { background:none; border:none; color:#e53935; cursor:pointer; padding:0; font-size:14px; font-weight:bold; }
        .btn-agregar-badge {
            background:transparent; border:1px dashed #aaa; border-radius:4px;
            color:#666; font-size:11px; padding:1px 8px; cursor:pointer;
            display:inline-flex; align-items:center; gap:4px;
        }
        .btn-agregar-badge:hover { border-color:#1976d2; color:#1976d2; background:#e3f2fd; }
        .docente-row-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .btn-row {
            padding:6px 14px; border:none; border-radius:5px; cursor:pointer;
            text-decoration:none; display:inline-flex; align-items:center;
            gap:5px; font-size:13px; font-weight:500; color:white !important;
        }
        .btn-edit { background-color:#2196F3; }
        .btn-delete { background-color:#dc3545; }

        @media (max-width: 600px) {
            .btn-label { display: none; }
            .docente-row-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <?php include "navbar.php"; ?>
    <h1 style="text-align:center; color:#fff;">Gestión de Preceptores</h1>

    <div style="width:95%; max-width:1200px; margin:10px auto; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <form id="formFiltros" method="get">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar preceptor..." style="padding:10px; border-radius:6px; border:1px solid #ccc; width:220px;">
        </form>
        <div style="display:flex; gap:10px;">
            <a href="menu.php"><button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Menú</button></a>
            <button onclick="abrirModalCargarCSV()" style="padding:10px 20px; border-radius:8px; background:#28a745; color:#fff; border:none; cursor:pointer;">📄 CSV</button>
            <button onclick="abrirModalCrear()" style="padding:10px 20px; border-radius:8px; background:#16682b; color:#fff; border:none; cursor:pointer;">➕ Nuevo Preceptor</button>
        </div>
    </div>

    <div id="tablaWrapper"></div>

    <!-- Modal Crear -->
    <div id="modalCrear" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:500px;">
            <span class="cerrar" onclick="cerrarModalCrear()">&times;</span>
            <h3 style="color:#000;">Registrar Preceptor</h3>
            <form method="post">
                <input type="hidden" name="accion" value="crear">
                <input type="text" name="nombre" placeholder="Nombre" required>
                <input type="text" name="apellido" placeholder="Apellido" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="clave" placeholder="Contraseña" required>
                <input type="text" name="telefono" placeholder="Teléfono">
                <button type="submit">Guardar</button>
            </form>
        </div>
    </div>

    <!-- Modal Editar -->
    <div id="modalEditar" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:500px;">
            <span class="cerrar" onclick="cerrarModalEditar()">&times;</span>
            <h3 style="color:#000;">Editar Preceptor</h3>
            <form method="post">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id_usuario" id="edit_id_usuario">
                <input type="text" name="nombre" id="edit_nombre" required>
                <input type="text" name="apellido" id="edit_apellido" required>
                <input type="email" name="email" id="edit_email" required>
                <input type="password" name="clave" placeholder="Nueva Contraseña (opcional)">
                <input type="text" name="telefono" id="edit_telefono">
                <button type="submit">Guardar Cambios</button>
            </form>
        </div>
    </div>

    <!-- Modal Asignar Curso -->
    <div id="modalAsignarCurso" class="modal-overlay" style="display:none; position:fixed; inset:0; z-index:2100; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
        <div class="modal-box" style="background:#fff; border-radius:12px; width:90%; max-width:400px; padding:25px; box-shadow:0 10px 25px rgba(0,0,0,0.3); color:#000; position:relative;">
            <span class="cerrar" onclick="cerrarModalAsignarCurso()" style="position:absolute; top:15px; right:15px; font-size:24px; cursor:pointer; color:#000;">&times;</span>
            <h3 style="margin:0 0 20px 0; color:#000; text-align:center; font-size:1.3rem;">Asignar Curso</h3>
            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:8px; font-weight:600; color:#333;">Seleccione un curso:</label>
                <select id="selectCurso" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; background:#fff; color:#000; font-size:1rem;">
                    <option value="">-- Seleccione Curso --</option>
                    <?php 
                    $cursos_all = $conexion->query("SELECT * FROM cursos ORDER BY nombre_curso")->fetch_all(MYSQLI_ASSOC);
                    foreach ($cursos_all as $c): ?>
                        <option value="<?= $c['id_curso']?>"><?= htmlspecialchars($c['nombre_curso'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="asignarCurso()" style="width:100%; padding:12px; background:#16682b; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:1rem; transition:background 0.2s;">
                <i class="fa fa-save"></i> Guardar Asignación
            </button>
        </div>
    </div>

    <!-- Modal CSV -->
    <div id="modalCargarCSV" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:500px;">
            <span class="cerrar" onclick="cerrarModalCargarCSV()">&times;</span>
            <h3 style="color:#000;">Cargar CSV</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="cargar_csv">
                <input type="file" name="csv_file" accept=".csv" required style="margin:20px 0;">
                <button type="submit" style="width:100%; padding:10px; background:#28a745; color:white; border:none; border-radius:4px; cursor:pointer;">Cargar</button>
            </form>
        </div>
    </div>

    <div id="confirm-dialog">
        <div class="confirm-dialog-content">
            <div class="row">
                <i class="fa-solid fa-exclamation-triangle confirm-dialog-icon"></i>
                <p id="confirm-msg" class="confirm-dialog-message"></p>
            </div>
            <div class="confirm-dialog-buttons">
                <button onclick="closeConfirm(false)" class="confirm-dialog-button confirm-dialog-cancel">Cancelar</button>
                <button onclick="closeConfirm(true)" class="confirm-dialog-button confirm-dialog-confirm">Aceptar</button>
            </div>
        </div>
    </div>

    <script>
    let confirmResolve = null; 
    let preceptorActualId = null;

    function abrirModalCrear(){ document.getElementById("modalCrear").style.display="flex"; }
    function cerrarModalCrear(){ document.getElementById("modalCrear").style.display="none"; }
    function abrirModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="flex"; }
    function cerrarModalCargarCSV(){ document.getElementById("modalCargarCSV").style.display="none"; }
    
    function abrirModalEditar(p){
        document.getElementById("modalEditar").style.display="flex";
        document.getElementById("edit_id_usuario").value = p.id_usuario;
        document.getElementById("edit_nombre").value = p.nombre;
        document.getElementById("edit_apellido").value = p.apellido;
        document.getElementById("edit_email").value = p.email;
        document.getElementById("edit_telefono").value = p.telefono || '';
    }
    function cerrarModalEditar(){ document.getElementById("modalEditar").style.display="none"; }
    
    function abrirModalAsignarCurso(id){ preceptorActualId = id; document.getElementById("modalAsignarCurso").style.display="flex"; }
    function cerrarModalAsignarCurso(){ document.getElementById("modalAsignarCurso").style.display="none"; }

    async function asignarCurso(){
        const sel = document.getElementById("selectCurso");
        const idCurso = sel.value;
        if(!idCurso || !preceptorActualId){
            alert('Por favor seleccione un curso');
            return;
        }
        
        const fd = new URLSearchParams();
        fd.append('accion','asignar_curso');
        fd.append('id_preceptor', preceptorActualId);
        fd.append('id_curso', idCurso);
        
        try {
            const resp = await fetch('preceptores.php', { 
                method:'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString() 
            });
            const data = await resp.json();
            if(data.success){ 
                cerrarModalAsignarCurso(); 
                sel.value = ""; // Resetear select
                actualizarTabla(); 
            } else { 
                alert(data.message || 'Error al asignar curso'); 
            }
        } catch(e) {
            console.error(e);
            alert('Error de conexión');
        }
    }

    async function removerCurso(idP, idC, e){
        if(e) { e.preventDefault(); e.stopPropagation(); }
        if(await showConfirm("¿Quitar este curso del preceptor?")){
            const fd = new URLSearchParams();
            fd.append('accion','quitar_curso');
            fd.append('id_preceptor', idP);
            fd.append('id_curso', idC);
            const resp = await fetch('preceptores.php', { method:'POST', body:fd });
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
    
    function confirmarEliminar(id){
        showConfirm("¿Eliminar este preceptor completamenta?").then(v => {
            if(v) window.location.href = 'preceptores.php?eliminar=' + id;
        });
    }

    async function actualizarTabla(){
        const fd = new FormData(document.getElementById('formFiltros'));
        const p = new URLSearchParams(fd); p.append('partial','1');
        const r = await fetch('preceptores.php?' + p.toString());
        document.getElementById('tablaWrapper').innerHTML = await r.text();
    }

    document.querySelector('#formFiltros input').addEventListener('input', () => {
        clearTimeout(window.t);
        window.t = setTimeout(actualizarTabla, 350);
    });

    document.addEventListener("DOMContentLoaded", actualizarTabla);
    </script>
</body>
</html>
