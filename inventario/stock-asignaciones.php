<?php
declare(strict_types=1);
require_once __DIR__ . '/remisiones_auth.php';
rmaPermitir([2, 5]);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/remisiones_service.php';

$action = (string) ($_GET['action'] ?? '');
try {
    if ($action === 'listar') {
        $stmt = $pdo->query('SELECT ci.id, ci.id_caso, c.numero_caso, ci.id_stock, s.nombre AS insumo_nombre,
            s.codigo_sku, ci.id_tecnico, u.nombre AS tecnico_nombre, ci.cantidad,
            ci.fecha_aplicacion, ci.observacion, n.id AS remision_id, n.estado AS remision_estado
            FROM caso_insumos ci
            JOIN casos c ON ci.id_caso = c.id
            JOIN stock s ON ci.id_stock = s.id
            LEFT JOIN usuarios u ON ci.id_tecnico = u.id
            LEFT JOIN notas_remision n ON n.id_caso_insumo = ci.id
            ORDER BY ci.id DESC LIMIT 500');
        echo json_encode(['status' => 'success', 'asignaciones' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($action === 'auxiliares') {
        $casos = $pdo->query('SELECT c.id, c.numero_caso, c.equipo, cl.nombre AS cliente_nombre
            FROM casos c LEFT JOIN clientes cl ON cl.id = c.id_cliente WHERE c.fecha_cierre IS NULL ORDER BY c.id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $stock = $pdo->query('SELECT id, codigo_sku, nombre, cantidad
            FROM stock WHERE cantidad > 0 ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC);
        $tecnicos = $pdo->query('SELECT id, nombre FROM usuarios WHERE id_rol=1 ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'casos' => $casos, 'stock' => $stock, 'tecnicos' => $tecnicos, 'usuario_id' => rmaUsuarioId(), 'rol' => rmaRol()]);
        exit;
    }
    if ($action === 'guardar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Se requiere POST.']);
            exit;
        }
        rmaCsrfValidar();
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) {
            throw new InvalidArgumentException('Datos de aplicación incompletos.');
        }
        $idCaso = (int) ($datos['id_caso'] ?? 0);
        $idStock = (int) ($datos['id_stock'] ?? 0);
        require_once __DIR__.'/operaciones_service.php';
        $cantidad = rmaCantidadPositiva($datos['cantidad'] ?? null);
        $idTecnico = rmaRol() === 1 ? rmaUsuarioId() : (int) ($datos['id_tecnico'] ?? 0);
        $costo = null;
        // El costo no se puede alterar desde un perfil técnico.
        if (rmaRol() === 1) {
            $costo = null;
        }
        $pdo->beginTransaction();
        $remision = rmaRegistrarConsumo($pdo, $idCaso, $idStock, $idTecnico, $cantidad, $costo, (string) ($datos['observacion'] ?? ''), rmaUsuarioId());
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Consumo registrado y nota de remisión NR-' . str_pad((string) $remision, 6, '0', STR_PAD_LEFT) . ' enviada al depósito.', 'remision_id' => $remision]);
        exit;
    }
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Acción inválida.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rmaEnviarError($e);
}
