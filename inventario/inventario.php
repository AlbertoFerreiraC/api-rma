<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../db_inventario.php";

if (!isset($pdo_inv)) {
    echo json_encode(["status" => "error", "message" => "Error de conexión con la base de datos de inventario."]);
    exit;
}

$action = $_GET["action"] ?? "";

/*==================================================
=        LISTAR CATÁLOGO DE STOCK                  =
==================================================*/
if ($action === "listar") {
    try {
        $stmt = $pdo_inv->query("SELECT * FROM productos ORDER BY id DESC");
        echo json_encode([
            "status" => "success",
            "stock" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

/*==================================================
=        GUARDAR NUEVO PRODUCTO                    =
==================================================*/
if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $input = json_decode(file_get_contents("php://input"), true);
        
        $codigo = trim($input["codigo"] ?? "");
        $nombre = trim($input["nombre"] ?? "");
        $categoria = trim($input["categoria"] ?? "");
        $marca = trim($input["marca"] ?? "");
        $stock_actual = intval($input["stock_actual"] ?? 0);
        $stock_rma = intval($input["stock_rma"] ?? 0);
        $stock_minimo = intval($input["stock_minimo"] ?? 5);

        if ($codigo === "" || $nombre === "") {
            throw new Exception("El código y el nombre del producto son obligatorios.");
        }

        $stock_disponible = $stock_actual - $stock_rma;
        $estado_alerta = 'OK';
        if ($stock_actual <= 0) {
            $estado_alerta = 'SIN STOCK';
        } elseif ($stock_disponible <= $stock_minimo) {
            $estado_alerta = 'ALERTA - STOCK BAJO';
        }

        $stmt = $pdo_inv->prepare("INSERT INTO productos (codigo, nombre, categoria, marca, stock_actual, stock_rma, stock_disponible, estado_alerta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$codigo, $nombre, $categoria, $marca, $stock_actual, $stock_rma, $stock_disponible, $estado_alerta]);

        echo json_encode(["status" => "success", "message" => "Producto registrado correctamente en el inventario."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);