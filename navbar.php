<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "conexion.php";
require_once __DIR__ . '/config_menus.php';

// Procesar actualización de datos del usuario
if (isset($_POST['accion']) && $_POST['accion'] == 'editar_usuario') {
    $id_usuario = $_SESSION['id_usuario'];
    $nombre = $conexion->real_escape_string($_POST['nombre']);
    $apellido = $conexion->real_escape_string($_POST['apellido']);
    $clave = $_POST['clave'];
    
    // Procesar la carga de la imagen
    if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] == 0) {
        $foto = $_FILES['foto_perfil'];
        $extension = strtolower(pathinfo($foto['name'], PATHINFO_EXTENSION));
        $extensionesPermitidas = array('jpg', 'jpeg', 'png', 'gif');
        
        if (in_array($extension, $extensionesPermitidas)) {
            $rutaFoto = 'fotos/' . $id_usuario . '.jpg';
            
            // Mover el archivo subido a la carpeta de fotos
            if (move_uploaded_file($foto['tmp_name'], $rutaFoto)) {
                // El archivo ya viene redimensionado del lado del cliente a 200x200
                // Verificar que el archivo no exceda 1MB
                if (filesize($rutaFoto) > 1048576) {
                    // Si es muy grande, intentamos reducir la calidad
                    $imagen = imagecreatefromjpeg($rutaFoto);
                    if ($imagen !== false) {
                        imagejpeg($imagen, $rutaFoto, 70); // Reducir calidad al 70%
                        imagedestroy($imagen);
                    }
                }
            }
        }
    }
    
    // Actualizar los datos del usuario
    if (!empty($clave)) {
        $clave = $conexion->real_escape_string($clave);
        $result = $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido', clave='$clave' WHERE id_usuario=$id_usuario");
    } else {
        $result = $conexion->query("UPDATE usuarios SET nombre='$nombre', apellido='$apellido' WHERE id_usuario=$id_usuario");
    }
    
    if ($result) {
        // Actualizar datos de sesión
        $_SESSION['nombre'] = $nombre;
        $_SESSION['apellido'] = $apellido;
        
        // Forzar la actualización de la variable $fotoUsuario
        $fotoUsuario = "fotos/" . $id_usuario . ".jpg";
        if(!file_exists($fotoUsuario)) $fotoUsuario = "fotos/default.jpg";
        $fotoUsuario .= '?' . time();
        
        echo "<script>
            // Actualizar la foto en el menú superior
            var imgElement = document.getElementById('usuarioAvatar');
            if (imgElement) {
                imgElement.src = '$fotoUsuario';
            }
            // Cerrar el modal
            cerrarModalEditarUsuario();
            // Recargar la página después de un pequeño retraso
            setTimeout(function() {
                window.location.reload();
            }, 300);
        </script>";
    } else {
        echo "<script>alert('Error al actualizar los datos: " . $conexion->error . "');</script>";
    }
}

// $nombresPerfiles y $menus ahora provienen de config_menus.php

$fotoUsuario = "fotos/".$_SESSION['id_usuario'].".jpg";
if(!file_exists($fotoUsuario)) {
    $fotoUsuario = "fotos/0.jpg";
    // Si no existe 0.jpg, intentamos crearla
    if(!file_exists($fotoUsuario)) {
        // Crear una imagen simple de 200x200 píxeles
        $imagen = imagecreatetruecolor(200, 200);
        $fondo = imagecolorallocate($imagen, 230, 230, 230);
        $texto = imagecolorallocate($imagen, 150, 150, 150);
        imagefill($imagen, 0, 0, $fondo);
        $texto_str = "SIN FOTO";
        $fuente = 5;
        $x = (200 - (imagefontwidth($fuente) * strlen($texto_str))) / 2;
        $y = 90;
        imagestring($imagen, $fuente, $x, $y, $texto_str, $texto);
        imagejpeg($imagen, $fotoUsuario, 90);
        imagedestroy($imagen);
    }
}

if(!isset($_SESSION['perfil_actual'])) {
    $_SESSION['perfil_actual'] = $_SESSION['perfiles'][0] ?? 1;
}
$perfilActivo = $_SESSION['perfil_actual'];


$fotoUsuario = "fotos/".$_SESSION['id_usuario'].".jpg";
if(!file_exists($fotoUsuario)) {
    $fotoUsuario = "fotos/0.jpg";
    // Si no existe 0.jpg, intentamos crearla
    if(!file_exists($fotoUsuario)) {
        // Crear una imagen simple de 200x200 píxeles
        $imagen = imagecreatetruecolor(200, 200);
        $fondo = imagecolorallocate($imagen, 230, 230, 230);
        $texto = imagecolorallocate($imagen, 150, 150, 150);
        imagefill($imagen, 0, 0, $fondo);
        $texto_str = "SIN FOTO";
        $fuente = 5;
        $x = (200 - (imagefontwidth($fuente) * strlen($texto_str))) / 2;
        $y = 90;
        imagestring($imagen, $fuente, $x, $y, $texto_str, $texto);
        imagejpeg($imagen, $fotoUsuario, 90);
        imagedestroy($imagen);
    }
}

if(!isset($_SESSION['perfil_actual'])) {
    $_SESSION['perfil_actual'] = $_SESSION['perfiles'][0];
}
$perfilActivo = $_SESSION['perfil_actual'];
?>

<div class="header">
    <div class="logo-box">
        <img src="fotos/logo.png" class="logo" alt="Logo Colegio">
        <span class="nombre-colegio">Instituto Adolfo Alsina</span>
    </div>
    <div class="user-menu">
        <div class="user-info">
            <div class="nombre"><?php echo htmlspecialchars($_SESSION['nombre'].' '.$_SESSION['apellido']); ?></div>
            <?php if(count($_SESSION['perfiles'])>1): ?>
                <div class="perfil-actual" onclick="togglePerfilDropdown()">
                    <span id="perfilActual"><?php echo $nombresPerfiles[$perfilActivo][0]; ?></span>
                    <i class="fa-solid fa-caret-down"></i>
                </div>
                <div id="dropdownPerfil" class="dropdown-perfil">
                    <?php foreach($_SESSION['perfiles'] as $p): ?>
                        <div onclick="cambiarPerfil('<?php echo $p;?>')">
                            <span><?php echo $nombresPerfiles[$p][0];?></span>
                            <i class="fa-solid <?php echo $nombresPerfiles[$p][1];?>"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="perfil-actual"><?php echo $nombresPerfiles[$perfilActivo][0]; ?></div>
            <?php endif; ?>
        </div>
        <img src="<?php echo $fotoUsuario . '?' . time(); ?>" class="usuario" alt="Usuario" onclick="toggleDropdown()" id="usuarioAvatar">
        <div id="dropdownMenu" class="dropdown">
            <a href="menu.php"><i class="fa-solid fa-bars"></i> Menu</a>
            <a href="#" onclick="abrirModalEditarUsuario()"><i class="fa-solid fa-user-pen"></i> Editar Usuario</a>
            <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
        </div>
    </div>
</div>

<div class="datetime" id="datetime"></div>

<!-- Modal Editar Usuario -->
<div id="modalEditarUsuario" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <span class="cerrar" onclick="cerrarModalEditarUsuario()">&times;</span>
    <h3>Editar Mis Datos</h3>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="editar_usuario">
        
        <div class="foto-perfil-container" style="text-align: center; margin-bottom: 20px;">
            <label for="fotoPerfil" style="cursor: pointer;">
                <img id="previewFoto" src="<?php echo $fotoUsuario . '?' . time(); ?>" alt="Foto de perfil" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid #4a90e2; margin-bottom: 10px;">
                <div style="color: #4a90e2; font-size: 14px;">Haz clic para cambiar la foto</div>
            </label>
            <input type="file" id="fotoPerfil" name="foto_perfil" accept="image/*" style="display: none;" onchange="previewImage(this)">
        </div>
        
        <input type="text" name="nombre" value="<?php echo htmlspecialchars($_SESSION['nombre'] ?? ''); ?>" placeholder="Nombre" required>
        <input type="text" name="apellido" value="<?php echo htmlspecialchars($_SESSION['apellido'] ?? ''); ?>" placeholder="Apellido" required>
        <input type="email" name="email" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" placeholder="Email" readonly style="background: rgba(255,255,255,0.5); cursor: not-allowed;">
        <input type="password" name="clave" placeholder="Nueva Contraseña (opcional)">
        <button type="submit">Guardar Cambios</button>
    </form>
  </div>
</div>

<script>  
function cambiarPerfil(perfil){
    // Feedback inmediato: actualizar el nombre del perfil visible
    try {
        if (typeof perfiles !== 'undefined' && perfiles[perfil] && perfiles[perfil][0]) {
            var span = document.getElementById('perfilActual');
            if (span) span.textContent = perfiles[perfil][0];
        }
    } catch (e) {}

    // Ocultar el dropdown inmediatamente para feedback visual
    var perfilMenu = document.getElementById('dropdownPerfil');
    if (perfilMenu) perfilMenu.style.display = 'none';

    // Llamada para cambiar el perfil en la sesión
    var xhr = new XMLHttpRequest();
    xhr.open("POST","cambiar_perfil_ajax.php",true);
    xhr.setRequestHeader("Content-type","application/x-www-form-urlencoded");
    xhr.timeout = 4000; // 4s de tolerancia
    var navigated = false;
    function maybeNavigate(){
        if(navigated) return;
        var grid = document.getElementById('gridMenu');
        if (grid && typeof MENUS !== 'undefined' && MENUS[perfil]){
            // Estamos en menu.php: reconstruir el grid en caliente, sin navegar
            try{
                grid.innerHTML = '';
                MENUS[perfil].forEach(function(item){
                    var a = document.createElement('a'); a.href = item[2];
                    var card = document.createElement('div'); card.className = 'menu-card';
                    var i = document.createElement('i'); i.className = 'fa-solid ' + item[1];
                    var p = document.createElement('p'); p.textContent = item[0];
                    card.appendChild(i); card.appendChild(p); a.appendChild(card);
                    grid.appendChild(a);
                });
            }catch(e){}
            navigated = true; // marcar como resuelto sin navegación
        } else {
            // No estamos en menu.php: navegar para refrescar
            window.location.href = 'menu.php';
            navigated = true;
        }
    }
    xhr.onreadystatechange=function(){ if(xhr.readyState===4){ maybeNavigate(); } };
    xhr.onerror = maybeNavigate;
    xhr.ontimeout = maybeNavigate;
    xhr.send("perfil="+encodeURIComponent(perfil));
    // Fallback por si nada de lo anterior dispara
    setTimeout(maybeNavigate, 600);
}

var perfiles = <?php echo json_encode($nombresPerfiles); ?>;
var MENUS = <?php echo json_encode($menus); ?>;

function toggleDropdown(){
    var menu = document.getElementById('dropdownMenu');
    var perfilMenu = document.getElementById('dropdownPerfil');
    if(perfilMenu && perfilMenu.style.display==='block') perfilMenu.style.display='none';
    menu.style.display = (menu.style.display==='block') ? 'none' : 'block';
}

function togglePerfilDropdown(){
    var perfilMenu = document.getElementById('dropdownPerfil');
    var menu = document.getElementById('dropdownMenu');
    if(menu && menu.style.display==='block') menu.style.display='none';
    perfilMenu.style.display = (perfilMenu.style.display==='block') ? 'none' : 'block';
}

window.onclick=function(e){
    if(!e.target.closest('.usuario') && !e.target.closest('.perfil-actual')){
        var menu = document.getElementById('dropdownMenu');
        var perfilMenu = document.getElementById('dropdownPerfil');
        if(menu && menu.style.display==='block') menu.style.display='none';
        if(perfilMenu && perfilMenu.style.display==='block') perfilMenu.style.display='none';
    }
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        var reader = new FileReader();
        
        reader.onload = function(e) {
            var img = new Image();
            img.onload = function() {
                // Crear un canvas para redimensionar
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                
                // Tamaño deseado
                var maxWidth = 200;
                var maxHeight = 200;
                var width = img.width;
                var height = img.height;

                // Calcular las nuevas dimensiones manteniendo la relación de aspecto
                if (width > height) {
                    if (width > maxWidth) {
                        height = Math.round((height * maxWidth) / width);
                        width = maxWidth;
                    }
                } else {
                    if (height > maxHeight) {
                        width = Math.round((width * maxHeight) / height);
                        height = maxHeight;
                    }
                }

                // Establecer el tamaño del canvas
                canvas.width = width;
                canvas.height = height;
                
                // Dibujar la imagen redimensionada
                ctx.drawImage(img, 0, 0, width, height);
                
                // Convertir a blob y actualizar el input file
                canvas.toBlob(function(blob) {
                    var resizedFile = new File([blob], file.name, {
                        type: 'image/jpeg',
                        lastModified: Date.now()
                    });
                    
                    // Crear un nuevo DataTransfer y asignar el archivo redimensionado
                    var dataTransfer = new DataTransfer();
                    dataTransfer.items.add(resizedFile);
                    input.files = dataTransfer.files;
                    
                    // Actualizar la vista previa
                    document.getElementById('previewFoto').src = URL.createObjectURL(blob);
                }, 'image/jpeg', 0.9);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
}

function actualizarFechaHora(){
    var now = new Date();
    var opcionesFecha = { day:'2-digit', month:'2-digit', year:'numeric' };
    var fecha = now.toLocaleDateString('es-AR', opcionesFecha);
    var hora = now.toLocaleTimeString('es-AR', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('datetime').innerHTML = fecha+"<br>"+hora;
}
setInterval(actualizarFechaHora,1000);
actualizarFechaHora();

// Funciones para modal de editar usuario
function abrirModalEditarUsuario(){
    document.getElementById('modalEditarUsuario').style.display = 'flex';
    // Cerrar dropdown si está abierto
    var menu = document.getElementById('dropdownMenu');
    if(menu) menu.style.display = 'none';
}

function cerrarModalEditarUsuario(){
    document.getElementById('modalEditarUsuario').style.display = 'none';
}
</script>