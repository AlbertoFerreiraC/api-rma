<?php
$host = "162.220.10.11";
$db   = "dosisma_inventario_db";
$user = "dosisma_stock"; // Cambiar por tu usuario de BD
$pass = "inventariobrunorma2k26";     // Cambiar por tu contraseña de BD
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo_inv = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error de conexión a la base de datos de inventario: " . $e->getMessage()
    ]);
    exit;
}