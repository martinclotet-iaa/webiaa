<?php
require_once __DIR__ . '/conexion.php';

echo "<h2>Estructura de la tabla 'intensificaciones'</h2>";

// Verificar si la tabla existe
$result = $conexion->query("SHOW TABLES LIKE 'intensificaciones'");
if ($result->num_rows == 0) {
    echo "<p style='color:red;'>❌ La tabla 'intensificaciones' NO existe en la base de datos.</p>";
    exit;
}

echo "<p style='color:green;'>✅ La tabla 'intensificaciones' existe.</p>";

// Obtener estructura de la tabla
echo "<h3>Columnas:</h3>";
$columns = $conexion->query("DESCRIBE intensificaciones");
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Clave</th><th>Default</th><th>Extra</th></tr>";
while ($col = $columns->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$col['Field']}</td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "<td>{$col['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// Obtener claves foráneas
echo "<h3>Claves Foráneas:</h3>";
$fks = $conexion->query("
    SELECT 
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'colegio'
    AND TABLE_NAME = 'intensificaciones'
    AND REFERENCED_TABLE_NAME IS NOT NULL
");

if ($fks->num_rows > 0) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Constraint</th><th>Columna</th><th>Referencia Tabla</th><th>Referencia Columna</th></tr>";
    while ($fk = $fks->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$fk['CONSTRAINT_NAME']}</td>";
        echo "<td>{$fk['COLUMN_NAME']}</td>";
        echo "<td>{$fk['REFERENCED_TABLE_NAME']}</td>";
        echo "<td>{$fk['REFERENCED_COLUMN_NAME']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange;'>⚠️ No se encontraron claves foráneas.</p>";
}

// Obtener índices
echo "<h3>Índices:</h3>";
$indexes = $conexion->query("SHOW INDEX FROM intensificaciones");
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Nombre</th><th>Columna</th><th>Único</th><th>Tipo</th></tr>";
while ($idx = $indexes->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$idx['Key_name']}</td>";
    echo "<td>{$idx['Column_name']}</td>";
    echo "<td>" . ($idx['Non_unique'] == 0 ? 'Sí' : 'No') . "</td>";
    echo "<td>{$idx['Index_type']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<br><p><a href='javascript:history.back()'>← Volver</a></p>";
?>
