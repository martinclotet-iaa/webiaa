<?php
session_start();
require_once "conexion.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $conexion->real_escape_string($_POST['email']);
    $clave = $_POST['clave'];

    // Buscar usuario y sus perfiles
    $sql = "SELECT u.id_usuario, u.nombre, u.apellido, u.clave, p.id_perfil, p.nombre_perfil
            FROM usuarios u
            LEFT JOIN usuario_perfil up ON u.id_usuario = up.id_usuario
            LEFT JOIN perfiles p ON up.id_perfil = p.id_perfil
            WHERE u.email = '$email'";

    $result = $conexion->query($sql);

    if ($result && $result->num_rows > 0) {
        $perfiles = [];
        $row0 = null;

        while ($row = $result->fetch_assoc()) {
            if (!$row0) { // primera fila → datos base del usuario
                $row0 = $row;
            }
            if (!empty($row['id_perfil'])) {
                $perfiles[] = (int)$row['id_perfil'];
            }
        }

        // Verificar clave (⚠️ si usás hash, reemplazar con password_verify)
        if ($row0['clave'] === $clave) {
            $_SESSION['id_usuario'] = $row0['id_usuario'];
            $_SESSION['perfiles']   = $perfiles; // guarda todos los perfiles
            $_SESSION['nombre']     = $row0['nombre'];
            $_SESSION['apellido']   = $row0['apellido'];
            $_SESSION['email']      = $email;

            header("Location: menu.php");
            exit;
        } else {
            header("Location: index.php?error=Clave incorrecta");
            exit;
        }
    } else {
        header("Location: index.php?error=Usuario no encontrado");
        exit;
    }
} else {
    header("Location: index.php");
    exit;
}
?>

