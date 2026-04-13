<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/config_menus.php';

// Perfil activo actual (debe estar seteado en el login/cambio de perfil)
$perfilActivo = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 1;




?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Menú</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="estilos.css"> 

</head>
<body>
<?php include "navbar.php"; ?>

<h1>Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre'].' '.$_SESSION['apellido']); ?> <i class="fas fa-hand-paper"></i></h1>
<p class="perfiles">Estas en el menu principal de la plataforma </p>

<div id="gridMenu" class="menu-container">
<?php
if (isset($menus[$perfilActivo])) {
    $items = $menus[$perfilActivo];
    // Mover cualquier ítem cuyo título sea "Configuración" (o entidad equivalente) al final
    $end = [];
    $rest = [];
    foreach ($items as $it) {
        $titulo = isset($it[0]) ? $it[0] : '';
        $isConfig = (strcasecmp($titulo, 'Configuración') === 0) || (strcasecmp($titulo, 'Configuraci&oacute;n') === 0);
        if ($isConfig) { $end[] = $it; } else { $rest[] = $it; }
    }
    $items = array_merge($rest, $end);

    // Render seguro con escape
    foreach ($items as $item) {
        $titulo = htmlspecialchars($item[0] ?? '', ENT_QUOTES, 'UTF-8');
        $icon   = htmlspecialchars($item[1] ?? '', ENT_QUOTES, 'UTF-8');
        $href   = htmlspecialchars($item[2] ?? '#', ENT_QUOTES, 'UTF-8');
        echo "<a href='".$href."'><div class='menu-card'><i class='fa-solid ".$icon."'></i><p>".$titulo."</p></div></a>";
    }
}
?>
</div>

</body>
</html>
