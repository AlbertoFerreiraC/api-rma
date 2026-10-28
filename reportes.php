<?php
declare(strict_types=1);
require_once __DIR__.'/inventario/remisiones_auth.php';rmaPermitir([1,2,5]);
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit;}
rmaCsrfValidar();
try {
    $d=json_decode(file_get_contents('php://input'),true);
    if(!is_array($d) || !is_string($d['titulo']??null) || !is_array($d['columnas']??null) || !is_array($d['filas']??null)) throw new InvalidArgumentException('Reporte inválido.');
    $cols=$d['columnas'];$rows=$d['filas'];
    if(count($cols)<1 || count($cols)>20 || count($rows)>10000 || mb_strlen($d['titulo'])>200) throw new InvalidArgumentException('El reporte supera los límites permitidos.');
    foreach($cols as $c)if(!is_string($c)||mb_strlen($c)>120)throw new InvalidArgumentException('Encabezado inválido.');
    foreach($rows as $r){if(!is_array($r)||count($r)!==count($cols))throw new InvalidArgumentException('Fila inválida.');foreach($r as $v)if(!is_string($v)||mb_strlen($v)>10000)throw new InvalidArgumentException('Celda inválida.');}
    require_once __DIR__.'/inventario/remisiones_pdf.php';
    $p=new RmaRemisionDocumento(['id'=>0,'titulo'=>$d['titulo'],'fecha_creacion'=>(new DateTimeImmutable('now',new DateTimeZone('America/Asuncion')))->format('Y-m-d H:i:s'),'estado'=>count($rows).' registros','numero_caso'=>''],true);
    $p->listado($cols,$rows);
    $pdf=$p->finish();header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="reporte-rma.pdf"');header('Content-Length: '.strlen($pdf));header('X-Content-Type-Options: nosniff');echo $pdf;
}catch(Throwable $e){rmaEnviarError($e);}
