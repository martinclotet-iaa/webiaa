<?php
// Guardia de acceso dinámico basado en los menús por perfil
// Incluir en el tope de cada página visible para usuarios, luego de session_start()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config_menus.php';

// Páginas a las que siempre se puede acceder estando logueado
$siemprePermitidas = [
    'menu.php',
    'logout.php',
    'mismaterias.php',
    'miscursos.php',
    'rite_materia_curso.php',
    'asistencias_materia_curso.php',
    'asistencias_curso.php',
    'asistencias_curso_ajax.php',
    'notas_parciales_materia_curso.php',
    'notas_intensificacion.php',
    'inasistencias_curso.php',
    'asistencias_ajax.php',
    'notas_ajax.php',
    'rite_guardar_ajax.php',
    'usuarios.php',
    'usuarios_nuevo.php',
    'docente_materia.php',
    'materias_ajax.php',
    // index.php y login.php se manejan antes del login; si llegan aquí ya están logueados
];

// Determinar el archivo actual (nombre base)
$archivoActual = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Si es una página siempre permitida, no validar contra menús
if (in_array($archivoActual, $siemprePermitidas, true)) {
    return; // permitir
}

// Endpoints exclusivos para Administrador (4) y Preceptor (5)
$soloAdminPreceptor = ['recursantes.php','intensifican.php'];
if (in_array($archivoActual, $soloAdminPreceptor, true)) {
    $perfilActual = isset($_SESSION['perfil_actual']) ? intval($_SESSION['perfil_actual']) : 0;
    if (!in_array($perfilActual, [4,5], true)) {
      header('Location: menu.php');
      exit;
    }
}

// Construir el conjunto de rutas permitidas según los perfiles del usuario
$perfilesUsuario = isset($_SESSION['perfiles']) && is_array($_SESSION['perfiles'])
    ? $_SESSION['perfiles']
    : [];

$permitidos = [];
foreach ($perfilesUsuario as $perfilId) {
    if (!empty($menus[$perfilId]) && is_array($menus[$perfilId])) {
        foreach ($menus[$perfilId] as $item) {
            // Estructura: [texto, icono, ruta]
            if (isset($item[2]) && is_string($item[2])) {
                $permitidos[] = basename($item[2]);
            }
        }
    }
}

// Evitar duplicados
$permitidos = array_values(array_unique($permitidos));

// Validar acceso
if (!in_array($archivoActual, $permitidos, true)) {
    // Redirigir a menú si la página no está permitida por los perfiles del usuario
    header('Location: menu.php');
    exit;
}
