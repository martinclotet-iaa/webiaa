<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

 // Control de acceso dinámico basado en menús
 require_once __DIR__ . '/acceso.php';

require_once "conexion.php";

$vista_actual = isset($_POST['vista_actual']) ? $_POST['vista_actual'] : (isset($_GET['vista']) ? $_GET['vista'] : 'normal');

// Obtener lista de materias con sus cursos e id_curso_materia
$materias = $conexion->query("
    SELECT m.*, 
           GROUP_CONCAT(DISTINCT CONCAT(c.nombre_curso, ':', cm.id_curso_materia) ORDER BY c.nombre_curso SEPARATOR ', ') as cursos_data
    FROM materias m
    LEFT JOIN curso_materia cm ON m.id_materia = cm.id_materia
    LEFT JOIN cursos c ON cm.id_curso = c.id_curso
    GROUP BY m.id_materia
    ORDER BY m.nombre_materia, m.tipo
")->fetch_all(MYSQLI_ASSOC);

// Obtener lista de cursos para el formulario
$cursos = $conexion->query("SELECT * FROM cursos ORDER BY nombre_curso")->fetch_all(MYSQLI_ASSOC);

// Obtener lista de docentes para el formulario
$docentes = $conexion->query("
    SELECT d.id_docente, u.nombre, u.apellido 
    FROM docentes d
    INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
    ORDER BY u.apellido, u.nombre
")->fetch_all(MYSQLI_ASSOC);

// Crear nueva materia
if (isset($_POST['accion']) && $_POST['accion'] == "crear") {
    $nombre = $conexion->real_escape_string($_POST['nombre_materia']);
    $tipo = $conexion->real_escape_string($_POST['tipo'] ?? 'Programática');
    
    $conexion->query("INSERT INTO materias (nombre_materia, tipo) 
                      VALUES ('$nombre', '$tipo')");
    $id_materia = $conexion->insert_id;

    // Asignar cursos a la materia
    if (!empty($_POST['cursos'])) {
        foreach ($_POST['cursos'] as $id_curso) {
            $conexion->query("INSERT INTO curso_materia (id_curso, id_materia) 
                             VALUES ($id_curso, $id_materia)");
        }
    }

    header("Location: materias.php");
    exit;
}

// Actualizar materia
if (isset($_POST['accion']) && $_POST['accion'] == "editar") {
    $id_materia = intval($_POST['id_materia']);
    $nombre = $conexion->real_escape_string($_POST['nombre_materia']);
    $tipo = $conexion->real_escape_string($_POST['tipo'] ?? 'Programática');
    
    $conexion->query("UPDATE materias 
                     SET nombre_materia = '$nombre', tipo = '$tipo' 
                     WHERE id_materia = $id_materia");

    // Actualizar cursos de la materia
    $nuevos_cursos = isset($_POST['cursos']) && is_array($_POST['cursos']) ? $_POST['cursos'] : [];
    
    $result_actuales = $conexion->query("SELECT id_curso, id_curso_materia FROM curso_materia WHERE id_materia = $id_materia");
    $mapa_cursos_actuales = [];
    $cursos_actuales = [];
    
    if ($result_actuales) {
        while ($row = $result_actuales->fetch_assoc()) {
            $mapa_cursos_actuales[$row['id_curso']] = $row['id_curso_materia'];
            $cursos_actuales[] = $row['id_curso'];
        }
    }
    
    $cursos_a_eliminar = array_diff($cursos_actuales, $nuevos_cursos);
    $cursos_a_agregar = array_diff($nuevos_cursos, $cursos_actuales);
    
    foreach ($cursos_a_eliminar as $id_curso) {
        $id_cm = $mapa_cursos_actuales[$id_curso];
        $conexion->query("DELETE FROM docente_materia WHERE id_curso_materia = $id_cm");
        $conexion->query("DELETE FROM curso_materia WHERE id_curso_materia = $id_cm");
    }
    
    foreach ($cursos_a_agregar as $id_curso) {
        $id_curso = intval($id_curso);
        $conexion->query("INSERT INTO curso_materia (id_curso, id_materia) VALUES ($id_curso, $id_materia)");
    }

    header("Location: materias.php");
    exit;
}

// Desvincular materia de un curso
if (isset($_POST['accion']) && $_POST['accion'] == "desvincular") {
    $id_materia = intval($_POST['id_materia']);
    $id_curso = intval($_POST['id_curso']);
    
    // Iniciar transacción para asegurar la integridad de los datos
    $conexion->begin_transaction();
    
    try {
        // 1. Obtener el id_curso_materia que vamos a eliminar
        $query = "SELECT id_curso_materia FROM curso_materia 
                 WHERE id_materia = $id_materia AND id_curso = $id_curso";
        $result = $conexion->query($query);
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $id_curso_materia = $row['id_curso_materia'];
            
            // 2. Primero eliminar las referencias en docente_materia
            $conexion->query("DELETE FROM docente_materia WHERE id_curso_materia = $id_curso_materia");
            
            // 3. Luego eliminar el registro de curso_materia
            $conexion->query("DELETE FROM curso_materia WHERE id_curso_materia = $id_curso_materia");
            
            // Confirmar la transacción si todo salió bien
            $conexion->commit();
            $_SESSION['mensaje'] = "Materia desvinculada correctamente del curso.";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("No se encontró la relación entre el curso y la materia.");
        }
    } catch (Exception $e) {
        // Si hay algún error, deshacer los cambios
        $conexion->rollback();
        $_SESSION['mensaje'] = "Error al desvincular la materia: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "error";
    }

    header("Location: materias.php?vista=cursos");
    exit;
}

// Eliminar materia
if (isset($_GET['eliminar'])) {
    $id_materia = intval($_GET['eliminar']);
    
    // Verificar si se debe mostrar la confirmación
    if (!isset($_GET['confirmar'])) {
        // Obtener nombre de la materia para el mensaje
        $materia = $conexion->query("SELECT nombre_materia FROM materias WHERE id_materia = $id_materia")->fetch_assoc();
        if ($materia) {
            $nombre_materia = htmlspecialchars($materia['nombre_materia']);
            echo "
            <script>
                if (confirm('¿Estás seguro de eliminar la materia \'$nombre_materia\'?\\n\\nEsta acción eliminará:\\n- La materia del sistema\\n- Todas las relaciones con cursos\\n- Todas las relaciones con docentes\\n\\nEsta acción NO se puede deshacer.')) {
                    window.location.href = 'materias.php?eliminar=$id_materia&confirmar=1';
                } else {
                    window.location.href = 'materias.php';
                }
            </script>
            ";
            exit;
        }
    } else {
        // Si se confirmó, proceder con la eliminación
        $conexion->begin_transaction();
        try {
            // 1. Eliminar relaciones con docentes
            $conexion->query("DELETE dm FROM docente_materia dm 
                            INNER JOIN curso_materia cm ON dm.id_curso_materia = cm.id_curso_materia 
                            WHERE cm.id_materia = $id_materia");
            
            // 2. Eliminar relaciones con cursos
            $conexion->query("DELETE FROM curso_materia WHERE id_materia = $id_materia");
            
            // 3. Finalmente, eliminar la materia
            $conexion->query("DELETE FROM materias WHERE id_materia = $id_materia");
            
            $conexion->commit();
            
        } catch (Exception $e) {
            $conexion->rollback();
            die("Error al eliminar la materia: " . $e->getMessage());
        }
    }
    
    header("Location: materias.php");
    exit;
}

// Obtener datos de una materia para editar
$materia_editar = null;
if (isset($_GET['editar'])) {
    $id_materia = intval($_GET['editar']);
    $materia_editar = $conexion->query("
        SELECT m.*, 
               GROUP_CONCAT(cm.id_curso) as cursos_ids
        FROM materias m
        LEFT JOIN curso_materia cm ON m.id_materia = cm.id_materia
        WHERE m.id_materia = $id_materia
        GROUP BY m.id_materia
    ")->fetch_assoc();
}

// Agregar materia a un curso
if (isset($_POST['accion']) && $_POST['accion'] == 'agregar_materia_curso') {
    $id_curso = intval($_POST['id_curso']);
    // Soportar selección múltiple: id_materias[] o fallback a id_materia
    $ids_materias = [];
    if (isset($_POST['id_materias']) && is_array($_POST['id_materias'])) {
        foreach ($_POST['id_materias'] as $idm) {
            $idm = intval($idm);
            if ($idm > 0) $ids_materias[] = $idm;
        }
    } elseif (isset($_POST['id_materia'])) {
        $idm = intval($_POST['id_materia']);
        if ($idm > 0) $ids_materias[] = $idm;
    }
    
    if ($id_curso > 0 && !empty($ids_materias)) {
        $insertadas = 0; $duplicadas = 0;
        foreach ($ids_materias as $id_materia) {
            // Verificar si la relación ya existe
            $verificar = $conexion->query("SELECT 1 FROM curso_materia 
                                         WHERE id_curso = $id_curso AND id_materia = $id_materia");
            if ($verificar && $verificar->num_rows == 0) {
                $ok = $conexion->query("INSERT INTO curso_materia (id_curso, id_materia) VALUES ($id_curso, $id_materia)");
                if ($ok) { $insertadas++; } else { /* opcional: log error */ }
            } else {
                $duplicadas++;
            }
        }
        if ($insertadas > 0 && $duplicadas === 0) {
            $_SESSION['mensaje'] = ($insertadas === 1)
                ? "Materia asignada correctamente al curso."
                : "$insertadas materias asignadas correctamente al curso.";
            $_SESSION['tipo_mensaje'] = "success";
        } elseif ($insertadas > 0 && $duplicadas > 0) {
            $_SESSION['mensaje'] = "$insertadas nuevas asignaciones. $duplicadas ya estaban asignadas.";
            $_SESSION['tipo_mensaje'] = "warning";
        } else {
            $_SESSION['mensaje'] = "Las materias seleccionadas ya estaban asignadas a este curso.";
            $_SESSION['tipo_mensaje'] = "warning";
        }
    } else {
        $_SESSION['mensaje'] = "Error: Datos inválidos.";
        $_SESSION['tipo_mensaje'] = "error";
    }
    
    header("Location: materias.php?vista=cursos");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Materias</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="estilos.css?v=<?php echo time(); ?>">
    <style>
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            border: none;
            font-size: 14px;
        }
        
        .btn i {
            font-size: 14px;
        }
        
        .btn-primary {
            background-color: #28a745;
            color: white !important;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white !important;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .materias-container {
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
        }
        .materias-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .materia-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 20px auto;
            overflow: hidden;
            width: 95%;
            max-width: 1000px;
        }
        
        .card-header {
            background-color: #E8F5E9;
            padding: 12px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #333;
            display: flex;
            flex-wrap: wrap; /* permitir que baje si es necesario */
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .curso-count-badge {
            background: #d1e7dd; /* verde suave */
            color: #0f5132;      /* verde oscuro legible */
            border: 1px solid #badbcc;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 0.9rem;
            line-height: 1.4;
            flex-shrink: 0;      /* que no se reduzca el badge */
        }
        
        .materias-lista {
            padding: 0 15px 15px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .materia-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin-bottom: 10px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .materia-nombre {
            font-size: 15px;
            color: #333;
            margin: 0;
            padding: 0 10px 0 0;
            flex-grow: 1;
            line-height: 1.2;
        }
        
        .btn-eliminar {
            background: none;
            border: 1px solid #dc3545;
            color: #dc3545;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            flex-shrink: 0;
        }
        
        .btn-eliminar:hover {
            background-color: #f8d7da;
        }
        
        .materia-actions {
            margin-top: 15px;
            margin-bottom: 15px; /* espacio debajo */
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: center; /* centrar botones */
        }
        .delete-form {
            display: inline-block;
            margin: 0;
            padding: 0;
            line-height: 1;
        }
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        .btn i {
            margin-right: 4px;
        }
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-primary {
            background-color: #4CAF50;
            color: white;
        }
        .btn-edit {
            background-color: #2196F3;
            color: white;
        }
        .btn-delete {
            background-color: #f44336;
            color: white;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background-color: #fefefe;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 5px;
            position: relative;
            color: #000000; /* Ensure text is black */
        }
        .modal-content h2 {
            color: #000000 !important; /* Force black color for all h2 in modals */
            margin-top: 0;
        }
        .modal-content .form-group label,
        .modal-content .form-control,
        .modal-content select,
        .modal-content option {
            color: #000000 !important;
        }
        .modal-content .btn {
            color: #000000 !important;
        }
        .close {
            color: #000000;
            position: absolute;
            right: 15px;
            top: 10px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: #000000;
            text-decoration: none;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .checkbox-group {
            max-height: 200px;
            overflow-y: scroll;
            scrollbar-width: thin;
            scrollbar-color: #888 #f1f1f1;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
        }
        .checkbox-group::-webkit-scrollbar {
            width: 8px;
        }
        .checkbox-group::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .checkbox-group::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .checkbox-group::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .checkbox-item {
            margin-bottom: 8px;
        }
        .materia-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .materia-item:hover {
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .materia-nombre {
            font-size: 16px;
            font-weight: 400; /* sin negrita */
            color: #333;
            padding: 0 5px;
        }
        
        .btn-agregar-container {
            padding: 0 15px 15px;
            width: 100%;
            box-sizing: border-box;
            display: flex;
            justify-content: center;
        }
        
        .btn-agregar-container .btn {
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
            display: block;
        }
        
        .btn-eliminar {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 5px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }
        
        .btn-eliminar:hover {
            background-color: #f8d7da;
        }
        
        .materias-lista {
            margin: 20px auto;
            width: 100%;
            max-width: 800px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .materia-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            width: 100%;
            box-sizing: border-box;
        }
        
        .text-center.mt-3 {
            margin-top: 0 !important; /* Remove top margin */
            padding: 0 15px 15px; /* Add bottom padding instead */
            text-align: center;
            width: 100%;
            box-sizing: border-box;
        }
        
        .text-center.mt-3 .btn {
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
            display: block;
        }
        /* Estilos para el modal de acceso rápido (estilo mismaterias.php) */
        #modalAccesoRapido .modal-content {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 0;
            overflow: hidden;
            max-width: 320px;
        }
        .ar-card-header {
            background: linear-gradient(90deg, #16682b 0%, #28a745 100%);
            padding: 15px;
            color: white;
            text-align: center;
        }
        .ar-card-body { padding: 20px; }
        .ar-title { font-size: 1.2rem; font-weight: 700; margin: 0; }
        .ar-subtitle { font-size: 1rem; opacity: 0.9; margin-top: 5px; }
        .ar-actions { display: flex; flex-direction: column; gap: 10px; }
        .ar-btn {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            padding: 10px; border-radius: 8px; text-decoration: none; color: white !important;
            font-weight: 500; transition: transform 0.2s;
        }
        .ar-btn:hover { transform: scale(1.02); }
        .ar-btn-notas { background: #6c757d; }
        .ar-btn-asist { background: #007bff; }
        .ar-btn-rite  { background: #16682b; }
        .ar-btn-docente { background: #ffc107; color: #000 !important; border: 1px solid #e0a800; margin-top: 5px; }
        .ar-docente-info {
            background: #fff; border: 1px solid #dee2e6; border-radius: 8px;
            padding: 8px; margin-bottom: 10px; font-size: 0.9rem; text-align: left;
        }
        .ar-docente-label { font-weight: bold; color: #666; font-size: 0.75rem; text-transform: uppercase; margin-bottom: 3px; }
        .ar-docente-name { color: #333; font-weight: 600; display: block; }
        .ar-docente-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #eee; }
        .ar-docente-row:last-child { border-bottom: none; }
        .ar-docente-del { color: #dc3545; cursor: pointer; background: none; border: none; font-size: 0.9rem; padding: 0 5px; }
        .ar-add-docente { margin-top: 10px; padding-top: 10px; border-top: 1px solid #dee2e6; }
        .ar-select { width: 100%; padding: 5px; border-radius: 4px; border: 1px solid #ccc; margin-bottom: 5px; font-size: 0.85rem; }
        .ar-btn-add { width: 100%; padding: 5px; background: #28a745; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
        
        .materia-curso-link {
            background: #e9ecef; color: #16682b; border: 1px solid #ced4da;
            padding: 2px 8px; border-radius: 12px; font-size: 12px; cursor: pointer;
            text-decoration: none; display: inline-block; margin-bottom: 4px;
            transition: all 0.2s;
        }
        .materia-curso-link:hover { background: #16682b; color: white; border-color: #16682b; }
        .materia-curso-link:hover { background: #16682b; color: white; border-color: #16682b; }

        .materia-nombre-link {
            color: #16682b;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: color 0.2s;
        }
        .materia-nombre-link:hover {
            color: #28a745;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php include "navbar.php"; ?>
    
    <div class="materias-container">
        <h1>Gestión de Materias</h1>
        
        <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                <a href="menu.php" class="btn btn-secondary" style="text-decoration:none;">
                    ← Volver al Menú
                </a>
                <button class="btn btn-primary" onclick="abrirModalCrear()">
                    <i class="fas fa-plus"></i> Nueva Materia
                </button>
                <button class="btn btn-secondary" onclick="toggleView()">
                    <i class="fas fa-list"></i> Listar por Curso
                </button>
            </div>
        </div>

        <div class="materias-grid" id="materiasGrid" style="<?php echo ($vista_actual === 'cursos') ? 'display: none;' : 'display: grid;' ?>">
            <?php foreach ($materias as $materia): ?>
            <div class="materia-card">
                <div class="card-header" style="padding-bottom: 8px;">
                    <h3 style="width: 100%; white-space: normal; margin-bottom: 2px;">
                        <?php echo htmlspecialchars($materia['nombre_materia']); ?>
                    </h3>
                    <span style="font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; color: #777; align-self: flex-end; opacity: 0.8;">
                        <?php echo htmlspecialchars($materia['tipo'] ?: 'Programática'); ?>
                    </span>
                </div>
                <?php if (!empty($materia['cursos_data'])): ?>
                    <div class="materias-lista">
                        <div style="margin-bottom:6px; font-weight:600; color:#000;">Cursos:</div>
                        <div class="cursos-chips">
                            <?php 
                            $items = explode(', ', $materia['cursos_data']);
                            foreach ($items as $item) {
                                if(strpos($item, ':') !== false) {
                                    list($nombre_c, $id_cm) = explode(':', $item);
                                    echo '<a href="javascript:void(0)" class="materia-curso-link" onclick="abrirAccesoRapido(\''.addslashes($materia['nombre_materia']).'\', \''.addslashes($nombre_c).'\', '.$id_cm.')">' . htmlspecialchars($nombre_c) . '</a> ';
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="materia-actions">
                    <a href="?editar=<?php echo $materia['id_materia']; ?>" class="btn btn-edit">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                    <button type="button" 
                            class="btn btn-delete" 
                            style="margin: 0; padding: 5px 10px; font-size: 12px; line-height: 1.2; background-color: #ff0000; color: white; border: none; cursor: pointer;"
                            onclick="showDeleteModal(<?php echo $materia['id_materia']; ?>, '<?php echo addslashes($materia['nombre_materia']); ?>')">
                        <i class="fas fa-trash"></i> <span>Eliminar</span>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Vista de Cursos -->
        <div id="cursosGrid" class="materias-grid" style="<?php echo ($vista_actual === 'cursos') ? 'display: grid;' : 'display: none;' ?>">
            <?php
            // Agrupar materias por curso
            $cursos_materias = [];
            
            // Primero obtenemos todos los cursos
            $query_cursos = "SELECT c.id_curso, c.nombre_curso 
                           FROM cursos c
                           ORDER BY c.nombre_curso";
            $result_cursos = $conexion->query($query_cursos);
            
            while ($curso = $result_cursos->fetch_assoc()) {
                $cursos_materias[$curso['id_curso']] = [
                    'nombre' => $curso['nombre_curso'],
                    'materias' => []
                ];
                
                // Obtener materias para este curso e id_curso_materia
                $query_materias = "SELECT m.id_materia, m.nombre_materia, m.tipo, cm.id_curso_materia
                                 FROM materias m
                                 JOIN curso_materia cm ON m.id_materia = cm.id_materia
                                 WHERE cm.id_curso = " . $curso['id_curso'] . "
                                 ORDER BY m.nombre_materia";
                $result_materias = $conexion->query($query_materias);
                
                while ($materia = $result_materias->fetch_assoc()) {
                    $cursos_materias[$curso['id_curso']]['materias'][] = $materia;
                }
            }
            
            // Mostrar tarjetas de cursos
            foreach ($cursos_materias as $id_curso => $curso_data): 
                $materias_curso = $curso_data['materias'];
                $nombre_curso = $curso_data['nombre'];
            ?>
                <div class="materia-card">
                    <div class="card-header">
                        <h3>
                            <span>Curso: <?php echo htmlspecialchars($nombre_curso); ?></span>
                            <?php $__cnt = count($materias_curso); ?>
                            <span class="curso-count-badge"><?php echo $__cnt . ' ' . ($__cnt === 1 ? 'materia' : 'materias'); ?></span>
                        </h3>
                    </div>
                    <div class="materias-lista">
                        <?php if (empty($materias_curso)): ?>
                            <p class="text-muted">No hay materias asignadas a este curso.</p>
                        <?php else: ?>
                            <?php foreach ($materias_curso as $materia): ?>
                                <div class="materia-item">
                                    <span class="materia-nombre">
                                        <a href="javascript:void(0)" class="materia-nombre-link" 
                                           onclick="abrirAccesoRapido('<?php echo addslashes($materia['nombre_materia']); ?>', '<?php echo addslashes($nombre_curso); ?>', <?php echo $materia['id_curso_materia']; ?>)">
                                            <?php echo htmlspecialchars($materia['nombre_materia']); ?>
                                        </a>
                                        <span style="font-size: 0.7rem; color: #666; margin-left: 5px;">
                                            (<?php echo htmlspecialchars($materia['tipo'] ?: 'Prog.'); ?>)
                                        </span>
                                    </span>
                                    <button class="btn-eliminar" onclick="confirmarEliminar(<?php echo $materia['id_materia']; ?>, '<?php echo addslashes($materia['nombre_materia']); ?>', <?php echo $id_curso; ?>)" title="Eliminar materia">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="text-center mt-3">
                        <button type="button" class="btn btn-primary" onclick='abrirAsignarMateria(<?php echo $id_curso; ?>, <?php echo json_encode(array_map("intval", array_column($materias_curso, "id_materia"))); ?>)'>
                            <i class="fas fa-plus"></i> Asociar Materia
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal para nueva materia -->
    <div id="nuevaMateriaModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 5px; position: relative;">
            <style>
                #nuevaMateriaModal * {
                    color: #000000 !important;
                    box-sizing: border-box;
                }
                #nuevaMateriaModal .form-group {
                    margin-bottom: 15px;
                }
                #nuevaMateriaModal .form-group label,
                #nuevaMateriaModal .checkbox-item,
                #nuevaMateriaModal h2,
                #nuevaMateriaModal .btn {
                    color: #000000 !important;
                }
                #nuevaMateriaModal .btn i {
                    color: #000000 !important;
                }
            </style>
            <span class="close" onclick="document.getElementById('nuevaMateriaModal').style.display='none';">&times;</span>
            <h2>Nueva Materia</h2>
            <form method="post" action="materias.php">
                <input type="hidden" name="accion" value="crear">
                
                <div class="form-group">
                    <label for="nombre_materia">Nombre de la Materia:</label>
                    <input type="text" id="nombre_materia" name="nombre_materia" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="tipo_materia">Tipo de Materia:</label>
                    <select id="tipo_materia" name="tipo" class="form-control" required>
                        <option value="Programática">Programática</option>
                        <option value="Extraprogramática">Extraprogramática</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Asignar a cursos:</label>
                    <div class="checkbox-group">
                        <?php foreach ($cursos as $curso): ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="curso_<?php echo $curso['id_curso']; ?>_nuevo" 
                                       name="cursos[]" value="<?php echo $curso['id_curso']; ?>">
                                <label for="curso_<?php echo $curso['id_curso']; ?>_nuevo">
                                    <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" 
                            onclick="document.getElementById('nuevaMateriaModal').style.display='none';">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Materia
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para asignar materia existente a curso -->
    <div id="asignarMateriaModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 5px; position: relative;">
            <span class="close" onclick="cerrarModal('asignarMateriaModal')">&times;</span>
            <h2>Asociar Materia al Curso</h2>
            <form id="formAsignarMateria" method="post" action="materias.php">
                <input type="hidden" name="accion" value="agregar_materia_curso">
                <input type="hidden" name="id_curso" id="id_curso_materia">
                
                <style>
                    /* Dropdown múltiple con apariencia de select */
                    .multi-select {
                        position: relative;
                        width: 100%;
                        box-sizing: border-box;
                        margin: 0; /* sin desbordes laterales */
                    }
                    .multi-select-toggle {
                        width: 100%;
                        padding: 8px 10px;
                        border: 1px solid #e2e8f0;
                        border-radius: 6px;
                        background: #fff;
                        cursor: pointer;
                        display: flex; align-items: center; justify-content: space-between;
                        box-sizing: border-box;
                        margin: 0; /* alinear con el contenedor del modal */
                    }
                    .multi-select-menu {
                        position: absolute; left: 0; right: 0; top: calc(100% + 4px);
                        background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
                        box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                        max-height: 220px; overflow-y: auto; z-index: 5000; display: none;
                        box-sizing: border-box; max-width: 100%;
                    }
                    .multi-select.open .multi-select-menu { 
                        display: block; 
                        position: static;        /* deja de ser absoluto para empujar el layout */
                        margin-top: 4px; 
                        max-height: 300px;       /* altura razonable para que sigan viéndose los botones */
                    }
                    .multi-select-search {
                        display: flex;
                        align-items: center;
                        padding: 6px 10px;
                        border-bottom: 1px solid #e9ecef;
                        position: sticky; top: 0; background: #fff; z-index: 1;
                    }
                    .multi-select-search input {
                        width: 100%;
                        padding: 6px 8px; border: 1px solid #e2e8f0;
                        border-radius: 4px; font-size: 13px;
                        box-sizing: border-box;
                    }
                    .multi-select-item { padding: 6px 10px; display: flex; align-items: center; gap: 8px; }
                    .multi-select-item:hover { background: #f8fafc; }
                    .multi-select-count { color: #666; font-size: 0.9em; }
                </style>
                <div class="form-group">
                    <label>Seleccione materias:</label>
                    <div class="multi-select" id="msMaterias">
                        <div class="multi-select-toggle" id="msMateriasToggle">
                            <span id="msMateriasText">-- Seleccione materias --</span>
                            <span style="opacity:.6;">▾</span>
                        </div>
                        <div class="multi-select-menu" id="msMateriasMenu">
                            <div class="multi-select-search"><input type="text" id="msMateriasSearch" placeholder="Buscar materia..." autocomplete="off"></div>
                            <?php 
                            $todas_materias = $conexion->query("SELECT * FROM materias ORDER BY nombre_materia");
                            while($materia = $todas_materias->fetch_assoc()): 
                            ?>
                            <label class="multi-select-item">
                                <input type="checkbox" class="ms-item" value="<?php echo (int)$materia['id_materia']; ?>">
                                <span><?php echo htmlspecialchars($materia['nombre_materia']); ?></span>
                            </label>
                            <?php endwhile; ?>
                        </div>
                        <div id="msMateriasHidden"></div>
                    </div>
                </div>
                
                <div class="form-actions" style="margin-top: 20px; text-align: right;">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('asignarMateriaModal')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Asociar Materia</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmación de eliminación -->
    <div id="confirmDeleteModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 5px; position: relative;">
            <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle" style="color: #ffc107; font-size: 24px; margin-top: 2px;"></i>
                <p id="deleteMessage" style="margin: 0; color: #000; font-size: 16px; line-height: 1.4;"></p>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" id="cancelDeleteBtn" style="padding: 8px 16px;">
                    Cancelar
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" style="padding: 8px 16px;">
                    <i class="fas fa-trash-alt"></i> Eliminar
                </button>
            </div>
        </div>
    </div>

    <script>
    // Multi-select controller for materias in the Assign modal
    (function(){
        const ms = document.getElementById('msMaterias');
        if (!ms) return; // not in this view
        const toggle = document.getElementById('msMateriasToggle');
        const menu   = document.getElementById('msMateriasMenu');
        const text   = document.getElementById('msMateriasText');
        const hidden = document.getElementById('msMateriasHidden');
        const search = document.getElementById('msMateriasSearch');

        function updateTextAndHidden(){
            const checksAll = menu.querySelectorAll('input.ms-item:checked');
            const checksEnabled = menu.querySelectorAll('input.ms-item:checked:not(:disabled)');
            const values = [];
            const labels = [];
            checksEnabled.forEach(chk => {
                values.push(chk.value);
                const label = chk.closest('label');
                const span = label ? label.querySelector('span') : null;
                if (span) labels.push(span.textContent.trim());
            });
            // Update visible text: mostrar vacío si no hay selecciones nuevas (habilitadas)
            if (labels.length === 0) {
                text.textContent = '';
            } else if (labels.length <= 2) {
                text.textContent = labels.join(', ');
            } else {
                text.textContent = labels.slice(0,2).join(', ') + ' +' + (labels.length-2);
            }
            // Rebuild hidden inputs
            hidden.innerHTML = '';
            values.forEach(v => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'id_materias[]';
                inp.value = v;
                hidden.appendChild(inp);
            });
        }

        function openMenu(){ ms.classList.add('open'); }
        function closeMenu(){ ms.classList.remove('open'); }
        function toggleMenu(){ ms.classList.toggle('open'); }

        toggle.addEventListener('click', function(e){ e.preventDefault(); toggleMenu(); });
        menu.addEventListener('change', function(e){ if (e.target.matches('input.ms-item')) updateTextAndHidden(); });
        document.addEventListener('click', function(e){ if (!ms.contains(e.target)) closeMenu(); });

        // Filter items by search text
        function normalize(s){ return (s||'').toLowerCase().normalize('NFD').replace(/\p{Diacritic}+/gu,''); }
        if (search) {
            search.addEventListener('input', function(){
                const q = normalize(search.value.trim());
                const items = menu.querySelectorAll('label.multi-select-item');
                items.forEach(it => {
                    const name = normalize((it.querySelector('span')?.textContent)||'');
                    it.style.display = (!q || name.includes(q)) ? 'flex' : 'none';
                });
            });
        }

        // Expose some helpers globally so other functions can reuse them
        window.__msMaterias = {
            update: updateTextAndHidden,
            reset: function(){
                const inputs = menu.querySelectorAll('input.ms-item');
                inputs.forEach(chk => {
                    chk.disabled = false;
                    chk.checked = false;
                    const label = chk.closest('label');
                    if (label) label.style.opacity = '';
                });
                // dejar texto vacío al resetear
                if (text) text.textContent = '';
                updateTextAndHidden();
            },
            preselectDisable: function(idArray){
                if (!Array.isArray(idArray)) return;
                const set = new Set(idArray.map(v => String(parseInt(v,10))));
                const inputs = menu.querySelectorAll('input.ms-item');
                inputs.forEach(chk => {
                    if (set.has(String(parseInt(chk.value,10)))){
                        chk.checked = true;
                        chk.disabled = true;
                        const label = chk.closest('label');
                        if (label) label.style.opacity = '0.6';
                    }
                });
                updateTextAndHidden();
            }
        };

        // Initialize
        updateTextAndHidden();
    })();
    </script>

    <!-- Modal para crear/editar materia -->
    <div id="modalMateria" class="modal" style="display: <?php echo isset($_GET['editar']) ? 'flex' : 'none'; ?>">
        <div class="modal-content" style="color: #000000;">
        <style>
            #modalMateria * {
                color: #000000 !important;
            }
            #modalMateria .form-group label,
            #modalMateria .checkbox-item,
            #modalMateria h2,
            #modalMateria .btn {
                color: #000000 !important;
            }
            #modalMateria .btn i {
                color: #000000 !important;
            }
        </style>
            <h2><?php echo isset($_GET['editar']) ? 'Editar Materia' : 'Nueva Materia'; ?></h2>
            <form method="post" action="materias.php">
                <input type="hidden" name="accion" value="<?php echo isset($_GET['editar']) ? 'editar' : 'crear'; ?>" autocomplete="off">
                <?php if (isset($_GET['editar'])): ?>
                    <input type="hidden" name="id_materia" value="<?php echo $materia_editar['id_materia']; ?>" autocomplete="off">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="nombre_materia_ed">Nombre de la Materia:</label>
                    <input type="text" id="nombre_materia_ed" name="nombre_materia" class="form-control" 
                           value="<?php echo isset($materia_editar) ? htmlspecialchars($materia_editar['nombre_materia']) : ''; ?>" 
                           autocomplete="subject" required>
                </div>

                <div class="form-group">
                    <label for="tipo_materia_ed">Tipo de Materia:</label>
                    <select id="tipo_materia_ed" name="tipo" class="form-control" required>
                        <option value="Programática" <?php echo (isset($materia_editar) && ($materia_editar['tipo'] ?? '') === 'Programática') ? 'selected' : ''; ?>>Programática</option>
                        <option value="Extraprogramática" <?php echo (isset($materia_editar) && ($materia_editar['tipo'] ?? '') === 'Extraprogramática') ? 'selected' : ''; ?>>Extraprogramática</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Cursos:</label>
                    <div class="checkbox-group">
                        <?php foreach ($cursos as $curso): 
                            $checked = (isset($materia_editar) && strpos(',' . $materia_editar['cursos_ids'] . ',', ',' . $curso['id_curso'] . ',') !== false) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="curso_<?php echo $curso['id_curso']; ?>" 
                                       name="cursos[]" 
                                       value="<?php echo $curso['id_curso']; ?>" 
                                       <?php echo $checked; ?>>
                                <label for="curso_<?php echo $curso['id_curso']; ?>">
                                    <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                    <button type="button" class="btn" onclick="cerrarModal('modalMateria')">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variables globales para almacenar los datos de la acción de eliminación
        let deleteAction = null;
        let deleteParams = null;

        // Función para mostrar el modal de confirmación de eliminación
        function showDeleteModal(id, nombre, id_curso) {
            const modal = document.getElementById('confirmDeleteModal');
            const message = document.getElementById('deleteMessage');
            
            // Determinar si es una desvinculación o una eliminación completa
            const esDesvincular = (id_curso !== undefined && id_curso !== null);
            
            // Configurar el mensaje
            if (esDesvincular) {
                message.textContent = `¿Estás seguro de que deseas desvincular la materia "${nombre}" de este curso?\n\nEsta acción solo eliminará la relación con el curso, no la materia en sí.`;
            } else {
                message.textContent = `¿Estás seguro de eliminar la materia "${nombre}"?\n\nEsta acción eliminará:\n- La materia del sistema\n- Todas las relaciones con cursos\n- Todas las relaciones con docentes\n\nEsta acción NO se puede deshacer.`;
            }
            
            // Mostrar el modal
            modal.style.display = 'block';
            
            // Configurar la acción de eliminación
            deleteAction = function() {
                if (esDesvincular) {
                    // Crear un formulario dinámico para enviar los datos de desvinculación
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'materias.php?vista=cursos';
                    
                    const accionInput = document.createElement('input');
                    accionInput.type = 'hidden';
                    accionInput.name = 'accion';
                    accionInput.value = 'desvincular';
                    
                    const idMateriaInput = document.createElement('input');
                    idMateriaInput.type = 'hidden';
                    idMateriaInput.name = 'id_materia';
                    idMateriaInput.value = id;
                    
                    const idCursoInput = document.createElement('input');
                    idCursoInput.type = 'hidden';
                    idCursoInput.name = 'id_curso';
                    idCursoInput.value = id_curso;
                    
                    const vistaInput = document.createElement('input');
                    vistaInput.type = 'hidden';
                    vistaInput.name = 'vista_actual';
                    vistaInput.value = 'cursos';
                    
                    form.appendChild(accionInput);
                    form.appendChild(idMateriaInput);
                    form.appendChild(idCursoInput);
                    form.appendChild(vistaInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    // Acción para eliminar la materia completamente mediante GET
                    window.location.href = 'materias.php?eliminar=' + id + '&confirmar=1';
                }
            };
            
            // Configurar el botón de confirmación
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            confirmBtn.onclick = function() {
                modal.style.display = 'none';
                deleteAction();
            };
            
            // Configurar el botón de cancelar
            const cancelBtn = document.getElementById('cancelDeleteBtn');
            cancelBtn.onclick = function() {
                modal.style.display = 'none';
            };
            
            // Cerrar el modal al hacer clic fuera del contenido
            window.onclick = function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            };
            
            // Cerrar con la tecla ESC
            document.onkeydown = function(evt) {
                evt = evt || window.event;
                if (evt.key === 'Escape') {
                    modal.style.display = 'none';
                }
            };
        }

        // Función para confirmar la eliminación (mantenida para compatibilidad)
        function confirmarEliminar(id_materia, nombre_materia, id_curso) {
            showDeleteModal(id_materia, nombre_materia, id_curso);
        }
        
        // Función para abrir el modal de creación
        function abrirModalCrear() {
            // Mostrar el modal de nueva materia
            document.getElementById('nuevaMateriaModal').style.display = 'block';
            
            // Limpiar el formulario
            const form = document.querySelector('#nuevaMateriaModal form');
            if (form) {
                form.reset();
            }
        }

        // Función para abrir el modal de asignar materia a curso
        function abrirAsignarMateria(id_curso, materiasAsignadas) {
            // Establecer el ID del curso en el formulario
            document.getElementById('id_curso_materia').value = id_curso;

            // Resetear el selector y preseleccionar/inhabilitar las ya asignadas
            if (window.__msMaterias) {
                window.__msMaterias.reset();
                if (Array.isArray(materiasAsignadas) && materiasAsignadas.length){
                    window.__msMaterias.preselectDisable(materiasAsignadas);
                }
            }

            // Mostrar el modal de asignar materia
            document.getElementById('asignarMateriaModal').style.display = 'block';

            // Actualizar la URL con el ID del curso sin recargar la página
            const url = new URL(window.location);
            url.searchParams.set('id_curso_modal', id_curso);
            window.history.pushState({}, '', url);
        }

        function cerrarModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        // Cerrar modal al hacer clic fuera del contenido
        window.onclick = function(event) {
            const modal = document.getElementById('modalMateria');
            const deleteModal = document.getElementById('confirmDeleteModal');
            
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
        

        // Si hay un error en el formulario, mantener el modal abierto
        <?php if (isset($_POST['accion'])): ?>
            document.getElementById('modalMateria').style.display = 'flex';
        <?php endif; ?>
        // Función para alternar entre vistas
        function toggleView() {
            const gridView = document.getElementById('materiasGrid');
            const cursosView = document.getElementById('cursosGrid');
            const toggleBtn = document.querySelector('button[onclick*="toggleView"]');
            
            if (gridView.style.display === 'none') {
                gridView.style.display = 'grid';
                cursosView.style.display = 'none';
                toggleBtn.innerHTML = '<i class="fas fa-list"></i> Listar por Curso';
                // Actualizar la URL sin recargar la página
                const url = new URL(window.location);
                url.searchParams.set('vista', 'normal');
                window.history.pushState({}, '', url);
            } else {
                gridView.style.display = 'none';
                cursosView.style.display = 'grid';
                toggleBtn.innerHTML = '<i class="fas fa-th"></i> Vista Normal';
                // Actualizar la URL sin recargar la página
                const url = new URL(window.location);
                url.searchParams.set('vista', 'cursos');
                window.history.pushState({}, '', url);
            }
        }
        
        // Al cargar la página, verificar el estado de la vista
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            const vista = urlParams.get('vista');
            
            if (vista === 'cursos' || '<?php echo $vista_actual; ?>' === 'cursos') {
                const materiasGrid = document.getElementById('materiasGrid');
                const cursosGrid = document.getElementById('cursosGrid');
                const toggleBtn = document.querySelector('.btn-secondary[onclick*="toggleView"]');
                
                if (materiasGrid && cursosGrid && toggleBtn) {
                    materiasGrid.style.display = 'none';
                    cursosGrid.style.display = 'grid';
                    toggleBtn.innerHTML = '<i class="fas fa-th"></i> Vista Normal';
                }
            }
        };
        
        // Agregar evento al formulario de agregar materia para mantener la vista
        const formAgregarMateria = document.getElementById('formAgregarMateria');
        if (formAgregarMateria) {
            formAgregarMateria.addEventListener('submit', function(e) {
                const id_curso = document.getElementById('id_curso_materia').value;
                const id_materia = document.getElementById('materia').value;
                
                if (!id_curso || !id_materia) {
                    e.preventDefault();
                    alert('Por favor, complete todos los campos requeridos.');
                    return false;
                }
                
                // Agregar el parámetro de vista al action del formulario
                this.action = `materias.php?vista=cursos`;
                
                return true;
            });
        }
    </script>

    <!-- Modal Acceso Rápido Materia (Estilo mismaterias.php) -->
    <div id="modalAccesoRapido" class="modal">
        <div class="modal-content" style="max-width: 320px !important;">
            <span class="close" style="color:white; z-index:10; position:absolute; right:15px; top:10px;" onclick="cerrarModal('modalAccesoRapido')">&times;</span>
            <div class="ar-card-header">
                <p id="ar-materia" class="ar-title"></p>
                <p id="ar-curso" class="ar-subtitle"></p>
            </div>
            <div class="ar-card-body">
                <div class="ar-actions">
                    <div id="ar-docente-container" class="ar-docente-info">
                        <div class="ar-docente-label">Docentes Asignados:</div>
                        <div id="ar-lista-docentes" style="max-height: 100px; overflow-y: auto; margin-bottom: 5px;">
                            <!-- Se carga dinámicamente -->
                        </div>
                        <div class="ar-add-docente">
                            <select id="ar-select-docente" class="ar-select">
                                <option value="">+ Agregar Docente</option>
                                <?php foreach ($docentes as $d): ?>
                                    <option value="<?php echo $d['id_docente']; ?>"><?php echo htmlspecialchars($d['apellido'].', '.$d['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="ar-btn-add" onclick="agregarDocenteModal()">Asignar</button>
                        </div>
                    </div>
                    <a id="lnk-notas" href="#" class="ar-btn ar-btn-notas"><i class="fa fa-pen"></i> Notas</a>
                    <a id="lnk-asist" href="#" class="ar-btn ar-btn-asist"><i class="fa fa-user-check"></i> Asistencia</a>
                    <a id="lnk-rite"  href="#" class="ar-btn ar-btn-rite"><i class="fa fa-clipboard-list"></i> Acreditación</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para desvincular docente -->
    <div id="confirmDocenteModal" class="modal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 400px; border-radius: 8px; position: relative; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
            <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 24px; margin-top: 2px;"></i>
                <div>
                    <h3 style="margin: 0 0 10px 0; color: #333; font-size: 1.1rem;">Confirmar Acción</h3>
                    <p id="confirmDocenteMessage" style="margin: 0; color: #666; font-size: 0.95rem; line-height: 1.4;">¿Estás seguro de que deseas quitar a este docente de la materia?</p>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('confirmDocenteModal')" style="padding: 6px 12px; font-size: 0.9rem; background-color: #6c757d; color: white !important;">
                    Cancelar
                </button>
                <button type="button" id="btnConfirmarDocente" class="btn" style="padding: 6px 12px; font-size: 0.9rem; background-color: #dc3545; color: white !important;">
                    Quitar Docente
                </button>
            </div>
        </div>
    </div>

    <script>
        let current_id_cm = null;

        async function cargarDocentesModal(id_cm) {
            const container = document.getElementById('ar-lista-docentes');
            container.innerHTML = '<span style="font-size:0.8rem; color:#888;">Cargando...</span>';
            try {
                const response = await fetch('materias_ajax.php?action=get_docentes_full&id_cm=' + id_cm);
                const data = await response.json();
                container.innerHTML = '';
                if (data.ok && data.docentes && data.docentes.length > 0) {
                    data.docentes.forEach((doc, index) => {
                        const div = document.createElement('div');
                        div.className = 'ar-docente-row';
                        div.innerHTML = `
                            <span style="font-size:0.85rem; color:#333;">${index === 0 ? '<b>(T)</b> ' : ''}${doc.nombre}</span>
                            <button type="button" class="ar-docente-del" onclick="quitarDocenteModal(${doc.id_docente})" title="Quitar">
                                <i class="fa fa-times"></i>
                            </button>
                        `;
                        container.appendChild(div);
                    });
                } else {
                    container.innerHTML = '<span style="font-size:0.8rem; color:#888;">Sin docentes asignados</span>';
                }
            } catch (error) {
                container.innerHTML = '<span style="font-size:0.8rem; color:red;">Error al cargar</span>';
            }
        }

        async function agregarDocenteModal() {
            const id_docente = document.getElementById('ar-select-docente').value;
            if (!id_docente || !current_id_cm) return alert('Seleccione un docente');

            const body = new URLSearchParams();
            body.append('accion', 'asignar_materia');
            body.append('id_docente', id_docente);
            body.append('id_curso_materia', current_id_cm);

            try {
                const response = await fetch('docentes_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });
                const data = await response.json();
                if (data.success) {
                    cargarDocentesModal(current_id_cm);
                    document.getElementById('ar-select-docente').value = '';
                } else {
                    alert(data.message);
                }
            } catch (error) { alert('Error al procesar'); }
        }

        async function quitarDocenteModal(id_docente) {
            const modal = document.getElementById('confirmDocenteModal');
            const btnConfirmar = document.getElementById('btnConfirmarDocente');
            
            modal.style.display = 'flex';
            
            btnConfirmar.onclick = async function() {
                modal.style.display = 'none';
                const body = new URLSearchParams();
                body.append('accion', 'quitar_materia');
                body.append('id_docente', id_docente);
                body.append('id_curso_materia', current_id_cm);

                try {
                    const response = await fetch('docentes_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body
                    });
                    const data = await response.json();
                    if (data.success) {
                        cargarDocentesModal(current_id_cm);
                    } else {
                        alert(data.message);
                    }
                } catch (error) { alert('Error al procesar'); }
            };
        }

        async function abrirAccesoRapido(materia, curso, id_cm) {
            current_id_cm = id_cm;
            document.getElementById('ar-materia').textContent = materia;
            document.getElementById('ar-curso').textContent = 'Curso: ' + curso;
            document.getElementById('lnk-notas').href = 'notas_parciales_materia_curso.php?id=' + id_cm;
            document.getElementById('lnk-asist').href = 'asistencias_materia_curso.php?id=' + id_cm;
            document.getElementById('lnk-rite').href  = 'rite_materia_curso.php?id=' + id_cm;
            
            cargarDocentesModal(id_cm);

            document.getElementById('modalAccesoRapido').style.display = 'flex';
        }
    </script>
</body>
</html>