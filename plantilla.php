<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}





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
if(isset($menus[$perfilActivo])){
    foreach($menus[$perfilActivo] as $item){
        echo "<a href='".$item[2]."'><div class='menu-card'><i class='fa-solid ".$item[1]."'></i><p>".$item[0]."</p></div></a>";
    }
}
?>
</div>

<div class="datetime" id="datetime"></div>

<!-- Selector de perfil para usuarios con múltiples perfiles -->
<div id="perfilChooser" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); align-items:center; justify-content:center; z-index:9999;">
  <div style="background:#fff; border-radius:10px; padding:18px; width:320px; max-width:90%; box-shadow:0 10px 30px rgba(0,0,0,.2);">
    <h3 style="margin:0 0 10px; font-size:18px;">Elegí un perfil</h3>
    <p style="margin:0 0 14px; color:#555; font-size:14px;">Seleccioná con qué perfil querés ingresar a esta sección.</p>
    <div id="perfilChooserButtons" style="display:flex; gap:10px; flex-wrap:wrap;"></div>
    <div style="margin-top:14px; text-align:right;">
      <button id="cancelPerfilChooser" style="background:#6c757d; color:#fff; border:none; padding:8px 12px; border-radius:6px; cursor:pointer;">Cancelar</button>
    </div>
  </div>
  <input type="hidden" id="perfilChooserTarget" value="" />
  <input type="hidden" id="perfilChooserHref" value="" />
</div>

<script>
function cambiarPerfil(perfil){
    var xhr = new XMLHttpRequest();
    xhr.open("POST","cambiar_perfil_ajax.php",true);
    xhr.setRequestHeader("Content-type","application/x-www-form-urlencoded");
    xhr.onreadystatechange=function(){
        if(xhr.readyState==4 && xhr.status==200){
            // Al cambiar el perfil desde el header, redirigimos siempre al menú principal
            window.location.href = 'menu.php';
        }
    };
    xhr.send("perfil="+perfil);
}


var perfiles = <?php echo json_encode($nombresPerfiles); ?>;

// Perfiles del usuario y perfil activo actual
var userPerfiles = <?php echo json_encode($_SESSION['perfiles']); ?>;
var perfilActivoJs = <?php echo json_encode($_SESSION['perfil_actual'] ?? ($_SESSION['perfiles'][0] ?? 1)); ?>;

// Interceptar clics en el grid para usuarios con múltiples perfiles
document.getElementById('gridMenu').addEventListener('click', function(e){
    var a = e.target.closest('a');
    if (!a) return;
    if (userPerfiles && userPerfiles.length > 1) {
        e.preventDefault();
        abrirSelectorPerfil(a.getAttribute('href'));
    }
});

function abrirSelectorPerfil(href){
    var chooser = document.getElementById('perfilChooser');
    var buttons = document.getElementById('perfilChooserButtons');
    document.getElementById('perfilChooserHref').value = href;
    buttons.innerHTML = '';
    (userPerfiles || []).forEach(function(pid){
        var b = document.createElement('button');
        b.textContent = perfiles[pid] ? perfiles[pid][0] : ('Perfil '+pid);
        b.style.cssText = 'flex:1; min-width:120px; background:#0d6efd; color:#fff; border:none; padding:8px 12px; border-radius:6px; cursor:pointer;';
        b.onclick = function(){ seleccionarPerfilYIr(pid, href); };
        buttons.appendChild(b);
    });
    chooser.style.display = 'flex';
}

document.getElementById('cancelPerfilChooser').onclick = function(){
    document.getElementById('perfilChooser').style.display = 'none';
};

function seleccionarPerfilYIr(pid, href){
    var xhr = new XMLHttpRequest();
    xhr.open('POST','cambiar_perfil_ajax.php',true);
    xhr.setRequestHeader('Content-type','application/x-www-form-urlencoded');
    xhr.onreadystatechange = function(){
        if (xhr.readyState === 4) {
            // Cerrar modal y navegar pase lo que pase
            document.getElementById('perfilChooser').style.display = 'none';
            // Pequeño delay para asegurar que la sesión se guardó
            setTimeout(function(){ window.location.href = 'menu.php'; }, 100);
        }
    };
    xhr.send('perfil='+encodeURIComponent(pid));
}

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

function actualizarFechaHora(){
    var now = new Date();
    var opcionesFecha = { day:'2-digit', month:'2-digit', year:'numeric' };
    var fecha = now.toLocaleDateString('es-AR', opcionesFecha);
    var hora = now.toLocaleTimeString('es-AR', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('datetime').innerHTML = fecha+"<br>"+hora;
}
setInterval(actualizarFechaHora,1000);
actualizarFechaHora();
</script>
</body>
</html>
