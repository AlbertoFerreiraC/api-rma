<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Manejo de peticiones preflight OPTIONS (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../db.php";

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==========================================
        // 📡 LISTAR TODOS LOS TIPOS DE CASO
        // ==========================================
        case 'listar':
            $stmt = $pdo->prepare("SELECT id, nombre FROM tipos_caso ORDER BY id ASC");
            $stmt->execute();
            $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "tipos" => $tipos
            ]);
            break;

        // ==========================================
        // 📥 OBTENER UN TIPO POR ID (EDICIÓN)
        // ==========================================
        case 'obtener':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(["status" => "error", "message" => "ID no proporcionado."]);
                exit();
            }

            $stmt = $pdo->prepare("SELECT id, nombre FROM tipos_caso WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $tipo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tipo) {
                echo json_encode([
                    "status" => "success",
                    "tipo" => $tipo
                ]);
            } else {
                echo json_encode(["status" => "error", "message" => "Tipo de caso no encontrado."]);
            }
            break;

        // ==========================================
        // 💾 GUARDAR / CREAR NUEVO TIPO
        // ==========================================
        case 'guardar':
            $data = json_decode(file_get_contents("php://input"), true);
            $nombre = trim($data['nombre'] ?? '');

            if (empty($nombre)) {
                echo json_encode(["status" => "error", "message" => "El nombre del tipo es obligatorio."]);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO tipos_caso (nombre) VALUES (:nombre)");
            $stmt->execute([':nombre' => $nombre]);

            echo json_encode([
                "status" => "success",
                "message" => "Tipo de caso inyectado correctamente."
            ]);
            break;

        // ==========================================
        // 🔄 ACTUALIZAR TIPO EXISTENTE
        // ==========================================
        case 'actualizar':
            $data = json_decode(file_get_contents("php://input"), true);
            $id = $data['id'] ?? null;
            $nombre = trim($data['nombre'] ?? '');

            if (!$id || empty($nombre)) {
                echo json_encode(["status" => "error", "message" => "Datos incompletos para actualizar."]);
                exit();
            }

            $stmt = $pdo->prepare("UPDATE tipos_caso SET nombre = :nombre WHERE id = :id");
            $stmt->execute([
                ':nombre' => $nombre,
                ':id' => $id
            ]);

            echo json_encode([
                "status" => "success",
                "message" => "Tipo de caso actualizado exitosamente."
            ]);
            break;

        // ==========================================
        // 🗑️ ELIMINAR TIPO (CON VALIDACIÓN DE CASOS)
        // ==========================================
        case 'eliminar':
            $id = $_GET['id'] ?? null;

            if (!$id) {
                echo json_encode(["status" => "error", "message" => "ID no válido."]);
                exit();
            }

            // Validar si existen casos vinculados a este id_tipo_caso
            $check = $pdo->prepare("SELECT COUNT(*) FROM casos WHERE id_tipo_caso = :id");
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() > 0) {
                echo json_encode([
                    "status" => "error",
                    "message" => "No se puede purgar. Existen registros de casos vinculados a este tipo de RMA."
                ]);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM tipos_caso WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode([
                "status" => "success",
                "message" => "Tipo de caso eliminado del sistema."
            ]);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);
            break;
    }

} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "DATABASE_ERROR: " . $e->getMessage()
    ]);
}