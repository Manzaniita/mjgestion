<?php
session_start();
require_once 'config.php';

$error_message = '';

if (isLoggedIn()) {
    header("Location: mj_productos.php"); // O la página principal que prefieras
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error_message = "Por favor, ingrese usuario y contraseña.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash, rol, nombre_completo, activo FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['activo'] && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['user_rol'] = $user['rol'];
            $_SESSION['user_nombre_completo'] = $user['nombre_completo'];
            
            // Redirigir según el rol o a una página general
            header("Location: mj_productos.php");
            exit;
        } elseif ($user && !$user['activo']) {
            $error_message = "Su cuenta ha sido desactivada. Contacte al administrador.";
        }
        else {
            $error_message = "Usuario o contraseña incorrectos.";
        }
    }
}

// Mostrar mensaje de error de acceso denegado si existe
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MJ Gestión</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f7f6; /* Un gris muy claro */
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-container h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 2em;
        }
        .login-container .logo {
            font-size: 3em;
            color: #3498db; /* Azul agradable */
            margin-bottom: 20px;
        }
        .login-container p {
            color: #7f8c8d; /* Gris suave */
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #34495e; /* Gris oscuro */
            font-weight: 600;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 1em;
        }
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        .btn-login {
            background-color: #3498db;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
            width: 100%;
            transition: background-color 0.3s ease;
        }
        .btn-login:hover {
            background-color: #2980b9; /* Un azul más oscuro */
        }
        .error-message {
            background-color: #e74c3c;
            color: white;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo"><i class="fas fa-boxes"></i></div>
        <h1>Bienvenido a MJ Gestión</h1>
        <p>Por favor, inicie sesión para continuar.</p>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <form method="POST" action="mj_login.php">
            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Usuario</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Contraseña</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-login">Ingresar</button>
        </form>
    </div>
</body>
</html>