<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__.'/../../inventario/remisiones_auth.php';
rmaPermitir([1,2]);
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/evidencias_service.php';

if (!isset($pdo)) {
    echo json_encode(["status" => "error", "message" => "Conexión caída con la base de datos central."]);
    exit;
}

$action = $_GET["action"] ?? "";

if ($action === "listar_cola") {
    try {
        $buscar = trim($_GET["buscar"] ?? "");
        $alcance = trim($_GET["alcance"] ?? "activos");
        $orden = strtolower(trim($_GET["orden"] ?? "asc")) === "desc" ? "DESC" : "ASC";

        $sql = "SELECT c.id, c.numero_caso, c.equipo, c.marca, c.numero_serie, c.id_estado_actual, ec.nombre AS estado_nombre 
                FROM dosisma_rma_bd.casos c
                INNER JOIN dosisma_rma_bd.estados_caso ec ON c.id_estado_actual = ec.id";

        $conditions = [];
        $params = [];

        if ($alcance === "activos") {
            $conditions[] = "c.id_estado_actual != 4";
        }

        if ($buscar !== "") {
            $conditions[] = "(c.numero_caso LIKE ? OR c.numero_serie LIKE ? OR c.equipo LIKE ? OR c.marca LIKE ?)";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
        }

        if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY c.id $orden LIMIT 1000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            "status" => "success",
            "cola" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Fallo en motor de búsqueda de cola: " . $e->getMessage()]);
    }
    exit;
}

if ($action === "aux_estados") {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM dosisma_rma_bd.estados_caso ORDER BY id ASC");
        $stmt->execute();
        echo json_encode(["status" => "success", "estados" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "obtener_caso") {
    try {
        $id = intval($_GET["id"] ?? 0);
        $stmt = $pdo->prepare("SELECT id, numero_caso, equipo, marca, modelo, numero_serie, descripcion_problema, diagnostico_final, foto_archivo, id_estado_actual FROM dosisma_rma_bd.casos WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $caso = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$caso) {
            throw new Exception("El hardware solicitado no figura en el taller.");
        }
        echo json_encode(["status" => "success", "caso" => $caso]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

if ($action === "guardar_diagnostico" && $_SERVER["REQUEST_METHOD"] === "POST") {
    rmaCsrfValidar();
    $nuevasFotos=[];
    try {
        $pdo->beginTransaction();
        $id_caso = intval($_POST["id_caso"] ?? 0);
        $id_usuario = rmaUsuarioId();
        $id_estado_actual = intval($_POST["id_estado_actual"] ?? 0);
        $diagnostico_final = trim($_POST["diagnostico_final"] ?? "");

        if ($id_caso <= 0 || $id_estado_actual <= 0 || $diagnostico_final === "") {
            throw new Exception("Formulario incompleto. Ingrese el informe de diagnóstico técnico.");
        }

        $stmt_check = $pdo->prepare("SELECT foto_archivo, numero_caso FROM dosisma_rma_bd.casos WHERE id = ?");
        $stmt_check->execute([$id_caso]);
        $caso_actual = $stmt_check->fetch(PDO::FETCH_ASSOC);
        $nombre_foto_bd = $caso_actual["foto_archivo"] ?? null;

        $nuevasFotos=rmaRecibirFotos($_FILES['foto_archivo']??null);
        rmaAnexarFotos($pdo,$id_caso,array_merge([$nombre_foto_bd],$nuevasFotos));
        if($nuevasFotos)$nombre_foto_bd=$nuevasFotos[0];

        $sql_update = "UPDATE dosisma_rma_bd.casos 
                       SET diagnostico_final = ?, id_estado_actual = ?, foto_archivo = ? 
                       WHERE id = ?";
        $stmt = $pdo->prepare($sql_update);
        $stmt->execute([$diagnostico_final, $id_estado_actual, $nombre_foto_bd, $id_caso]);

        $stmt_estado = $pdo->prepare("SELECT nombre FROM dosisma_rma_bd.estados_caso WHERE id = ?");
        $stmt_estado->execute([$id_estado_actual]);
        $nombre_estado = $stmt_estado->fetchColumn();

        $observacion_historial = "ACTUALIZACIÓN DESDE TALLER: " . $nombre_estado . ". INFORME: " . $diagnostico_final;

        $sql_historial = "INSERT INTO dosisma_rma_bd.historial_estados (id_caso, id_estado, fecha, observacion, id_usuario) VALUES (?, ?, NOW(), ?, ?)";
        $stmt_hist = $pdo->prepare($sql_historial);
        $stmt_hist->execute([$id_caso, $id_estado_actual, $observacion_historial, $id_usuario]);

        $pdo->commit();
        echo json_encode([
            "status" => "success",
            "message" => "Logs de reparación inyectados con éxito. Historial de estados modificado."
        ]);

    } catch (Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        rmaRetirarFotos($nuevasFotos);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Acción inválida en el laboratorio."]);