<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: loginAdmin.html');
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    header('Location: loginAdmin.html?error=1&email=' . urlencode($email));
    exit;
}

$conexion = @new mysqli('localhost', 'root', '', 'rosacocoaBD');
if ($conexion->connect_error) {
    $conexion = @new mysqli('localhost', 'root', '', 'rosacocoa');
}

if ($conexion->connect_error) {
    header('Location: loginAdmin.html?error=1&email=' . urlencode($email));
    exit;
}

$sql = 'SELECT usu_id, usu_nombre, usu_correo, usu_password, usu_rol FROM usuario WHERE usu_correo = ? AND usu_rol IN (?, ?) AND usu_estatus = 1 LIMIT 1';
$stmt = $conexion->prepare($sql);
$rolAdmin = 'ADMIN';
$rolChef = 'CHEF';

if (!$stmt) {
    $conexion->close();
    header('Location: loginAdmin.html?error=1&email=' . urlencode($email));
    exit;
}

$stmt->bind_param('sss', $email, $rolAdmin, $rolChef);
$stmt->execute();
$resultado = $stmt->get_result();
$usuario = $resultado ? $resultado->fetch_assoc() : null;

$valido = false;
if ($usuario) {
    $hash = $usuario['usu_password'];
    $valido = password_verify($password, $hash) || hash_equals($hash, $password);
}

$stmt->close();
$conexion->close();

if (!$valido) {
    header('Location: loginAdmin.html?error=1&email=' . urlencode($email));
    exit;
}

$_SESSION['admin_id'] = (int)$usuario['usu_id'];
$_SESSION['admin_nombre'] = $usuario['usu_nombre'];
$_SESSION['admin_correo'] = $usuario['usu_correo'];
$_SESSION['admin_rol'] = strtoupper((string)$usuario['usu_rol']);
$_SESSION['admin_last_activity'] = time();
$_SESSION['admin_session_started'] = time();
$_SESSION['admin_timeout_seconds'] = 30000;

header('Location: menuadmin.html');
exit;
?>

