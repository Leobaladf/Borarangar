<?php
header("Content-Type: application/json; charset=UTF-8");

// ... (cole aqui seu bloco de conexão com o banco de dados) ...
$servername = "localhost";
$username = "borarangar_user";
$password = "Tredf1234----";
$dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { /* ... (código de erro) ... */ exit(); }

// Pega o subdomínio que o Nginx nos enviou
$subdomain = $_SERVER['HTTP_X_SUBDOMAIN'] ?? '';

// Se não veio subdomínio, podemos pegar da URL diretamente (plano B)
if (empty($subdomain)) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $parts = explode('.', $host);
    if (count($parts) > 2) {
        $subdomain = $parts[0];
    }
}

if (empty($subdomain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Subdomínio não identificado.']);
    exit();
}

// Procura o restaurante com este subdomínio
$stmt = $conn->prepare("SELECT id, nome FROM restaurantes WHERE subdominio = ?");
$stmt->bind_param("s", $subdomain);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $restaurant = $result->fetch_assoc();
    echo json_encode($restaurant);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Restaurante não encontrado.']);
}

$stmt->close();
$conn->close();
?>
