<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../../db.php"; 

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Protocolo de base de datos desincronizado."]);
    exit;
}

$action = $_GET["action"] ?? "";

if ($action === "obtener_timeline") {
    try {
        $numero_caso = trim($_GET["numero_caso"] ?? "");

        if ($numero_caso === "") {
            throw new Exception("Vector de búsqueda vacío.");
        }

        $sql_caso = "SELECT c.id, c.numero_caso, c.equipo, c.marca, c.numero_serie, cl.nombre AS cliente_nombre
                     FROM dosisma_rma_bd.casos c
                     INNER JOIN dosisma_rma_bd.clientes cl ON c.id_cliente = cl.id
                     WHERE c.numero_caso = ? LIMIT 1";
        
        $stmt_c = $pdo->prepare($sql_caso);
        $stmt_c->execute([$numero_caso]);
        $info_caso = $stmt_c->fetch(PDO::FETCH_ASSOC);

        if (!$info_caso) {
            throw new Exception("El identificador de caso ingresado no está registrado en el sistema.");
        }

        $sql_timeline = "SELECT 
                            h.id, h.id_estado, h.fecha, h.observacion,
                            ec.nombre AS estado_nombre,
                            u.nombre AS usuario_nombre
                         FROM dosisma_rma_bd.historial_estados h
                         INNER JOIN dosisma_rma_bd.estados_caso ec ON h.id_estado = ec.id
                         INNER JOIN dosisma_rma_bd.usuarios u ON h.id_usuario = u.id
                         WHERE h.id_caso = ?
                         ORDER BY h.id ASC";

        $stmt_t = $pdo->prepare($sql_timeline);
        $stmt_t->execute([$info_caso['id']]);
        $timeline = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "info_caso" => $info_caso,
            "timeline" => $timeline
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Comando vectorial no reconocido en el nodo cronológico."]);