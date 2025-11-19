<?php
session_start();

$filename = $_GET['file'] ?? '';
if (empty($filename) || !preg_match('/^[a-zA-Z0-9\-\.]+$/', $filename)) {
    http_response_code(400);
    exit('Nome de arquivo inválido.');
}

// --- LÓGICA DE PERMISSÃO INTELIGENTE ---
$allowed_restaurante_id = null;

// Cenário 1: Usuário logado (painel do lojista)
if (isset($_SESSION['restaurante_id'])) {
    $allowed_restaurante_id = $_SESSION['restaurante_id'];
} 
// Cenário 2: Acesso público (delivery.html), que deve fornecer o ID do restaurante
elseif (isset($_GET['restaurante_id'])) {
    $allowed_restaurante_id = filter_var($_GET['restaurante_id'], FILTER_VALIDATE_INT);
}

// Se não se encaixa em nenhum cenário, o acesso é negado.
if (!$allowed_restaurante_id) {
    http_response_code(403);
    exit('Acesso Negado');
}

$filepath = 'uploads/cardapio/' . $filename;

// --- VERIFICAÇÃO DE PROPRIEDADE ---
// Conecta ao banco de dados para garantir que a imagem pertence ao restaurante permitido.
$servername = "localhost";
$username = "borarangar_user";
$password = "Tredf1234----"; // Sua senha
$dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    exit('Erro de servidor');
}

$stmt = $conn->prepare("SELECT id FROM cardapio_itens WHERE imagem_path = ? AND restaurante_id = ?");
$stmt->bind_param("si", $filepath, $allowed_restaurante_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0 || !file_exists($filepath)) {
    http_response_code(404);
    // Para depuração: error_log("Imagem não encontrada: $filepath para restaurante $allowed_restaurante_id");
    // Em produção, podemos servir uma imagem padrão de "não encontrado"
    // header('Location: /path/to/placeholder.jpg');
    exit('Imagem não encontrada.');
}
$stmt->close();
$conn->close();

// --- SERVIR A IMAGEM ---
$mime_type = mime_content_type($filepath);
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit();
?>
