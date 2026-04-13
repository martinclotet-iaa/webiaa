<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

// Guardia de acceso dinámico según menú
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config_menus.php';

$idUsuario = intval($_SESSION['id_usuario']);
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1; 

$cursos = [];

if ($perfilActual === 5) { // Preceptor
    // Detectar si existe col legacy id_preceptor 
    $hasPreceptorCol = false;
    if ($rs = $conexion->query("SHOW COLUMNS FROM cursos LIKE 'id_preceptor'")) { 
        $hasPreceptorCol = ($rs->num_rows > 0); 
    }
    
    $filtro = "cp.id_preceptor = $idUsuario";
    if ($hasPreceptorCol) {
        $filtro = "(cp.id_preceptor = $idUsuario OR c.id_preceptor = $idUsuario)";
    }
    
    $sql = "
        SELECT c.id_curso, c.nombre_curso
        FROM cursos c
        LEFT JOIN curso_preceptor cp ON c.id_curso = cp.id_curso
        WHERE $filtro
        GROUP BY c.id_curso
        ORDER BY c.nombre_curso
    ";
    $cursos = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);
} else {
    // Otros perfiles: mostrar vacío por ahora
    $cursos = [];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Cursos</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
  <style>
    /* Contenedor de tarjetas */
    .cards-container { 
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 15px;
      padding: 20px;
    }
    
    /* Tarjeta estilo moderno */
    .card { 
      background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
      border-radius: 12px;
      border: 1px solid #e9ecef;
      box-shadow: 0 4px 18px rgba(0,0,0,0.1);
      overflow: hidden;
      transition: all 0.3s ease;
      width: 262px;
      min-height: 220px;
      display: flex;
      flex-direction: column;
    }
    .card:hover { 
      transform: translateY(-4px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    }
    
    /* Header con gradiente */
    .card-header {
      background: linear-gradient(90deg, #16682b 0%, #28a745 100%);
      padding: 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }
    .header-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      text-align: center;
    }
    .card-header .title, .card-header .subtitle {
      color: #fff;
      margin: 0;
    }
    .card-header .title {
      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 4px;
      padding: 10px 0;
    }
    
    /* Cuerpo de la tarjeta */
    .card-body {
      padding: 15px;
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 10px;
    }
    
    .btn { 
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 10px 12px;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 600;
      border: none;
      transition: all 0.2s ease;
      cursor: pointer;
      width: 100%;
      box-sizing: border-box;
    }
    .btn:hover {
      transform: scale(1.03);
    }
    .btn-asist { 
      background: #007bff; 
      color: #fff; 
    }
    .btn-asist:hover { 
      background: #0056b3; 
    }
    
    .btn-alumnos {
      background: #ffc107;
      color: #000;
    }
    .btn-alumnos:hover {
      background: #e0a800;
    }
    
    .btn-rite {
      background: #6f42c1;
      color: #fff;
    }
    .btn-rite:hover {
      background: #59359a;
    }
    
    .btn-inasist { 
      background: #dc3545; 
      color: #fff; 
    }
    .btn-inasist:hover { 
      background: #c82333; 
    }
    
    .empty { 
      color: #6c757d; 
      text-align: center; 
      padding: 30px; 
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <h1 style="margin: 16px 16px 0;">Mis Cursos <i class="fa-solid fa-chalkboard"></i></h1>
  <p class="perfiles" style="margin: 8px 16px 0;">
    <?php if ($perfilActual === 5): ?>
      Estos son los cursos que tenés a cargo como preceptor.
    <?php else: ?>
      No hay cursos disponibles para tu perfil actual.
    <?php endif; ?>
  </p>
  <div style="width:90%; margin:12px auto 0; display:flex; align-items:center; gap:10px;">
    <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
      <a href="menu.php" style="text-decoration:none;">
        <button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Volver al Menú</button>
      </a>
    </div>
  </div>

  <?php if (empty($cursos)): ?>
    <div class="empty">
      <i class="fa fa-circle-info"></i> No se encontraron cursos asignados para tu perfil actual.
    </div>
  <?php else: ?>
    <div class="cards-container">
      <?php foreach ($cursos as $c): ?>
        <?php $idC = intval($c['id_curso']); ?>
        <div class="card">
          <div class="card-header">
            <div class="header-content">
              <p class="title"><?php echo htmlspecialchars($c['nombre_curso']); ?></p>
            </div>
          </div>
          <div class="card-body">
            <a class="btn btn-asist" href="asistencias_curso.php?id=<?php echo $idC; ?>" title="Tomar Asistencia Diaria">
              <i class="fa fa-user-check"></i> Asistencia Diaria
            </a>
            <a class="btn btn-inasist" href="inasistencias_curso.php?id=<?php echo $idC; ?>" title="Cargar Inasistencias Globales">
              <i class="fa fa-calendar-times"></i> Inasistencias Globales
            </a>
            <a class="btn btn-alumnos" href="alumnos.php?curso=<?php echo $idC; ?>&from=miscursos" title="Ver Alumnos del Curso">
              <i class="fa fa-users"></i> Alumnos
            </a>
            <a class="btn btn-rite" href="rite_alumno.php?id_curso=<?php echo $idC; ?>" title="Ver RITE de Alumnos">
              <i class="fa fa-id-card"></i> RITE
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</body>
</html>