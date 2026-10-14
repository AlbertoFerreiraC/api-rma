<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db_inventario.php";

if (!isset($pdo_inv)) {
    echo json_encode([
        "status" => "error",
        "message" => "No fue posible establecer conexión con la base de datos de inventario."
    ]);
    exit;
}

$action = $_GET["action"] ?? "";

/*==================================================
=        1. CONSULTAR ALERTAS Y MÉTRICAS KPIS      =
==================================================*/
if ($action === "consultar_alertas") {
    try {
        // Consultar productos con alertas o sin stock basados en la nueva estructura
        $sqlAlertas = "SELECT 
                            id,
                            codigo,
                            nombre,
                            categoria,
                            marca,
                            stock_actual,
                            stock_rma,
                            stock_disponible,
                            estado_alerta
                        FROM productos
                        WHERE estado_alerta != 'OK'
                        ORDER BY stock_disponible ASC, nombre ASC";

        $stmtAlertas = $pdo_inv->prepare($sqlAlertas);
        $stmtAlertas->execute();
        $alertas = $stmtAlertas->fetchAll(PDO::FETCH_ASSOC);

        // Cálculo de KPIs del inventario
        $stmtTotales = $pdo_inv->query("SELECT 
            COUNT(*) as total_productos,
            SUM(stock_actual) as total_unidades,
            SUM(stock_rma) as total_rma,
            SUM(CASE WHEN estado_alerta = 'SIN STOCK' THEN 1 ELSE 0 END) as sin_stock,
            SUM(CASE WHEN estado_alerta = 'ALERTA - STOCK BAJO' THEN 1 ELSE 0 END) as stock_bajo,
            SUM(CASE WHEN estado_alerta = 'OK' THEN 1 ELSE 0 END) as stock_ok
            FROM productos");
        
        $kpisGlobales = $stmtTotales->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "alertas" => $alertas,
            "kpis" => [
                "total_productos" => intval($kpisGlobales['total_productos'] ?? 0),
                "unidades_totales" => intval($kpisGlobales['total_unidades'] ?? 0),
                "en_proceso_rma" => intval($kpisGlobales['total_rma'] ?? 0),
                "sin_stock" => intval($kpisGlobales['sin_stock'] ?? 0),
                "stock_bajo" => intval($kpisGlobales['stock_bajo'] ?? 0),
                "stock_ok" => intval($kpisGlobales['stock_ok'] ?? 0)
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al obtener las alertas de stock: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=        2. REABASTECER / REPOSICIÓN DE STOCK      =
==================================================*/
if ($action === "reabastecer" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $id_producto = intval($input["id"] ?? 0);
        $cantidad_ingreso = intval($input["cantidad_ingreso"] ?? 0);
        $motivo = trim($input["motivo"] ?? "Reposición de stock por inventario.");
        $id_usuario = intval($_SESSION['id_usuario'] ?? 1);

        if ($id_producto <= 0 || $cantidad_ingreso <= 0) {
            throw new Exception("Datos incompletos o cantidad no válida para la reposición.");
        }

        $pdo_inv->beginTransaction();

        $stmtCheck = $pdo_inv->prepare("SELECT stock_actual, stock_rma FROM productos WHERE id = ? FOR UPDATE");
        $stmtCheck->execute([$id_producto]);
        $prod = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("El producto seleccionado no existe en el inventario.");
        }

        $stockAnterior = intval($prod['stock_actual']);
        $stockRma = intval($prod['stock_rma']);
        $stockNuevo = $stockAnterior + $cantidad_ingreso;
        $stockDisponibleNuevo = $stockNuevo - $stockRma;

        // Evaluar nuevo estado de alerta
        $estadoAlerta = 'OK';
        if ($stockNuevo <= 0) {
            $estadoAlerta = 'SIN STOCK';
        } elseif ($stockDisponibleNuevo <= 5) { // Umbral configurable
            $estadoAlerta = 'ALERTA - STOCK BAJO';
        }

        // Actualizar producto
        $stmtUpdate = $pdo_inv->prepare("UPDATE productos SET stock_actual = ?, stock_disponible = ?, estado_alerta = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$stockNuevo, $stockDisponibleNuevo, $estadoAlerta, $id_producto]);

        // Registrar en Kardex
        $stmtMov = $pdo_inv->prepare("INSERT INTO movimientos_inventario (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, observacion) VALUES (?, 'REPOSICION', ?, ?, ?, ?, ?)");
        $stmtMov->execute([$id_producto, $cantidad_ingreso, $stockAnterior, $stockNuevo, $id_usuario, $motivo]);

        $pdo_inv->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Reposición registrada correctamente. Stock actual actualizado a " . $stockNuevo . " unidades."
        ]);

    } catch (Exception $e) {
        if ($pdo_inv->inTransaction()) {
            $pdo_inv->rollBack();
        }
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida en API de alertas."]);