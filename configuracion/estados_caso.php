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

// Reemplazar con la ruta real a tu conexión PDO / MySQLi
require_once "../db.php";

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==========================================
        // 📡 LISTAR TODOS LOS ESTADOS DE CASO
        // ==========================================
        case 'listar':
            $stmt = $pdo->prepare("SELECT id, nombre FROM estados_caso ORDER BY id ASC");
            $stmt->execute();
            $estados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "estados" => $estados
            ]);
            break;

        // ==========================================
        // 📥 OBTENER UN ESTADO POR ID (EDICIÓN)
        // ==========================================
        case 'obtener':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(["status" => "error", "message" => "ID no proporcionado."]);
                exit();
            }

            $stmt = $pdo->prepare("SELECT id, nombre FROM estados_caso WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $estado = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($estado) {
                echo json_encode([
                    "status" => "success",
                    "estado" => $estado
                ]);
            } else {
                echo json_encode(["status" => "error", "message" => "Estado de caso no encontrado."]);
            }
            break;

        // ==========================================
        // 💾 GUARDAR / CREAR NUEVO ESTADO
        // ==========================================
        case 'guardar':
            $data = json_decode(file_get_contents("php://input"), true);
            $nombre = trim($data['nombre'] ?? '');

            if (empty($nombre)) {
                echo json_encode(["status" => "error", "message" => "El nombre del estado es obligatorio."]);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO estados_caso (nombre) VALUES (:nombre)");
            $stmt->execute([':nombre' => $nombre]);

            echo json_encode([
                "status" => "success",
                "message" => "Estado de caso inyectado correctamente."
            ]);
            break;

        // ==========================================
        // 🔄 ACTUALIZAR ESTADO EXISTENTE
        // ==========================================
        case 'actualizar':
            $data = json_decode(file_get_contents("php://input"), true);
            $id = $data['id'] ?? null;
            $nombre = trim($data['nombre'] ?? '');

            if (!$id || empty($nombre)) {
                echo json_encode(["status" => "error", "message" => "Datos incompletos para actualizar."]);
                exit();
            }

            $stmt = $pdo->prepare("UPDATE estados_caso SET nombre = :nombre WHERE id = :id");
            $stmt->execute([
                ':nombre' => $nombre,
                ':id' => $id
            ]);

            echo json_encode([
                "status" => "success",
                "message" => "Estado de caso actualizado exitosamente."
            ]);
            break;

        // ==========================================
        // 🗑️ ELIMINAR ESTADO (CON VALIDACIÓN DE INTEGRIDAD)
        // ==========================================
        case 'eliminar':
            $id = $_GET['id'] ?? null;

            if (!$id) {
                echo json_encode(["status" => "error", "message" => "ID no válido."]);
                exit();
            }

            // Validar integridad en tabla casos
            $checkCasos = $pdo->prepare("SELECT COUNT(*) FROM casos WHERE id_estado_actual = :id");
            $checkCasos->execute([':id' => $id]);
            if ($checkCasos->fetchColumn() > 0) {
                echo json_encode([
                    "status" => "error",
                    "message" => "No se puede purgar. Existen casos activos utilizando este estado."
                ]);
                exit();
            }

            // Validar integridad en tabla historial_estados
            $checkHistorial = $pdo->prepare("SELECT COUNT(*) FROM historial_estados WHERE id_estado = :id");
            $checkHistorial->execute([':id' => $id]);
            if ($checkHistorial->fetchColumn() > 0) {
                echo json_encode([
                    "status" => "error",
                    "message" => "No se puede purgar. Existen registros de historial asociados a este estado."
                ]);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM estados_caso WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode([
                "status" => "success",
                "message" => "Estado de caso eliminado de la matriz."
            ]);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "Acción no válida."]);
            break;
    }

} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "DATABASE_ERROR: " . $e->getMessage()
    ]);
}