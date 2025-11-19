<?php
// ATIVAR LOGS (manter ativo por enquanto)
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log'); // Ajuste se necessário
error_reporting(E_ALL);
ini_set('display_errors', 0); // NÃO mostra erros

session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Habilita exceções
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("USUARIO_API: Falha CRÍTICA Conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

// --- FUNÇÃO AUXILIAR PARA VERIFICAR SUPER ADMIN ---
function isSuperAdmin() {
    return isset($_SESSION['user_id']) && 
           isset($_SESSION['user_role']) && 
           $_SESSION['user_role'] === 'admin' && 
           !isset($_SESSION['restaurante_id']);
}

// --- ROTEADOR ---
$method = $_SERVER['REQUEST_METHOD'];

// ===========================================
// MÉTODO GET - LISTAR USUÁRIOS OU CLIENTES
// ===========================================
if ($method === 'GET') {
    // TODAS as rotas GET exigem uma sessão ativa
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403); 
        echo json_encode(["status" => "error", "message" => "Acesso negado."]); 
        exit(); 
    }

    // O padrão agora é 'get_users'. Se nenhuma ação for passada, cai em 'get_users'.
    $action = $_GET['action'] ?? 'get_users'; 

    // --- LÓGICA: LISTAR CLIENTES (APENAS SUPER ADMIN) ---
    if ($action === 'get_clients') {
        if (!isSuperAdmin()) {
            http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso negado."]); exit();
        }
        $stmt = null;
        try {
            $sql = "SELECT c.id, c.nome, c.email, c.telefone, c.endereco, c.created_at, r.nome as nome_restaurante
                    FROM clientes c
                    JOIN restaurantes r ON c.restaurante_id = r.id
                    ORDER BY r.nome, c.nome";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Erro prepare get_clients.");
            $stmt->execute();
            $result = $stmt->get_result();
            $clientes = [];
            while($row = $result->fetch_assoc()) {
                 try { $row['created_at'] = isset($row['created_at']) ? (new DateTime($row['created_at']))->format('d/m/Y H:i') : null; } catch (Exception $e) {$row['created_at'] = null;}
                 $clientes[] = $row;
            }
            echo json_encode($clientes);

        } catch (mysqli_sql_exception | Exception $e) {
            error_log("USUARIO_API GET Clients: Erro: ".$e->getMessage()); http_response_code(500); echo json_encode(['status' => 'error']);
        } finally {
            if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
        }
        exit();
    }

    // --- LÓGICA: LISTAR USUÁRIOS (FUNCIONÁRIOS/DONOS/ADMINS) ---
      elseif ($action === 'get_users') {
        $stmt = null;
        try {
            // --- CASO 1: SUPER ADMIN PEDINDO A LISTA COMPLETA ---
            // (Chamado pelo /admin.borarangar.com.br/html/usuarios.html)
            if (isSuperAdmin()) { 
                 $sql = "SELECT u.id, u.nome, u.email, u.role, r.nome as nome_restaurante
                         FROM usuarios u LEFT JOIN restaurantes r ON u.restaurante_id = r.id
                         ORDER BY r.nome, u.nome";
                 $stmt = $conn->prepare($sql);
                 if (!$stmt) throw new Exception("Erro prepare get_users admin.");
                 $stmt->execute();
                 $result = $stmt->get_result();
                 $usuarios = [];
                 while($row = $result->fetch_assoc()) { $usuarios[] = $row; }
                 echo json_encode($usuarios); // Retorna a lista completa

            // --- CASO 2: LOJISTA PEDINDO SEUS PRÓPRIOS DADOS ---
            // (Chamado pelo /painel/dashboard.html, que não envia action)
            } else { 
                 // Pega o ID e nome diretamente da sessão
                 $user_id_logado = $_SESSION['user_id'];
                 $user_nome_logado = $_SESSION['user_nome'];
                 // ATUALIZAÇÃO (para módulo de RH): Retorna também o 'role'
                 echo json_encode([['id' => $user_id_logado, 'nome' => $user_nome_logado, 'role' => $_SESSION['user_role']]]); 
            }

        } catch (mysqli_sql_exception | Exception $e) { 
            error_log("USUARIO_API GET Users: Erro: ".$e->getMessage()); http_response_code(500); echo json_encode(['status' => 'error']);
        } finally { 
            if ($stmt instanceof mysqli_stmt) { $stmt->close(); } 
        }
        exit(); 
    }

    // ===================================================================
    // ### INÍCIO DA CORREÇÃO DO BUG 1 (Original) ###
    // (Ação 'get_my_employees' para 'gerenciar-funcionarios.html')
    // ===================================================================
      elseif ($action === 'get_my_employees') {
        $stmt = null;
        try {
            // Garante que é um lojista logado
            if (!isset($_SESSION['restaurante_id'])) {
                http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso de lojista negado."]); exit();
            }

            $restaurante_id_logado = $_SESSION['restaurante_id'];

            // Busca TODOS os usuários associados a esse restaurante
            $sql = "SELECT id, nome, email, role FROM usuarios WHERE restaurante_id = ? ORDER BY nome";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Erro prepare get_my_employees.");

            $stmt->bind_param("i", $restaurante_id_logado);
            $stmt->execute();
            $result = $stmt->get_result();
            $usuarios = [];
            while($row = $result->fetch_assoc()) {
                $usuarios[] = $row;
            }
            echo json_encode($usuarios); // Retorna a lista completa de funcionários

        } catch (mysqli_sql_exception | Exception $e) {
            error_log("USUARIO_API GET get_my_employees: Erro: ".$e->getMessage()); http_response_code(500); echo json_encode(['status' => 'error']);
        } finally {
            if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
        }
        exit();
    }
    // ===================================================================
    // ### FIM DA CORREÇÃO ###
    // ===================================================================

    // ========================================================
    // ### INÍCIO DA NOVA FUNÇÃO DE ENTREGADORES ###
    // (Adicionada para o Módulo de Entregas)
    // ========================================================
      elseif ($action === 'get_entregadores') {
        $stmt = null;
        try {
            // Garante que é um lojista logado
            if (!isset($_SESSION['restaurante_id'])) {
                http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso de lojista negado."]); exit();
            }

            $restaurante_id_logado = $_SESSION['restaurante_id'];

            // Busca TODOS os usuários com o cargo 'entregador' deste restaurante
            $sql = "SELECT id, nome FROM usuarios WHERE restaurante_id = ? AND role = 'entregador' ORDER BY nome";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Erro prepare get_entregadores.");

            $stmt->bind_param("i", $restaurante_id_logado);
            $stmt->execute();
            $result = $stmt->get_result();
            $entregadores = [];
            while($row = $result->fetch_assoc()) {
                $entregadores[] = $row;
            }
            echo json_encode($entregadores); // Retorna a lista de entregadores

        } catch (mysqli_sql_exception | Exception $e) {
            error_log("USUARIO_API GET get_entregadores: Erro: ".$e->getMessage()); http_response_code(500); echo json_encode(['status' => 'error']);
        } finally {
            if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
        }
        exit();
    }
    // ========================================================
    // ### FIM DA NOVA FUNÇÃO ###
    // ========================================================

    else { http_response_code(400); echo json_encode(["status" => "error", "message" => "Ação GET inválida."]); exit(); }
}

// ===========================================
// MÉTODO POST - REGISTRAR USUÁRIO OU LOGIN
// ===========================================
elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- ROTA DE LOGIN ---
    if ($action === 'login') {
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $senha = $_POST['senha'] ?? '';
        $subdominio = $_POST['subdominio'] ?? null; 

        if (empty($email) || empty($senha)) {
            http_response_code(400); echo json_encode(["status" => "error", "message" => "E-mail e senha são obrigatórios."]); exit();
        }

        $stmt = null; // Para login de lojista
        $stmt_admin_check = null; // Para checagem de superadmin no login de lojista
        $stmt_super_admin = null; // Para login direto de superadmin

        try {
            // ==============================================
            // CASO 1: LOGIN DO LOJISTA (VEIO COM SUBDOMÍNIO)
            // ==============================================
            if (!empty($subdominio)) {
                $stmt_rest = $conn->prepare("SELECT id FROM restaurantes WHERE subdominio = ?");
                $stmt_rest->bind_param("s", $subdominio);
                $stmt_rest->execute();
                $restaurante_result = $stmt_rest->get_result();
                if ($restaurante_result->num_rows === 0) {
                    http_response_code(404); echo json_encode(["status" => "error", "message" => "Restaurante não encontrado."]); exit();
                }
                $restaurante_id = $restaurante_result->fetch_assoc()['id'];
                $stmt_rest->close();

                $stmt = $conn->prepare("SELECT id, nome, senha, role, restaurante_id FROM usuarios WHERE email = ? AND restaurante_id = ?");
                $stmt->bind_param("si", $email, $restaurante_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $login_lojista_ok = false;

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($senha, $user['senha'])) {
                        // SESSÃO DE LOJISTA NORMAL
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_nome'] = $user['nome'];
                        $_SESSION['restaurante_id'] = $user['restaurante_id']; 
                        $_SESSION['user_role'] = $user['role']; 
                        echo json_encode(["status" => "success", "data" => ["nome" => $user['nome']]]);
                        $login_lojista_ok = true; 
                    }
                }

                if ($login_lojista_ok) {
                   if ($stmt instanceof mysqli_stmt) $stmt->close();
                   exit(); 
                }

                // --- TENTATIVA DE LOGIN COMO SUPER ADMIN (SE LOGIN LOJISTA FALHOU) ---
                $stmt_admin_check = $conn->prepare("SELECT id, nome, senha, role FROM usuarios WHERE email = ? AND role = 'admin' AND restaurante_id IS NULL");
                $stmt_admin_check->bind_param("s", $email);
                $stmt_admin_check->execute();
                $result_admin = $stmt_admin_check->get_result();
                $login_admin_ok = false;

                if ($result_admin->num_rows === 1) {
                    $admin_user = $result_admin->fetch_assoc();
                    if (password_verify($senha, $admin_user['senha'])) {
                        // SESSÃO DE LOJISTA PARA O SUPER ADMIN
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $admin_user['id']; 
                        $_SESSION['user_nome'] = $admin_user['nome']; 
                        $_SESSION['restaurante_id'] = $restaurante_id; // ID DO RESTAURANTE DO SUBDOMÍNIO!
                        $_SESSION['user_role'] = 'admin'; 
                        echo json_encode(["status" => "success", "data" => ["nome" => $admin_user['nome'] . " (Admin)"]]); 
                        $login_admin_ok = true;
                    }
                }
                
                if ($login_admin_ok) {
                    if ($stmt instanceof mysqli_stmt) $stmt->close();
                    if ($stmt_admin_check instanceof mysqli_stmt) $stmt_admin_check->close();
                    exit(); 
                }

                // --- SE AMBAS AS TENTATIVAS FALHARAM ---
                http_response_code(401); 
                echo json_encode(["status" => "error", "message" => "Usuário não encontrado ou senha incorreta."]);
                exit(); 

            // ==============================================
            // CASO 2: LOGIN DO SUPER ADMIN (SEM SUBDOMÍNIO)
            // ==============================================
            } else { 
                $stmt_super_admin = $conn->prepare("SELECT id, nome, senha, role FROM usuarios WHERE email = ? AND role = 'admin' AND restaurante_id IS NULL");
                $stmt_super_admin->bind_param("s", $email);
                $stmt_super_admin->execute();
                $result = $stmt_super_admin->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($senha, $user['senha'])) {
                        // SESSÃO DE SUPER ADMIN
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_nome'] = $user['nome'];
                        $_SESSION['user_role'] = $user['role']; 
                        unset($_SESSION['restaurante_id']); 
                        echo json_encode(["status" => "success", "data" => ["nome" => $user['nome']]]);
                    } else {
                        http_response_code(401); echo json_encode(["status" => "error", "message" => "Senha incorreta."]);
                    }
                } else {
                    http_response_code(404); echo json_encode(["status" => "error", "message" => "Conta de administrador não encontrada."]);
                }
            } 

        } catch (mysqli_sql_exception | Exception $e) { 
            http_response_code(500);
            error_log("USUARIO_API LOGIN Erro: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Erro interno do servidor."]);
        } finally {
            if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
            if (isset($stmt_admin_check) && $stmt_admin_check instanceof mysqli_stmt) $stmt_admin_check->close();
            if (isset($stmt_super_admin) && $stmt_super_admin instanceof mysqli_stmt) $stmt_super_admin->close();
        }
        exit(); 

    // --- ROTA DE REGISTRO DE USUÁRIO (LOJISTA OU ADMIN) ---
    } elseif ($action === 'registrar') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso negado."]); exit();
        }
        $nome = trim($_POST['nome'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $senha = $_POST['senha'] ?? '';
        $role = trim($_POST['role'] ?? '');
        if (empty($nome) || empty($email) || empty($senha) || empty($role)) {
            http_response_code(400); echo json_encode(["status" => "error", "message" => "Todos os campos são obrigatórios."]); exit();
        }
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $restaurante_id_para_registrar = null;
        if (!isSuperAdmin()) {
            $restaurante_id_para_registrar = $_SESSION['restaurante_id'];
            if ($role === 'admin') { 
                 http_response_code(403); echo json_encode(["status" => "error", "message" => "Apenas Super Admins podem criar outros admins."]); exit();
            }
        } else {
            $restaurante_id_para_registrar = filter_input(INPUT_POST, 'restaurante_id', FILTER_VALIDATE_INT);
            if ($role === 'admin' && !empty($restaurante_id_para_registrar)) {
                http_response_code(400); echo json_encode(["status" => "error", "message" => "Admin global não pode ser associado a um restaurante."]); exit();
            }
            if ($role !== 'admin' && empty($restaurante_id_para_registrar)) {
                http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do restaurante é obrigatório para este cargo."]); exit();
            }
            if ($role === 'admin') $restaurante_id_para_registrar = null;
        }
        $stmt_reg = null;
        try {
            $stmt_reg = $conn->prepare("INSERT INTO usuarios (nome, email, senha, role, restaurante_id) VALUES (?, ?, ?, ?, ?)");
            $stmt_reg->bind_param("ssssi", $nome, $email, $senha_hash, $role, $restaurante_id_para_registrar);
            $stmt_reg->execute();
            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "Usuário criado!", "id" => $stmt_reg->insert_id]);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                http_response_code(409); echo json_encode(['status' => 'error', 'message' => 'Este e-mail já está cadastrado.']);
            } else {
                http_response_code(500); error_log("USUARIO_API REGISTER Erro: " . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Erro ao registrar usuário.']);
            }
        } finally {
            if ($stmt_reg instanceof mysqli_stmt) $stmt_reg->close();
        }
        exit();
    } 
    else { http_response_code(400); echo json_encode(["status" => "error", "message" => "Ação POST inválida."]); exit(); }
}
// ===========================================
// MÉTODO PUT - ATUALIZAR USUÁRIO (AGORA MAIS INTELIGENTE)
// ===========================================
elseif ($method === 'PUT') {
    // Pega o ID do usuário que queremos editar (da URL)
    $id_para_editar = $_GET['id'] ?? '';
    if (empty($id_para_editar)) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do usuário a ser editado é obrigatório."]); exit();
    }
    
    // Pega os dados (nome, email, cargo) do corpo da requisição
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) { http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); }

    $nome = trim($data['nome'] ?? '');
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $role = trim($data['role'] ?? '');

    if (empty($nome) || $email === false || empty($role)) { 
        http_response_code(400); echo json_encode(["status" => "error", "message" => "Nome, e-mail válido e cargo são obrigatórios."]); exit();
    }
    
    // --- LÓGICA DE PERMISSÃO ATUALIZADA ---
    $pode_editar = false;
    
    // CASO 1: Super Admin pode editar qualquer um
    if (isSuperAdmin()) {
        $pode_editar = true;
    } 
    // CASO 2: Gerente pode editar, mas vamos checar se o alvo é dele
    elseif (isset($_SESSION['restaurante_id']) && in_array($_SESSION['user_role'], ['admin', 'gerente'])) {
        $restaurante_id_gerente = $_SESSION['restaurante_id'];
        
        // Verifica se o usuário-alvo pertence ao mesmo restaurante do gerente
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND restaurante_id = ?");
        $stmt_check->bind_param("ii", $id_para_editar, $restaurante_id_gerente);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows === 1) {
            $pode_editar = true;
        }
        $stmt_check->close();
    }
    
    if (!$pode_editar) {
        http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso negado. Você não pode editar este usuário."]); exit();
    }

    // --- FIM DA LÓGICA DE PERMISSÃO ---

    $stmt_update = null;
    try {
        // Se chegou aqui, a permissão foi concedida.
        
        // Não permite que um gerente promova outro usuário a 'admin' global
        if ($role === 'admin' && !isSuperAdmin()) {
            http_response_code(403); throw new Exception("Apenas o Super Admin pode definir o cargo 'admin'.");
        }
        
        $stmt_update = $conn->prepare("UPDATE usuarios SET nome = ?, email = ?, role = ? WHERE id = ?");
        $stmt_update->bind_param("sssi", $nome, $email, $role, $id_para_editar);
        $stmt_update->execute();
        
        if ($stmt_update->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Usuário atualizado!"]);
        } else {
            echo json_encode(["status" => "success", "message" => "Nenhuma alteração detectada."]); // Não é um erro se nada mudou
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) { // Erro de email duplicado
            http_response_code(409); echo json_encode(['status' => 'error', 'message' => 'Este e-mail já está em uso por outro usuário.']);
        } else {
            error_log("USUARIO_API PUT: Erro SQL: ".$e->getMessage()); http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar no banco.']);
        }
    } catch (Exception $e) {
        error_log("USUARIO_API PUT: Erro Geral: ".$e->getMessage());
        if (http_response_code() === 200) { http_response_code(400); } // Garante que não retorne 200 OK
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } finally {
        if (isset($stmt_update) && $stmt_update instanceof mysqli_stmt) $stmt_update->close();
    }
    exit(); 
}
// ===========================================
// MÉTODO DELETE - DELETAR USUÁRIO (SÓ SUPER ADMIN)
// (Nenhuma alteração necessária nesta seção)
// ===========================================
elseif ($method === 'DELETE') {
    if (!isSuperAdmin()) { http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso negado."]); exit(); }
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do usuário é obrigatório."]); exit();
    }
    $stmt = null;
    try {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "Usuário deletado!"]);
    } catch (mysqli_sql_exception $e) {
        error_log("USUARIO_API DELETE: Erro SQL: ".$e->getMessage()); http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao deletar.']);
    } finally {
        if ($stmt instanceof mysqli_stmt) $stmt->close();
    }
    exit();
}
// ===========================================
// MÉTODO NÃO PERMITIDO
// ===========================================
else {
    http_response_code(405); echo json_encode(["status" => "error", "message" => "Método não permitido."]); exit();
}

if ($conn instanceof mysqli) { $conn->close(); }
?>
