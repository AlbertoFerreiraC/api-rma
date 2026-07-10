<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../../db.php";

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Conexión caída con la base de datos central."]);
    exit;
}

$action = $_GET["action"] ?? "";

if ($action === "listar_logistica") {
    try {
        $buscar = trim($_GET["buscar"] ?? "");
        $alcance = trim($_GET["alcance"] ?? "todos");

        $sql = "SELECT 
                    c.id, c.numero_caso, c.equipo, c.marca, c.numero_serie, c.id_estado_actual,
                    p.nombre AS proveedor_nombre
                FROM dosisma_rma_bd.casos c
                LEFT JOIN dosisma_rma_bd.proveedores p ON c.id_proveedor = p.id";

        $conditions = [];
        $params = [];

        if ($alcance === "en_taller") {
            $conditions[] = "c.id_proveedor IS NULL";
        } elseif ($alcance === "en_proveedor") {
            $conditions[] = "c.id_proveedor IS NOT NULL";
        }

        if ($buscar !== "") {
            $conditions[] = "(c.numero_caso LIKE ? OR c.numero_serie LIKE ? OR c.equipo LIKE ? OR c.marca LIKE ?)";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
        }

        if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY c.id DESC LIMIT 1000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(["status" => "success", "casos" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "aux_proveedores") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, contacto FROM dosisma_rma_bd.proveedores ORDER BY nombre ASC");
        $stmt->execute();
        echo json_encode(["status" => "success", "proveedores" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "aux_estados") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.estados_caso ORDER BY id ASC");
        $stmt->execute();
        echo json_encode(["status" => "success", "estados" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "obtener_caso") {
    try {
        $id = intval($_GET["id"] ?? 0);
        $stmt = $pdo->prepare("SELECT id, numero_caso, equipo, marca, numero_serie, descripcion_problema, id_proveedor, fecha_envio_proveedor, referencia_proveedor, id_estado_actual FROM dosisma_rma_bd.casos WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $caso = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$caso)
            throw new Exception("El folio consultado no existe.");
        echo json_encode(["status" => "success", "caso" => $caso]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "guardar_flujo_externo" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $id_caso = intval($_POST["id_caso"] ?? 0);
        $id_usuario = intval($_POST["id_usuario"] ?? 0);
        $id_proveedor = intval($_POST["id_proveedor"] ?? 0);
        $id_estado_actual = intval($_POST["id_estado_actual"] ?? 0);
        $fecha_envio = trim($_POST["fecha_envio_proveedor"] ?? "");
        $referencia = trim($_POST["referencia_proveedor"] ?? "");

        if ($id_caso <= 0 || $id_proveedor <= 0 || $id_estado_actual <= 0 || $fecha_envio === "" || $referencia === "") {
            throw new Exception("Todos los campos operacionales del despacho son obligatorios.");
        }

        $sql_update = "UPDATE dosisma_rma_bd.casos 
                       SET id_proveedor = ?, fecha_envio_proveedor = ?, referencia_proveedor = ?, id_estado_actual = ? 
                       WHERE id = ?";
        $stmt = $pdo->prepare($sql_update);
        $stmt->execute([$id_proveedor, $fecha_envio, $referencia, $id_estado_actual, $id_caso]);

        $stmt_p = $pdo->prepare("SELECT nombre FROM dosisma_rma_bd.proveedores WHERE id = ?");
        $stmt_p->execute([$id_proveedor]);
        $nombre_prov = $stmt_p->fetchColumn();

        $stmt_e = $pdo->prepare("SELECT nombre FROM dosisma_rma_bd.estados_caso WHERE id = ?");
        $stmt_e->execute([$id_estado_actual]);
        $nombre_est = $stmt_e->fetchColumn();

        $fecha_formateada = date("d/m/Y", strtotime($fecha_envio));
        $observacion_log = "ENVÍO LOGÍSTICO REGISTRADO A: " . $nombre_prov . ". REF: " . $referencia . ". FECHA ENVÍO: " . $fecha_formateada . ". ESTADO ACTUAL: " . $nombre_est;

        $sql_hist = "INSERT INTO dosisma_rma_bd.historial_estados (id_caso, id_estado, fecha, observacion, id_usuario) VALUES (?, ?, NOW(), ?, ?)";
        $stmt_h = $pdo->prepare($sql_hist);
        $stmt_h->execute([$id_caso, $id_estado_actual, $observacion_log, $id_usuario]);

        echo json_encode([
            "status" => "success",
            "message" => "Ruta externa consolidada. El dispositivo fue asignado a la firma $nombre_prov con tracking: $referencia."
        ]);

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción denegada por el protocolo central de logística."]);