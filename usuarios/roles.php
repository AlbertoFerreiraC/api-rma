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
=            LISTAR ROLES
==================================================*/
if ($action === "listar") {

    try {
        // Query adaptado a la tabla roles limitado a los primeros 1000 nodos
        $sql = "SELECT id, nombre, estado 
                FROM dosisma_rma_bd.roles 
                ORDER BY id DESC 
                LIMIT 0, 1000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        echo json_encode([
            "status" => "success",
            "roles" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Fallo de lectura en matriz: " . $e->getMessage()
        ]);
    }

    exit;
}

/*==================================================
=            OBTENER ROL POR ID (EDIT)
==================================================*/
if ($action === "obtener" && $_SERVER["REQUEST_METHOD"] === "GET") {

    try {
        $id = intval($_GET["id"] ?? 0);

        if ($id <= 0) {
            throw new Exception("Identificador de nodo inválido.");
        }

        $stmt = $pdo->prepare("
            SELECT id, nombre, estado 
            FROM dosisma_rma_bd.roles 
            WHERE id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);

        $rol = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rol) {
            throw new Exception("El rol solicitado no existe en el sistema.");
        }

        echo json_encode([
            "status" => "success",
            "rol" => $rol
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
=            ACTUALIZAR ROL (UPDATE)
==================================================*/
if ($action === "actualizar" && $_SERVER["REQUEST_METHOD"] === "POST") {

    try {
        $input = json_decode(file_get_contents("php://input"), true);

        $id = intval($input["id"] ?? 0);

        $nombre = trim($input["nombre"] ?? "");

        // 🔥 CAPTURA COMO TEXTO (No usar intval)
        $estado = trim($input["estado"] ?? "");

        // Validamos que el estado sea "activo" o "inactivo"
        if ($id <= 0 || $nombre === "" || !in_array($estado, ["activo", "inactivo"])) {
            throw new Exception("Estructura de datos incompleta o estado no válido.");
        }

        if ($id <= 0 || $nombre === "" || $estado < 0) {
            throw new Exception("Estructura de datos incompleta para actualizar el nodo.");
        }

        // Verificar si ya existe otro rol con el mismo nombre para evitar duplicados redundantes
        $stmt = $pdo->prepare("SELECT id FROM dosisma_rma_bd.roles WHERE nombre = ? AND id != ?");
        $stmt->execute([$nombre, $id]);
        if ($stmt->fetch()) {
            throw new Exception("Ya existe otra designación con el nombre: '$nombre'.");
        }

        $sql = "UPDATE dosisma_rma_bd.roles 
                SET nombre = ?, estado = ? 
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nombre, $estado, $id]);

        echo json_encode([
            "status" => "success",
            "message" => "Matriz actualizada. Rol modificado correctamente."
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
=            GUARDAR ROL (INSERT)
==================================================*/
if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {

    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $nombre = trim($input["nombre"] ?? "");

        $estado = isset($input["estado"]) ? trim($input["estado"]) : "activo";

        if ($nombre === "" || !in_array($estado, ["activo", "inactivo"])) {
            throw new Exception("Debe asignar un nombre y un estado válido al nuevo rol.");
        }

        // Verificar duplicados antes de inyectar
        $stmt = $pdo->prepare("SELECT id FROM dosisma_rma_bd.roles WHERE nombre = ?");
        $stmt->execute([$nombre]);
        if ($stmt->fetch()) {
            throw new Exception("El rol '$nombre' ya se encuentra registrado en el sistema.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO dosisma_rma_bd.roles (nombre, estado) 
            VALUES (?, ?)
        ");
        $stmt->execute([$nombre, $estado]);

        echo json_encode([
            "status" => "success",
            "message" => "Inyección exitosa. Rol registrado en la matriz."
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
=            ELIMINAR ROL (DELETE)
==================================================*/
if ($action === "eliminar" && $_SERVER["REQUEST_METHOD"] === "DELETE") {

    try {
        $id = intval($_GET["id"] ?? 0);

        if ($id <= 0) {
            throw new Exception("ID de purga no válido.");
        }

        $check = $pdo->prepare("SELECT id FROM dosisma_rma_bd.usuarios WHERE id_rol = ? LIMIT 1");
        $check->execute([$id]);
        if ($check->fetch()) {
            throw new Exception("No se puede purgar el rol. Existen dependencias activas (usuarios asignados).");
        }


        $stmt = $pdo->prepare("
            DELETE FROM dosisma_rma_bd.roles 
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        if ($stmt->rowCount() == 0) {
            throw new Exception("El nodo especificado no existe o ya fue purgado.");
        }

        echo json_encode([
            "status" => "success",
            "message" => "Nodo eliminado de la base de datos correctamente."
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

    exit;
}

// Fallback por si ejecutan una acción huérfana
echo json_encode([
    "status" => "error",
    "message" => "Protocolo o acción no reconocidos por la matriz de control."
]);