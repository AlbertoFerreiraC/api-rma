<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once "../../db.php";

if (!isset($pdo)) {
    echo json_encode([
        "status" => "error",
        "message" => "Protocolo caído. No hay conexión con el núcleo de la base de datos."
    ]);
    exit;
}

$action = $_GET["action"] ?? "";

if ($action === "aux_clientes") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, cedula FROM dosisma_rma_bd.clientes ORDER BY nombre ASC");
        $stmt->execute();

        echo json_encode([
            "status" => "success",
            "clientes" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "aux_tipos") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.tipos_caso ORDER BY nombre ASC");
        $stmt->execute();

        echo json_encode([
            "status" => "success",
            "tipos" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "obtener_proximo_numero") {
    try {
        $stmt = $pdo->prepare("SELECT MAX(id) as ultimo_id FROM dosisma_rma_bd.casos");
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        $proximo_id = intval($res['ultimo_id'] ?? 0) + 1;
        $anio_actual = date("Y");
        $proximo_numero = "RMA-" . $anio_actual . "-" . str_pad($proximo_id, 4, "0", STR_PAD_LEFT);

        echo json_encode([
            "status" => "success",
            "proximo_numero" => $proximo_numero
        ]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "guardar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $id_cliente = intval($_POST["id_cliente"] ?? 0);
        $id_tecnico = intval($_POST["id_tecnico"] ?? 0);
        $id_tipo_caso = intval($_POST["id_tipo_case"] ?? 0);
        $equipo = trim($_POST["equipo"] ?? "");
        $marca = trim($_POST["marca"] ?? "");
        $modelo = trim($_POST["modelo"] ?? "");
        $numero_serie = trim($_POST["numero_serie"] ?? "");
        $descripcion_problema = trim($_POST["descripcion_problema"] ?? "");

        $id_estado_inicial = 1;

        if ($id_cliente <= 0 || $id_tipo_caso <= 0 || $equipo === "" || $marca === "" || $numero_serie === "") {
            throw new Exception("Datos obligatorios incompletos en el vector de transmisión.");
        }

        $stmt_num = $pdo->prepare("SELECT MAX(id) as ultimo_id FROM dosisma_rma_bd.casos");
        $stmt_num->execute();
        $res_num = $stmt_num->fetch(PDO::FETCH_ASSOC);
        $real_id = intval($res_num['ultimo_id'] ?? 0) + 1;
        $numero_caso_final = "RMA-" . date("Y") . "-" . str_pad($real_id, 4, "0", STR_PAD_LEFT);

        $nombre_foto_bd = null;
        if (isset($_FILES["foto_archivo"]) && $_FILES["foto_archivo"]["error"] === UPLOAD_ERR_OK) {

            $file_tmp = $_FILES["foto_archivo"]["tmp_name"];
            $file_name = $_FILES["foto_archivo"]["name"];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_exts = ["jpg", "jpeg", "png", "webp"];
            if (!in_array($file_ext, $allowed_exts)) {
                throw new Exception("Extensión de archivo denegada. Solo imágenes permitidas.");
            }

            $nombre_foto_bd = "EVIDENCIA_" . uniqid() . "." . $file_ext;

            $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/rma-app/uploads/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            if (!move_uploaded_file($file_tmp, $upload_dir . $nombre_foto_bd)) {
                throw new Exception("Fallo al escribir la evidencia visual en el almacenamiento físico absoluto.");
            }
        }

        $qr_placeholder = "QR_" . $numero_caso_final;

        $sql = "INSERT INTO dosisma_rma_bd.casos (
                    numero_caso, id_cliente, id_tecnico, id_tipo_caso, id_estado_actual,
                    equipo, marca, modelo, numero_serie, descripcion_problema,
                    foto_archivo, qr_archivo, fecha_ingreso
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $numero_caso_final,
            $id_cliente,
            $id_tecnico,
            $id_tipo_caso,
            $id_estado_inicial,
            $equipo,
            $marca,
            $modelo,
            $numero_serie,
            $descripcion_problema,
            $nombre_foto_bd,
            $qr_placeholder
        ]);

        $id_caso_insertado = $pdo->lastInsertId();

        $sql_historial = "INSERT INTO dosisma_rma_bd.historial_estados (
                            id_caso, id_estado, fecha, observacion, id_usuario
                          ) VALUES (?, ?, NOW(), ?, ?)";
        $stmt_hist = $pdo->prepare($sql_historial);
        $stmt_hist->execute([
            $id_caso_insertado,
            $id_estado_inicial,
            "INGRESO INICIAL AUTOMÁTICO DESDE SISTEMA",
            $id_tecnico
        ]);

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];

        $secret_salt = "MICRO_EXPRESS_SECURE_TOKEN_2026";
        $hash_seguro = base64_encode($numero_caso_final . "||" . md5($numero_caso_final . $secret_salt));

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];

        $url_visualizacion = $protocolo . $host . "/rma-app/comprobante-caso.php?token=" . urlencode($hash_seguro);

        $qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($url_visualizacion);

        echo json_encode([
            "status" => "success",
            "message" => "Caso registrado bajo la firma: $numero_caso_final.",
            "numero_caso" => $numero_caso_final,
            "qr_url" => $qr_image_url,
            "link_pdf" => $url_visualizacion
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode([
    "status" => "error",
    "message" => "Acción o vector no reconocido por el sistema de control central."
]);