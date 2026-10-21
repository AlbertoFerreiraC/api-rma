<?php
declare(strict_types=1);

function rmaInvEstado(int $actual, int $rma, int $minimo): string
{
    $libre = $actual - $rma;
    return $libre <= 0 ? 'SIN STOCK' : ($libre <= $minimo ? 'ALERTA - STOCK BAJO' : 'OK');
}
function rmaInvVersion(array $p): string
{
    $fields=[];
    foreach (['id','codigo','nombre','categoria','marca','stock_actual','stock_rma','stock_minimo'] as $k) $fields[$k]=(string)$p[$k];
    return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function rmaInvPublicar(array $p): array
{
    foreach (['id','stock_actual','stock_rma','stock_minimo'] as $k) $p[$k]=(int)$p[$k];
    $p['stock_disponible']=$p['stock_actual']-$p['stock_rma'];
    $p['estado_alerta']=rmaInvEstado($p['stock_actual'],$p['stock_rma'],$p['stock_minimo']);
    $p['version']=rmaInvVersion($p);
    return $p;
}
function rmaInvEntero($value, string $campo): int
{
    if ((!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value)))
        || (float)$value > 1000000000 || (float)$value < 0) {
        throw new InvalidArgumentException("$campo debe ser un entero entre 0 y 1000000000.");
    }
    return (int)$value;
}
function rmaInvValidar(array $datos): array
{
    $result=[];
    foreach (['codigo'=>50,'nombre'=>150,'categoria'=>100,'marca'=>100] as $k=>$max) {
        if (!is_string($datos[$k] ?? null)) throw new InvalidArgumentException("Completá el campo $k.");
        $v=trim($datos[$k]);
        if ($v==='' || mb_strlen($v)>$max) throw new InvalidArgumentException("El campo $k es obligatorio y admite hasta $max caracteres.");
        $result[$k]=$v;
    }
    foreach (['stock_actual','stock_rma','stock_minimo'] as $k) $result[$k]=rmaInvEntero($datos[$k]??null,$k);
    if ($result['stock_rma']>$result['stock_actual']) throw new InvalidArgumentException('El stock reservado para RMA no puede superar el stock actual.');
    return $result;
}
/** El llamador abre y confirma la transacción. Todo ajuste se guarda con su movimiento. */
function rmaInvActualizar(PDO $pdo, array $datos, int $usuario): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Se requiere una transacción.');
    $id=rmaInvEntero($datos['id']??null,'ID');
    $nuevo=rmaInvValidar($datos);
    $motivo=$datos['motivo']??null;
    if (!is_string($motivo) || trim($motivo)==='' || mb_strlen($motivo)>2000) throw new InvalidArgumentException('Indicá el motivo del ajuste (hasta 2000 caracteres).');
    $s=$pdo->prepare('SELECT * FROM productos WHERE id=? FOR UPDATE'); $s->execute([$id]); $antes=$s->fetch(PDO::FETCH_ASSOC);
    if (!$antes) throw new DomainException('El producto no existe.');
    if (!is_string($datos['version']??null) || !hash_equals(rmaInvVersion($antes),$datos['version'])) {
        throw new UnexpectedValueException('Otro usuario modificó este producto. Volvé a abrirlo antes de guardar.');
    }
    $cambios=[];
    foreach ($nuevo as $k=>$v) if ((string)$antes[$k] !== (string)$v) $cambios[]="$k: ".$antes[$k].' -> '.$v;
    if (!$cambios) return rmaInvPublicar($antes);
    $s=$pdo->prepare('UPDATE productos SET codigo=?,nombre=?,categoria=?,marca=?,stock_actual=?,stock_rma=?,stock_minimo=?,estado_alerta=?,updated_at=NOW() WHERE id=?');
    $s->execute([$nuevo['codigo'],$nuevo['nombre'],$nuevo['categoria'],$nuevo['marca'],$nuevo['stock_actual'],$nuevo['stock_rma'],$nuevo['stock_minimo'],
        rmaInvEstado($nuevo['stock_actual'],$nuevo['stock_rma'],$nuevo['stock_minimo']),$id]);
    $s=$pdo->prepare("INSERT INTO movimientos_inventario
        (producto_id,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,usuario_id,referencia,observacion)
        VALUES (?,'AJUSTE',?,?,?,?,?,?)");
    $s->execute([$id,abs($nuevo['stock_actual']-(int)$antes['stock_actual']),(int)$antes['stock_actual'],$nuevo['stock_actual'],$usuario,
        'Edición de inventario',trim($motivo)."\n".implode("\n",$cambios)]);
    $s=$pdo->prepare('SELECT * FROM productos WHERE id=?'); $s->execute([$id]);
    return rmaInvPublicar($s->fetch(PDO::FETCH_ASSOC));
}
