<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../../db.php";

if (!isset($pdo)) {
    echo json_encode([
        "status" => "error",
        "message" => "Protocolo caído. No hay conexión con el núcleo de la base de datos."
    ]);
    exit;
}

$action = $_GET["action"] ?? "";

if ($action === "listar_bandeja") {
    try {
        $buscar = trim($_GET["buscar"] ?? "");

        $sql = "SELECT 
                    c.id,
                    c.numero_caso,
                    c.equipo,
                    c.marca,
                    c.numero_serie,
                    c.fecha_ingreso,
                    c.id_estado_actual,
                    cl.nombre AS cliente_nombre,
                    ec.nombre AS estado_nombre
                FROM dosisma_rma_bd.casos c
                INNER JOIN dosisma_rma_bd.clientes cl ON c.id_cliente = cl.id
                INNER JOIN dosisma_rma_bd.estados_caso ec ON c.id_estado_actual = ec.id";

        if ($buscar !== "") {
            $sql .= " WHERE c.numero_caso LIKE ? 
                       OR cl.nombre LIKE ? 
                       OR c.numero_serie LIKE ? 
                       OR c.equipo LIKE ?";
            $params = ["%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%"];
        } else {
            $params = [];
        }

        $sql .= " ORDER BY c.id DESC LIMIT 1000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $secret_salt = "MICRO_EXPRESS_SECURE_TOKEN_2026";

        foreach ($resultado as &$row) {
            $num_caso = $row['numero_caso'];
            $hash_seguro = base64_encode($num_caso . "||" . md5($num_caso . $secret_salt));
            $row['link_secure'] = $protocolo . $host . "/rma-app/comprobante-caso.php?token=" . urlencode($hash_seguro);
            $row['qr_url'] = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($row['link_secure']);
        }

        echo json_encode([
            "status" => "success",
            "casos" => $resultado
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Fallo en lectura de matriz de control de bandeja: " . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode([
    "status" => "error",
    "message" => "Acción no reconocida en el nodo de telemetría de la bandeja."
]);