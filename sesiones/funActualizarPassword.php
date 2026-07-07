<?php

require_once "../db.php";

header("Content-Type: application/json");
session_start();

// =============================
// VALIDAR SESIÓN
// =============================
if (!isset($_SESSION["iniciarSesion"]) || $_SESSION["iniciarSesion"] != "ok") {
    http_response_code(401);
    echo json_encode(["mensaje" => "No autorizado"]);
    exit;
}

// =============================
// LEER JSON
// =============================
$data = json_decode(file_get_contents("php://input"), true);

if (
    !isset($data["idusuario"]) ||
    !isset($data["passActual"]) ||
    !isset($data["passNueva"])
) {
    http_response_code(400);
    echo json_encode(["mensaje" => "Datos incompletos"]);
    exit;
}

$idusuario = $data["idusuario"];
$passActual = base64_decode($data["passActual"]);
$passNueva = base64_decode($data["passNueva"]);

try {

    $db = new DB();
    $pdo = $db->connect();

    // =============================
    // OBTENER USUARIO
    // =============================
    $stmt = $pdo->prepare("
        SELECT pass
        FROM usuario
        WHERE idusuario = :idusuario
        LIMIT 1
    ");

    $stmt->bindParam(":idusuario", $idusuario);
    $stmt->execute();

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);
        echo json_encode(["mensaje" => "Usuario no encontrado"]);
        exit;
    }

    $hashGuardado = $usuario["pass"];

    // =============================
    // VALIDAR PASSWORD ACTUAL
    // =============================
    $passwordCorrecta = false;

    if (password_get_info($hashGuardado)["algo"] !== 0) {
        // Es hash moderno
        if (password_verify($passActual, $hashGuardado)) {
            $passwordCorrecta = true;
        }
    } else {
        // Texto plano antiguo
        if ($hashGuardado === $passActual) {
            $passwordCorrecta = true;
        }
    }

    if (!$passwordCorrecta) {
        echo json_encode(["mensaje" => "pass_incorrecta"]);
        exit;
    }

    // =============================  
    // GENERAR NUEVO HASH
    // =============================
    $nuevoHash = password_hash($passNueva, PASSWORD_DEFAULT);

    $update = $pdo->prepare("
        UPDATE usuario
        SET pass = :pass
        WHERE idusuario = :idusuario
    ");

    $update->bindParam(":pass", $nuevoHash);
    $update->bindParam(":idusuario", $idusuario);

    if ($update->execute()) {
        echo json_encode(["mensaje" => "ok"]);
    } else {
        echo json_encode(["mensaje" => "nok"]);
    }
} catch (Exception $e) {

    http_response_code(500);
    echo json_encode([
        "mensaje" => "Error interno"
    ]);
}
