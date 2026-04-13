<?php
// Redirección permanente a la nueva ubicación RITE
if (isset($_GET['id'])) {
    header('Location: rite_materia_curso.php?id=' . intval($_GET['id']), true, 301);
} else {
    header('Location: rite_materia_curso.php', true, 301);
}
exit;
