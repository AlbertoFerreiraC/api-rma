<?php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../inventario/remisiones_auth.php';
rmaPermitir([1, 2]);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inventario/remisiones_service.php';

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Sin conexión con la base de datos."]);
    exit;
}

$action = $_GET["action"] ?? $_POST["action"] ?? "";

if ($action === "consultar_estacion") {
    try {
        $idTecnico = rmaRol() === 1 ? rmaUsuarioId() : ($_GET["id_tecnico"] ?? null);

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
            "stock_insumos" => []
        ]);

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Error en perfil técnico: " . $e->getMessage()]);
    }
    exit;
}

// Guardar avance y consumo con una sola transacción: nunca hay nota sin stock/consumo.
if ($action === 'guardar_avance') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Se requiere POST.']);
        exit;
    }
    rmaCsrfValidar();
    try {
        $idCaso = (int) ($_POST['id_caso'] ?? 0);
        $idEstado = (int) ($_POST['id_estado'] ?? 0);
        $idInsumo = (int) ($_POST['id_insumo'] ?? 0);
        if (rmaRol() === 1 && $idInsumo > 0) {
            throw new DomainException('Para pedir un repuesto usá Agregar remisión. El stock lo gestiona depósito.');
        }
        $diagnostico = trim((string) ($_POST['diagnostico'] ?? ''));
        $idTecnico = rmaUsuarioId();
        if ($idCaso < 1 || $idEstado < 1 || $diagnostico === '' || strlen($diagnostico) > 10000) {
            throw new InvalidArgumentException('Completá el caso, el estado y el informe técnico.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT nombre FROM estados_caso WHERE id = ?');
        $stmt->execute([$idEstado]);
        $nombreEstado = $stmt->fetchColumn();
        if ($nombreEstado === false) {
            throw new DomainException('El estado seleccionado no existe.');
        }
        $stmt = $pdo->prepare('SELECT id, fecha_cierre, id_tecnico FROM casos WHERE id = ? FOR UPDATE');
        $stmt->execute([$idCaso]);
        $caso = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($caso && rmaRol() === 1 && (int)$caso['id_tecnico'] !== rmaUsuarioId()) {
            throw new DomainException('Este caso no está asignado a tu usuario.');
        }
        if (!$caso || $caso['fecha_cierre'] !== null) {
            throw new DomainException('El caso ya fue cerrado o no existe.');
        }
        $remision = null;
        if ($idInsumo > 0) {
            $remision = rmaRegistrarConsumo($pdo, $idCaso, $idInsumo, $idTecnico, 1, null, 'Aplicado desde estación de trabajo: ' . $diagnostico, rmaUsuarioId());
        }
        $cerrar = preg_match('/listo|entregado|resuelto/iu', (string) $nombreEstado) === 1;
        $stmt = $pdo->prepare('UPDATE casos SET id_estado_actual = ?, diagnostico_final = ?, fecha_cierre = ' . ($cerrar ? 'NOW()' : 'NULL') . ' WHERE id = ?');
        $stmt->execute([$idEstado, $diagnostico, $idCaso]);
        $stmt = $pdo->prepare('INSERT INTO historial_estados (id_caso, id_estado, fecha, observacion, id_usuario) VALUES (?, ?, NOW(), ?, ?)');
        $stmt->execute([$idCaso, $idEstado, $diagnostico, $idTecnico]);
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => $remision
            ? 'Avance guardado. Nota de remisión NR-' . str_pad((string) $remision, 6, '0', STR_PAD_LEFT) . ' pendiente en depósito.'
            : 'Avance guardado correctamente.', 'remision_id' => $remision]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        rmaEnviarError($e);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);
