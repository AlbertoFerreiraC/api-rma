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

/*==================================================
=        1. CONSULTAR ALERTAS Y METRICAS KPIS     =
==================================================*/
if ($action === "consultar_alertas") {
    try {
        // Consulta de insumos con cantidad menor o igual a stock_minimo
        $sqlAlertas = "SELECT 
                            s.id,
                            s.codigo_sku,
                            s.nombre,
                            c.nombre AS categoria_nombre,
                            p.nombre AS proveedor_nombre,
                            s.cantidad,
                            s.stock_minimo,
                            s.costo_unitario
                        FROM dosisma_rma_bd.stock s
                        LEFT JOIN dosisma_rma_bd.categorias_stock c ON s.id_categoria = c.id
                        LEFT JOIN dosisma_rma_bd.proveedores p ON s.id_proveedor_habitual = p.id
                        WHERE s.cantidad <= s.stock_minimo
                        ORDER BY s.cantidad ASC, s.nombre ASC";

        $stmtAlertas = $pdo->prepare($sqlAlertas);
        $stmtAlertas->execute();
        $alertas = $stmtAlertas->fetchAll(PDO::FETCH_ASSOC);

        // Cálculo de KPIs
        $agotados = 0;
        $criticos = 0;
        $proveedoresUnicos = [];
        $costoReposicionEstimado = 0;

        foreach ($alertas as $item) {
            if (intval($item['cantidad']) <= 0) {
                $agotados++;
            } else {
                $criticos++;
            }

            if (!empty($item['proveedor_nombre'])) {
                $proveedoresUnicos[$item['proveedor_nombre']] = true;
            }

            // Deficit para cubrir stock mínimo
            $deficit = intval($item['stock_minimo']) - intval($item['cantidad']);
            $unidadesAComprar = $deficit > 0 ? $deficit : 1;
            $costoReposicionEstimado += ($unidadesAComprar * floatval($item['costo_unitario']));
        }

        echo json_encode([
            "status" => "success",
            "alertas" => $alertas,
            "kpis" => [
                "agotados" => $agotados,
                "criticos" => $criticos,
                "proveedores_impactados" => count($proveedoresUnicos),
                "costo_reposicion_estimado" => $costoReposicionEstimado
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
=        2. REABASTECER INSUMO (RESTOCK)          =
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

        $id_stock = intval($input["id"] ?? 0);
        $cantidad_ingreso = intval($input["cantidad_ingreso"] ?? 0);
        $motivo = trim($input["motivo"] ?? "");
        $id_usuario = intval($_SESSION['id_usuario'] ?? 1); // fallback ID 1 si no hay sesión activa

        if ($id_stock <= 0 || $cantidad_ingreso <= 0) {
            throw new Exception("Estructura de datos incompleta o cantidad no válida para el reabastecimiento.");
        }

        if ($motivo === "") {
            $motivo = "Reabastecimiento directo de inventario por alerta de stock mínimo.";
        }

        $pdo->beginTransaction();

        // 1. Consultar stock actual
        $stmtConsultar = $pdo->prepare("SELECT cantidad FROM dosisma_rma_bd.stock WHERE id = ? FOR UPDATE");
        $stmtConsultar->execute([$id_stock]);
        $insumoActual = $stmtConsultar->fetch(PDO::FETCH_ASSOC);

        if (!$insumoActual) {
            throw new Exception("El insumo seleccionado no existe en el catálogo.");
        }

        $stockAnterior = intval($insumoActual['cantidad']);
        $stockNuevo = $stockAnterior + $cantidad_ingreso;

        // 2. Actualizar la cantidad en la tabla stock
        $stmtUpdate = $pdo->prepare("UPDATE dosisma_rma_bd.stock SET cantidad = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$stockNuevo, $id_stock]);

        // 3. Registrar el log de 'ENTRADA' en la tabla movimientos_stock
        $sqlMov = "INSERT INTO dosisma_rma_bd.movimientos_stock 
                    (id_stock, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, id_usuario, motivo, fecha) 
                   VALUES (?, 'ENTRADA', ?, ?, ?, ?, ?, NOW())";

        $stmtMov = $pdo->prepare($sqlMov);
        $stmtMov->execute([
            $id_stock,
            $cantidad_ingreso,
            $stockAnterior,
            $stockNuevo,
            $id_usuario,
            $motivo
        ]);

        $pdo->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Stock reabastecido con éxito. Se han ingresado " . $cantidad_ingreso . " unidades al inventario."
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

// Fallback por si ejecutan una acción errónea
echo json_encode([
    "status" => "error",
    "message" => "Protocolo de alertas no reconocido por la matriz de control."
]);
