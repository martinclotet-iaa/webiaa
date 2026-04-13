<?php
session_start();
if (isset($_SESSION['id_usuario'])) {
    header("Location: menu.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ingresar</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
    body {
        margin: 0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background:linear-gradient(to right,lab(1% -52.37 35.81),lab(1% -32.37 35.81));
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
            color:#fff;
    }



    .login-container {
        background: rgba(255, 255, 255, 0.05);
        padding: 40px 20px;
        border-radius: 15px;
        text-align: center;
        width: 400px;
        backdrop-filter: blur(10px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        color: #fff;
        box-sizing: border-box;
    }

    .login-container .icon {
        font-size: 60px;
        color: #16682b;
        margin-bottom: 15px;
    }

    .login-container h2 {
        margin-bottom: 25px;
        font-weight: 400;
    }

    .login-container input {
        width: calc(100% - 24px);
        padding: 12px;
        margin: 10px 0;
        border: none;
        border-radius: 8px;
        outline: none;
        background: rgba(255,255,255,0.1);
        color: #fff;
        font-size: 15px;
        box-sizing: border-box;
    }

    .login-container input::placeholder {
        color: #bbb;
    }

    .login-container button {
        width: 150px;
        padding: 12px;
        margin-top: 20px;
        background: linear-gradient(135deg, #16682b, #1a7530);
        border: none;
        color: #fff;
        font-size: 16px;
        border-radius: 8px;
        cursor: pointer;
        transition: 0.3s ease;
    }

    .login-container button:hover {
        background: linear-gradient(135deg, #1a7530, #0f4d1a);
    }

    .error {
        color: #ff5c5c;
        font-size: 14px;
        margin-bottom: 10px;
    }
.logoinicio {
    width: 100px;
    height: 120px;
}


body::before {
    content: "";
    position: fixed;   /* 🔹 fijo aunque se haga scroll */
    inset: 0;
    background: url("fotos/logo.png") no-repeat center center;
    background-size: contain;     /* siempre entero */
    opacity: 0.12;
    transform: rotate(-25deg) scale(1.4);  /* lo agranda sin cortar */
    transform-origin: center;
    z-index: -1;       /* detrás de todo el contenido */
    mix-blend-mode: overlay; /* efecto “burn” */
    pointer-events: none;  /* evita que bloquee clics */
            margin-left:120px;
    margin-top:80px;
}


</style>
</head>
<body>

<div class="login-container">
            <img src="fotos/logo.png" class="logoinicio" alt="Logo Colegio">
    <h2>Iniciar Sesión</h2>
    <?php
    if (isset($_GET['error'])) {
        echo "<p class='error'>".$_GET['error']."</p>";
    }
    ?>
    <form action="login.php" method="POST">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="clave" placeholder="Password" required>
        <button type="submit"><i class="fa-solid fa-right-to-bracket"></i>Ingresar</button>
    </form>
</div>

</body>
</html>
