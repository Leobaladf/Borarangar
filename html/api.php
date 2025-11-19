<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- FUNÇÃO AUXILIAR PARA VERIFICAR SUPER ADMIN ---
// (Copiada do usuario_api.php)
function isSuperAdmin() {
    return isset($_SESSION['user_id']) && 
           isset($_SESSION['user_role']) && 
           $_SESSION['user_role'] === 'admin' && 
           !isset($_SESSION['restaurante_id']);
}

// --- VALIDAÇÃO DE SESSÃO (ADICIONADA) ---
if (!isSuperAdmin()) {
    http_response_code(403); // Proibido
    echo json_encode(["status" => "error", "message" => "Acesso negado. Faça o login."]);
    exit();
}

// --- CONEXÃO COM O BANCO DE DADOS (Seu código original) ---
$servername = "localhost";
$username = "borarangar_user";
$password = "Tredf1234----"; // <<<<<<< COLOQUE SUA SENHA AQUI
$dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { /* ... código de erro ... */ exit(); }

$method = $_SERVER['REQUEST_METHOD'];

// O resto do seu arquivo (GET, POST, PUT, DELETE) continua abaixo...
if ($method === 'GET') {
    // --- LÓGICA PARA LISTAR RESTAURANTES (sem alterações) ---
    $sql = "SELECT id, nome, subdominio, licenca_status FROM restaurantes ORDER BY nome";
    $result = $conn->query($sql);
    $restaurantes = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $restaurantes[] = $row;
        }
    }
    echo json_encode($restaurantes);

} elseif ($method === 'POST') {
    // --- LÓGICA PARA CRIAR RESTAURANTE (sem alterações) ---
    $nome_restaurante = $_POST['nome'] ?? '';
    $subdominio = $_POST['subdominio'] ?? '';
    if (empty($nome_restaurante) || empty($subdominio)) { /* ... */ exit(); }
    $stmt = $conn->prepare("INSERT INTO restaurantes (nome, subdominio) VALUES (?, ?)");
    $stmt->bind_param("ss", $nome_restaurante, $subdominio);
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(["status" => "success", "message" => "Restaurante criado com sucesso!", "id" => $stmt->insert_id]);
    } else { /* ... código de erro ... */ }
    $stmt->close();

} elseif ($method === 'PUT') {
    // --- NOVA LÓGICA PARA ATUALIZAR UM RESTAURANTE ---
    $id = $_GET['id'] ?? '';
    $data = json_decode(file_get_contents('php://input'), true);
    $nome = $data['nome'] ?? '';
    $subdominio = $data['subdominio'] ?? '';

    if (empty($id) || empty($nome) || empty($subdominio)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID, nome e subdomínio são obrigatórios."]);
        exit();
    }
    $stmt = $conn->prepare("UPDATE restaurantes SET nome = ?, subdominio = ? WHERE id = ?");
    $stmt->bind_param("ssi", $nome, $subdominio, $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Restaurante atualizado com sucesso!"]);
    } else { /* ... código de erro ... */ }
    $stmt->close();

} elseif ($method === 'DELETE') {
    // --- NOVA LÓGICA PARA DELETAR UM RESTAURANTE ---
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID do restaurante é obrigatório."]);
        exit();
    }
    $stmt = $conn->prepare("DELETE FROM restaurantes WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Restaurante deletado com sucesso!"]);
    } else { /* ... código de erro ... */ }
    $stmt->close();

} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método não permitido."]);
}
$conn->close();
?>
