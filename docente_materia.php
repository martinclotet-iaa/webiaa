<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}
require_once __DIR__ . '/acceso.php';
require_once "conexion.php";

$id_cm = isset($_GET['id_cm']) ? intval($_GET['id_cm']) : 0;
if ($id_cm <= 0) {
    header("Location: materias.php");
    exit;
}

// Obtener info del curso y materia
$info = $conexion->query("
    SELECT c.nombre_curso, m.nombre_materia, m.tipo 
    FROM curso_materia cm
    INNER JOIN cursos c ON cm.id_curso = c.id_curso
    INNER JOIN materias m ON cm.id_materia = m.id_materia
    WHERE cm.id_curso_materia = $id_cm
")->fetch_assoc();

if (!$info) {
    header("Location: materias.php");
    exit;
}

// Obtener docentes asignados
$asignados = $conexion->query("
    SELECT d.id_docente, u.nombre, u.apellido
    FROM docente_materia dm
    INNER JOIN docentes d ON dm.id_docente = d.id_docente
    INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
    WHERE dm.id_curso_materia = $id_cm
    ORDER BY dm.id_docente_materia ASC
")->fetch_all(MYSQLI_ASSOC);

// Obtener todos los docentes para el selector
$todos_docentes = $conexion->query("
    SELECT d.id_docente, u.nombre, u.apellido
    FROM docentes d
    INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
    ORDER BY u.apellido, u.nombre
")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignar Docentes - <?php echo htmlspecialchars($info['nombre_materia']); ?> [<?php echo htmlspecialchars($info['tipo'] ?: 'Programática'); ?>]</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="estilos.css">
    <style>
        .container { max-width: 600px; margin: 30px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: #16682b; color: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        .header h2 { margin: 0; font-size: 1.3rem; }
        .header p { margin: 5px 0 0; opacity: 0.9; }
        .docente-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid #eee; }
        .docente-item:last-child { border-bottom: none; }
        .role-badge { font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; font-weight: bold; margin-left: 10px; }
        .role-titular { background: #e8f5e9; color: #16682b; border: 1px solid #c8e6c9; }
        .role-suplente { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
        .btn-remove { color: #dc3545; cursor: pointer; background: none; border: none; font-size: 1.1rem; }
        .add-section { margin-top: 25px; padding-top: 20px; border-top: 2px solid #f0f0f0; }
        .add-section h3 { font-size: 1rem; margin-bottom: 15px; color: #444; }
        .select-group { display: flex; gap: 10px; }
        select { flex: 1; padding: 10px; border-radius: 6px; border: 1px solid #ddd; font-size: 0.95rem; }
        .btn-add { background: #16682b; color: #fff; padding: 0 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; }
        .footer-actions { margin-top: 30px; display: flex; justify-content: center; }
        .btn-back { background: #6c757d; color: #fff; padding: 10px 25px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 10px; }
    </style>
</head>
<body>
    <?php include "navbar.php"; ?>
    <div class="container" style="color: #000;">
        <div class="header">
            <h2>Asignación de Docentes</h2>
            <p><?php echo htmlspecialchars($info['nombre_materia']); ?> (<?php echo htmlspecialchars($info['nombre_curso']); ?>) <span style="font-size: 0.8rem; opacity: 0.7;">[<?php echo htmlspecialchars($info['tipo'] ?: 'Programática'); ?>]</span></p>
        </div>

        <div id="lista-docentes">
            <?php if (empty($asignados)): ?>
                <p style="text-align:center; color:#888; padding:20px;">No hay docentes asignados todavía.</p>
            <?php else: ?>
                <?php foreach ($asignados as $idx => $doc): ?>
                <div class="docente-item">
                    <div>
                        <i class="fa fa-user-tie" style="color:#16682b; margin-right:10px;"></i>
                        <strong><?php echo htmlspecialchars($doc['apellido'] . ', ' . $doc['nombre']); ?></strong>
                        <span class="role-badge <?php echo $idx === 0 ? 'role-titular' : 'role-suplente'; ?>">
                            <?php echo $idx === 0 ? 'TITULAR' : 'SUPLENTE'; ?>
                        </span>
                    </div>
                    <button class="btn-remove" onclick="quitarDocente(<?php echo $doc['id_docente']; ?>)" title="Quitar asignación">
                        <i class="fa fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="add-section">
            <h3><i class="fa fa-plus-circle"></i> Agregar Docente</h3>
            <div class="select-group">
                <select id="select-docente">
                    <option value="">-- Seleccione un docente --</option>
                    <?php foreach ($todos_docentes as $doc): ?>
                        <option value="<?php echo $doc['id_docente']; ?>">
                            <?php echo htmlspecialchars($doc['apellido'] . ', ' . $doc['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-add" onclick="agregarDocente()">Agregar</button>
            </div>
            <p style="font-size:0.8rem; color:#666; margin-top:10px;">* El primer docente asignado figurará como titular.</p>
        </div>

        <div class="footer-actions">
            <a href="materias.php" class="btn-back"><i class="fa fa-arrow-left"></i> Volver a Materias</a>
        </div>
    </div>

    <script>
        async function agregarDocente() {
            const idDocente = document.getElementById('select-docente').value;
            if (!idDocente) return alert('Seleccione un docente');

            const body = new URLSearchParams();
            body.append('accion', 'asignar_materia');
            body.append('id_docente', idDocente);
            body.append('id_curso_materia', <?php echo $id_cm; ?>);

            try {
                const response = await fetch('docentes_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message);
                }
            } catch (error) {
                alert('Error al procesar la solicitud');
            }
        }

        async function quitarDocente(idDocente) {
            if (!confirm('¿Seguro que desea quitar a este docente de esta materia?')) return;

            const body = new URLSearchParams();
            body.append('accion', 'quitar_materia');
            body.append('id_docente', idDocente);
            body.append('id_curso_materia', <?php echo $id_cm; ?>);

            try {
                const response = await fetch('docentes_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message);
                }
            } catch (error) {
                alert('Error al procesar la solicitud');
            }
        }
    </script>
</body>
</html>
