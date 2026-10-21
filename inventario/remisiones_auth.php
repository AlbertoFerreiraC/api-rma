<?php
declare(strict_types=1);

function rmaSesion(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}
function rmaUsuarioId(): int
{
    rmaSesion();
    return (int) ($_SESSION['id'] ?? 0);
}
function rmaRol(): int
{
    rmaSesion();
    return (int) ($_SESSION['id_rol'] ?? 0);
}
function rmaPermitir(array $roles): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (rmaUsuarioId() <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Iniciá sesión para continuar.']);
        exit;
    }
    if (!in_array(rmaRol(), $roles, true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tenés permiso para realizar esta operación.']);
        exit;
    }
}
function rmaCsrfValidar(): void
{
    rmaSesion();
    $expected = (string) ($_SESSION['rma_csrf'] ?? '');
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['rma_csrf'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'La sesión de seguridad expiró. Recargá la página.']);
        exit;
    }
}
function rmaEnviarError(Throwable $e): void
{
    error_log('RMA API failure: ' . $e->getMessage());
    http_response_code($e instanceof DomainException || $e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['status' => 'error', 'message' => $e instanceof DomainException || $e instanceof InvalidArgumentException
        ? $e->getMessage() : 'No se pudo completar la operación. Revisá la conexión y las tablas instaladas.']);
}
