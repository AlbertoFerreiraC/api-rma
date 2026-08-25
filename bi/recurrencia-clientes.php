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

if ($action === "consultar_recurrencia") {
    try {
        // Matriz completa con los campos exactos: cedula y celular
        $sqlClientes = "SELECT 
                            cl.id,
                            cl.nombre,
                            IFNULL(cl.cedula, 'S/N') AS documento,
                            IFNULL(cl.celular, 'S/N') AS telefono,
                            COUNT(c.id) AS total_casos,
                            SUM(CASE WHEN c.fecha_cierre IS NOT NULL THEN 1 ELSE 0 END) AS casos_resueltos,
                            IFNULL(ROUND(AVG(DATEDIFF(IFNULL(c.fecha_cierre, NOW()), c.fecha_ingreso)), 1), 0) AS sla_promedio
                        FROM dosisma_rma_bd.clientes cl
                        INNER JOIN dosisma_rma_bd.casos c ON cl.id = c.id_cliente
                        GROUP BY cl.id, cl.nombre, cl.cedula, cl.celular
                        ORDER BY total_casos DESC";

        $stmtC = $pdo->prepare($sqlClientes);
        $stmtC->execute();
        $clientes = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $totalClientes = count($clientes);
        $reincidentes = 0;
        $segUnico = 0;
        $segOcasional = 0;
        $segAlto = 0;

        foreach ($clientes as $c) {
            $totalC = intval($c['total_casos']);
            if ($totalC > 1) {
                $reincidentes++;
            }

            if ($totalC === 1) {
                $segUnico++;
            } else if ($totalC <= 3) {
                $segOcasional++;
            } else {
                $segAlto++;
            }
        }

        $indiceRecurrencia = $totalClientes > 0 ? round(($reincidentes / $totalClientes) * 100, 1) : 0;
        $topClienteNombre = $totalClientes > 0 ? $clientes[0]['nombre'] : 'N/A';

        // SLA Promedio global percibido por el cliente
        $slasArray = array_filter(array_column($clientes, 'sla_promedio'), function ($v) {
            return $v > 0; });
        $slaPromedioGlobalCliente = count($slasArray) > 0 ? round(array_sum($slasArray) / count($slasArray), 1) : 0;

        // Top 5 Clientes para gráfico
        $topClientes = array_slice($clientes, 0, 5);

        echo json_encode([
            "status" => "success",
            "kpis" => [
                "total_clientes" => $totalClientes,
                "indice_recurrencia" => $indiceRecurrencia,
                "top_cliente_nombre" => $topClienteNombre,
                "sla_promedio_cliente" => $slaPromedioGlobalCliente
            ],
            "segmentacion" => [
                "unico" => $segUnico,
                "ocasional" => $segOcasional,
                "alto" => $segAlto
            ],
            "top_clientes" => $topClientes,
            "clientes" => $clientes
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error procesando fidelización de clientes: " . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);