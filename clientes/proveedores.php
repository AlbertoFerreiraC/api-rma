<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Error de conexión a la base de datos."]);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === "listar") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, contacto, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at FROM proveedores ORDER BY id DESC");
        $stmt->execute();
        $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "proveedores" => $proveedores]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "guardar" && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['nombre']) || empty($input['contacto'])) {
            throw new Exception("Nombre del proveedor y datos de contacto son obligatorios.");
        }

        $stmt = $pdo->prepare("INSERT INTO proveedores (nombre, contacto, created_at) VALUES (:nombre, :contacto, NOW())");
        $stmt->execute([
            ':nombre' => strip_tags(trim($input['nombre'])),
            ':contacto' => strip_tags(trim($input['contacto']))
        ]);

        echo json_encode(["status" => "success", "message" => "Proveedor registrado correctamente."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "actualizar" && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['id']) || empty($input['nombre']) || empty($input['contacto'])) {
            throw new Exception("Todos los campos son obligatorios para actualizar.");
        }

        $stmt = $pdo->prepare("UPDATE proveedores SET nombre = :nombre, contacto = :contacto WHERE id = :id");
        $stmt->execute([
            ':id' => (int) $input['id'],
            ':nombre' => strip_tags(trim($input['nombre'])),
            ':contacto' => strip_tags(trim($input['contacto']))
        ]);

        echo json_encode(["status" => "success", "message" => "Datos del proveedor actualizados."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "historial") {
    try {
        $idProveedor = isset($_GET['id_proveedor']) ? (int) $_GET['id_proveedor'] : 0;

        if ($idProveedor <= 0) {
            throw new Exception("ID de proveedor inválido.");
        }

        $sql = "SELECT 
                    c.id,
                    c.numero_caso,
                    c.equipo,
                    c.marca,
                    c.modelo,
                    c.numero_serie,
                    c.referencia_proveedor,
                    DATE_FORMAT(c.fecha_envio_proveedor, '%Y-%m-%d') AS fecha_envio_proveedor,
                    e.nombre AS estado_actual
                FROM casos c
                LEFT JOIN estados_caso e ON c.id_estado_actual = e.id
                WHERE c.id_proveedor = :id_proveedor
                ORDER BY c.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_proveedor' => $idProveedor]);
        $casos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "casos" => $casos]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "eliminar" && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $idProveedor = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($idProveedor <= 0) {
            throw new Exception("Identificador no válido.");
        }

        $checkCasos = $pdo->prepare("SELECT COUNT(*) AS total FROM casos WHERE id_proveedor = :id_proveedor");
        $checkCasos->execute([':id_proveedor' => $idProveedor]);
        $totalCasos = $checkCasos->fetch(PDO::FETCH_ASSOC)['total'];

        if ($totalCasos > 0) {
            throw new Exception("No se puede eliminar el proveedor porque tiene {$totalCasos} caso(s) de RMA asociado(s).");
        }

        $stmt = $pdo->prepare("DELETE FROM proveedores WHERE id = :id");
        $stmt->execute([':id' => $idProveedor]);

        echo json_encode(["status" => "success", "message" => "Proveedor eliminado del directorio."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);