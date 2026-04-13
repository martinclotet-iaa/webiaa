<?php
session_start();
if(isset($_POST['perfil']) && in_array($_POST['perfil'], $_SESSION['perfiles'])){
    $_SESSION['perfil_actual'] = intval($_POST['perfil']);
    echo "OK";
}else{
    echo "ERROR";
}
?>
