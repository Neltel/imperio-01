<?php
/**
 * =====================================================================
 * GERENCIAMENTO DE CLIENTES - COM VALIDAÇÃO EM TEMPO REAL
 * =====================================================================
 */

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

session_start();

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario = Auth::obter_usuario();
global $conexao;

$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$clientes = [];
$total_paginas = 0;
$pagina = 1;

// ===== FUNÇÕES AUXILIARES =====
function formatar_cpf_cnpj($valor) {
    $valor = preg_replace('/\D/', '', $valor);
    
    if (strlen($valor) == 11) {
        // CPF
        return substr($valor, 0, 3) . '.' . substr($valor, 3, 3) . '.' . substr($valor, 6, 3) . '-' . substr($valor, 9);
    } elseif (strlen($valor) == 14) {
        // CNPJ
        return substr($valor, 0, 2) . '.' . substr($valor, 2, 3) . '.' . substr($valor, 5, 3) . '/' . substr($valor, 8, 4) . '-' . substr($valor, 12);
    }
    
    return $valor;
}

function formatar_telefone($valor) {
    $valor = preg_replace('/\D/', '', $valor);
    
    if (strlen($valor) == 10) {
        return '(' . substr($valor, 0, 2) . ') ' . substr($valor, 2, 4) . '-' . substr($valor, 6);
    } elseif (strlen($valor) == 11) {
        return '(' . substr($valor, 0, 2) . ') ' . substr($valor, 2, 5) . '-' . substr($valor, 7);
    }
    
    return $valor;
}

// ===== PROCESSAR AÇÕES POST =====

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'salvar') {
    // Salva novo cliente ou atualiza
    $nome = isset($_POST['nome']) ? $conexao->real_escape_string(trim($_POST['nome'])) : '';
    $pessoa_tipo = isset($_POST['pessoa_tipo']) ? $_POST['pessoa_tipo'] : 'fisica';
    $cpf_cnpj = isset($_POST['cpf_cnpj']) ? preg_replace('/\D/', '', $_POST['cpf_cnpj']) : '';
    $telefone = isset($_POST['telefone']) ? preg_replace('/\D/', '', $_POST['telefone']) : '';
    $whatsapp = isset($_POST['whatsapp']) ? preg_replace('/\D/', '', $_POST['whatsapp']) : '';
    $email = isset($_POST['email']) ? $conexao->real_escape_string($_POST['email']) : '';
    $endereco_rua = isset($_POST['endereco_rua']) ? $conexao->real_escape_string(trim($_POST['endereco_rua'])) : '';
    $endereco_numero = isset($_POST['endereco_numero']) ? $conexao->real_escape_string($_POST['endereco_numero']) : '';
    $endereco_bairro = isset($_POST['endereco_bairro']) ? $conexao->real_escape_string($_POST['endereco_bairro']) : '';
    $endereco_cidade = isset($_POST['endereco_cidade']) ? $conexao->real_escape_string(trim($_POST['endereco_cidade'])) : '';
    $endereco_estado = isset($_POST['endereco_estado']) ? $_POST['endereco_estado'] : 'SP';
    
    $id_editar = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
    
    // Validação
    if (empty($nome)) {
        $erro = "Nome é obrigatório";
    } elseif (empty($cpf_cnpj)) {
        $erro = "CPF/CNPJ é obrigatório";
    } elseif (empty($endereco_rua)) {
        $erro = "Rua é obrigatória";
    } elseif (empty($endereco_numero)) {
        $erro = "Número é obrigatório";
    } elseif (empty($endereco_cidade)) {
        $erro = "Cidade é obrigatória";
    } else {
        if ($id_editar) {
            // Verificar se o CPF/CNPJ já existe em OUTRO cliente
            $sql_check = "SELECT id FROM clientes WHERE cpf_cnpj = ? AND id != ?";
            $stmt_check = $conexao->prepare($sql_check);
            $stmt_check->bind_param("si", $cpf_cnpj, $id_editar);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                $erro = "❌ Este CPF/CNPJ já está cadastrado para outro cliente!";
                $stmt_check->close();
            } else {
                $stmt_check->close();
                
                // Atualiza cliente existente
                $sql = "UPDATE clientes SET 
                        nome = ?,
                        pessoa_tipo = ?,
                        cpf_cnpj = ?,
                        telefone = ?,
                        whatsapp = ?,
                        email = ?,
                        endereco_rua = ?,
                        endereco_numero = ?,
                        endereco_bairro = ?,
                        endereco_cidade = ?,
                        endereco_estado = ?
                        WHERE id = ?";
                
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param(
                        "sssssssssssi",
                        $nome,
                        $pessoa_tipo,
                        $cpf_cnpj,
                        $telefone,
                        $whatsapp,
                        $email,
                        $endereco_rua,
                        $endereco_numero,
                        $endereco_bairro,
                        $endereco_cidade,
                        $endereco_estado,
                        $id_editar
                    );
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=atualizado');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $erro = "Erro na preparação da query: " . $conexao->error;
                }
            }
        } else {
            // Verificar se o CPF/CNPJ já existe
            $sql_check = "SELECT id FROM clientes WHERE cpf_cnpj = ?";
            $stmt_check = $conexao->prepare($sql_check);
            $stmt_check->bind_param("s", $cpf_cnpj);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                $erro = "❌ Este CPF/CNPJ já está cadastrado no sistema!";
                $stmt_check->close();
            } else {
                $stmt_check->close();
                
                // Insere novo cliente
                $sql = "INSERT INTO clientes (
                        nome, pessoa_tipo, cpf_cnpj, telefone, whatsapp, email,
                        endereco_rua, endereco_numero, endereco_bairro, endereco_cidade, endereco_estado, ativo
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param(
                        "sssssssssss",
                        $nome,
                        $pessoa_tipo,
                        $cpf_cnpj,
                        $telefone,
                        $whatsapp,
                        $email,
                        $endereco_rua,
                        $endereco_numero,
                        $endereco_bairro,
                        $endereco_cidade,
                        $endereco_estado
                    );
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=criado');
                        exit;
                    } else {
                        if ($stmt->errno == 1062) {
                            $erro = "❌ Este CPF/CNPJ já está cadastrado no sistema!";
                        } else {
                            $erro = "Erro ao criar: " . $stmt->error;
                        }
                    }
                    $stmt->close();
                } else {
                    $erro = "Erro na preparação da query: " . $conexao->error;
                }
            }
        }
    }
    
    // Se houve erro, mantém na tela de formulário
    if (!empty($erro)) {
        $acao = $id_editar ? 'editar' : 'novo';
        $cliente = [
            'id' => $id_editar,
            'nome' => $nome,
            'pessoa_tipo' => $pessoa_tipo,
            'cpf_cnpj' => $cpf_cnpj,
            'telefone' => $telefone,
            'whatsapp' => $whatsapp,
            'email' => $email,
            'endereco_rua' => $endereco_rua,
            'endereco_numero' => $endereco_numero,
            'endereco_bairro' => $endereco_bairro,
            'endereco_cidade' => $endereco_cidade,
            'endereco_estado' => $endereco_estado,
            'ativo' => 1
        ];
    }
}

// ===== PROCESSAR DELETAR =====

if ($acao === 'deletar' && $id) {
    $sql = "DELETE FROM clientes WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=deletado');
            exit;
        } else {
            $erro = "Erro ao deletar: " . $stmt->error;
        }
        $stmt->close();
    }
}

// ===== LISTAR CLIENTES =====
if ($acao === 'listar' || $acao === 'deletar') {
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    $limite = 50;
    $offset = ($pagina - 1) * $limite;
    
    $sql = "SELECT * FROM clientes ORDER BY nome ASC LIMIT {$limite} OFFSET {$offset}";
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $clientes[] = $linha;
        }
    } else {
        $erro = "Erro ao buscar clientes: " . $conexao->error;
    }
    
    $sql_total = "SELECT COUNT(*) as total FROM clientes";
    $resultado_total = $conexao->query($sql_total);
    
    if ($resultado_total) {
        $total = $resultado_total->fetch_assoc()['total'];
        $total_paginas = ceil($total / $limite);
    }
    
    // Verifica se teve mensagem de sucesso
    if (isset($_GET['mensagem'])) {
        if ($_GET['mensagem'] === 'criado') {
            $mensagem = "✓ Cliente criado com sucesso!";
        } elseif ($_GET['mensagem'] === 'atualizado') {
            $mensagem = "✓ Cliente atualizado com sucesso!";
        } elseif ($_GET['mensagem'] === 'deletado') {
            $mensagem = "✓ Cliente deletado com sucesso!";
        }
    }
    
} elseif ($acao === 'novo') {
    // Mostra formulário de novo cliente
    $cliente = [
        'id' => '', 
        'nome' => '', 
        'pessoa_tipo' => 'fisica', 
        'cpf_cnpj' => '', 
        'telefone' => '', 
        'whatsapp' => '', 
        'email' => '', 
        'endereco_rua' => '', 
        'endereco_numero' => '', 
        'endereco_bairro' => '', 
        'endereco_cidade' => '', 
        'endereco_estado' => 'SP',
        'ativo' => 1
    ];
    
} elseif ($acao === 'editar' && $id) {
    // Carrega cliente para editar
    $sql = "SELECT * FROM clientes WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $cliente = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$cliente) {
            $erro = "Cliente não encontrado";
            $acao = 'listar';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <style>
        .admin-container {
            display: flex;
            min-height: 100vh;
            background: #f5f6fa;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 20px;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            z-index: 50;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }
        
        .sidebar-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .sidebar-header h2 {
            color: white;
            margin-bottom: 8px;
            font-size: 20px;
        }
        
        .sidebar-header p {
            color: rgba(255,255,255,0.8);
            font-size: 12px;
            margin: 0;
        }
        
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .nav-item {
            padding: 12px 16px;
            border-radius: 6px;
            color: rgba(255,255,255,0.8);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .nav-item:hover,
        .nav-item.active {
            background: rgba(255,255,255,0.2);
            color: white;
            padding-left: 24px;
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 20px;
            width: calc(100% - 40px);
        }
        
        .btn-logout {
            width: 100%;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        
        .btn-logout:hover {
            background: #dc3545;
            border-color: #dc3545;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            overflow-y: auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
        }
        
        .page-header h1 {
            margin: 0;
        }
        
        .btn-novo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-novo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .form-group {
            margin-bottom: 15px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: Arial, sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        .validation-message {
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }
        
        .validation-error {
            color: #dc3545;
        }
        
        .validation-success {
            color: #28a745;
        }
        
        input.validation-error-field {
            border-color: #dc3545 !important;
            background-color: #fff8f8 !important;
        }
        
        input.validation-success-field {
            border-color: #28a745 !important;
            background-color: #f0fff0 !important;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .btn-salvar {
            background: #28a745;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-salvar:hover:not(:disabled) {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-salvar:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-cancelar {
            background: #6c757d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-cancelar:hover {
            background: #5a6268;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .table th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-weight: bold;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .table tr:hover {
            background: #f9f9f9;
        }
        
        .btn-editar {
            background: #007bff;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            margin-right: 5px;
        }
        
        .btn-editar:hover {
            background: #0056b3;
        }
        
        .btn-deletar {
            background: #dc3545;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-deletar:hover {
            background: #c82333;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-card {
            background: white;
            padding: 20px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .empty-message {
            text-align: center;
            padding: 40px;
            color: #666;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 5px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- INCLUIR SIDEBAR -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            
            <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensagem); ?></div>
            <?php endif; ?>

            <?php if ($erro): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($acao === 'listar'): ?>
                
                <div class="page-header">
                    <h1>👥 Gerenciamento de Clientes</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=novo" class="btn-novo">
                        ➕ Novo Cliente
                    </a>
                </div>

                <!-- LISTAGEM DE CLIENTES -->
                <?php if (count($clientes) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>CPF/CNPJ</th>
                            <th>Telefone</th>
                            <th>Email</th>
                            <th>Cidade/Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cli): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cli['nome']); ?></td>
                            <td><?php echo formatar_cpf_cnpj($cli['cpf_cnpj']); ?></td>
                            <td><?php echo formatar_telefone($cli['telefone']); ?></td>
                            <td><?php echo htmlspecialchars($cli['email']); ?></td>
                            <td><?php echo htmlspecialchars($cli['endereco_cidade'] . '/' . $cli['endereco_estado']); ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=editar&id=<?php echo $cli['id']; ?>" class="btn-editar">✏️ Editar</a>
                                <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=deletar&id=<?php echo $cli['id']; ?>" class="btn-deletar" onclick="return confirm('Tem certeza que deseja deletar este cliente?')">🗑️ Deletar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- PAGINAÇÃO -->
                <?php if ($total_paginas > 1): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                    <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?pagina=<?php echo $p; ?>" 
                       style="padding: 8px 12px; margin: 0 5px; background: <?php echo $p == $pagina ? '#667eea' : '#ddd'; ?>; color: <?php echo $p == $pagina ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 3px; display: inline-block;">
                        <?php echo $p; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="empty-message">
                    <p>Nenhum cliente cadastrado.</p>
                    <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=novo" class="btn-novo" style="margin-top: 15px;">
                        ➕ Criar Novo Cliente
                    </a>
                </div>
                <?php endif; ?>

            <?php else: ?>

                <!-- FORMULÁRIO NOVO/EDITAR -->
                <div class="form-card">
                    <h2><?php echo $acao === 'novo' ? '➕ Novo Cliente' : '✏️ Editar Cliente'; ?></h2>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=salvar" id="clienteForm">
                        
                        <?php if ($acao === 'editar'): ?>
                        <input type="hidden" name="id" value="<?php echo isset($cliente['id']) ? $cliente['id'] : ''; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome *</label>
                                <input type="text" name="nome" id="nome" value="<?php echo isset($cliente['nome']) ? htmlspecialchars($cliente['nome']) : ''; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Tipo de Pessoa *</label>
                                <select name="pessoa_tipo" id="pessoa_tipo" required onchange="atualizarMascara()">
                                    <option value="fisica" <?php echo isset($cliente['pessoa_tipo']) && $cliente['pessoa_tipo'] === 'fisica' ? 'selected' : ''; ?>>Pessoa Física</option>
                                    <option value="juridica" <?php echo isset($cliente['pessoa_tipo']) && $cliente['pessoa_tipo'] === 'juridica' ? 'selected' : ''; ?>>Pessoa Jurídica</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>CPF/CNPJ *</label>
                                <input type="text" id="cpf_cnpj" name="cpf_cnpj" 
                                       value="<?php echo isset($cliente['cpf_cnpj']) ? htmlspecialchars($cliente['cpf_cnpj']) : ''; ?>" 
                                       placeholder="000.000.000-00" required maxlength="18">
                                <span id="cpf_cnpj_status" class="validation-message"></span>
                            </div>
                            
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo isset($cliente['email']) ? htmlspecialchars($cliente['email']) : ''; ?>">
                                <span id="email_status" class="validation-message"></span>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Telefone</label>
                                <input type="text" id="telefone" name="telefone" 
                                       value="<?php echo isset($cliente['telefone']) ? htmlspecialchars($cliente['telefone']) : ''; ?>" 
                                       placeholder="(11) 3333-4444" maxlength="15">
                                <span id="telefone_status" class="validation-message"></span>
                            </div>
                            
                            <div class="form-group">
                                <label>WhatsApp</label>
                                <input type="text" id="whatsapp" name="whatsapp" 
                                       value="<?php echo isset($cliente['whatsapp']) ? htmlspecialchars($cliente['whatsapp']) : ''; ?>" 
                                       placeholder="(11) 99999-8888" maxlength="16">
                                <span id="whatsapp_status" class="validation-message"></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Rua *</label>
                            <input type="text" name="endereco_rua" value="<?php echo isset($cliente['endereco_rua']) ? htmlspecialchars($cliente['endereco_rua']) : ''; ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Número *</label>
                                <input type="text" name="endereco_numero" value="<?php echo isset($cliente['endereco_numero']) ? htmlspecialchars($cliente['endereco_numero']) : ''; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Bairro</label>
                                <input type="text" name="endereco_bairro" value="<?php echo isset($cliente['endereco_bairro']) ? htmlspecialchars($cliente['endereco_bairro']) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Cidade *</label>
                                <input type="text" name="endereco_cidade" value="<?php echo isset($cliente['endereco_cidade']) ? htmlspecialchars($cliente['endereco_cidade']) : ''; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Estado *</label>
                                <select name="endereco_estado" required>
                                    <option value="SP" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'SP' ? 'selected' : ''; ?>>São Paulo (SP)</option>
                                    <option value="RJ" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'RJ' ? 'selected' : ''; ?>>Rio de Janeiro (RJ)</option>
                                    <option value="MG" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'MG' ? 'selected' : ''; ?>>Minas Gerais (MG)</option>
                                    <option value="RS" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'RS' ? 'selected' : ''; ?>>Rio Grande do Sul (RS)</option>
                                    <option value="BA" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'BA' ? 'selected' : ''; ?>>Bahia (BA)</option>
                                    <option value="SC" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'SC' ? 'selected' : ''; ?>>Santa Catarina (SC)</option>
                                    <option value="PR" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'PR' ? 'selected' : ''; ?>>Paraná (PR)</option>
                                    <option value="PE" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'PE' ? 'selected' : ''; ?>>Pernambuco (PE)</option>
                                    <option value="CE" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'CE' ? 'selected' : ''; ?>>Ceará (CE)</option>
                                    <option value="PA" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'PA' ? 'selected' : ''; ?>>Pará (PA)</option>
                                    <option value="GO" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'GO' ? 'selected' : ''; ?>>Goiás (GO)</option>
                                    <option value="PB" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'PB' ? 'selected' : ''; ?>>Paraíba (PB)</option>
                                    <option value="MA" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'MA' ? 'selected' : ''; ?>>Maranhão (MA)</option>
                                    <option value="ES" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'ES' ? 'selected' : ''; ?>>Espírito Santo (ES)</option>
                                    <option value="PI" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'PI' ? 'selected' : ''; ?>>Piauí (PI)</option>
                                    <option value="RN" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'RN' ? 'selected' : ''; ?>>Rio Grande do Norte (RN)</option>
                                    <option value="AL" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'AL' ? 'selected' : ''; ?>>Alagoas (AL)</option>
                                    <option value="MT" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'MT' ? 'selected' : ''; ?>>Mato Grosso (MT)</option>
                                    <option value="MS" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'MS' ? 'selected' : ''; ?>>Mato Grosso do Sul (MS)</option>
                                    <option value="DF" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'DF' ? 'selected' : ''; ?>>Distrito Federal (DF)</option>
                                    <option value="TO" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'TO' ? 'selected' : ''; ?>>Tocantins (TO)</option>
                                    <option value="AC" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'AC' ? 'selected' : ''; ?>>Acre (AC)</option>
                                    <option value="AM" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'AM' ? 'selected' : ''; ?>>Amazonas (AM)</option>
                                    <option value="AP" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'AP' ? 'selected' : ''; ?>>Amapá (AP)</option>
                                    <option value="RR" <?php echo isset($cliente['endereco_estado']) && $cliente['endereco_estado'] === 'RR' ? 'selected' : ''; ?>>Roraima (RR)</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn-salvar" id="btnSalvar">✓ Salvar</button>
                            <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php" class="btn-cancelar">✕ Cancelar</a>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <script>
        // Variável para armazenar o ID do cliente (quando estiver editando)
        const clienteId = <?php echo isset($cliente['id']) ? $cliente['id'] : 0; ?>;
        
        /**
         * Função para verificar se campo já existe no banco
         */
        function verificarDuplicidade(campo, valor) {
            if (!valor || valor.length < 3) {
                // Limpar mensagem se campo estiver vazio
                const msgDiv = document.getElementById(campo + '_status');
                const inputField = document.getElementById(campo);
                if (msgDiv) {
                    msgDiv.innerHTML = '';
                    msgDiv.className = 'validation-message';
                }
                if (inputField) {
                    inputField.style.borderColor = '#ddd';
                    inputField.style.backgroundColor = '';
                    inputField.classList.remove('validation-error-field', 'validation-success-field');
                }
                return;
            }
            
            // Limpar números para CPF/CNPJ, Telefone, WhatsApp
            let valorLimpo = valor;
            if (campo === 'cpf_cnpj' || campo === 'telefone' || campo === 'whatsapp') {
                valorLimpo = valor.replace(/\D/g, '');
                if (valorLimpo.length === 0) return;
            }
            
            // Mostrar loading
            const msgDiv = document.getElementById(campo + '_status');
            if (msgDiv) {
                msgDiv.innerHTML = 'Verificando... <span class="loading"></span>';
                msgDiv.className = 'validation-message';
            }
            
            const url = `<?php echo BASE_URL; ?>/app/admin/verificar_cliente.php?campo=${campo}&valor=${encodeURIComponent(valorLimpo)}&id=${clienteId}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const inputField = document.getElementById(campo);
                    
                    if (data.exists) {
                        // Campo duplicado
                        if (msgDiv) {
                            msgDiv.innerHTML = data.message;
                            msgDiv.className = 'validation-message validation-error';
                        }
                        if (inputField) {
                            inputField.style.borderColor = '#dc3545';
                            inputField.style.backgroundColor = '#fff8f8';
                            inputField.classList.add('validation-error-field');
                            inputField.classList.remove('validation-success-field');
                        }
                        
                        // Desabilitar botão de salvar
                        const btnSalvar = document.getElementById('btnSalvar');
                        if (btnSalvar) btnSalvar.disabled = true;
                    } else {
                        // Campo OK
                        if (msgDiv) {
                            msgDiv.innerHTML = '✓ Disponível';
                            msgDiv.className = 'validation-message validation-success';
                        }
                        if (inputField) {
                            inputField.style.borderColor = '#28a745';
                            inputField.style.backgroundColor = '#f0fff0';
                            inputField.classList.add('validation-success-field');
                            inputField.classList.remove('validation-error-field');
                        }
                        
                        // Verificar se todos os campos obrigatórios estão OK
                        verificarTodosCampos();
                    }
                })
                .catch(error => {
                    console.error('Erro na verificação:', error);
                    if (msgDiv) {
                        msgDiv.innerHTML = 'Erro ao verificar';
                        msgDiv.className = 'validation-message validation-error';
                    }
                });
        }
        
        /**
         * Verificar se todos os campos obrigatórios estão válidos
         */
        function verificarTodosCampos() {
            const camposObrigatorios = ['cpf_cnpj'];
            const camposOpcionais = ['email', 'telefone', 'whatsapp'];
            let todosOk = true;
            
            // Verificar campos obrigatórios
            camposObrigatorios.forEach(campo => {
                const input = document.getElementById(campo);
                const status = document.getElementById(campo + '_status');
                
                if (input && input.value.trim() !== '') {
                    if (status && status.innerHTML.includes('⚠️')) {
                        todosOk = false;
                    }
                } else if (input && input.value.trim() === '') {
                    todosOk = false;
                }
            });
            
            // Verificar campos opcionais (apenas se tiverem valor)
            camposOpcionais.forEach(campo => {
                const input = document.getElementById(campo);
                const status = document.getElementById(campo + '_status');
                
                if (input && input.value.trim() !== '') {
                    if (status && status.innerHTML.includes('⚠️')) {
                        todosOk = false;
                    }
                }
            });
            
            const btnSalvar = document.getElementById('btnSalvar');
            if (btnSalvar) {
                btnSalvar.disabled = !todosOk;
            }
        }
        
        /**
         * Formata CPF/CNPJ enquanto digita e verifica
         */
        const cpfInput = document.getElementById('cpf_cnpj');
        if (cpfInput) {
            cpfInput.addEventListener('input', function() {
                let valor = this.value.replace(/\D/g, '');
                
                const tipoPessoa = document.querySelector('select[name="pessoa_tipo"]').value;
                
                if (tipoPessoa === 'fisica') {
                    // CPF: 000.000.000-00
                    if (valor.length > 11) valor = valor.slice(0, 11);
                    if (valor.length > 9) {
                        valor = valor.slice(0, 3) + '.' + valor.slice(3, 6) + '.' + valor.slice(6, 9) + '-' + valor.slice(9);
                    } else if (valor.length > 6) {
                        valor = valor.slice(0, 3) + '.' + valor.slice(3, 6) + '.' + valor.slice(6);
                    } else if (valor.length > 3) {
                        valor = valor.slice(0, 3) + '.' + valor.slice(3);
                    }
                } else {
                    // CNPJ: 00.000.000/0000-00
                    if (valor.length > 14) valor = valor.slice(0, 14);
                    if (valor.length > 12) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2, 5) + '.' + valor.slice(5, 8) + '/' + valor.slice(8, 12) + '-' + valor.slice(12);
                    } else if (valor.length > 8) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2, 5) + '.' + valor.slice(5, 8) + '/' + valor.slice(8);
                    } else if (valor.length > 5) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2, 5) + '.' + valor.slice(5);
                    } else if (valor.length > 2) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2);
                    }
                }
                
                this.value = valor;
                
                // Verificar duplicidade ao digitar (após 1 segundo sem digitar)
                clearTimeout(this.timeout);
                this.timeout = setTimeout(() => {
                    const valorNumerico = this.value.replace(/\D/g, '');
                    const tamanhoEsperado = tipoPessoa === 'fisica' ? 11 : 14;
                    if (valorNumerico.length === tamanhoEsperado) {
                        verificarDuplicidade('cpf_cnpj', this.value);
                    }
                }, 1000);
            });
            
            // Verificar ao perder o foco
            cpfInput.addEventListener('blur', function() {
                const tipoPessoa = document.querySelector('select[name="pessoa_tipo"]').value;
                const valorNumerico = this.value.replace(/\D/g, '');
                const tamanhoEsperado = tipoPessoa === 'fisica' ? 11 : 14;
                if (valorNumerico.length === tamanhoEsperado) {
                    verificarDuplicidade('cpf_cnpj', this.value);
                }
            });
        }
        
        /**
         * Verificação para Email
         */
        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.addEventListener('input', function() {
                clearTimeout(this.timeout);
                this.timeout = setTimeout(() => {
                    if (this.value.length > 5 && this.value.includes('@') && this.value.includes('.')) {
                        verificarDuplicidade('email', this.value);
                    }
                }, 1000);
            });
            
            emailInput.addEventListener('blur', function() {
                if (this.value.length > 5 && this.value.includes('@') && this.value.includes('.')) {
                    verificarDuplicidade('email', this.value);
                }
            });
        }
        
        /**
         * Formata Telefone enquanto digita e verifica
         */
        const telefoneInput = document.getElementById('telefone');
        if (telefoneInput) {
            telefoneInput.addEventListener('input', function() {
                let valor = this.value.replace(/\D/g, '');
                
                if (valor.length > 11) valor = valor.slice(0, 11);
                if (valor.length > 7) {
                    valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2, 7) + '-' + valor.slice(7);
                } else if (valor.length > 2) {
                    valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2);
                } else if (valor.length > 0) {
                    valor = '(' + valor;
                }
                
                this.value = valor;
                
                clearTimeout(this.timeout);
                this.timeout = setTimeout(() => {
                    if (this.value.replace(/\D/g, '').length >= 10) {
                        verificarDuplicidade('telefone', this.value);
                    }
                }, 1000);
            });
            
            telefoneInput.addEventListener('blur', function() {
                if (this.value.replace(/\D/g, '').length >= 10) {
                    verificarDuplicidade('telefone', this.value);
                }
            });
        }
        
        /**
         * Formata WhatsApp enquanto digita e verifica
         */
        const whatsappInput = document.getElementById('whatsapp');
        if (whatsappInput) {
            whatsappInput.addEventListener('input', function() {
                let valor = this.value.replace(/\D/g, '');
                
                if (valor.length > 11) valor = valor.slice(0, 11);
                if (valor.length > 7) {
                    valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2, 7) + '-' + valor.slice(7);
                } else if (valor.length > 2) {
                    valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2);
                } else if (valor.length > 0) {
                    valor = '(' + valor;
                }
                
                this.value = valor;
                
                clearTimeout(this.timeout);
                this.timeout = setTimeout(() => {
                    if (this.value.replace(/\D/g, '').length >= 10) {
                        verificarDuplicidade('whatsapp', this.value);
                    }
                }, 1000);
            });
            
            whatsappInput.addEventListener('blur', function() {
                if (this.value.replace(/\D/g, '').length >= 10) {
                    verificarDuplicidade('whatsapp', this.value);
                }
            });
        }
        
        /**
         * Atualiza máscara CPF/CNPJ quando muda tipo de pessoa
         */
        function atualizarMascara() {
            const campoCPF = document.getElementById('cpf_cnpj');
            const tipo = document.querySelector('select[name="pessoa_tipo"]').value;
            campoCPF.value = '';
            campoCPF.placeholder = tipo === 'fisica' ? '000.000.000-00' : '00.000.000/0000-00';
            campoCPF.style.borderColor = '#ddd';
            campoCPF.style.backgroundColor = '';
            campoCPF.classList.remove('validation-error-field', 'validation-success-field');
            
            const statusDiv = document.getElementById('cpf_cnpj_status');
            if (statusDiv) {
                statusDiv.innerHTML = '';
                statusDiv.className = 'validation-message';
            }
        }
        
        // Verificar campos existentes ao carregar a página (apenas para edição)
        document.addEventListener('DOMContentLoaded', function() {
            if (clienteId > 0) {
                // Se estiver editando, verificar os campos preenchidos
                if (cpfInput && cpfInput.value) {
                    setTimeout(() => verificarDuplicidade('cpf_cnpj', cpfInput.value), 500);
                }
                if (emailInput && emailInput.value) {
                    setTimeout(() => verificarDuplicidade('email', emailInput.value), 500);
                }
                if (telefoneInput && telefoneInput.value) {
                    setTimeout(() => verificarDuplicidade('telefone', telefoneInput.value), 500);
                }
                if (whatsappInput && whatsappInput.value) {
                    setTimeout(() => verificarDuplicidade('whatsapp', whatsappInput.value), 500);
                }
            }
        });
    </script>

    <script src="<?php echo BASE_URL; ?>/public/js/main.js"></script>
</body>
</html>