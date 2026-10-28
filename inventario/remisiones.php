<?php

declare(strict_types=1);
require_once __DIR__ . '/remisiones_auth.php';
rmaPermitir([1, 2, 5]);
require_once __DIR__ . '/../db.php';

$action = (string) ($_GET['action'] ?? 'listar');
$tecnico = rmaRol() === 1;
$select = "SELECT n.*, u.nombre AS tecnico_nombre, uc.nombre AS creador_nombre,
    uf.nombre AS cierre_nombre, c.equipo, c.marca, c.modelo, c.numero_serie,
    cl.nombre AS cliente_nombre, cl.cedula AS cliente_cedula, cl.celular AS cliente_celular
    FROM notas_remision n LEFT JOIN usuarios u ON n.id_tecnico=u.id
    LEFT JOIN usuarios uc ON n.id_usuario_creador=uc.id
    LEFT JOIN usuarios uf ON n.id_usuario_cierre=uf.id
    LEFT JOIN casos c ON n.id_caso=c.id LEFT JOIN clientes cl ON c.id_cliente=cl.id";
$publicar = static function (array $nota) use ($tecnico): array {
    unset($nota['costo_unitario']);
    return $nota;
};
try {
    if ($action === 'casos') {
        $stmt = $pdo->prepare('SELECT id, numero_caso, equipo FROM casos WHERE fecha_cierre IS NULL ORDER BY id DESC');
        $stmt->execute();
        echo json_encode(['status' => 'success', 'casos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($action === 'contar') {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(estado='PENDIENTE') AS pendientes,
            SUM(estado IN ('PENDIENTE','ACTIVA')) AS activas, SUM(estado='CERRADA') AS cerradas,
            SUM(estado='CANCELADA') AS canceladas, SUM(estado='RECHAZADA') AS rechazadas
            FROM notas_remision" . ($tecnico ? ' WHERE id_usuario_creador = ?' : ''));
        $stmt->execute($tecnico ? [rmaUsuarioId()] : []);
        echo json_encode(['status' => 'success'] + array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC)));
        exit;
    }
    if ($action === 'listar') {
        $estado = (string) ($_GET['estado'] ?? 'TODAS');
        if (!in_array($estado, ['TODAS', 'ACTIVAS', 'PENDIENTE', 'ACTIVA', 'CERRADA', 'CANCELADA', 'RECHAZADA'], true)) {
            throw new InvalidArgumentException('Estado de remisión no válido.');
        }
        $where = [];
        $params = [];
        if ($estado === 'ACTIVAS') $where[] = "n.estado IN ('PENDIENTE','ACTIVA')";
        elseif ($estado !== 'TODAS') {
            $where[] = 'n.estado = ?';
            $params[] = $estado;
        }
        if ($tecnico) {
            $where[] = 'n.id_usuario_creador = ?';
            $params[] = rmaUsuarioId();
        }
        $stmt = $pdo->prepare($select . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY n.id DESC');
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'remisiones' => array_map($publicar, $stmt->fetchAll(PDO::FETCH_ASSOC))]);
        exit;
    }
    if (in_array($action, ['detalle', 'pdf'], true)) {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new InvalidArgumentException('Indica una remisión válida.');
        $stmt = $pdo->prepare($select . ' WHERE n.id = ?' . ($tecnico ? ' AND n.id_usuario_creador = ?' : ''));
        $stmt->execute($tecnico ? [$id, rmaUsuarioId()] : [$id]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nota) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'La remisión no existe.']);
            exit;
        }
        if ($action === 'pdf') {
            require_once __DIR__ . '/remisiones_pdf.php';
            $pdf = rmaRemisionPdf($nota);
            header('Content-Type: application/pdf');
            header('Content-Length: '.strlen($pdf));
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: inline; filename="NR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT) . '.pdf"');
            echo $pdf;
            exit;
        }
        echo json_encode(['status' => 'success', 'remision' => $publicar($nota)]);
        exit;
    }
    if (in_array($action, ['solicitar', 'cambiar_estado', 'cerrar'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Se requiere POST.']);
            exit;
        }
        if (($action === 'solicitar' && !$tecnico) || ($action !== 'solicitar' && $tecnico)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tenés permiso para realizar esta operación.']);
            exit;
        }
        rmaCsrfValidar();
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) throw new InvalidArgumentException('Datos inválidos.');
        $pdo->beginTransaction();
        if ($action === 'solicitar') {
            $casoId = filter_var($datos['id_caso'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = filter_var($datos['cantidad'] ?? null, FILTER_VALIDATE_INT);
            $detalle = trim((string)($datos['insumo_nombre'] ?? ''));
            $obs = trim((string)($datos['observacion'] ?? ''));
            if (!$casoId || !$cantidad || $cantidad < 1 || $cantidad > 100000 || $detalle === '' || mb_strlen($detalle) > 255 || mb_strlen($obs) > 2000) {
                throw new InvalidArgumentException('Completá el caso, el repuesto (hasta 255 caracteres) y una cantidad entera entre 1 y 100000. Observación: hasta 2000 caracteres.');
            }
            $stmt = $pdo->prepare('SELECT numero_caso FROM casos WHERE id = ? AND fecha_cierre IS NULL FOR UPDATE');
            $stmt->execute([$casoId]);
            $numero = $stmt->fetchColumn();
            if ($numero === false) throw new DomainException('Seleccioná un caso abierto para solicitar la remisión.');
            $stmt = $pdo->prepare("INSERT INTO notas_remision
                (id_caso,numero_caso,id_caso_insumo,id_stock,codigo_sku,insumo_nombre,id_tecnico,id_usuario_creador,cantidad,costo_unitario,observacion,estado)
                VALUES (?,?,NULL,NULL,NULL,?,?,?,?,0,?,'PENDIENTE')");
            $stmt->execute([$casoId, $numero, $detalle, rmaUsuarioId(), rmaUsuarioId(), $cantidad, $obs ?: null]);
            $id = (int)$pdo->lastInsertId();
            $mensaje = 'Solicitud enviada al depósito.';
        } else {
            $id = filter_var($datos['id'] ?? null, FILTER_VALIDATE_INT);
            $nuevo = $action === 'cerrar' ? 'CERRADA' : (string)($datos['estado'] ?? '');
            $obs = trim((string)($datos['observacion_cierre'] ?? ''));
            if (!$id || $id < 1 || !in_array($nuevo, ['ACTIVA', 'CERRADA', 'CANCELADA', 'RECHAZADA'], true) || mb_strlen($obs) > 2000) throw new InvalidArgumentException('Datos de estado no válidos.');
            if (in_array($nuevo, ['CANCELADA', 'RECHAZADA'], true) && $obs === '') throw new InvalidArgumentException('Indicá el motivo de cancelación o rechazo.');
            $stmt = $pdo->prepare('SELECT estado FROM notas_remision WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $actual = $stmt->fetchColumn();
            if ($actual === false) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'La remisión no existe.']);
                exit;
            }
            if (!in_array($actual, ['PENDIENTE', 'ACTIVA'], true) || $actual === $nuevo) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => 'La nota ya cambió de estado. Actualizá el listado.']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE notas_remision SET estado = ?, fecha_cierre = ' . ($nuevo === 'ACTIVA' ? 'NULL' : 'NOW()') . ', id_usuario_cierre = ?, observacion_cierre = ? WHERE id = ?');
            $stmt->execute([$nuevo, rmaUsuarioId(), $obs ?: null, $id]);
            $mensaje = 'Estado actualizado correctamente.';
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => $id, 'message' => $mensaje]);
        exit;
    }
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Acción no reconocida.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    rmaEnviarError($e);
}
