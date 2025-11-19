<?php
// /var/www/html/google_api.php

session_start();
header("Content-Type: application/json; charset=UTF-8");
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log'); // Verifique o caminho do log no seu servidor
error_reporting(E_ALL);

// É obrigatório usar o composer para instalar a biblioteca do Google.
// Se você não o fez, este arquivo deve estar na raiz do seu projeto.
require __DIR__ . '/vendor/autoload.php';

// --- 1. CONFIGURAÇÃO (Seu Client ID) ---
// USE SEU CLIENT ID OBTIDO NO GOOGLE CLOUD CONSOLE
const GOOGLE_CLIENT_ID = "220116030662-0sq2avuo9a3hrvglq9g008e057ca557l.apps.googleusercontent.com"; // SUBSTITUA PELO SEU CLIENT ID REAL
$client = new Google_Client(['client_id' => GOOGLE_CLIENT_ID]); 

// --- 2. CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; 
$username = "borarangar_user"; 
$password = "Tredf1234----"; // <<<<<<< SUA SENHA AQUI
$dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); 
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("GOOGLE_API: Falha CRÍTICA na conexão DB: " . $e->getMessage());
    http_response_code(500); 
    echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); 
    exit();
}

// =============================================================
// MÉTODO POST - LOGAR/REGISTRAR COM GOOGLE
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A API recebe um JSON
    $data = json_decode(file_get_contents('php://input'), true);

    // Valida os dados recebidos do JS
    $id_token = $data['credential'] ?? null;
    $restaurante_id = filter_var($data['restaurante_id'] ?? null, FILTER_VALIDATE_INT);
    
    if (empty($id_token) || !$restaurante_id) {
        http_response_code(400); 
        exit(json_encode(['status' => 'error', 'message' => 'Token do Google ou ID do restaurante é obrigatório.']));
    }

    try {
        // 3. Verifica o token e obtém os dados do usuário
        $payload = $client->verifyIdToken($id_token);

        if ($payload) {
            $email = $payload['email'];
            $google_id = $payload['sub'];
            $nome = $payload['name'];
            $foto = $payload['picture'] ?? null;
            
            // 4. Procura o cliente no banco de dados (por ID Google e ID do Restaurante)
            $stmt_select = $conn->prepare("SELECT id, nome, email, telefone, endereco FROM clientes WHERE google_id = ? AND restaurante_id = ?");
            $stmt_select->bind_param("si", $google_id, $restaurante_id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            
            // 5. Cliente EXISTE (LOGIN)
            if ($result->num_rows > 0) {
                $cliente = $result->fetch_assoc();
                $stmt_select->close();
                
                // Opcional: Atualiza nome e foto se o Google tiver dados mais recentes
                $stmt_update = $conn->prepare("UPDATE clientes SET nome = ?, foto_url = ? WHERE id = ?");
                $stmt_update->bind_param("ssi", $nome, $foto, $cliente['id']);
                $stmt_update->execute();
                if ($stmt_update) $stmt_update->close();
                
                // LOGIN: Preenche a sessão
                $_SESSION['cliente'] = [
                    'id' => $cliente['id'], 
                    'nome' => $cliente['nome'],
                    'email' => $cliente['email'], 
                    'telefone' => $cliente['telefone'], 
                    'endereco' => $cliente['endereco'],
                    'restaurante_id' => $restaurante_id
                ];
                
                http_response_code(200); // OK
                echo json_encode(['status' => 'success', 'message' => 'Login Google realizado com sucesso!', 'action' => 'login', 'data' => $_SESSION['cliente']]);

            } 
            // 6. Cliente NÃO EXISTE (REGISTRO)
            else {
                // CORREÇÃO CRÍTICA: Cria um hash seguro para a senha, 
                // garantindo que a coluna SENHA (se for NOT NULL) seja preenchida.
                $senha_hash = password_hash(uniqid(), PASSWORD_DEFAULT); 

                // Adicionado 'restaurante_id' e 'senha' no INSERT
                $stmt_insert = $conn->prepare("INSERT INTO clientes (nome, email, google_id, foto_url, restaurante_id, senha) VALUES (?, ?, ?, ?, ?, ?)");
                // Tipos: s (nome), s (email), s (google_id), s (foto_url), i (restaurante_id), s (senha HASH)
                $stmt_insert->bind_param("ssssis", $nome, $email, $google_id, $foto, $restaurante_id, $senha_hash); 
                
                if ($stmt_insert->execute()) {
                    $new_client_id = $conn->insert_id;
                    
                    // LOGIN: Preenche a sessão para o novo cliente
                    $_SESSION['cliente'] = [
                        'id' => $new_client_id, 
                        'nome' => $nome, 
                        'email' => $email, 
                        'telefone' => null, 
                        'endereco' => null,
                        'restaurante_id' => $restaurante_id
                    ];
                    
                    http_response_code(201); // Created
                    echo json_encode(['status' => 'success', 'message' => 'Conta Google criada! Complete seu cadastro.', 'action' => 'register', 'data' => $_SESSION['cliente']]);
                } else {
                    // Se houver falha na execução (e.g. erro de BD)
                    throw new Exception("Erro ao registrar novo cliente no BD.");
                }
                $stmt_insert->close();
            }

        } else {
            http_response_code(401); 
            exit(json_encode(['status' => 'error', 'message' => 'Token do Google inválido.']));
        }
    } catch (\Throwable $e) {
        // Captura qualquer erro de execução
        error_log("Google API Error: " . $e->getMessage());
        http_response_code(500); 
        exit(json_encode(['status' => 'error', 'message' => 'Erro interno do servidor.']));
    }
}
else {
    http_response_code(405); 
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido']);
}

$conn->close();
?>
