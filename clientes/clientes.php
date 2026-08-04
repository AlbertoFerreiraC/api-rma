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
        $stmt = $pdo->prepare("SELECT id, nombre, cedula, celular, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at FROM clientes ORDER BY id DESC");
        $stmt->execute();
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "clientes" => $clientes]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "guardar" && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['nombre']) || empty($input['cedula']) || empty($input['celular'])) {
            throw new Exception("Nombre, cédula y celular son obligatorios.");
        }

        $checkStmt = $pdo->prepare("SELECT id FROM clientes WHERE cedula = :cedula");
        $checkStmt->execute([':cedula' => trim($input['cedula'])]);
        if ($checkStmt->fetch()) {
            throw new Exception("Ya existe un cliente registrado con esa cédula / RUC.");
        }

        $stmt = $pdo->prepare("INSERT INTO clientes (nombre, cedula, celular, created_at) VALUES (:nombre, :cedula, :celular, NOW())");
        $stmt->execute([
            ':nombre' => strip_tags(trim($input['nombre'])),
            ':cedula' => strip_tags(trim($input['cedula'])),
            ':celular' => strip_tags(trim($input['celular']))
        ]);

        echo json_encode(["status" => "success", "message" => "Cliente registrado correctamente."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "actualizar" && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['id']) || empty($input['nombre']) || empty($input['cedula']) || empty($input['celular'])) {
            throw new Exception("Todos los campos son obligatorios para actualizar.");
        }

        $idCliente = (int) $input['id'];

        $checkStmt = $pdo->prepare("SELECT id FROM clientes WHERE cedula = :cedula AND id != :id");
        $checkStmt->execute([':cedula' => trim($input['cedula']), ':id' => $idCliente]);
        if ($checkStmt->fetch()) {
            throw new Exception("La cédula / RUC ingresada pertenece a otro cliente registrado.");
        }

        $stmt = $pdo->prepare("UPDATE clientes SET nombre = :nombre, cedula = :cedula, celular = :celular WHERE id = :id");
        $stmt->execute([
            ':id' => $idCliente,
            ':nombre' => strip_tags(trim($input['nombre'])),
            ':cedula' => strip_tags(trim($input['cedula'])),
            ':celular' => strip_tags(trim($input['celular']))
        ]);

        echo json_encode(["status" => "success", "message" => "Datos del cliente actualizados."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "historial") {
    try {
        $idCliente = isset($_GET['id_cliente']) ? (int) $_GET['id_cliente'] : 0;

        if ($idCliente <= 0) {
            throw new Exception("ID de cliente inválido.");
        }

        $sql = "SELECT 
                    c.id,
                    c.numero_caso,
                    c.equipo,
                    c.marca,
                    c.modelo,
                    c.numero_serie,
                    c.descripcion_problema,
                    c.diagnostico_final,
                    DATE_FORMAT(c.fecha_ingreso, '%Y-%m-%d %H:%i') AS fecha_ingreso,
                    DATE_FORMAT(c.fecha_cierre, '%Y-%m-%d %H:%i') AS fecha_cierre,
                    e.nombre AS estado_actual,
                    t.nombre AS tipo_caso
                FROM casos c
                LEFT JOIN estados_caso e ON c.id_estado_actual = e.id
                LEFT JOIN tipos_caso t ON c.id_tipo_caso = t.id
                WHERE c.id_cliente = :id_cliente
                ORDER BY c.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_cliente' => $idCliente]);
        $casos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "casos" => $casos]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

if ($action === "eliminar" && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $idCliente = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($idCliente <= 0) {
            throw new Exception("Identificador de cliente no válido.");
        }

        $checkCasos = $pdo->prepare("SELECT COUNT(*) AS total FROM casos WHERE id_cliente = :id_cliente");
        $checkCasos->execute([':id_cliente' => $idCliente]);
        $totalCasos = $checkCasos->fetch(PDO::FETCH_ASSOC)['total'];

        if ($totalCasos > 0) {
            throw new Exception("No se puede eliminar el cliente porque tiene {$totalCasos} caso(s) de RMA asociado(s).");
        }

        $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
        $stmt->execute([':id' => $idCliente]);

        echo json_encode(["status" => "success", "message" => "Cliente eliminado del directorio."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);