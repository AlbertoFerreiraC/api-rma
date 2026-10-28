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

if ($action === "consultar_sla") {
    try {
        // 1. Tiempos promedio de estancia por estado (días)
        $sqlTiemposEstado = "SELECT e.nombre AS estado,
                                    ROUND(AVG(TIMESTAMPDIFF(HOUR, h.fecha, IFNULL(
                                        (SELECT h2.fecha FROM dosisma_rma_bd.historial_estados h2 
                                         WHERE h2.id_caso = h.id_caso AND h2.id > h.id ORDER BY h2.id ASC LIMIT 1), NOW()
                                    ))) / 24, 1) AS dias_promedio
                             FROM dosisma_rma_bd.historial_estados h
                             INNER JOIN dosisma_rma_bd.estados_caso e ON h.id_estado = e.id
                             GROUP BY e.id, e.nombre
                             ORDER BY dias_promedio DESC";
        $stmtTE = $pdo->prepare($sqlTiemposEstado);
        $stmtTE->execute();
        $tiemposEstado = $stmtTE->fetchAll(PDO::FETCH_ASSOC);

        $estadoCritico = count($tiemposEstado) > 0 ? $tiemposEstado[0]['estado'] : 'N/A';

        // 2. Primera Respuesta (Promedio en Horas desde fecha_ingreso hasta primer registro en historial)
        $sqlPrimeraResp = "SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, c.fecha_ingreso, h.fecha)), 1) AS horas_promedio
                           FROM dosisma_rma_bd.casos c
                           INNER JOIN (
                               SELECT id_caso, MIN(fecha) AS fecha 
                               FROM dosisma_rma_bd.historial_estados 
                               GROUP BY id_caso
                           ) h ON c.id = h.id_caso";
        $stmtPR = $pdo->prepare($sqlPrimeraResp);
        $stmtPR->execute();
        $horasPrimeraResp = $stmtPR->fetch(PDO::FETCH_ASSOC)['horas_promedio'] ?? 0;

        // 3. Casos con Retrabajo (mismo estado insertado más de 1 vez para un caso)
        $sqlRetrabajos = "SELECT COUNT(DISTINCT id_caso) AS retrabajos FROM (
                            SELECT id_caso, id_estado, COUNT(*) AS veces 
                            FROM dosisma_rma_bd.historial_estados 
                            GROUP BY id_caso, id_estado 
                            HAVING veces > 1
                          ) AS sub";
        $stmtRet = $pdo->prepare($sqlRetrabajos);
        $stmtRet->execute();
        $casosRetrabajo = $stmtRet->fetch(PDO::FETCH_ASSOC)['retrabajos'] ?? 0;

        // 4. Detalle por caso para tabla y cálculo SLA general (SLA norma <= 3 días)
        $sqlDetalle = "SELECT c.id, c.numero_caso, cl.nombre AS cliente_nombre, e.nombre AS estado_actual,
                              c.fecha_ingreso,
                              IFNULL(ROUND(TIMESTAMPDIFF(HOUR, c.fecha_ingreso, (SELECT MIN(fecha) FROM dosisma_rma_bd.historial_estados WHERE id_caso = c.id)), 1), 0) AS horas_primera_resp,
                              IFNULL(DATEDIFF(NOW(), (SELECT MAX(fecha) FROM dosisma_rma_bd.historial_estados WHERE id_caso = c.id)), 0) AS dias_en_estado_actual,
                              DATEDIFF(IFNULL(c.fecha_cierre, NOW()), c.fecha_ingreso) AS dias_totales,
                              (SELECT COUNT(*) - COUNT(DISTINCT id_estado) FROM dosisma_rma_bd.historial_estados WHERE id_caso = c.id) AS retrabajos
                       FROM dosisma_rma_bd.casos c
                       LEFT JOIN dosisma_rma_bd.clientes cl ON c.id_cliente = cl.id
                       LEFT JOIN dosisma_rma_bd.estados_caso e ON c.id_estado_actual = e.id
                       ORDER BY c.id DESC";
        $stmtDet = $pdo->prepare($sqlDetalle);
        $stmtDet->execute();
        $casosDetalle = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        $casosEnSla = 0;
        $casosFueraSla = 0;
        foreach ($casosDetalle as $cd) {
            if ($cd['dias_totales'] <= 3) {
                $casosEnSla++;
            } else {
                $casosFueraSla++;
            }
        }
        $totalEvaluados = count($casosDetalle);
        $pctCumplimiento = $totalEvaluados > 0 ? round(($casosEnSla / $totalEvaluados) * 100, 1) : 100;

        echo json_encode([
            "status" => "success",
            "kpis" => [
                "primera_respuesta_hrs" => $horasPrimeraResp,
                "casos_retrabajo" => $casosRetrabajo,
                "sla_cumplimiento_pct" => $pctCumplimiento,
                "estado_critico" => $estadoCritico,
                "casos_en_sla" => $casosEnSla,
                "casos_fuera_sla" => $casosFueraSla
            ],
            "tiempos_estado" => $tiemposEstado,
            "casos_detalle" => $casosDetalle
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error procesando métricas SLA: " . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);
