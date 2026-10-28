<?php
require_once __DIR__ . '/remisiones_auth.php';
rmaPermitir([2, 5]);

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
if (in_array($action,["ajustar","guardar"],true)) { http_response_code(410); echo json_encode(["status"=>"error","message"=>"Usá Asignaciones, Movimientos o Bajas de stock para registrar operaciones trazables."]); exit; }

/*==================================================
=        1. LISTAR MOVIMIENTOS DEL KARDEX         =
==================================================*/
if ($action === "listar") {
    try {
        $sql = "SELECT 
                    m.id,
                    m.id_stock,
                    s.nombre AS insumo_nombre,
                    s.codigo_sku,
                    m.tipo_movimiento,
                    m.cantidad,
                    m.stock_anterior,
                    m.stock_nuevo,
                    m.id_caso,
                    c.numero_caso,
                    m.id_usuario,
                    u.nombre AS usuario_nombre,
                    m.motivo,
                    m.fecha
                FROM dosisma_rma_bd.movimientos_stock m
                INNER JOIN dosisma_rma_bd.stock s ON m.id_stock = s.id
                LEFT JOIN dosisma_rma_bd.casos c ON m.id_caso = c.id
                LEFT JOIN dosisma_rma_bd.usuarios u ON m.id_usuario = u.id
                ORDER BY m.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "movimientos" => $movimientos
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al obtener movimientos de kardex: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=        2. INSUMOS AUXILIARES PARA EL MODAL       =
==================================================*/
if ($action === "aux_stock") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, cantidad FROM dosisma_rma_bd.stock ORDER BY nombre ASC");
        $stmt->execute();
        $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "stock" => $stock
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al consultar lista de stock: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=        3. REGISTRAR AJUSTE MANUAL EN KARDEX     =
==================================================*/
if ($action === "ajustar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $id_stock = intval($input["id_stock"] ?? 0);
        $tipo_movimiento = trim($input["tipo_movimiento"] ?? "AJUSTE_INVENTARIO");
        $cantidad_ingresada = intval($input["cantidad"] ?? 0);
        $motivo = trim($input["motivo"] ?? "");
        $id_usuario = intval($_SESSION['id_usuario'] ?? 1);

        if ($id_stock <= 0 || $cantidad_ingresada <= 0 || $motivo === "") {
            throw new Exception("Debe seleccionar un insumo, especificar la cantidad y proveer el motivo del ajuste.");
        }

        $pdo->beginTransaction();

        // Obtener stock actual
        $stmtCheck = $pdo->prepare("SELECT cantidad FROM dosisma_rma_bd.stock WHERE id = ? FOR UPDATE");
        $stmtCheck->execute([$id_stock]);
        $stockActual = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$stockActual) {
            throw new Exception("El insumo seleccionado no existe.");
        }

        $stockAnterior = intval($stockActual['cantidad']);

        // Calcular nuevo stock según tipo de ajuste
        if ($tipo_movimiento === "SALIDA_RMA") {
            if ($stockAnterior < $cantidad_ingresada) {
                throw new Exception("No es posible realizar una salida por cantidad mayor al stock disponible.");
            }
            $stockNuevo = $stockAnterior - $cantidad_ingresada;
        } else {
            // ENTRADA, DEVOLUCION, AJUSTE_INVENTARIO
            $stockNuevo = $stockAnterior + $cantidad_ingresada;
        }

        // Actualizar la tabla stock
        $stmtUpdate = $pdo->prepare("UPDATE dosisma_rma_bd.stock SET cantidad = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$stockNuevo, $id_stock]);

        // Registrar el movimiento en el Kardex
        $sqlMov = "INSERT INTO dosisma_rma_bd.movimientos_stock 
                    (id_stock, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, id_usuario, motivo, fecha) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmtMov = $pdo->prepare($sqlMov);
        $stmtMov->execute([
            $id_stock,
            $tipo_movimiento,
            $cantidad_ingresada,
            $stockAnterior,
            $stockNuevo,
            $id_usuario,
            $motivo
        ]);

        $pdo->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Ajuste de inventario ejecutado con éxito y registrado en el log auditor de Kardex."
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

// Fallback
echo json_encode([
    "status" => "error",
    "message" => "Protocolo de kardex no reconocido por la matriz de control."
]);
