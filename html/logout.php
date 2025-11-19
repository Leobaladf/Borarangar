<?php
// Inicia a sessão
session_start();

// Limpa todas as variáveis da sessão
$_SESSION = array();

// Destrói a sessão
session_destroy();

// Redireciona o usuário para a página de login
header("Location: /painel/"); // Aponta para o diretório do painel do lojista
exit();
?>
