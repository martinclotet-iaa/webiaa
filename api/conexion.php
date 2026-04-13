<?php
// Configuración de la base de datos usando variables de entorno
// En local usa los valores por defecto del USBWebserver
$servername = getenv('DB_HOST') ?: "localhost";
$username   = getenv('DB_USER') ?: "root";
$password   = getenv('DB_PASS') ?: "usbw";
$dbname     = getenv('DB_NAME') ?: "colegio";

// Crear conexión
$conexion = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conexion->connect_error) {
    die("Connection failed: " . $conexion->connect_error);
}

// Usar UTF-8 completo
mysqli_set_charset($conexion, 'utf8mb4');