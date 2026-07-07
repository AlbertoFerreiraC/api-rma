<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode([
        "status" => "error",
        "message" => "No fue posible establecer conexión con la base de datos de la matriz."
    ]);
    exit;
}

$action = $_GET["action"] ?? "";

/*==================================================
=            ACTUALIZAR PERFIL (UPDATE)
==================================================*/
if ($action === "actualizar" && $_SERVER["REQUEST_METHOD"] === "POST") {

    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $id = intval($input["id"] ?? 0);
        $nombre = trim($input["nombre"] ?? "");
        $email = trim($input["email"] ?? "");
        $password = trim($input["contrasena"] ?? "");

        // Validaciones críticas de integridad
        if ($id <= 0 || $nombre === "" || $email === "") {
            throw new Exception("Estructura de datos incompleta para actualizar el perfil.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El formato del correo electrónico (email) no es válido.");
        }

        // Evitar que el usuario usurpe el email de otro operador registrado
        $stmt = $pdo->prepare("SELECT id FROM dosisma_rma_bd.usuarios WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            throw new Exception("El correo electrónico ya se encuentra asignado a otro nodo de usuario.");
        }

        // 🔥 EVALUACIÓN DE CAMBIO DE CONTRASEÑA
        if ($password !== "") {
            // El usuario ingresó una nueva clave -> Inyectamos un nuevo hash seguro
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "UPDATE dosisma_rma_bd.usuarios 
                    SET nombre = ?, email = ?, contrasena_hash = ? 
                    WHERE id = ?";
            $params = [$nombre, $email, $hash, $id];
        } else {
            // El campo vino vacío -> Conservamos la clave actual sin tocar la columna contrasena_hash
            $sql = "UPDATE dosisma_rma_bd.usuarios 
                    SET nombre = ?, email = ? 
                    WHERE id = ?";
            $params = [$nombre, $email, $id];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // 🔥 RECOMENDACIÓN CRÍTICA: Actualizamos las variables de sesión activas de PHP
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['nombre'] = $nombre;
        $_SESSION['email'] = $email;

        echo json_encode([
            "status" => "success",
            "message" => "Perfil personal sincronizado. Los tokens de sesión fueron actualizados."
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}

// Fallback por si ejecutan un protocolo erróneo o inexistente
echo json_encode([
    "status" => "error",
    "message" => "Protocolo de perfil no reconocido por la matriz de control."
]);