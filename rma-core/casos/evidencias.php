<?php
declare(strict_types=1);
require_once __DIR__.'/../../inventario/remisiones_auth.php';rmaPermitir([1,2]);
require_once __DIR__.'/../../db_config.php';$pdo=rmaConexion('rma');
try {
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);if(!$id||$id<1)throw new InvalidArgumentException('Caso inválido.');
    $s=$pdo->prepare('SELECT numero_caso,foto_archivo FROM casos WHERE id=?');$s->execute([$id]);$caso=$s->fetch();
    if(!$caso){http_response_code(404);echo json_encode(['status'=>'error','message'=>'El caso no existe.']);exit;}
    $s=$pdo->prepare('SELECT archivo FROM caso_fotos WHERE id_caso=? ORDER BY id');$s->execute([$id]);
    $files=array_values(array_unique(array_merge(array_filter([$caso['foto_archivo']]),$s->fetchAll(PDO::FETCH_COLUMN))));
    if(isset($_GET['archivo'])) {
        $file=(string)$_GET['archivo'];
        if(!in_array($file,$files,true)||basename($file)!==$file||!preg_match('/\.(jpg|jpeg|png|webp)$/i',$file)){http_response_code(404);exit;}
        $path=__DIR__.'/../../../uploads/'.$file;
        if(!is_file($path)){http_response_code(404);exit;}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(415);exit;}
        header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }
    echo json_encode(['status'=>'success','numero_caso'=>$caso['numero_caso'],'fotos'=>array_map(static fn($f)=>['archivo'=>$f,'url'=>'../api-rma/rma-core/casos/evidencias.php?id='.$id.'&archivo='.rawurlencode($f),'disponible'=>is_file(__DIR__.'/../../../uploads/'.basename($f))],$files)]);
}catch(Throwable $e){rmaEnviarError($e);}
