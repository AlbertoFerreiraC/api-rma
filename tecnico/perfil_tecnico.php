<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Sin conexión con la base de datos."]);
    exit;
}

$action = $_GET["action"] ?? $_POST["action"] ?? "";

if ($action === "consultar_estacion") {
    try {
        $idTecnico = $_GET["id_tecnico"] ?? null;

        // Condición para filtrar por técnico o mostrar todos si es superusuario
        $whereTecnico = "";
        $paramsWhere = [];
        if (!empty($idTecnico)) {
            $whereTecnico = " WHERE c.id_tecnico = :id_tecnico ";
            $paramsWhere[":id_tecnico"] = $idTecnico;
        }

        // 1. KPIs del Banco del Técnico
        $sqlAsignadas = "SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos c $whereTecnico AND c.fecha_cierre IS NULL";
        if (empty($whereTecnico))
            $sqlAsignadas = "SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos c WHERE c.fecha_cierre IS NULL";

        $stmtAsig = $pdo->prepare($sqlAsignadas);
        $stmtAsig->execute($paramsWhere);
        $asignadas = $stmtAsig->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Subcola (Diagnóstico / Reparación)
        $sqlDiag = "SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos c 
                    INNER JOIN dosisma_rma_bd.estados_caso e ON c.id_estado_actual = e.id 
                    " . (empty($whereTecnico) ? "WHERE" : $whereTecnico . " AND") . " e.nombre LIKE '%diagnóstico%' AND c.fecha_cierre IS NULL";
        $stmtD = $pdo->prepare($sqlDiag);
        $stmtD->execute($paramsWhere);
        $enDiag = $stmtD->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        $sqlRep = "SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos c 
                   INNER JOIN dosisma_rma_bd.estados_caso e ON c.id_estado_actual = e.id 
                   " . (empty($whereTecnico) ? "WHERE" : $whereTecnico . " AND") . " (e.nombre LIKE '%reparación%' OR e.nombre LIKE '%taller%') AND c.fecha_cierre IS NULL";
        $stmtR = $pdo->prepare($sqlRep);
        $stmtR->execute($paramsWhere);
        $enRep = $stmtR->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Reparados hoy
        $sqlHoy = "SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos c 
                   " . (empty($whereTecnico) ? "WHERE" : $whereTecnico . " AND") . " DATE(c.fecha_cierre) = CURDATE()";
        $stmtHoy = $pdo->prepare($sqlHoy);
        $stmtHoy->execute($paramsWhere);
        $reparadosHoy = $stmtHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Órdenes Trabadas (Espera de repuesto o en proveedor)
        $sqlTrab = "SELECT COUNT(*) AS total FROM dosisma_rma_bd.casos c 
                    INNER JOIN dosisma_rma_bd.estados_caso e ON c.id_estado_actual = e.id 
                    " . (empty($whereTecnico) ? "WHERE" : $whereTecnico . " AND") . " (e.nombre LIKE '%espera%' OR e.nombre LIKE '%proveedor%') AND c.fecha_cierre IS NULL";
        $stmtTrab = $pdo->prepare($sqlTrab);
        $stmtTrab->execute($paramsWhere);
        $ordenesTrabadas = $stmtTrab->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // 2. Tablas Máquinas en Proceso (Casos activos)
        $sqlCasosProc = "SELECT c.id, c.numero_caso, c.equipo, c.marca, c.modelo, c.descripcion_problema, c.diagnostico_final,
                                c.id_estado_actual, e.nombre AS estado_nombre, cl.nombre AS cliente_nombre
                         FROM dosisma_rma_bd.casos c
                         LEFT JOIN dosisma_rma_bd.estados_caso e ON c.id_estado_actual = e.id
                         LEFT JOIN dosisma_rma_bd.clientes cl ON c.id_cliente = cl.id
                         " . (empty($whereTecnico) ? "WHERE c.fecha_cierre IS NULL" : $whereTecnico . " AND c.fecha_cierre IS NULL") . "
                         ORDER BY c.id DESC LIMIT 10";
        $stmtProc = $pdo->prepare($sqlCasosProc);
        $stmtProc->execute($paramsWhere);
        $casosProceso = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

        // 3. Tabla Despachos Recientes
        $sqlCasosDesp = "SELECT c.id, c.numero_caso, c.equipo, c.marca, c.modelo, c.diagnostico_final,
                                DATE_FORMAT(c.fecha_cierre, '%d/%m %H:%i') AS fecha_cierre
                         FROM dosisma_rma_bd.casos c
                         " . (empty($whereTecnico) ? "WHERE c.fecha_cierre IS NOT NULL" : $whereTecnico . " AND c.fecha_cierre IS NOT NULL") . "
                         ORDER BY c.fecha_cierre DESC LIMIT 5";
        $stmtDesp = $pdo->prepare($sqlCasosDesp);
        $stmtDesp->execute($paramsWhere);
        $casosDespachados = $stmtDesp->fetchAll(PDO::FETCH_ASSOC);

        // 4. Catálogos para el modal (Estados e Insumos)
        $stmtEst = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.estados_caso ORDER BY id ASC");
        $stmtEst->execute();
        $estadosCaso = $stmtEst->fetchAll(PDO::FETCH_ASSOC);

        $stmtStk = $pdo->prepare("SELECT id, nombre, cantidad, precio_venta FROM dosisma_rma_bd.stock WHERE cantidad > 0 ORDER BY nombre ASC");
        $stmtStk->execute();
        $stockInsumos = $stmtStk->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "kpis" => [
                "asignadas" => $asignadas,
                "en_diagnostico" => $enDiag,
                "en_reparacion" => $enRep,
                "reparados_hoy" => $reparadosHoy,
                "tasa_exito" => 98.5,
                "reingresos" => 0,
                "ordenes_trabadas" => $ordenesTrabadas
            ],
            "casos_proceso" => $casosProceso,
            "casos_despachados" => $casosDespachados,
            "estados_caso" => $estadosCaso,
            "stock_insumos" => $stockInsumos
        ]);

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Error en perfil técnico: " . $e->getMessage()]);
    }
    exit;
}

// Guardar Avance Técnico desde Modal
if ($action === "guardar_avance") {
    try {
        $idCaso = $_POST["id_caso"] ?? null;
        $idEstado = $_POST["id_estado"] ?? null;
        $idInsumo = $_POST["id_insumo"] ?? null;
        $diagnostico = $_POST["diagnostico"] ?? "";
        $idTecnico = $_POST["id_tecnico"] ?? null;

        if (!$idCaso || !$idEstado) {
            echo json_encode(["status" => "error", "message" => "Faltan parámetros obligatorios."]);
            exit;
        }

        // 1. Obtener nombre del estado para verificar si es cierre
        $stmtEstNom = $pdo->prepare("SELECT nombre FROM dosisma_rma_bd.estados_caso WHERE id = :id");
        $stmtEstNom->execute([":id" => $idEstado]);
        $nombreEstado = strtolower($stmtEstNom->fetch(PDO::FETCH_ASSOC)['nombre'] ?? '');

        $fechaCierre = null;
        if (strpos($nombreEstado, 'listo') !== false || strpos($nombreEstado, 'entregado') !== false || strpos($nombreEstado, 'resuelto') !== false) {
            $fechaCierre = date('Y-m-d H:i:s');
        }

        // 2. Actualizar Caso
        $sqlUp = "UPDATE dosisma_rma_bd.casos 
                  SET id_estado_actual = :id_estado, 
                      diagnostico_final = :diagnostico " .
            ($fechaCierre ? ", fecha_cierre = '$fechaCierre' " : "") .
            "WHERE id = :id_caso";
        $stmtUp = $pdo->prepare($sqlUp);
        $stmtUp->execute([
            ":id_estado" => $idEstado,
            ":diagnostico" => $diagnostico,
            ":id_caso" => $idCaso
        ]);

        // 3. Insertar Historial de Estado
        $sqlHist = "INSERT INTO dosisma_rma_bd.historial_estados (id_caso, id_estado, fecha, observacion, id_usuario)
                    VALUES (:id_caso, :id_estado, NOW(), :obs, :id_usuario)";
        $stmtHist = $pdo->prepare($sqlHist);
        $stmtHist->execute([
            ":id_caso" => $idCaso,
            ":id_estado" => $idEstado,
            ":obs" => $diagnostico,
            ":id_usuario" => $idTecnico
        ]);

        // 4. Si se aplicó un insumo de stock
        if (!empty($idInsumo)) {
            $stmtStkData = $pdo->prepare("SELECT costo_unitario, cantidad FROM dosisma_rma_bd.stock WHERE id = :id");
            $stmtStkData->execute([":id" => $idInsumo]);
            $stkRow = $stmtStkData->fetch(PDO::FETCH_ASSOC);

            if ($stkRow && $stkRow['cantidad'] > 0) {
                // Descontar stock
                $pdo->prepare("UPDATE dosisma_rma_bd.stock SET cantidad = cantidad - 1 WHERE id = :id")->execute([":id" => $idInsumo]);

                // Registrar en caso_insumos
                $sqlIns = "INSERT INTO dosisma_rma_bd.caso_insumos (id_caso, id_stock, id_tecnico, cantidad, costo_aplicado, fecha_aplicacion, observacion)
                           VALUES (:id_caso, :id_stock, :id_tecnico, 1, :costo, NOW(), :obs)";
                $stmtIns = $pdo->prepare($sqlIns);
                $stmtIns->execute([
                    ":id_caso" => $idCaso,
                    ":id_stock" => $idInsumo,
                    ":id_tecnico" => $idTecnico,
                    ":costo" => $stkRow['costo_unitario'],
                    ":obs" => "Aplicado desde estación de trabajo"
                ]);
            }
        }

        echo json_encode(["status" => "success", "message" => "Avance registrado correctamente."]);

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Error guardando avance: " . $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);