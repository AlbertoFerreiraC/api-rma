<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode([
        "status" => "error",
        "message" => "No fue posible establecer conexión con la base de datos."
    ]);
    exit;
}

$action = $_GET["action"] ?? "";

/*==================================================
=            LISTAR USUARIOS
==================================================*/
if ($action === "listar") {

    try {

        $sql = "SELECT
                    u.id,
                    u.usuario,
                    u.nombre,
                    u.email,
                    u.created_at,
                    r.nombre AS rol
                FROM usuarios u
                INNER JOIN roles r
                    ON r.id = u.id_rol
                ORDER BY u.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        echo json_encode([
            "status" => "success",
            "usuarios" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {

        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}

/*==================================================
=            OBTENER USUARIO POR ID (EDIT)
==================================================*/
if ($action === "obtener" && $_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        $id = intval($_GET["id"] ?? 0);

        if ($id <= 0) {
            throw new Exception("ID inválido.");
        }

        $stmt = $pdo->prepare("
            SELECT id, id_rol, usuario, nombre, email
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("Usuario no encontrado.");
        }

        echo json_encode([
            "status" => "success",
            "usuario" => $user
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}


/*==================================================
=            ACTUALIZAR USUARIO (UPDATE)
==================================================*/
if ($action === "actualizar" && $_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $input = json_decode(file_get_contents("php://input"), true);

        $id = intval($input["id"] ?? 0);
        $usuario = trim($input["usuario"] ?? "");
        $nombre = trim($input["nombre"] ?? "");
        $email = trim($input["email"] ?? "");
        $password = trim($input["contrasena"] ?? "");
        $idRol = intval($input["id_rol"] ?? 0);

        if ($id <= 0 || $usuario == "" || $nombre == "" || $email == "" || $idRol == 0) {
            throw new Exception("Datos incompletos.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Si viene contraseña -> actualizar hash, si no -> no tocarla
        if ($password !== "") {

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "UPDATE usuarios
                    SET id_rol = ?,
                        usuario = ?,
                        nombre = ?,
                        email = ?,
                        contrasena_hash = ?
                    WHERE id = ?";

            $params = [$idRol, $usuario, $nombre, $email, $hash, $id];

        } else {

            $sql = "UPDATE usuarios
                    SET id_rol = ?,
                        usuario = ?,
                        nombre = ?,
                        email = ?
                    WHERE id = ?";

            $params = [$idRol, $usuario, $nombre, $email, $id];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            "status" => "success",
            "message" => "Usuario actualizado correctamente."
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}


/*==================================================
=            GUARDAR USUARIO
==================================================*/
if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("JSON inválido.");
        }

        $usuario = trim($input["usuario"] ?? "");
        $nombre = trim($input["nombre"] ?? "");
        $email = trim($input["email"] ?? "");
        $password = trim($input["contrasena"] ?? "");
        $idRol = intval($input["id_rol"] ?? 0);

        if (
            $usuario == "" ||
            $nombre == "" ||
            $email == "" ||
            $password == "" ||
            $idRol == 0
        ) {
            throw new Exception("Debe completar todos los campos.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El correo electrónico no es válido.");
        }

        // Verificar usuario existente

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE usuario = ?
               OR email = ?
        ");

        $stmt->execute([$usuario, $email]);

        if ($stmt->fetch()) {
            throw new Exception("Ya existe un usuario o correo registrado.");
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
            INSERT INTO usuarios
            (
                id_rol,
                usuario,
                nombre,
                email,
                contrasena_hash,
                created_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        $stmt->execute([
            $idRol,
            $usuario,
            $nombre,
            $email,
            $hash
        ]);

        echo json_encode([
            "status" => "success",
            "message" => "Usuario registrado correctamente."
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}


/*==================================================
=            ELIMINAR USUARIO
==================================================*/
if ($action === "eliminar" && $_SERVER["REQUEST_METHOD"] === "DELETE") {

    try {

        $id = intval($_GET["id"] ?? 0);

        if ($id <= 0) {
            throw new Exception("ID inválido.");
        }

        $stmt = $pdo->prepare("
            DELETE FROM usuarios
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        if ($stmt->rowCount() == 0) {
            throw new Exception("No existe el usuario.");
        }

        echo json_encode([
            "status" => "success",
            "message" => "Usuario eliminado correctamente."
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}

echo json_encode([
    "status" => "error",
    "message" => "Acción no válida."
]);