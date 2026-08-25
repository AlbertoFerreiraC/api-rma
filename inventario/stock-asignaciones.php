<?php

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
=        1. LISTAR CONSUMOS DE INSUMOS EN TALLER   =
==================================================*/
if ($action === "listar") {
    try {
        $sql = "SELECT 
                    ci.id,
                    ci.id_caso,
                    c.numero_caso,
                    ci.id_stock,
                    s.nombre AS insumo_nombre,
                    s.codigo_sku,
                    ci.id_tecnico,
                    u.nombre AS tecnico_nombre,
                    ci.cantidad,
                    ci.costo_aplicado,
                    ci.fecha_aplicacion,
                    ci.observacion
                FROM dosisma_rma_bd.caso_insumos ci
                INNER JOIN dosisma_rma_bd.casos c ON ci.id_caso = c.id
                INNER JOIN dosisma_rma_bd.stock s ON ci.id_stock = s.id
                LEFT JOIN dosisma_rma_bd.usuarios u ON ci.id_tecnico = u.id
                ORDER BY ci.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "asignaciones" => $asignaciones
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al consultar las asignaciones de insumos: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=        2. AUXILIARES PARA FORMULARIO            =
==================================================*/
if ($action === "auxiliares") {
    try {
        // Casos activos en taller
        $stmtCasos = $pdo->prepare("SELECT c.id, c.numero_caso, c.equipo, cl.nombre AS cliente_nombre 
                                    FROM dosisma_rma_bd.casos c 
                                    LEFT JOIN dosisma_rma_bd.clientes cl ON c.id_cliente = cl.id 
                                    ORDER BY c.id DESC");
        $stmtCasos->execute();
        $casos = $stmtCasos->fetchAll(PDO::FETCH_ASSOC);

        // Insumos con cantidad > 0
        $stmtStock = $pdo->prepare("SELECT id, codigo_sku, nombre, cantidad, costo_unitario 
                                    FROM dosisma_rma_bd.stock 
                                    WHERE cantidad > 0 
                                    ORDER BY nombre ASC");
        $stmtStock->execute();
        $stock = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

        // Usuarios técnicos
        $stmtTecnicos = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.usuarios ORDER BY nombre ASC");
        $stmtTecnicos->execute();
        $tecnicos = $stmtTecnicos->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "casos" => $casos,
            "stock" => $stock,
            "tecnicos" => $tecnicos
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al obtener datos auxiliares: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=        3. REGISTRAR APLICACIÓN DE INSUMO EN RMA =
==================================================*/
if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $id_caso = intval($input["id_caso"] ?? 0);
        $id_stock = intval($input["id_stock"] ?? 0);
        $id_tecnico = intval($input["id_tecnico"] ?? 0);
        $cantidad = intval($input["cantidad"] ?? 0);
        $costo_aplicado = floatval($input["costo_aplicado"] ?? 0);
        $observacion = !empty($input["observacion"]) ? trim($input["observacion"]) : null;

        if ($id_caso <= 0 || $id_stock <= 0 || $id_tecnico <= 0 || $cantidad <= 0) {
            throw new Exception("Campos requeridos incompletos para registrar el consumo en taller.");
        }

        // Verificar disponibilidad en stock
        $stmtCheck = $pdo->prepare("SELECT cantidad FROM dosisma_rma_bd.stock WHERE id = ?");
        $stmtCheck->execute([$id_stock]);
        $stockItem = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$stockItem) {
            throw new Exception("El insumo seleccionado no existe.");
        }

        if (intval($stockItem['cantidad']) < $cantidad) {
            throw new Exception("Stock insuficiente. Unidades disponibles actuales: " . $stockItem['cantidad']);
        }

        // Inserción en caso_insumos (Esto dispara el Trigger trg_descontar_stock_rma automáticamente)
        $sql = "INSERT INTO dosisma_rma_bd.caso_insumos 
                (id_caso, id_stock, id_tecnico, cantidad, costo_aplicado, fecha_aplicacion, observacion) 
                VALUES (?, ?, ?, ?, ?, NOW(), ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id_caso,
            $id_stock,
            $id_tecnico,
            $cantidad,
            $costo_aplicado,
            $observacion
        ]);

        echo json_encode([
            "status" => "success",
            "message" => "Insumo registrado correctamente en el Caso RMA. El stock ha sido descontado mediante el Trigger automático."
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

// Fallback por protocolo no soportado
echo json_encode([
    "status" => "error",
    "message" => "Protocolo de asignación de insumos no reconocido por la matriz de control."
]);