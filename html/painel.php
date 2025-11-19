<?php
// Inicia a sessão. Esta linha é OBRIGATÓRIA em qualquer página que precise de login.
session_start();

// Verifica se a variável de sessão 'user_id' NÃO existe.
// Se não existir, significa que o usuário não está logado.
if (!isset($_SESSION['user_id'])) {
    // Redireciona o usuário para a página de login e encerra o script.
    header("Location: login.html");
    exit();
}

// Se o script chegou até aqui, significa que o usuário ESTÁ logado.
// Podemos usar as informações que guardamos na sessão.
$user_nome = $_SESSION['user_nome'];
$restaurante_id = $_SESSION['restaurante_id'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Painel Administrativo</title>
    <style>
        body { font-family: sans-serif; background-color: #f0f2f5; text-align: center; padding-top: 5rem; }
        .panel { background-color: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); display: inline-block; }
        h1 { color: #333; }
        p { color: #555; }
        code { background-color: #e9ecef; padding: 0.2rem 0.4rem; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="panel">
        <h1>Bem-vindo ao seu Painel, <?php echo htmlspecialchars($user_nome); ?>!</h1>
        <p>Você está gerenciando o restaurante com ID: <code><?php echo $restaurante_id; ?></code>.</p>
        <p>A partir daqui, você poderá ver seus pedidos, gerenciar seu cardápio, etc.</p>
        <br>
        <a href="logout.php">Sair (Logout)</a>
    </div>
</body>
</html>
