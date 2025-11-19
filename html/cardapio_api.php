<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost";
$username = "borarangar_user";
$password = "Tredf1234----"; // Mantenha sua senha
$dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { /* ... */ exit(); }

$method = $_SERVER['REQUEST_METHOD'];

// ===================================================================
// ### INÍCIO DA CORREÇÃO DO BUG 3 ###
// Função de Upload de Imagem ATUALIZADA com validação
// ===================================================================
function handleImageUpload($fileInputName, $currentItemPath = null) {
    // Verifica se um arquivo foi enviado e não há erros
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
        
        // --- 1. VERIFICA O TAMANHO (Ex: 5MB) ---
        $max_size = 5 * 1024 * 1024; // 5 Megabytes
        if ($_FILES[$fileInputName]['size'] > $max_size) {
            // Lança uma Exceção que será capturada no Bloco POST
            throw new Exception("Erro: Arquivo muito grande (Max 5MB)."); 
        }

        // --- 2. VERIFICA O TIPO (MIME Type) ---
        // Usamos finfo para checar o tipo real do arquivo, não só a extensão
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES[$fileInputName]['tmp_name']);
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        
        if (!in_array($mime, $allowed_types)) {
            // Lança uma Exceção
            throw new Exception("Erro: Tipo de arquivo não permitido (Use JPG, PNG ou WebP).");
        }
        
        // --- 3. CRIA O DIRETÓRIO E NOME SEGURO ---
        $uploadDir = 'uploads/cardapio/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        // Deleta a imagem antiga se uma nova for enviada
        if ($currentItemPath && file_exists($currentItemPath)) {
            unlink($currentItemPath);
        }

        // Gera um nome único e seguro, mantendo a extensão original
        $ext = pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $uploadDir . $fileName;
        
        // Move o arquivo
        if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $targetPath)) {
            return $targetPath; // Retorna o novo caminho
        } else {
            throw new Exception("Erro interno ao salvar a imagem.");
        }
    }
    // Se nenhum arquivo novo foi enviado, retorna o caminho antigo (ou null)
    return $currentItemPath; 
}
// ===================================================================
// ### FIM DA CORREÇÃO ###
// ===================================================================


if ($method === 'GET') {
    $restaurante_id = $_GET['restaurante_id'] ?? null;
    if (!$restaurante_id) {
        if (!isset($_SESSION['restaurante_id'])) {
            http_response_code(403); exit();
        }
        $restaurante_id = $_SESSION['restaurante_id'];
    }

    // (Nenhuma alteração no GET)
    $stmt = $conn->prepare("SELECT id, nome, descricao, preco, categoria, imagem_path, disponivel FROM cardapio_itens WHERE restaurante_id = ? ORDER BY nome");
    $stmt->bind_param("i", $restaurante_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cardapio = [];
    while($row = $result->fetch_assoc()) {
        $cardapio[] = $row;
    }
    echo json_encode($cardapio);
    $stmt->close();

} elseif ($method === 'POST') {
    if (!isset($_SESSION['restaurante_id'])) { http_response_code(403); exit(); }
    $restaurante_id_logado = $_SESSION['restaurante_id'];
    
    // --- CORREÇÃO: Adicionado Bloco try...catch ---
    // Para capturar os erros (Ex: "Arquivo muito grande") da função handleImageUpload
    try {
        
        // --- LÓGICA DE ATUALIZAÇÃO (EDITAR) ---
        if (isset($_POST['action']) && $_POST['action'] === 'update') {
            $id = $_POST['id'] ?? '';
            $nome = $_POST['nome'] ?? '';
            $preco = $_POST['preco'] ?? '';
            $categoria = $_POST['categoria'] ?? '';
            $descricao = $_POST['descricao'] ?? '';

            // Busca o caminho da imagem atual para poder deletá-la se uma nova for enviada
            $stmt_path = $conn->prepare("SELECT imagem_path FROM cardapio_itens WHERE id = ? AND restaurante_id = ?");
            $stmt_path->bind_param("ii", $id, $restaurante_id_logado);
            $stmt_path->execute();
            $currentImagePath = $stmt_path->get_result()->fetch_assoc()['imagem_path'] ?? null;
            $stmt_path->close();

            // A função handleImageUpload AGORA PODE LANÇAR UMA EXCEÇÃO
            $imagem_path = handleImageUpload('imagem', $currentImagePath);
            
            $stmt = $conn->prepare("UPDATE cardapio_itens SET nome = ?, preco = ?, categoria = ?, descricao = ?, imagem_path = ? WHERE id = ? AND restaurante_id = ?");
            $stmt->bind_param("sdsssii", $nome, $preco, $categoria, $descricao, $imagem_path, $id, $restaurante_id_logado);
            
            if ($stmt->execute()) {
                echo json_encode(["status" => "success", "message" => "Item atualizado com sucesso!"]);
            } else {
                 http_response_code(500);
                 echo json_encode(["status" => "error", "message" => "Erro ao atualizar item."]);
            }
            $stmt->close();

        } else { // --- LÓGICA DE CRIAÇÃO (ADICIONAR NOVO) ---
            $nome = $_POST['nome'] ?? '';
            $preco = $_POST['preco'] ?? '';
            $categoria = $_POST['categoria'] ?? '';
            $descricao = $_POST['descricao'] ?? '';

            // A função handleImageUpload AGORA PODE LANÇAR UMA EXCEÇÃO
            $imagem_path = handleImageUpload('imagem');

            $stmt = $conn->prepare("INSERT INTO cardapio_itens (restaurante_id, nome, preco, categoria, descricao, imagem_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isdsss", $restaurante_id_logado, $nome, $preco, $categoria, $descricao, $imagem_path);

            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(["status" => "success", "message" => "Item criado com sucesso!", "id" => $stmt->insert_id]);
            } else { 
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Erro ao criar item."]);
            }
            $stmt->close();
        }

    } catch (Exception $e) {
        // Captura os erros de upload (Ex: "Arquivo muito grande")
        http_response_code(400); // Bad Request
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        exit();
    }

} elseif ($method === 'DELETE') {
    // (Nenhuma alteração no DELETE)
    if (!isset($_SESSION['restaurante_id'])) { http_response_code(403); exit(); }
    $restaurante_id_logado = $_SESSION['restaurante_id'];
    $id = $_GET['id'] ?? '';
    if (empty($id)) { /* ... */ exit(); }

    $stmt_path = $conn->prepare("SELECT imagem_path FROM cardapio_itens WHERE id = ? AND restaurante_id = ?");
    $stmt_path->bind_param("ii", $id, $restaurante_id_logado);
    $stmt_path->execute();
    $imagePath = $stmt_path->get_result()->fetch_assoc()['imagem_path'] ?? null;
    $stmt_path->close();
    
    $stmt = $conn->prepare("DELETE FROM cardapio_itens WHERE id = ? AND restaurante_id = ?");
    $stmt->bind_param("ii", $id, $restaurante_id_logado);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        if ($imagePath && file_exists($imagePath)) {
            unlink($imagePath);
        }
        echo json_encode(["status" => "success", "message" => "Item deletado com sucesso!"]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Item não encontrado."]);
    }
    $stmt->close();
}

$conn->close();
?>
