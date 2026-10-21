<?php
require_once __DIR__ . '/remisiones_auth.php';
rmaPermitir([2, 5]);
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db_inventario.php";

if (!isset($pdo_inv)) {
    echo json_encode(["status" => "error", "message" => "Error de conexión con la base de datos de inventario."]);
    exit;
}

$action = $_GET["action"] ?? "";

/*==================================================
=   1. LISTAR ASIGNACIONES / MOVIMIENTOS TALLER    =
==================================================*/
if ($action === "listar") {
    try {
        $stmt = $pdo_inv->prepare("SELECT m.*, p.nombre AS producto_nombre, p.codigo FROM movimientos_inventario m INNER JOIN productos p ON m.producto_id = p.id WHERE m.tipo_movimiento = 'ENTREGA_TECNICO' ORDER BY m.id DESC");
        $stmt->execute();
        
        echo json_encode([
            "status" => "success",
            "asignaciones" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

/*==================================================
=   2. MOVER INVENTARIO AL TÉCNICO (DESCONTAR)      =
==================================================*/
if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $input = json_decode(file_get_contents("php://input"), true);
        
        $producto_id = intval($input["producto_id"] ?? 0);
        $cantidad = intval($input["cantidad"] ?? 0);
        $referencia = trim($input["referencia"] ?? ""); // Ej: Nro de Caso RMA
        $observacion = trim($input["observacion"] ?? "Asignación de repuesto a técnico en taller.");
        $usuario_id = intval($_SESSION['id_usuario'] ?? 1);

        if ($producto_id <= 0 || $cantidad <= 0) {
            throw new Exception("Debe seleccionar un producto y una cantidad válida.");
        }

        $pdo_inv->beginTransaction();

        $stmt = $pdo_inv->prepare("SELECT stock_actual, stock_rma FROM productos WHERE id = ? FOR UPDATE");
        $stmt->execute([$producto_id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) throw new Exception("El producto no existe.");

        $stockAnterior = intval($prod['stock_actual']);
        if ($stockAnterior < $cantidad) {
            throw new Exception("Stock insuficiente para realizar la entrega. Stock actual: " . $stockAnterior);
        }

        $stockNuevo = $stockAnterior - $cantidad;
        $stockRma = intval($prod['stock_rma']);
        $stockDisponibleNuevo = $stockNuevo - $stockRma;

        $estadoAlerta = ($stockNuevo <= 0) ? 'SIN_STOCK' : (($stockDisponibleNuevo <= 5) ? 'ALERTA - STOCK BAJO' : 'OK');

        // Actualizar stock
        $stmtUp = $pdo_inv->prepare("UPDATE productos SET stock_actual = ?, stock_disponible = ?, estado_alerta = ? WHERE id = ?");
        $stmtUp->execute([$stockNuevo, $stockDisponibleNuevo, $estadoAlerta, $producto_id]);

        // Registrar movimiento
        $stmtMov = $pdo_inv->prepare("INSERT INTO movimientos_inventario (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, referencia, observacion) VALUES (?, 'ENTREGA_TECNICO', ?, ?, ?, ?, ?, ?)");
        $stmtMov->execute([$producto_id, $cantidad, $stockAnterior, $stockNuevo, $usuario_id, $referencia, $observacion]);

        $pdo_inv->commit();

        echo json_encode(["status" => "success", "message" => "Insumo entregado al técnico correctamente."]);
    } catch (Exception $e) {
        if ($pdo_inv->inTransaction()) $pdo_inv->rollBack();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);
