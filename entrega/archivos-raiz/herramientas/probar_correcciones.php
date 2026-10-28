<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
ob_start();
require_once __DIR__.'/../api-rma/db_config.php';
$rma=rmaConexion('rma');$inv=rmaConexion('inventario');$base='http://localhost/rma-app';$dir=__DIR__.'/../tmp/correcciones';if(!is_dir($dir))mkdir($dir,0775,true);
$stateFile=$dir.'/fixture.json';$mode=$argv[1]??'verificar';
function qa(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FALLO: '.$message);echo 'OK: '.$message."\n";}
function httpQa(string $path,?array $session=null,?array $data=null,bool $form=false, bool $csrf=true):array {
 global $base;$h=curl_init($base.$path);$headers=[];
 $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90];
 if($session)$opts[CURLOPT_COOKIE]='PHPSESSID='.$session['sid'];
 if($data!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$form?$data:json_encode($data);if(!$form)$headers[]='Content-Type: application/json';if($csrf&&$session)$headers[]='X-CSRF-TOKEN: '.$session['token'];}
 $opts[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($h,$opts);$body=curl_exec($h);if($body===false)throw new RuntimeException(curl_error($h));
 $result=['code'=>curl_getinfo($h,CURLINFO_HTTP_CODE),'type'=>curl_getinfo($h,CURLINFO_CONTENT_TYPE),'body'=>$body,'data'=>json_decode($body,true)];curl_close($h);return $result;
}
if($mode==='preparar') {
 if(is_file($stateFile))throw new RuntimeException('Ya existe una prueba preparada. Usá verificar o limpiar.');
 $tag='QA-CORR-'.bin2hex(random_bytes(5));$sessions=[];
 foreach([1,2,5] as $role){$s=$rma->prepare('SELECT id,nombre FROM usuarios WHERE id_rol=? ORDER BY id LIMIT 1');$s->execute([$role]);$u=$s->fetch();if(!$u)throw new RuntimeException('Falta rol '.$role);session_id('qa'.bin2hex(random_bytes(16)));session_start();$token=bin2hex(random_bytes(32));$_SESSION=['id'=>(int)$u['id'],'id_rol'=>$role,'nombre'=>$u['nombre'],'rma_csrf'=>$token];$sessions[$role]=['id'=>(int)$u['id'],'sid'=>session_id(),'token'=>$token];session_write_close();}
 $s=$rma->prepare("INSERT INTO clientes (nombre,cedula,celular) VALUES (?,?,'')");$s->execute([$tag,$tag]);$cliente=(int)$rma->lastInsertId();
 $tipo=(int)$rma->query('SELECT id FROM tipos_caso ORDER BY id LIMIT 1')->fetchColumn();
 $s=$rma->prepare('INSERT INTO casos (numero_caso,id_cliente,id_tecnico,id_tipo_caso,id_estado_actual,equipo,marca,numero_serie,descripcion_problema) VALUES (?,?,?,?,1,?,?,?,?)');$s->execute([$tag,$cliente,$sessions[1]['id'],$tipo,'Equipo temporal QA','QA',$tag,'Prueba automatizada temporal']);$caso=(int)$rma->lastInsertId();
 $s=$rma->prepare('INSERT INTO stock (codigo_sku,nombre,cantidad,stock_minimo) VALUES (?,?,10,3)');$s->execute([$tag,$tag]);$insumo=(int)$rma->lastInsertId();
 $s=$inv->prepare("INSERT INTO productos (codigo,nombre,categoria,marca,stock_actual,stock_rma,stock_minimo) VALUES (?,?,'QA','QA',20,0,5)");$s->execute([$tag,$tag]);$producto=(int)$inv->lastInsertId();
 file_put_contents($stateFile,json_encode(compact('tag','sessions','cliente','tipo','caso','insumo','producto'),JSON_PRETTY_PRINT));echo "Fixture temporal preparada.\n";exit;
}
if(!is_file($stateFile))throw new RuntimeException('Ejecutá primero preparar.');$f=json_decode(file_get_contents($stateFile),true);$admin=$f['sessions'][2];$dep=$f['sessions'][5];$tec=$f['sessions'][1];
if($mode==='limpiar') {
 $inv->beginTransaction();$s=$inv->prepare('SELECT codigo FROM productos WHERE id=? FOR UPDATE');$s->execute([$f['producto']]);if($s->fetchColumn()!==$f['tag'])throw new RuntimeException('El producto no coincide con la fixture.');
 foreach(['operaciones_rma','movimientos_inventario'] as $t){$s=$inv->prepare("DELETE FROM $t WHERE producto_id=?");$s->execute([$f['producto']]);}$s=$inv->prepare('DELETE FROM productos WHERE id=?');$s->execute([$f['producto']]);$inv->commit();
 $rma->beginTransaction();$s=$rma->prepare('SELECT id,foto_archivo FROM casos WHERE id_cliente=?');$s->execute([$f['cliente']]);$casos=$s->fetchAll();$files=[];
 foreach($casos as $c){$s=$rma->prepare('SELECT archivo FROM caso_fotos WHERE id_caso=?');$s->execute([$c['id']]);$files=array_merge($files,$s->fetchAll(PDO::FETCH_COLUMN),array_filter([$c['foto_archivo']]));foreach(['notas_remision','caso_insumos','historial_estados','caso_fotos','bajas_stock'] as $t){$s=$rma->prepare("DELETE FROM $t WHERE id_caso=?");$s->execute([$c['id']]);}}
 foreach(['bajas_stock','movimientos_stock'] as $t){$s=$rma->prepare("DELETE FROM $t WHERE id_stock=?");$s->execute([$f['insumo']]);}$s=$rma->prepare('DELETE FROM stock WHERE id=? AND codigo_sku=?');$s->execute([$f['insumo'],$f['tag']]);$s=$rma->prepare('DELETE FROM casos WHERE id_cliente=?');$s->execute([$f['cliente']]);$s=$rma->prepare('DELETE FROM clientes WHERE id=? AND cedula=?');$s->execute([$f['cliente'],$f['tag']]);$rma->commit();
 foreach(array_unique($files) as $file){$p=__DIR__.'/../uploads/'.basename($file);if(is_file($p)&&str_starts_with(basename($file),'EVIDENCIA_'))unlink($p);}
 foreach($f['sessions'] as $session){session_id($session['sid']);session_start();$_SESSION=[];session_destroy();}unlink($stateFile);echo "Datos y sesiones QA retirados. Datos existentes preservados.\n";exit;
}
ob_end_flush();
qa(httpQa('/api-rma/inventario/operaciones.php?action=listar')['code']===401,'Se exige sesión');
$op='/api-rma/inventario/operaciones.php?action=guardar';$body=['fuente'=>'central','id_caso'=>$f['caso'],'id_stock'=>$f['producto'],'tipo'=>'CENTRAL_RMA','cantidad'=>5,'motivo'=>$f['tag'],'causa'=>'FALLA_TECNICA'];
qa(httpQa($op,$dep,$body,false,false)['code']===403,'CSRF obligatorio');
qa(httpQa($op,$tec,$body)['code']===403,'Técnico no registra traslados');
foreach([['cantidad'=>-1],['cantidad'=>1.5],['cantidad'=>99999],['motivo'=>''],['tipo'=>'OTRO']] as $bad)qa(httpQa($op,$dep,array_replace($body,$bad))['code']===422,'Rechaza cantidades, motivos y tipos inválidos');
$r=httpQa($op,$dep,$body);qa($r['code']===200,'Traslado Central → RMA');
$s=$inv->prepare('SELECT stock_actual,stock_rma FROM productos WHERE id=?');$s->execute([$f['producto']]);$p=$s->fetch();qa((int)$p['stock_actual']===20&&(int)$p['stock_rma']===5,'Traslado conserva total y reserva cinco unidades');
qa(httpQa($op,$dep,array_replace($body,['tipo'=>'RMA_CENTRAL','cantidad'=>6]))['code']===422,'Devolución no supera el saldo del RMA');
qa(httpQa($op,$dep,array_replace($body,['tipo'=>'RMA_CENTRAL','cantidad'=>2]))['code']===200,'Devolución RMA → Central');
qa(httpQa($op,$tec,array_replace($body,['tipo'=>'BAJA_RMA','cantidad'=>1]))['code']===200,'Técnico da de baja un ítem de su RMA');
qa(httpQa($op,$dep,array_replace($body,['tipo'=>'BAJA_CENTRAL','id_caso'=>0,'cantidad'=>2]))['code']===200,'Depósito registra pérdida de stock central');
$s->execute([$f['producto']]);$p=$s->fetch();qa((int)$p['stock_actual']===17&&(int)$p['stock_rma']===2,'Bajas descuentan exactamente la cantidad y mantienen el saldo en RMA');
qa(httpQa($op,$tec,['fuente'=>'insumos','tipo'=>'BAJA_INSUMO','id_caso'=>$f['caso'],'id_stock'=>$f['insumo'],'cantidad'=>2,'causa'=>'FALLA_TECNICA','motivo'=>$f['tag']])['code']===200,'Baja de insumo con informe técnico');
$r=httpQa('/api-rma/inventario/stock-asignaciones.php?action=guardar',$dep,['id_caso'=>$f['caso'],'id_stock'=>$f['insumo'],'id_tecnico'=>$tec['id'],'cantidad'=>2,'observacion'=>'Prueba de consumo']);qa($r['code']===200&&isset($r['data']['remision_id']),'Consumo genera una remisión');$rem=$r['data']['remision_id'];
$s=$rma->prepare('SELECT cantidad FROM stock WHERE id=?');$s->execute([$f['insumo']]);qa((int)$s->fetchColumn()===6,'Baja y trigger de consumo descuentan una sola vez');
$r=httpQa('/api-rma/inventario/remisiones.php?action=pdf&id='.$rem,$dep);qa($r['code']===200&&str_starts_with($r['body'],'%PDF-')&&str_ends_with($r['body'],"%%EOF\n"),'PDF de remisión completo y válido por HTTP');file_put_contents($dir.'/remision-validada.pdf',$r['body']);
foreach(['central','insumos'] as $fuente){$r=httpQa('/api-rma/inventario/stock-alertas.php?action=consultar_alertas&fuente='.$fuente,$dep);qa($r['code']===200&&$r['data']['status']==='success'&&!str_contains($r['body'],'costo')&&!str_contains($r['body'],'precio'),'Stock crítico sin precios: '.$fuente);}
$r=httpQa('/api-rma/inventario/stock-asignaciones.php?action=auxiliares',$dep);qa(!str_contains($r['body'],'costo')&&!str_contains($r['body'],'precio'),'Asignaciones sin costos en API');
foreach(['/api-rma/notificaciones/notificaciones.php?action=listar_casos','/api-rma/clientes/clientes.php?action=listar','/adm-rma/notificaciones'] as $path)qa(httpQa($path,$dep)['code']===403,'Depósito no accede a comunicaciones');
$image=__DIR__.'/../uploads/DEMO_EVIDENCIA.png';qa(is_file($image),'Existe imagen de demostración para la prueba');
$data=['id_cliente'=>$f['cliente'],'id_tecnico'=>$tec['id'],'id_tipo_caso'=>$f['tipo'],'equipo'=>'Equipo QA','marca'=>'QA','numero_serie'=>$f['tag'].'-FOTO','descripcion_problema'=>'Prueba de evidencia','foto_archivo[0]'=>new CURLFile($image,'image/png','primera.png'),'foto_archivo[1]'=>new CURLFile($image,'image/png','segunda.png')];
$r=httpQa('/api-rma/rma-core/casos/casos.php?action=guardar',$tec,$data,true);qa($r['code']===200&&($r['data']['status']??'')==='success','Creación de RMA con dos imágenes');$id=$r['data']['id_caso'];
$r=httpQa('/api-rma/rma-core/casos/evidencias.php?id='.$id,$tec);qa(count($r['data']['fotos']??[])===2,'Las dos fotos siguen visibles después de guardar');$url=$r['data']['fotos'][0]['url'];$r=httpQa(substr($url,2),$tec);qa($r['code']===200&&$r['type']==='image/png','Imagen accesible y con MIME correcto');
$r=httpQa('/api-rma/rma-core/casos/taller.php?action=guardar_diagnostico',$tec,['id_caso'=>$id,'id_estado_actual'=>2,'diagnostico_final'=>'Prueba de conservación de imágenes','foto_archivo[0]'=>new CURLFile($image,'image/png','tercera.png')],true);qa(($r['data']['status']??'')==='success','Guardar diagnóstico con otra foto');
$r=httpQa('/api-rma/rma-core/casos/evidencias.php?id='.$id,$tec);qa(count($r['data']['fotos']??[])===3,'Diagnóstico conserva las fotos anteriores');
$r=httpQa('/api-rma/reportes.php',$admin,['titulo'=>'Reporte de prueba con acentos: remisión y devolución','columnas'=>['Caso','Insumo','Cantidad','Informe'],'filas'=>array_fill(0,20,[$f['tag'],'Memoria DDR4','2',str_repeat('Justificación técnica con acentos. ',6)])]);qa($r['code']===200&&str_starts_with($r['body'],'%PDF-'),'Exportación PDF con varias páginas y acentos');file_put_contents($dir.'/reporte-validado.pdf',$r['body']);
foreach(['usuarios','bandejaCasos','proveedoresRma','clientes','proveedores','remisiones','dashboardInventario','stockMovimientos','slaProcesos','performanceTecnico','confiabilidadProducto','auditoriaProveedores','recurrenciaClientes','stockAsignaciones','bajasStock','stockCatalogo','stockAlertas'] as $route){$r=httpQa('/adm-rma/'.$route,$admin);qa($r['code']===200&&str_contains($r['body'],'reportes.js')&&!preg_match('/(?:Fatal error|Warning:|Parse error)/',$r['body']),'Vista y exportación: '.$route);}
echo "Verificación funcional completa. Ejecutar limpiar al terminar la revisión visual.\n";
