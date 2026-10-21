<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../db.php';

try {

    $usuario = trim($_POST["usuario"] ?? '');
    $password = $_POST["password"] ?? '';

    if (empty($usuario) || empty($password)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Complete todos los campos"
        ]);
        exit;
    }

    $sql = "SELECT id, id_rol, usuario, nombre, email, contrasena_hash
            FROM usuarios
            WHERE usuario = :usuario
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':usuario', $usuario);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Usuario o contraseña incorrectos"
        ]);
        exit;
    }

    $storedPassword = $user["contrasena_hash"];

    $tieneHash = password_get_info($storedPassword)['algo'] !== null || preg_match('/^\$2[aby]\$/', $storedPassword) === 1;
    $isValid = $tieneHash ? password_verify($password, $storedPassword) : hash_equals($storedPassword, $password);

    if (!$isValid) {
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Usuario o contraseña incorrectos"
        ]);
        exit;
    }

    // Migración gradual: la próxima vez que el usuario inicie sesión, reemplazar clave legada en texto por bcrypt.
    if (!$tieneHash) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $up = $pdo->prepare('UPDATE usuarios SET contrasena_hash = ? WHERE id = ? AND contrasena_hash = ?');
        $up->execute([$newHash, $user['id'], $storedPassword]);
    }
    session_regenerate_id(true);

    /* =========================
        🔥 SESIÓN
    ========================= */
    $_SESSION["id"] = $user["id"];
    $_SESSION["id_rol"] = $user["id_rol"];
    $_SESSION["usuario"] = $user["usuario"];
    $_SESSION["nombre"] = $user["nombre"];
    $_SESSION["email"] = $user["email"];
    $_SESSION['rma_csrf'] = bin2hex(random_bytes(32));

    /* =========================
        REDIRECCIÓN POR ROL
    ========================= */
    $redirigirA = "inicio";
 
    if ((int) $user["id_rol"] === 1) {
        $redirigirA = "perfil_tecnico";
    }

    if ((int) $user["id_rol"] === 2) {
        $redirigirA = "inicio";
    }

    if ((int) $user['id_rol'] === 5) {
        $redirigirA = 'dashboardInventario';
    }

    echo json_encode([
        "success" => true,
        "nombre" => $user["nombre"],
        "redirect" => $redirigirA
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Error interno del servidor"
    ]);
}
