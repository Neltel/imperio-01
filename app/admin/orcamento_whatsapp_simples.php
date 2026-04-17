<?php
/**
 * Versão SIMPLIFICADA apenas para testar WhatsApp
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/WhatsApp.php';

session_start();

// Função de debug
function debug_msg($msg) {
    echo "<div style='background:#e8f4f8; border-left:4px solid #3498db; padding:10px; margin:5px;'>";
    echo "<strong>DEBUG:</strong> " . print_r($msg, true);
    echo "</div>";
}

debug_msg("Iniciando teste simplificado");

global $conexao;
$whatsapp = new WhatsApp($conexao);

$id = isset($_GET['id']) ? intval($_GET['id']) : 1;
$acao = $_GET['acao'] ?? '';

// Processar envio
if ($acao == 'enviar') {
    debug_msg("Processando envio para ID: " . $id);
    
    // Buscar dados
    $sql = "SELECT o.*, c.nome as cliente_nome, c.telefone as cliente_telefone 
            FROM orcamentos o 
            LEFT JOIN clientes c ON o.cliente_id = c.id 
            WHERE o.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $orcamento = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    debug_msg("Dados do orçamento: " . print_r($orcamento, true));
    
    if ($orcamento) {
        $telefone = $orcamento['cliente_telefone'] ?? '';
        debug_msg("Telefone encontrado: " . $telefone);
        
        if (!empty($telefone)) {
            $mensagem = "TESTE - Orçamento #" . $id . " - " . date('d/m/Y H:i:s');
            debug_msg("Mensagem: " . $mensagem);
            
            $link = $whatsapp->gerarLink($telefone, $mensagem);
            debug_msg("Link gerado: " . $link);
            
            echo "<script>window.open('{$link}', '_blank');</script>";
            echo "<div style='background:#d4edda; padding:20px; text-align:center;'>";
            echo "<h2>✅ Link do WhatsApp gerado!</h2>";
            echo "<p><a href='{$link}' target='_blank'>Clique aqui se não abrir automaticamente</a></p>";
            echo "</div>";
        } else {
            echo "<div style='background:#f8d7da; padding:20px;'>❌ Cliente sem telefone!</div>";
        }
    } else {
        echo "<div style='background:#f8d7da; padding:20px;'>❌ Orçamento não encontrado!</div>";
    }
}

// Listar orçamentos para teste
$sql = "SELECT o.id, o.numero, c.nome, c.telefone 
        FROM orcamentos o 
        LEFT JOIN clientes c ON o.cliente_id = c.id 
        ORDER BY o.id DESC LIMIT 10";
$result = $conexao->query($sql);
$orcamentos = [];
while ($row = $result->fetch_assoc()) {
    $orcamentos[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Teste WhatsApp Simplificado</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e3c72; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        .btn { background: #25D366; color: white; padding: 8px 15px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .btn:hover { background: #128C7E; }
        .warning { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>📱 Teste WhatsApp - Versão Simplificada</h1>
            
            <div class="warning">
                <strong>⚠️ Instruções:</strong>
                <ol>
                    <li>Escolha um orçamento da lista</li>
                    <li>Clique no botão "Enviar Teste"</li>
                    <li>Verifique se o WhatsApp abre com a mensagem</li>
                    <li>Se não abrir, copie o link que aparece</li>
                </ol>
            </div>
            
            <h3>Últimos 10 Orçamentos:</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Número</th>
                        <th>Cliente</th>
                        <th>Telefone</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orcamentos as $o): ?>
                    <tr>
                        <td><?php echo $o['id']; ?></td>
                        <td><?php echo $o['numero'] ?? '-'; ?></td>
                        <td><?php echo $o['nome']; ?></td>
                        <td>
                            <?php if ($o['telefone']): ?>
                                <span style="color: green;"><?php echo $o['telefone']; ?></span>
                            <?php else: ?>
                                <span style="color: red;">Sem telefone</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($o['telefone']): ?>
                                <a href="?acao=enviar&id=<?php echo $o['id']; ?>" class="btn" target="_blank">
                                    <i class="fab fa-whatsapp"></i> Enviar Teste
                                </a>
                            <?php else: ?>
                                <span style="color: #999;">Indisponível</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h3>Teste Manual</h3>
            <p>Digite um telefone e mensagem para testar:</p>
            <form method="GET" action="?">
                <input type="hidden" name="acao" value="enviar">
                <div style="margin-bottom: 10px;">
                    <label>Telefone:</label><br>
                    <input type="text" name="telefone" placeholder="(17) 99624-0725" style="width: 100%; padding: 8px;">
                </div>
                <div style="margin-bottom: 10px;">
                    <label>Mensagem:</label><br>
                    <textarea name="mensagem" rows="3" style="width: 100%; padding: 8px;">Teste manual - Império AR</textarea>
                </div>
                <button type="submit" class="btn" style="background: #1e3c72;">Testar</button>
            </form>
            
            <?php
            if (isset($_GET['acao']) && $_GET['acao'] == 'enviar' && isset($_GET['telefone'])) {
                $telefone = $_GET['telefone'];
                $mensagem = $_GET['mensagem'] ?? 'Teste manual';
                
                $link = $whatsapp->gerarLink($telefone, $mensagem);
                echo "<div style='margin-top:20px; padding:10px; background:#e8f4f8;'>";
                echo "<strong>Link gerado:</strong><br>";
                echo "<a href='{$link}' target='_blank'>{$link}</a>";
                echo "</div>";
            }
            ?>
        </div>
    </div>
</body>
</html>