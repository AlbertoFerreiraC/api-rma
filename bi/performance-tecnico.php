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

if ($action === "consultar_performance") {
    try {
        // Consulta de métricas por técnico activo
        $sqlTecnicos = "SELECT 
                            u.id,
                            u.nombre,
                            COUNT(c.id) AS casos_asignados,
                            SUM(CASE WHEN c.fecha_cierre IS NOT NULL THEN 1 ELSE 0 END) AS casos_resueltos,
                            SUM(CASE WHEN c.fecha_cierre IS NULL THEN 1 ELSE 0 END) AS casos_pendientes,
                            IFNULL(ROUND((SUM(CASE WHEN c.fecha_cierre IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(c.id), 0)) * 100, 1), 0) AS tasa_cierre,
                            IFNULL(ROUND(AVG(CASE WHEN c.fecha_cierre IS NOT NULL THEN DATEDIFF(c.fecha_cierre, c.fecha_ingreso) ELSE NULL END), 1), 0) AS mttr_dias,
                            IFNULL((SELECT SUM(ci.costo_aplicado * ci.cantidad) FROM dosisma_rma_bd.caso_insumos ci WHERE ci.id_tecnico = u.id), 0) AS costo_insumos
                        FROM dosisma_rma_bd.usuarios u
                        INNER JOIN dosisma_rma_bd.roles r ON u.id_rol = r.id
                        LEFT JOIN dosisma_rma_bd.casos c ON u.id = c.id_tecnico
                        WHERE r.nombre LIKE '%técnico%' OR r.nombre LIKE '%tecnico%' OR u.id_rol = 1
                        GROUP BY u.id, u.nombre
                        ORDER BY casos_resueltos DESC";

        $stmtTec = $pdo->prepare($sqlTecnicos);
        $stmtTec->execute();
        $tecnicos = $stmtTec->fetchAll(PDO::FETCH_ASSOC);

        // Si no hay usuarios explícitamente con rol técnico, consultar todos los operadores con casos asignados
        if (count($tecnicos) === 0) {
            $sqlTecnicosFallback = "SELECT 
                                        u.id,
                                        u.nombre,
                                        COUNT(c.id) AS casos_asignados,
                                        SUM(CASE WHEN c.fecha_cierre IS NOT NULL THEN 1 ELSE 0 END) AS casos_resueltos,
                                        SUM(CASE WHEN c.fecha_cierre IS NULL THEN 1 ELSE 0 END) AS casos_pendientes,
                                        IFNULL(ROUND((SUM(CASE WHEN c.fecha_cierre IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(c.id), 0)) * 100, 1), 0) AS tasa_cierre,
                                        IFNULL(ROUND(AVG(CASE WHEN c.fecha_cierre IS NOT NULL THEN DATEDIFF(c.fecha_cierre, c.fecha_ingreso) ELSE NULL END), 1), 0) AS mttr_dias,
                                        IFNULL((SELECT SUM(ci.costo_aplicado * ci.cantidad) FROM dosisma_rma_bd.caso_insumos ci WHERE ci.id_tecnico = u.id), 0) AS costo_insumos
                                    FROM dosisma_rma_bd.usuarios u
                                    LEFT JOIN dosisma_rma_bd.casos c ON u.id = c.id_tecnico
                                    GROUP BY u.id, u.nombre
                                    HAVING casos_asignados > 0
                                    ORDER BY casos_resueltos DESC";
            $stmtFB = $pdo->prepare($sqlTecnicosFallback);
            $stmtFB->execute();
            $tecnicos = $stmtFB->fetchAll(PDO::FETCH_ASSOC);
        }

        // Totales y KPIs
        $totalAsignadosSum = array_sum(array_column($tecnicos, 'casos_asignados'));
        $totalResueltosSum = array_sum(array_column($tecnicos, 'casos_resueltos'));
        $costoTotalInsumos = array_sum(array_column($tecnicos, 'costo_insumos'));

        $tasaCierreGlobal = $totalAsignadosSum > 0 ? round(($totalResueltosSum / $totalAsignadosSum) * 100, 1) : 0;

        $mttrArray = array_filter(array_column($tecnicos, 'mttr_dias'), function ($v) {
            return $v > 0; });
        $mttrGlobal = count($mttrArray) > 0 ? round(array_sum($mttrArray) / count($mttrArray), 1) : 0;

        $topTecnico = count($tecnicos) > 0 ? $tecnicos[0]['nombre'] : 'N/A';

        echo json_encode([
            "status" => "success",
            "kpis" => [
                "tasa_cierre_global" => $tasaCierreGlobal,
                "mttr_global" => $mttrGlobal,
                "top_tecnico" => $topTecnico,
                "costo_total_insumos" => $costoTotalInsumos
            ],
            "tecnicos" => $tecnicos
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al obtener rendimiento técnico: " . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);