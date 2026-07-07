<?php

$host = "162.220.10.11";
$dbname = "dosisma_rma_bd";
$user = "dosisma_rma";
$password = "tesis2026bruno";

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Error de conexión a la base de datos"
    ]);

    exit;
}