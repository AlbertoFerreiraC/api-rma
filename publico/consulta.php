<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db.php";

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Conexión a la base de datos no disponible."]);
    exit();
}

$busqueda = $_GET['busqueda'] ?? '';

if (empty($busqueda)) {
    echo json_encode(["status" => "error", "message" => "Debe ingresar un término de búsqueda."]);
    exit();
}

try {
    $term = trim($busqueda);

    // Buscar caso activo
    $sql = "SELECT 
                c.id,
                c.numero_caso,
                c.equipo,
                c.marca,
                c.modelo,
                c.numero_serie,
                c.descripcion_problema,
                c.diagnostico_final,
                DATE_FORMAT(c.fecha_ingreso, '%Y-%m-%d %H:%i') AS fecha_ingreso,
                DATE_FORMAT(c.fecha_cierre, '%Y-%m-%d %H:%i') AS fecha_cierre,
                e.nombre AS estado_actual,
                t.nombre AS tipo_caso,
                cli.nombre AS cliente_nombre,
                cli.cedula AS cliente_cedula
            FROM casos c
            INNER JOIN clientes cli ON c.id_cliente = cli.id
            LEFT JOIN estados_caso e ON c.id_estado_actual = e.id
            LEFT JOIN tipos_caso t ON c.id_tipo_caso = t.id
            WHERE c.numero_caso = :term 
               OR c.numero_serie = :term 
               OR cli.cedula = :term
            ORDER BY c.id DESC 
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':term' => $term]);
    $caso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caso) {
        echo json_encode(["status" => "error", "message" => "No se encontró ningún registro para: " . htmlspecialchars($term)]);
        exit();
    }

    // 🔑 Generar Token Seguro para la impresión oficial
    $secret_salt = "MICRO_EXPRESS_SECURE_TOKEN_2026";
    $hash = md5($caso['numero_caso'] . $secret_salt);
    $rawToken = $caso['numero_caso'] . "||" . $hash;
    $tokenImpresion = base64_encode($rawToken);

    $caso['token_impresion'] = $tokenImpresion;

    // Obtener historial del caso
    $sqlHistorial = "SELECT 
                        h.id,
                        DATE_FORMAT(h.fecha, '%Y-%m-%d %H:%i') AS fecha,
                        h.observacion,
                        e.nombre AS estado
                     FROM historial_estados h
                     INNER JOIN estados_caso e ON h.id_estado = e.id
                     WHERE h.id_caso = :id_caso
                     ORDER BY h.id DESC";

    $stmtHist = $pdo->prepare($sqlHistorial);
    $stmtHist->execute([':id_caso' => $caso['id']]);
    $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "caso" => $caso,
        "historial" => $historial
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Error interno: " . $e->getMessage()]);
}
exit();