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
$perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1; // 1=alumno, 3=docente

$materias = [];

if ($perfilActual === 3) { // Docente
    $sql = "
        SELECT DISTINCT m.nombre_materia, m.tipo, c.nombre_curso, cm.id_curso_materia
        FROM docentes d
        INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
        INNER JOIN docente_materia dm ON dm.id_docente = d.id_docente
        INNER JOIN curso_materia cm ON cm.id_curso_materia = dm.id_curso_materia
        INNER JOIN cursos c ON c.id_curso = cm.id_curso
        INNER JOIN materias m ON m.id_materia = cm.id_materia
        WHERE u.id_usuario = $idUsuario
        ORDER BY c.nombre_curso, m.nombre_materia
    ";
    $materias = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);
} else if ($perfilActual === 1) { // Alumno
    $sql = "
        SELECT DISTINCT m.nombre_materia, m.tipo, c.nombre_curso, cm.id_curso_materia
        FROM alumnos a
        INNER JOIN usuarios u ON a.id_usuario = u.id_usuario
        INNER JOIN curso_materia cm ON cm.id_curso = a.id_curso
        INNER JOIN cursos c ON c.id_curso = cm.id_curso
        INNER JOIN materias m ON m.id_materia = cm.id_materia
        WHERE u.id_usuario = $idUsuario
        ORDER BY m.nombre_materia
    ";
    $materias = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);
}
 else {
    // Otros perfiles: mostrar vacío por ahora
    $materias = [];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Materias</title>
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
      min-height: 260px;
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
    }
    .card-header .subtitle {
      font-size: 1.1rem;
      font-weight: 500;
    }
    .intensifica-badge {
      background: #ff6b35;
      color: #fff;
      padding: 7px 9px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 600;
      text-decoration: none;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .badge-text {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 0.8rem;
      font-weight: 500;
    }
    .intensifica-badge .count, .recursa-badge .count {
      background: #fff;
      color: #000;
      min-width: 20px;
      height: 20px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: bold;
    }
    .recursa-badge {
      background: #dc3545;
      color: #fff;
      padding: 7px 9px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 600;
      text-decoration: none;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 5px;
    }
    
    /* Cuerpo de la tarjeta */
    .card-body {
      padding: 15px;
    }
    .badge-container {
      margin-top: 10px;
      text-align: center;
    }
    
    .title { 
      font-weight: 700; 
      font-size: 1rem; 
      color: #333; 
      margin: 0 0 6px 0;
    }
    
    .subtitle { 
      font-size: 0.9rem; 
      color: #666; 
      margin: 0 0 15px 0;
    }
    
    /* Acciones en fila */
    .actions { 
      display: flex;
      gap: 7.5px;
      flex-wrap: wrap;
    }
    .btn { 
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 7.5px 12px;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.8rem;
      font-weight: 500;
      border: none;
      transition: all 0.2s ease;
      cursor: pointer;
      flex: 1;
      min-height: 30px;
    }
    .btn:hover {
      transform: scale(1.05);
    }
    .btn-rite { 
      background: #16682b; 
      color: #fff; 
    }
    .btn-rite:hover { 
      background: #0f5132; 
    }
    
    .btn-asist { 
      background: #007bff; 
      color: #fff; 
    }
    .btn-asist:hover { 
      background: #0056b3; 
    }
    
    .btn-notas { 
      background: #6c757d; 
      color: #fff; 
    }
    .btn-notas:hover { 
      background: #5a6268; 
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

  <h1 style="margin: 16px 16px 0;">Mis Materias <i class="fa-solid fa-book-open"></i></h1>
  <p class="perfiles" style="margin: 8px 16px 0;">
    <?php if ($perfilActual === 3): ?>
      Estas son las materias en las que sos docente.
    <?php elseif ($perfilActual === 1): ?>
      Estas son las materias correspondientes a tu curso.
    <?php else: ?>
      No hay materias disponibles para tu perfil actual.
    <?php endif; ?>
  </p>
  <div style="width:90%; margin:12px auto 0; display:flex; align-items:center; gap:10px;">
    <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
      <a href="menu.php" style="text-decoration:none;">
        <button style="padding:10px 20px; border-radius:8px; background:#6c757d; color:#fff; border:none; cursor:pointer;">← Volver al Menú</button>
      </a>
    </div>
  </div>

  <?php if (empty($materias)): ?>
    <div class="empty">
      <i class="fa fa-circle-info"></i> No se encontraron materias para tu perfil actual.
    </div>
  <?php else: ?>
    <div class="cards-container">
      <?php foreach ($materias as $m): ?>
        <?php 
          $idCM = intval($m['id_curso_materia']);
          // Intensifican (estados 3 y 4)
          $qInt = $conexion->query("SELECT COUNT(*) AS c FROM notas WHERE id_curso_materia = $idCM AND id_estado IN (3, 4)");
          $cInt = ($qInt && $row = $qInt->fetch_assoc()) ? intval($row['c']) : 0;
          // Recursan (estado 2)
          $qRec = $conexion->query("SELECT COUNT(*) AS c FROM notas WHERE id_curso_materia = $idCM AND id_estado = 2");
          $cRec = ($qRec && $row = $qRec->fetch_assoc()) ? intval($row['c']) : 0;
        ?>
        <div class="card">
          <div class="card-header">
            <div class="header-content">
              <p class="title"><?php echo htmlspecialchars($m['nombre_materia']); ?></p>
              <p class="subtitle" style="font-size: 0.8rem; opacity: 0.8; margin-bottom: 4px;">
                <i class="fa fa-tag"></i> <?php echo htmlspecialchars($m['tipo'] ?: 'Programática'); ?>
              </p>
              <p class="subtitle">Curso: <?php echo htmlspecialchars($m['nombre_curso']); ?></p>
            </div>
          </div>
          <div class="card-body">
            <div class="actions">
              <a class="btn btn-notas" href="notas_parciales_materia_curso.php?id=<?php echo $idCM; ?>" title="Cargar Notas">
                <i class="fa fa-pen"></i> Notas
              </a>
              <a class="btn btn-asist" href="asistencias_materia_curso.php?id=<?php echo $idCM; ?>" title="Tomar Asistencia">
                <i class="fa fa-user-check"></i> Asistencia
              </a>
              <a class="btn btn-rite" href="rite_materia_curso.php?id=<?php echo $idCM; ?>" title="Acreditación">
                <i class="fa fa-clipboard-list"></i> Acreditación
              </a>
            </div>
            <div class="badge-container">
              <?php if ($cRec > 0): ?>
                <a class="recursa-badge" href="rite_materia_curso.php?id=<?php echo $idCM; ?>" title="Alumnos Recursantes (<?php echo $cRec; ?>)" >
                  <span class="badge-text">
                    <i class="fa fa-rotate-right"></i>
                    Recursan
                  </span>
                  <span class="count"><?php echo $cRec; ?></span>
                </a>
              <?php endif; ?>
              <?php if ($cInt > 0): ?>
                <a class="intensifica-badge" href="notas_intensificacion.php?id=<?php echo $idCM; ?>" title="Notas de Intensificación (<?php echo $cInt; ?>)" >
                  <span class="badge-text">
                    <i class="fa fa-fire"></i>
                    Intensifican
                  </span>
                  <span class="count"><?php echo $cInt; ?></span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</body>
</html>
