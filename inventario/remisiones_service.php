<?php
declare(strict_types=1);

/**
 * Una única operación para ambas pantallas de consumo.
 * El llamador debe abrir/cerrar la transacción; ante excepción debe hacer rollback.
 * Respeta el trigger legado de caso_insumos si descuenta existencias.
 * Si no hay trigger, aplica el descuento y registra la salida en Kardex.
 */
function rmaRegistrarConsumo(PDO $pdo, int $casoId, int $stockId, int $tecnicoId, int $cantidad, ?float $costo, ?string $observacion, int $usuarioId): int
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Registrar consumos requiere una transacción de base de datos.');
    }
    if ($casoId < 1 || $stockId < 1 || $tecnicoId < 1 || $usuarioId < 1 || $cantidad < 1 || $cantidad > 100000) {
        throw new InvalidArgumentException('Los datos del caso, insumo, responsable o cantidad no son válidos.');
    }
    $stmt = $pdo->prepare('SELECT id, numero_caso, fecha_cierre FROM casos WHERE id = ? FOR UPDATE');
    $stmt->execute([$casoId]);
    $caso = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$caso) {
        throw new DomainException('El caso RMA no existe.');
    }
    if ($caso['fecha_cierre'] !== null) {
        throw new DomainException('Este caso RMA ya está cerrado.');
    }

    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? AND id_rol=1');
    $stmt->execute([$tecnicoId]);
    if (!$stmt->fetchColumn()) {
        throw new DomainException('El técnico seleccionado no existe.');
    }

    $stmt = $pdo->prepare('SELECT id, nombre, codigo_sku, costo_unitario, cantidad FROM stock WHERE id = ? FOR UPDATE');
    $stmt->execute([$stockId]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stock) {
        throw new DomainException('El insumo no existe.');
    }
    $antes = (int) $stock['cantidad'];
    if ($antes < $cantidad) {
        throw new DomainException('Stock insuficiente para este insumo. Disponible: ' . $antes . '.');
    }
    $costoEfectivo = $costo ?? (float) $stock['costo_unitario'];
    if (!is_finite($costoEfectivo) || $costoEfectivo < 0 || $costoEfectivo > 999999999999) {
        throw new DomainException('El costo del insumo no es válido.');
    }
    $observacion = trim($observacion ?? '');
    if (mb_strlen($observacion) > 255) {
        throw new InvalidArgumentException('La observación excede los 255 caracteres permitidos.');
    }

    $insertar = $pdo->prepare('INSERT INTO caso_insumos (id_caso, id_stock, id_tecnico, cantidad, costo_aplicado, fecha_aplicacion, observacion) VALUES (?, ?, ?, ?, ?, NOW(), ?)');
    $insertar->execute([$casoId, $stockId, $tecnicoId, $cantidad, $costoEfectivo, $observacion !== '' ? $observacion : null]);
    $consumoId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT cantidad FROM stock WHERE id = ? FOR UPDATE');
    $stmt->execute([$stockId]);
    $despues = (int) $stmt->fetchColumn();
    if ($despues === $antes) {
        // Instancias sin trigger: se descuenta manualmente UNA SOLA VEZ y se escribe el Kardex.
        $actualizar = $pdo->prepare('UPDATE stock SET cantidad = cantidad - ?, updated_at = NOW() WHERE id = ? AND cantidad >= ?');
        $actualizar->execute([$cantidad, $stockId, $cantidad]);
        if ($actualizar->rowCount() !== 1) {
            throw new RuntimeException('No fue posible descontar el stock.');
        }
        $mov = $pdo->prepare("INSERT INTO movimientos_stock (id_stock, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, id_caso, id_usuario, motivo, fecha) VALUES (?, 'SALIDA_RMA', ?, ?, ?, ?, ?, ?, NOW())");
        $mov->execute([$stockId, $cantidad, $antes, $antes - $cantidad, $casoId, $usuarioId, 'Consumo en reparación RMA #' . $caso['numero_caso']]);
    } elseif ($despues !== $antes - $cantidad) {
        throw new RuntimeException('El trigger de stock descontó una cantidad inesperada. La operación se revirtió.');
    }

    $remision = $pdo->prepare('INSERT INTO notas_remision (id_caso, numero_caso, id_caso_insumo, id_stock, codigo_sku, insumo_nombre, id_tecnico, id_usuario_creador, cantidad, costo_unitario, observacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $remision->execute([$casoId, $caso['numero_caso'], $consumoId, $stockId, $stock['codigo_sku'], $stock['nombre'], $tecnicoId, $usuarioId, $cantidad, $costoEfectivo, $observacion !== '' ? $observacion : null]);
    return (int) $pdo->lastInsertId();
}
