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

    // ==========================================
    // 🔐 VALIDACIÓN DOBLE (HASH + TEXTO PLANO)
    // ==========================================

    $isValid = false;

    // 1. Si es hash bcrypt
    if (str_starts_with($storedPassword, '$2y$')) {
        $isValid = password_verify($password, $storedPassword);
    }

    // 2. Si es texto plano (legacy)
    else {
        $isValid = ($password === $storedPassword);
    }

    if (!$isValid) {
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Usuario o contraseña incorrectos"
        ]);
        exit;
    }

    /* =========================
        🔥 SESIÓN
    ========================= */
    $_SESSION["id"] = $user["id"];
    $_SESSION["id_rol"] = $user["id_rol"];
    $_SESSION["usuario"] = $user["usuario"];
    $_SESSION["nombre"] = $user["nombre"];
    $_SESSION["email"] = $user["email"];

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