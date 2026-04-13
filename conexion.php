<?php


$servername = "localhost";
$username = "root";
$password = "usbw";
$dbname = "colegio";

// Create connection
$conexion = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conexion->connect_error) {
  die("Connection failed: " . $conexion->connect_error);
}

mysqli_set_charset($conexion, 'utf8mb4'); // Usar UTF-8 completo