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

if ($action === "consultar_fallas") {
    try {
        // 1. Total general de casos de RMA
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos");
        $stmtTotal->execute();
        $totalCasos = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // 2. Modelo más crítico
        $sqlModCritico = "SELECT modelo, COUNT(*) AS total 
                          FROM dosisma_rma_bd.casos 
                          GROUP BY modelo 
                          ORDER BY total DESC LIMIT 1";
        $stmtMC = $pdo->prepare($sqlModCritico);
        $stmtMC->execute();
        $modCriticoRow = $stmtMC->fetch(PDO::FETCH_ASSOC);
        $modeloCritico = $modCriticoRow ? $modCriticoRow['modelo'] : 'N/A';

        // 3. Total de modelos distintos auditados
        $stmtModelos = $pdo->prepare("SELECT COUNT(DISTINCT modelo) AS total FROM dosisma_rma_bd.casos");
        $stmtModelos->execute();
        $totalModelos = $stmtModelos->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // 4. Índice de severidad
        $sqlSeveros = "SELECT COUNT(*) AS severos 
                       FROM dosisma_rma_bd.casos c
                       WHERE EXISTS (SELECT 1 FROM dosisma_rma_bd.caso_insumos ci WHERE ci.id_caso = c.id)
                          OR c.diagnostico_final LIKE '%cambio%' 
                          OR c.diagnostico_final LIKE '%reemplazo%' 
                          OR c.diagnostico_final LIKE '%placa%'";
        $stmtSev = $pdo->prepare($sqlSeveros);
        $stmtSev->execute();
        $casosSeveros = $stmtSev->fetch(PDO::FETCH_ASSOC)['severos'] ?? 0;
        $indiceSeveridad = $totalCasos > 0 ? round(($casosSeveros / $totalCasos) * 100, 1) : 0;

        // 5. Pareto de Marcas
        $sqlPareto = "SELECT marca, COUNT(*) AS cantidad 
                      FROM dosisma_rma_bd.casos 
                      GROUP BY marca 
                      ORDER BY cantidad DESC";
        $stmtP = $pdo->prepare($sqlPareto);
        $stmtP->execute();
        $paretoRaw = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        $acumulado = 0;
        $paretoMarcas = [];
        foreach ($paretoRaw as $p) {
            $cant = intval($p['cantidad']);
            $acumulado += $cant;
            $pctAcum = $totalCasos > 0 ? round(($acumulado / $totalCasos) * 100, 1) : 0;

            $paretoMarcas[] = [
                "marca" => $p['marca'],
                "cantidad" => $cant,
                "porcentaje_acumulado" => $pctAcum
            ];
        }

        // 6. Cluster de Fallas
        $conceptosClave = ["Pantalla", "Encendido", "Cargador", "Batería", "Teclado", "Disco", "Placa", "Calentamiento", "Software"];
        $clusterFallas = [];
        $fallaTopNombre = "N/A";
        $maxFallaQty = -1;

        foreach ($conceptosClave as $concepto) {
            $stmtConcepto = $pdo->prepare("SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos WHERE descripcion_problema LIKE :kw OR diagnostico_final LIKE :kw");
            $kw = "%" . $concepto . "%";
            $stmtConcepto->bindParam(":kw", $kw);
            $stmtConcepto->execute();
            $cantFalla = intval($stmtConcepto->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            if ($cantFalla > 0) {
                $clusterFallas[] = [
                    "concepto" => $concepto,
                    "total" => $cantFalla
                ];
                if ($cantFalla > $maxFallaQty) {
                    $maxFallaQty = $cantFalla;
                    $fallaTopNombre = $concepto;
                }
            }
        }

        // 7. Top 10 Modelos Críticos (Compatible con ONLY_FULL_GROUP_BY)
        $sqlTopModelos = "SELECT c.marca, c.modelo, COUNT(*) AS total_reclamos,
                                 COALESCE(MAX(tc.nombre), 'Garantía Estándar') AS tipo_caso_predominante,
                                 MIN(c.descripcion_problema) AS falla_frecuente
                          FROM dosisma_rma_bd.casos c
                          LEFT JOIN dosisma_rma_bd.tipos_caso tc ON c.id_tipo_caso = tc.id
                          GROUP BY c.marca, c.modelo
                          ORDER BY total_reclamos DESC LIMIT 10";
        $stmtTM = $pdo->prepare($sqlTopModelos);
        $stmtTM->execute();
        $topModelosRaw = $stmtTM->fetchAll(PDO::FETCH_ASSOC);

        $topModelos = [];
        foreach ($topModelosRaw as $tm) {
            $reclamos = intval($tm['total_reclamos']);
            $pct = $totalCasos > 0 ? round(($reclamos / $totalCasos) * 100, 1) : 0;

            $severidadStr = "LEVE";
            if ($reclamos >= 5 || $pct >= 10)
                $severidadStr = "ALTA";
            else if ($reclamos >= 2 || $pct >= 5)
                $severidadStr = "MEDIA";

            $topModelos[] = [
                "marca" => $tm['marca'],
                "modelo" => $tm['modelo'],
                "total_reclamos" => $reclamos,
                "porcentaje_total" => $pct,
                "falla_frecuente" => $tm['falla_frecuente'] ?? 'Sin detalle',
                "tipo_caso_predominante" => $tm['tipo_caso_predominante'],
                "severidad" => $severidadStr
            ];
        }

        echo json_encode([
            "status" => "success",
            "kpis" => [
                "modelo_critico" => $modeloCritico,
                "indice_severidad" => $indiceSeveridad,
                "total_modelos" => $totalModelos,
                "falla_top" => $fallaTopNombre
            ],
            "pareto_marcas" => $paretoMarcas,
            "cluster_fallas" => $clusterFallas,
            "top_modelos" => $topModelos
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error procesando análisis de confiabilidad: " . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);