<?php
// Configuración centralizada de perfiles y menús accesibles por perfil
// Este archivo NO debe producir salida HTML.

// Perfiles con nombres e íconos
$nombresPerfiles = array(
    1 => array("Alumno", "fa-user-graduate"),
    2 => array("Tutor", "fa-user-friends"),
    3 => array("Docente", "fa-chalkboard-teacher"),
    4 => array("Administrador", "fa-user-shield"),
    5 => array("Preceptor", "fa-user-check")
);

// Menús por perfil (texto, icono, ruta)
$menus = array(
    1 => array(
        array("Clases","fa-book","clases.php"),
        array("Rite","fa-graduation-cap","rite_alumno.php"),
        array("Mensajes","fa-envelope","mensajes.php"),
        array("Mis Materias","fa-book-open","mismaterias.php")
    ),
    2 => array(
        array("Hijos","fa-child","hijos.php"),
        array("Calificaciones","fa-star","calificaciones.php"),
    ),
    3 => array(
        array("Mis Materias","fa-chalkboard-teacher","mismaterias.php"),
        array("Alumnos","fa-user-graduate","alumnos.php"),
        array("Material","fa-folder-open","material.php")
    ),
    4 => array(
        array("Usuarios","fa-users","usuarios.php"),
        array("Alumnos","fa-graduation-cap","alumnos.php"),
        array("Tutores","fa-user-tie","tutores.php"),
        array("Docentes","fa-chalkboard-teacher","docentes.php"),
        array("Preceptores","fa-user-check","preceptores.php"),
        array("Materias","fa-book-open","materias.php"),
        array("Cursos","fa-chalkboard","cursos.php"),
        array("Rite","fa-chart-bar","rite_alumno.php"),
        array("Recursantes","fa-arrows-rotate","recursantes.php"),
        array("Intensifican","fa-fire","intensifican.php"),
        array("Configuración","fa-cogs","config.php")
    ),
    5 => array(
        // Menú para preceptores
        array("Mis Cursos","fa-chalkboard","miscursos.php"),
        array("Alumnos","fa-user-graduate","alumnos.php"),
        array("Rite","fa-graduation-cap","rite_alumno.php"),
        array("Recursantes","fa-arrows-rotate","recursantes.php"),
        array("Intensifican","fa-fire","intensifican.php")
    )
);

