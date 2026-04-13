<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

// Diccionario de nombres de perfiles
$nombresPerfiles = [
    1 => 'Alumno',
    2 => 'Tutor',
    3 => 'Docente',
    4 => 'Administrador'
];

// Perfiles activos de la sesión
$perfilesActivos = [];
if (isset($_SESSION['perfiles']) && is_array($_SESSION['perfiles'])) {
    foreach ($_SESSION['perfiles'] as $idPerfil) {
        if (isset($nombresPerfiles[$idPerfil])) {
            $perfilesActivos[$idPerfil] = $nombresPerfiles[$idPerfil];
        }
    }
}

// Si el usuario envió el formulario, guardamos el perfil elegido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perfil'])) {
    $perfilSeleccionado = intval($_POST['perfil']);
    if (array_key_exists($perfilSeleccionado, $perfilesActivos)) {
        $_SESSION['perfil_actual'] = $perfilSeleccionado;
    }
    header("Location: menu.php"); // volver al menú principal
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar de Perfil</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: Segoe UI, Tahoma, sans-serif;
            background: linear-gradient(to right, #141e30, #243b55);
            color: #fff;
            margin: 0;
            padding: 0;
            text-align: center;
        }
        .container {
            margin-top: 100px;
            background: rgba(255,255,255,0.05);
            padding: 30px;
            border-radius: 15px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            box-shadow: 0 6px 15px rgba(0,0,0,0.4);
        }
        h1 {
            margin-bottom: 20px;
            font-weight: 400;
        }
        select {
            padding: 10px;
            font-size: 16px;
            border-radius: 8px;
            border: none;
            width: 100%;
            margin-bottom: 20px;
        }
        button {
            padding: 12px 20px;
            font-size: 16px;
            border: none;
            border-radius: 8px;
            background: #00aaff;
            color: #fff;
            cursor: pointer;
            transition: 0.3s;
            width: 100%;
        }
        button:hover {
            background: #0077cc;
        }
        a {
            display: block;
            margin-top: 15px;
            color: #ccc;
            text-decoration: none;
        }
        a:hover {
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fa-solid fa-repeat"></i> Cambiar de Perfil</h1>
        <form method="post">
            <select name="perfil" required>
                <option value="">-- Selecciona un perfil --</option>
                <?php foreach ($perfilesActivos as $id => $nombre): ?>
                    <option value="<?php echo $id; ?>" 
                        <?php echo (isset($_SESSION['perfil_actual']) && $_SESSION['perfil_actual'] == $id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($nombre); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><i class="fa-solid fa-check"></i> Confirmar</button>
        </form>
        <a href="menu.php"><i class="fa-solid fa-arrow-left"></i> Volver al menú</a>
    </div>
</body>
</html>
