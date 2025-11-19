<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost";
$username = "borarangar_user";
$password = "Tredf1234----"; // Mantenha sua senha aqui
$dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { 
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erro de conexão."]);
    exit(); 
}

$method = $_SERVER['REQUEST_METHOD'];
$restaurante_id = null;

// Lógica para determinar o ID do restaurante (público via URL ou privado via sessão)
if (isset($_GET['restaurante_id'])) {
    $restaurante_id = filter_var($_GET['restaurante_id'], FILTER_VALIDATE_INT);
} elseif (isset($_SESSION['restaurante_id'])) {
    $restaurante_id = $_SESSION['restaurante_id'];
}

// Se nenhum ID válido for encontrado, o acesso é negado
if (!$restaurante_id) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Acesso negado. ID do restaurante não fornecido."]);
    exit();
}

if ($method === 'GET') {
    // CORREÇÃO: Adicionado o campo 'icone' ao SELECT
    $stmt = $conn->prepare("SELECT id, nome, icone FROM categorias WHERE restaurante_id = ? ORDER BY nome ASC");
    $stmt->bind_param("i", $restaurante_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $categorias = [];
    while($row = $result->fetch_assoc()) {
        $categorias[] = $row;
    }
    echo json_encode($categorias);
    $stmt->close();

} elseif ($method === 'POST') {
    // Apenas usuários logados podem criar categorias
    if (!isset($_SESSION['restaurante_id'])) {
        http_response_code(403);
        exit(json_encode(["status" => "error", "message" => "Acesso não permitido."]));
    }
    
    $nome = $_POST['nome'] ?? '';
    if (empty($nome)) { 
        http_response_code(400);
        exit(json_encode(["status" => "error", "message" => "O nome da categoria é obrigatório."]));
    }

    $stmt = $conn->prepare("INSERT INTO categorias (restaurante_id, nome) VALUES (?, ?)");
    $stmt->bind_param("is", $_SESSION['restaurante_id'], $nome); // Usa o ID da sessão por segurança
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(["status" => "success", "message" => "Categoria criada com sucesso!"]);
    } else { 
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Erro ao criar categoria."]);
    }
    $stmt->close();

} elseif ($method === 'DELETE') {
    // Apenas usuários logados podem deletar
    if (!isset($_SESSION['restaurante_id'])) {
        http_response_code(403);
        exit(json_encode(["status" => "error", "message" => "Acesso não permitido."]));
    }

    $id = $_GET['id'] ?? '';
    if (empty($id)) { 
        http_response_code(400);
        exit(json_encode(["status" => "error", "message" => "ID da categoria é obrigatório."]));
    }
    
    $stmt = $conn->prepare("DELETE FROM categorias WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $id, $_SESSION['restaurante_id']);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(["status" => "success", "message" => "Categoria deletada com sucesso!"]);
    } else { 
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Categoria não encontrada ou não pertence ao seu restaurante."]);
    }
    $stmt->close();
}

$conn->close();
?>
