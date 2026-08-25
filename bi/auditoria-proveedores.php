<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode([
        "status" => "error",
        "message" => "Sin conexión con la base de datos."
    ]);
    exit;
}

$action = $_GET["action"] ?? "";

if ($action === "consultar_proveedores") {
    try {
        // 1. Total proveedores registrados
        $stmtTot = $pdo->prepare("SELECT COUNT(*) AS total FROM dosisma_rma_bd.proveedores");
        $stmtTot->execute();
        $totalProveedores = $stmtTot->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // 2. Casos actualmente derivados / en gestión externa
        $stmtExt = $pdo->prepare("SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos WHERE id_proveedor IS NOT NULL AND fecha_cierre IS NULL");
        $stmtExt->execute();
        $casosEnProveedor = $stmtExt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // 3. Matriz completa de proveedores y sus métricas SLA
        $sqlProvMatriz = "SELECT 
                            p.id,
                            p.nombre,
                            p.contacto,
                            IFNULL((SELECT SUM(s.cantidad) FROM dosisma_rma_bd.stock s WHERE s.id_proveedor_habitual = p.id), 0) AS insumos_habituales,
                            COUNT(c.id) AS casos_derivados,
                            SUM(CASE WHEN c.fecha_cierre IS NULL AND c.id_proveedor IS NOT NULL THEN 1 ELSE 0 END) AS casos_pendientes,
                            IFNULL(ROUND(AVG(CASE WHEN c.fecha_envio_proveedor IS NOT NULL THEN DATEDIFF(IFNULL(c.fecha_cierre, NOW()), c.fecha_envio_proveedor) ELSE NULL END), 1), 0) AS sla_dias
                          FROM dosisma_rma_bd.proveedores p
                          LEFT JOIN dosisma_rma_bd.casos c ON p.id = c.id_proveedor
                          GROUP BY p.id, p.nombre, p.contacto
                          ORDER BY casos_derivados DESC";

        $stmtPM = $pdo->prepare($sqlProvMatriz);
        $stmtPM->execute();
        $proveedores = $stmtPM->fetchAll(PDO::FETCH_ASSOC);

        // Proveedor con mayor incidencia
        $topProveedor = count($proveedores) > 0 ? $proveedores[0]['nombre'] : 'N/A';

        // SLA Global Promedio de respuesta de proveedores
        $slasArray = array_filter(array_column($proveedores, 'sla_dias'), function ($v) {
            return $v > 0; });
        $slaPromedioGlobal = count($slasArray) > 0 ? round(array_sum($slasArray) / count($slasArray), 1) : 0;

        echo json_encode([
            "status" => "success",
            "kpis" => [
                "total_proveedores" => $totalProveedores,
                "sla_promedio_global" => $slaPromedioGlobal,
                "casos_en_gestion_externa" => $casosEnProveedor,
                "top_proveedor" => $topProveedor
            ],
            "proveedores" => $proveedores
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error procesando auditoría de proveedores: " . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);