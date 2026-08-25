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
=            1. LISTAR INVENTARIO COMPLETO         =
==================================================*/
if ($action === "listar") {
    try {
        $sql = "SELECT 
                    s.id,
                    s.codigo_sku,
                    s.id_categoria,
                    c.nombre AS categoria_nombre,
                    s.nombre,
                    s.descripcion,
                    s.id_proveedor_habitual,
                    p.nombre AS proveedor_nombre,
                    s.cantidad,
                    s.stock_minimo,
                    s.costo_unitario,
                    s.precio_venta,
                    s.created_at,
                    s.updated_at
                FROM dosisma_rma_bd.stock s
                LEFT JOIN dosisma_rma_bd.categorias_stock c ON s.id_categoria = c.id
                LEFT JOIN dosisma_rma_bd.proveedores p ON s.id_proveedor_habitual = p.id
                ORDER BY s.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "stock" => $items
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Fallo al consultar el inventario: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=            2. OBTENER UN INSUMO POR ID           =
==================================================*/
if ($action === "obtener") {
    try {
        $id = intval($_GET["id"] ?? 0);

        if ($id <= 0) {
            throw new Exception("Identificador de insumo no válido.");
        }

        $stmt = $pdo->prepare("SELECT * FROM dosisma_rma_bd.stock WHERE id = ?");
        $stmt->execute([$id]);
        $stockItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stockItem) {
            throw new Exception("El insumo solicitado no existe en el catálogo.");
        }

        echo json_encode([
            "status" => "success",
            "stock" => $stockItem
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=            3. CATEGORÍAS AUXILIARES              =
==================================================*/
if ($action === "aux_categorias") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.categorias_stock ORDER BY nombre ASC");
        $stmt->execute();
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "categorias" => $categorias
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al obtener categorías: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=            4. PROVEEDORES AUXILIARES             =
==================================================*/
if ($action === "aux_proveedores") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.proveedores ORDER BY nombre ASC");
        $stmt->execute();
        $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "proveedores" => $proveedores
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Error al obtener proveedores: " . $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=            5. GUARDAR UN NUEVO INSUMO            =
==================================================*/
if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $codigo_sku = !empty($input["codigo_sku"]) ? trim($input["codigo_sku"]) : null;
        $id_categoria = !empty($input["id_categoria"]) ? intval($input["id_categoria"]) : null;
        $nombre = trim($input["nombre"] ?? "");
        $descripcion = !empty($input["descripcion"]) ? trim($input["descripcion"]) : null;
        $cantidad = intval($input["cantidad"] ?? 0);
        $stock_minimo = intval($input["stock_minimo"] ?? 2);
        $costo_unitario = floatval($input["costo_unitario"] ?? 0);
        $precio_venta = !empty($input["precio_venta"]) ? floatval($input["precio_venta"]) : 0.00;
        $id_proveedor_habitual = !empty($input["id_proveedor_habitual"]) ? intval($input["id_proveedor_habitual"]) : null;

        if ($nombre === "") {
            throw new Exception("El nombre del insumo o repuesto es obligatorio.");
        }

        // Verificar duplicidad de SKU
        if ($codigo_sku !== null) {
            $stmtCheck = $pdo->prepare("SELECT id FROM dosisma_rma_bd.stock WHERE codigo_sku = ?");
            $stmtCheck->execute([$codigo_sku]);
            if ($stmtCheck->fetch()) {
                throw new Exception("El código SKU ya se encuentra registrado para otro insumo.");
            }
        }

        $sql = "INSERT INTO dosisma_rma_bd.stock 
                (codigo_sku, id_categoria, nombre, descripcion, id_proveedor_habitual, cantidad, stock_minimo, costo_unitario, precio_venta, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $codigo_sku,
            $id_categoria,
            $nombre,
            $descripcion,
            $id_proveedor_habitual,
            $cantidad,
            $stock_minimo,
            $costo_unitario,
            $precio_venta
        ]);

        echo json_encode([
            "status" => "success",
            "message" => "Insumo o repuesto inyectado correctamente en la matriz de inventario."
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=            6. ACTUALIZAR INSUMO EXISTENTE        =
==================================================*/
if ($action === "actualizar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            throw new Exception("Carga útil JSON inválida.");
        }

        $id = intval($input["id"] ?? 0);
        $codigo_sku = !empty($input["codigo_sku"]) ? trim($input["codigo_sku"]) : null;
        $id_categoria = !empty($input["id_categoria"]) ? intval($input["id_categoria"]) : null;
        $nombre = trim($input["nombre"] ?? "");
        $descripcion = !empty($input["descripcion"]) ? trim($input["descripcion"]) : null;
        $cantidad = intval($input["cantidad"] ?? 0);
        $stock_minimo = intval($input["stock_minimo"] ?? 2);
        $costo_unitario = floatval($input["costo_unitario"] ?? 0);
        $precio_venta = !empty($input["precio_venta"]) ? floatval($input["precio_venta"]) : 0.00;
        $id_proveedor_habitual = !empty($input["id_proveedor_habitual"]) ? intval($input["id_proveedor_habitual"]) : null;

        if ($id <= 0 || $nombre === "") {
            throw new Exception("Estructura de datos incompleta para actualizar el insumo.");
        }

        // Verificar que el SKU no pertenezca a otro producto
        if ($codigo_sku !== null) {
            $stmtCheck = $pdo->prepare("SELECT id FROM dosisma_rma_bd.stock WHERE codigo_sku = ? AND id != ?");
            $stmtCheck->execute([$codigo_sku, $id]);
            if ($stmtCheck->fetch()) {
                throw new Exception("El código SKU especificado pertenece a otro nodo del catálogo.");
            }
        }

        $sql = "UPDATE dosisma_rma_bd.stock 
                SET codigo_sku = ?,
                    id_categoria = ?,
                    nombre = ?,
                    descripcion = ?,
                    id_proveedor_habitual = ?,
                    cantidad = ?,
                    stock_minimo = ?,
                    costo_unitario = ?,
                    precio_venta = ?,
                    updated_at = NOW() 
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $codigo_sku,
            $id_categoria,
            $nombre,
            $descripcion,
            $id_proveedor_habitual,
            $cantidad,
            $stock_minimo,
            $costo_unitario,
            $precio_venta,
            $id
        ]);

        echo json_encode([
            "status" => "success",
            "message" => "Insumo de inventario sincronizado correctamente."
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

/*==================================================
=            7. ELIMINAR INSUMO DE STOCK           =
==================================================*/
if ($action === "eliminar" && $_SERVER["REQUEST_METHOD"] === "DELETE") {
    try {
        $id = intval($_GET["id"] ?? 0);

        if ($id <= 0) {
            throw new Exception("Identificador de insumo no válido para eliminación.");
        }

        // Verificar integridad referencial antes de purgar
        $stmtCheck = $pdo->prepare("SELECT id FROM dosisma_rma_bd.caso_insumos WHERE id_stock = ? LIMIT 1");
        $stmtCheck->execute([$id]);
        if ($stmtCheck->fetch()) {
            throw new Exception("No es posible purgar el insumo pues posee registros de consumo vinculados a casos de RMA.");
        }

        $stmt = $pdo->prepare("DELETE FROM dosisma_rma_bd.stock WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode([
            "status" => "success",
            "message" => "Nodo de insumo eliminado del inventario central."
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

// Fallback para protocolos no contemplados
echo json_encode([
    "status" => "error",
    "message" => "Protocolo de inventario no reconocido por la matriz de control."
]);