<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Conexión no disponible."]);
    exit();
}

$action = $_GET['action'] ?? '';

// =========================================================
// 📡 LISTAR CASOS Y ESTADOS REALES
// =========================================================
if ($action === "listar_casos") {
    try {
        // Traer casos con datos del cliente y estado actual
        $sqlCasos = "SELECT 
                        c.id,
                        c.numero_caso,
                        c.equipo,
                        cl.nombre AS cliente,
                        cl.celular,
                        cl.cedula,
                        ec.nombre AS estado_actual
                     FROM casos c
                     INNER JOIN clientes cl ON c.id_cliente = cl.id
                     LEFT JOIN estados_caso ec ON c.id_estado_actual = ec.id
                     ORDER BY c.id DESC";

        $stmtCasos = $pdo->prepare($sqlCasos);
        $stmtCasos->execute();
        $casos = $stmtCasos->fetchAll(PDO::FETCH_ASSOC);

        // Traer estados disponibles
        $sqlEstados = "SELECT id, nombre FROM estados_caso ORDER BY id ASC";
        $stmtEstados = $pdo->prepare($sqlEstados);
        $stmtEstados->execute();
        $estados = $stmtEstados->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "casos" => $casos,
            "estados" => $estados
        ]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

echo json_encode(["status" => "error", "message" => "Acción no válida."]);