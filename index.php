<?php
session_start();

// CONFIGURAÇÕES
define('TEMPLATE_DIR', __DIR__ . '/template');
define('OUTPUT_DIR', __DIR__ . '/gerados');
define('CACHE_DIR', __DIR__ . '/cache');
define('LOG_DIR', __DIR__ . '/logs');
define('HISTORICO_FILE', __DIR__ . '/historico.json');
define('API_TIMEOUT', 10);
define('CACHE_DURATION', 86400); // 24 horas

// Blocos para geração de domínio
define('CHUNKS_PRIMARY', [
    "pgmnto","pgmto","pgto","pgmnt","pgamnto","pgamnt","pgamt",
    "cnsulrtar","consl","cnsl","conslr",
    "divda","divd","dpgto","pgmntcpfnm",
    "seraza","serazapgto","serazalimp",
    "acorddiv","acorddivpgto","acorddivpgmt",
    "limpnoe","limpnm","limpnoeconsl","limponeoconsl",
    "nomeok","nomok","nomeokpgto","nomeokconsl","cnslrcpf",
]);

define('CHUNKS_SECONDARY', [
    "cppf","cpfnm","pg","nm","ok","cnsl","crd","dpg","pgm","nmok","pagar","consultas","financa","feirao"
]);

define('TLDS', ["cfd","cyou","sbs","netlify.app"]);

// Criar diretórios necessários
foreach ([OUTPUT_DIR, CACHE_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// Sistema de Logs
function registrarLog($tipo, $mensagem) {
    $log = date('Y-m-d H:i:s') . " - [{$tipo}] {$mensagem}\n";
    @file_put_contents(LOG_DIR . '/gerador_' . date('Y-m-d') . '.log', $log, FILE_APPEND);
}

// Gerar domínio aleatório
function gerarDominioAleatorio() {
    $primary = CHUNKS_PRIMARY[array_rand(CHUNKS_PRIMARY)];
    $secondary = CHUNKS_SECONDARY[array_rand(CHUNKS_SECONDARY)];
    
    // Gerar código de 4 caracteres (letras e números)
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    // 50% de chance de incluir secondary
    if (rand(0, 1) == 1) {
        $domain = $primary . $secondary . $code;
    } else {
        $domain = $primary . $code;
    }
    
    return 'https://' . $domain . '.com.br';
}

// Salvar no histórico
function salvarHistorico($dados) {
    $historico = [];
    
    // Ler histórico existente
    if (file_exists(HISTORICO_FILE)) {
        $json = file_get_contents(HISTORICO_FILE);
        $historico = json_decode($json, true) ?: [];
    }
    
    // Adicionar novo registro
    $registro = [
        'data' => date('Y-m-d H:i:s'),
        'cnpj' => $dados['cnpj'],
        'razao_social' => $dados['razao_social'],
        'nome_fantasia' => $dados['nome_fantasia'],
        'email' => $dados['email'],
        'site' => $dados['site'],
        'telefone' => $dados['telefone'],
        'cidade' => $dados['cidade'],
        'estado' => $dados['estado']
    ];
    
    array_unshift($historico, $registro);
    
    // Manter apenas últimos 100 registros
    $historico = array_slice($historico, 0, 100);
    
    // Salvar
    file_put_contents(HISTORICO_FILE, json_encode($historico, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    registrarLog('INFO', "Histórico salvo para: {$dados['razao_social']}");
}

// Validação completa de CNPJ
function validarCNPJ($cnpj) {
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    
    if (strlen($cnpj) != 14 || preg_match('/(\d)\1{13}/', $cnpj)) {
        return false;
    }
    
    // Validação dos dígitos verificadores
    for ($t = 12; $t < 14; $t++) {
        $d = 0;
        $c = 0;
        for ($m = $t - 7; $m >= 2; $m--, $c++) {
            $d += $cnpj[$c] * $m;
        }
        for ($m = 9; $m >= 2 && $c < $t; $m--, $c++) {
            $d += $cnpj[$c] * $m;
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cnpj[$t] != $d) {
            return false;
        }
    }
    return true;
}

// Funções de formatação
function formatarTelefone($telefone) {
    if (!$telefone || strlen($telefone) < 10) return "";
    
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    
    if (strlen($telefone) == 10) {
        return "({$telefone[0]}{$telefone[1]}) {$telefone[2]}{$telefone[3]}{$telefone[4]}{$telefone[5]}-{$telefone[6]}{$telefone[7]}{$telefone[8]}{$telefone[9]}";
    } elseif (strlen($telefone) == 11) {
        return "({$telefone[0]}{$telefone[1]}) {$telefone[2]}{$telefone[3]}{$telefone[4]}{$telefone[5]}{$telefone[6]}-{$telefone[7]}{$telefone[8]}{$telefone[9]}{$telefone[10]}";
    }
    return $telefone;
}

function formatarCEP($cep) {
    if (!$cep || strlen($cep) != 8) return $cep;
    
    $cep = preg_replace('/[^0-9]/', '', $cep);
    return substr($cep, 0, 2) . '.' . substr($cep, 2, 3) . '-' . substr($cep, 5);
}

function formatarCNPJ($cnpj) {
    if (!$cnpj || strlen($cnpj) != 14) return $cnpj;
    
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    return substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . 
           substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12);
}

// Consulta CNPJ com Cache
function consultarCNPJComCache($cnpj) {
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    $cacheFile = CACHE_DIR . "/{$cnpj}.json";
    
    // Verifica cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_DURATION) {
        registrarLog('INFO', "CNPJ {$cnpj} obtido do cache");
        return json_decode(file_get_contents($cacheFile), true);
    }
    
    // Consulta API
    $dados = consultarCNPJ($cnpj);
    
    // Salva no cache se sucesso
    if (!isset($dados['erro'])) {
        file_put_contents($cacheFile, json_encode($dados));
        registrarLog('INFO', "CNPJ {$cnpj} consultado na API e salvo no cache");
    }
    
    return $dados;
}

function consultarCNPJ($cnpj) {
    if (!validarCNPJ($cnpj)) {
        return ['erro' => 'CNPJ inválido! Verificação de dígitos falhou.'];
    }
    
    $url = "https://minhareceita.org/{$cnpj}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        registrarLog('ERRO', "Erro ao consultar CNPJ {$cnpj}: {$error}");
        return ['erro' => 'Erro ao consultar API: ' . $error];
    }
    
    if ($httpCode == 404) {
        return ['erro' => 'CNPJ não encontrado na base de dados.'];
    }
    
    if ($httpCode != 200) {
        return ['erro' => 'Erro na API. Status: ' . $httpCode];
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        return ['erro' => 'Erro ao processar resposta da API.'];
    }
    
    return $data;
}

// Processar arquivos
function processarArquivos($replacements) {
    $arquivos = ['index.html', 'privacy.html', 'terms.html', 'cookie.html'];
    $resultados = [];
    
    foreach ($arquivos as $arquivo) {
        $templatePath = TEMPLATE_DIR . '/' . $arquivo;
        
        if (!file_exists($templatePath)) {
            $resultados[$arquivo] = ['status' => 'erro', 'msg' => 'Template não encontrado'];
            registrarLog('AVISO', "Template {$arquivo} não encontrado");
            continue;
        }
        
        $conteudo = file_get_contents($templatePath);
        
        foreach ($replacements as $chave => $valor) {
            $conteudo = str_replace($chave, $valor, $conteudo);
        }
        
        $outputPath = OUTPUT_DIR . '/' . $arquivo;
        
        if (file_put_contents($outputPath, $conteudo)) {
            $resultados[$arquivo] = ['status' => 'sucesso', 'msg' => 'Gerado com sucesso'];
            registrarLog('INFO', "Arquivo {$arquivo} gerado com sucesso");
        } else {
            $resultados[$arquivo] = ['status' => 'erro', 'msg' => 'Erro ao salvar'];
            registrarLog('ERRO', "Erro ao salvar {$arquivo}");
        }
    }
    
    return $resultados;
}

// Criar ZIP para download
function criarZip($arquivos, $nomeEmpresa) {
    // Verifica se ZipArchive está disponível
    if (!class_exists('ZipArchive')) {
        registrarLog('AVISO', "ZipArchive não disponível - ZIP não será criado");
        return false;
    }
    
    $zip = new ZipArchive();
    $nomeArquivo = 'site_' . preg_replace('/[^a-z0-9]/i', '_', $nomeEmpresa) . '_' . date('YmdHis') . '.zip';
    $caminhoZip = OUTPUT_DIR . '/' . $nomeArquivo;
    
    if ($zip->open($caminhoZip, ZipArchive::CREATE) === TRUE) {
        foreach ($arquivos as $arquivo => $resultado) {
            if ($resultado['status'] == 'sucesso') {
                $zip->addFile(OUTPUT_DIR . '/' . $arquivo, $arquivo);
            }
        }
        $zip->close();
        registrarLog('INFO', "ZIP criado: {$nomeArquivo}");
        return $nomeArquivo;
    }
    
    registrarLog('ERRO', "Erro ao criar ZIP");
    return false;
}

// Gerar token CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Processamento do formulário
$dados = null;
$resultados = null;
$erro = null;
$zipFile = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validação CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $erro = 'Token de segurança inválido. Recarregue a página.';
        registrarLog('SEGURANCA', 'Tentativa de ataque CSRF detectada');
    } else {
        $cnpj = trim($_POST['cnpj'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $site = trim($_POST['site'] ?? '');
        
        if (empty($cnpj)) {
            $erro = 'CNPJ é obrigatório!';
        } else {
            registrarLog('INFO', "Iniciando geração para CNPJ: {$cnpj}");
            $dadosAPI = consultarCNPJComCache($cnpj);
            
            if (isset($dadosAPI['erro'])) {
                $erro = $dadosAPI['erro'];
            } else {
                // Processa dados
                $cnpjLimpo = $dadosAPI['cnpj'] ?? '';
                $cnpjFormatado = formatarCNPJ($cnpjLimpo);
                $razaoSocial = $dadosAPI['razao_social'] ?? 'Empresa LTDA';
                $nomeFantasia = trim($dadosAPI['nome_fantasia'] ?? '');
                
                if (empty($nomeFantasia)) {
                    $partes = explode(' ', $razaoSocial, 2);
                    $nomeFantasia = count($partes) > 1 ? ucwords(strtolower($partes[1])) : 'Central';
                }
                
                $telefone = formatarTelefone($dadosAPI['ddd_telefone_1'] ?? '');
                $logradouro = $dadosAPI['logradouro'] ?? '';
                $numero = $dadosAPI['numero'] ?? '';
                $complemento = $dadosAPI['complemento'] ?? '';
                $bairro = $dadosAPI['bairro'] ?? '';
                $cidade = $dadosAPI['municipio'] ?? '';
                $estado = $dadosAPI['uf'] ?? '';
                $cep = formatarCEP($dadosAPI['cep'] ?? '');
                
                // Endereço completo
                $enderecoCompleto = "{$logradouro}, {$numero}";
                if ($complemento) $enderecoCompleto .= " - {$complemento}";
                $enderecoCompleto .= " - {$bairro}, {$cidade}/{$estado}";
                if ($cep) $enderecoCompleto .= " - CEP: {$cep}";
                
                // Validações
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = 'contato@example.com';
                }
                
                if (empty($site)) {
                    $site = 'https://seudominio.com';
                } elseif (!preg_match('/^https?:\/\//', $site)) {
                    $site = 'https://' . $site;
                }
                
                // Substituições
                $replacements = [
                    '{{RAZAO_SOCIAL}}' => htmlspecialchars($razaoSocial, ENT_QUOTES, 'UTF-8'),
                    '{{NOME_FANTASIA}}' => htmlspecialchars($nomeFantasia, ENT_QUOTES, 'UTF-8'),
                    '{{CNPJ}}' => htmlspecialchars($cnpjFormatado, ENT_QUOTES, 'UTF-8'),
                    '{{CNPJ_LIMPO}}' => htmlspecialchars($cnpjLimpo, ENT_QUOTES, 'UTF-8'),
                    '{{EMAIL}}' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                    '{{TELEFONE}}' => htmlspecialchars($telefone, ENT_QUOTES, 'UTF-8'),
                    '{{SITE}}' => htmlspecialchars($site, ENT_QUOTES, 'UTF-8'),
                    '{{LOGRADOURO}}' => htmlspecialchars($logradouro, ENT_QUOTES, 'UTF-8'),
                    '{{NUMERO}}' => htmlspecialchars($numero, ENT_QUOTES, 'UTF-8'),
                    '{{COMPLEMENTO}}' => htmlspecialchars($complemento, ENT_QUOTES, 'UTF-8'),
                    '{{BAIRRO}}' => htmlspecialchars($bairro, ENT_QUOTES, 'UTF-8'),
                    '{{CIDADE}}' => htmlspecialchars($cidade, ENT_QUOTES, 'UTF-8'),
                    '{{ESTADO}}' => htmlspecialchars($estado, ENT_QUOTES, 'UTF-8'),
                    '{{CEP}}' => htmlspecialchars($cep, ENT_QUOTES, 'UTF-8'),
                    '{{ENDERECO_COMPLETO}}' => htmlspecialchars($enderecoCompleto, ENT_QUOTES, 'UTF-8'),
                    '{{ANO}}' => date('Y'),
                    '{{DATA_GERACAO}}' => date('d/m/Y H:i:s')
                ];
                
                $dados = $replacements;
                $resultados = processarArquivos($replacements);
                
                // Criar ZIP
                $zipFile = criarZip($resultados, $nomeFantasia);
                
                // Salvar no histórico
                salvarHistorico([
                    'cnpj' => $cnpjLimpo,
                    'razao_social' => $razaoSocial,
                    'nome_fantasia' => $nomeFantasia,
                    'email' => $email,
                    'site' => $site,
                    'telefone' => $telefone,
                    'cidade' => $cidade,
                    'estado' => $estado
                ]);
                
                registrarLog('SUCESSO', "Site gerado para {$razaoSocial}");
            }
        }
    }
    
    // Regenera token CSRF
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Limpar arquivos antigos (mais de 7 dias)
$arquivosAntigos = glob(OUTPUT_DIR . '/*');
foreach ($arquivosAntigos as $arquivo) {
    if (is_file($arquivo) && (time() - filemtime($arquivo)) > 604800) {
        @unlink($arquivo);
    }
}

// Visualizar histórico
if (isset($_GET['ver_historico']) && file_exists(HISTORICO_FILE)) {
    $historico = json_decode(file_get_contents(HISTORICO_FILE), true) ?: [];
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Histórico de Gerações</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                overflow: hidden;
            }
            .header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .header h1 { font-size: 28px; }
            .btn-voltar {
                background: white;
                color: #667eea;
                padding: 10px 20px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
            }
            .content { padding: 40px; }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th {
                background: #f8f9fa;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #e0e0e0;
            }
            td {
                padding: 12px;
                border-bottom: 1px solid #e0e0e0;
            }
            tr:hover {
                background: #f8f9fa;
            }
            .site-link {
                color: #667eea;
                text-decoration: none;
                font-weight: 500;
            }
            .site-link:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>📜 Histórico de Gerações</h1>
                <a href="index.php" class="btn-voltar">← Voltar</a>
            </div>
            <div class="content">
                <p style="color: #666; margin-bottom: 20px;">
                    Total de gerações: <strong><?= count($historico) ?></strong>
                </p>
                <?php if (empty($historico)): ?>
                    <p style="text-align: center; color: #999; padding: 40px;">
                        Nenhuma geração registrada ainda.
                    </p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Empresa</th>
                                <th>CNPJ</th>
                                <th>Site Gerado</th>
                                <th>E-mail</th>
                                <th>Cidade/UF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['data']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($item['nome_fantasia']) ?></strong><br>
                                        <small style="color: #666;"><?= htmlspecialchars($item['razao_social']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($item['cnpj']) ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($item['site']) ?>" 
                                           target="_blank" 
                                           class="site-link">
                                            <?= htmlspecialchars($item['site']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($item['email']) ?></td>
                                    <td><?= htmlspecialchars($item['cidade']) ?>/<?= htmlspecialchars($item['estado']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerador Automático de Sites</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .content { padding: 40px; }
        .form-group {
            margin-bottom: 25px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover { 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-gerar {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            white-space: nowrap;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-gerar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 87, 108, 0.4);
        }
        .btn-download {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            margin-top: 15px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-error {
            background: #fee;
            border-left: 4px solid #f44;
            color: #c33;
        }
        .alert-success {
            background: #efe;
            border-left: 4px solid #4c4;
            color: #3a3;
        }
        .dados-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #e0e0e0;
        }
        .dados-box h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 18px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .dados-item {
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .dados-item:last-child { border-bottom: none; }
        .dados-item strong { color: #555; min-width: 150px; }
        .dados-item span { color: #333; flex: 1; }
        .btn-copy {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .btn-copy:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.4);
        }
        .btn-copy:active {
            transform: scale(0.95);
        }
        .resultado {
            display: flex;
            align-items: center;
            padding: 12px;
            margin: 8px 0;
            border-radius: 6px;
            background: #f8f9fa;
            transition: all 0.2s;
        }
        .resultado:hover { transform: translateX(5px); }
        .resultado.sucesso { background: #d4edda; border-left: 3px solid #28a745; }
        .resultado.erro { background: #f8d7da; border-left: 3px solid #dc3545; }
        .resultado-icon {
            width: 24px;
            height: 24px;
            margin-right: 12px;
            font-weight: bold;
            font-size: 18px;
        }
        .info-box {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #2196f3;
            font-size: 14px;
            color: #1565c0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 13px;
            border-top: 1px solid #e0e0e0;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .loading {
            display: none;
            text-align: center;
            margin: 20px 0;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Gerador Automático de Sites</h1>
            <p>Crie sites profissionais a partir de dados de CNPJ com validação e cache inteligente</p>
        </div>
        
        <div class="content">
            <?php if ($erro): ?>
                <div class="alert alert-error">
                    <strong>❌ Erro:</strong> <?= htmlspecialchars($erro) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($resultados): ?>
                <div class="alert alert-success">
                    <strong>✅ Sucesso!</strong> Todos os arquivos foram gerados com sucesso!
                </div>
                
                <div class="dados-box">
                    <h3>📋 Dados da Empresa</h3>
                    <div class="dados-item">
                        <strong>Razão Social:</strong>
                        <span><?= $dados['{{RAZAO_SOCIAL}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{RAZAO_SOCIAL}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>Nome Fantasia:</strong>
                        <span><?= $dados['{{NOME_FANTASIA}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{NOME_FANTASIA}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>CNPJ:</strong>
                        <span><?= $dados['{{CNPJ}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{CNPJ}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>Telefone:</strong>
                        <span><?= $dados['{{TELEFONE}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{TELEFONE}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>E-mail:</strong>
                        <span><?= $dados['{{EMAIL}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{EMAIL}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>Site:</strong>
                        <span><?= $dados['{{SITE}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{SITE}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>Endereço:</strong>
                        <span><?= $dados['{{ENDERECO_COMPLETO}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{ENDERECO_COMPLETO}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                    <div class="dados-item">
                        <strong>Data de Geração:</strong>
                        <span><?= $dados['{{DATA_GERACAO}}'] ?></span>
                        <button class="btn-copy" onclick="copiar('<?= addslashes($dados['{{DATA_GERACAO}}']) ?>')" title="Copiar">
                            📋
                        </button>
                    </div>
                </div>
                
                <div class="dados-box">
                    <h3>📁 Arquivos Gerados</h3>
                    <?php foreach ($resultados as $arquivo => $resultado): ?>
                        <div class="resultado <?= $resultado['status'] ?>">
                            <span class="resultado-icon"><?= $resultado['status'] == 'sucesso' ? '✅' : '❌' ?></span>
                            <div style="flex: 1;">
                                <strong><?= htmlspecialchars($arquivo) ?></strong>
                                <span style="color: #666; margin-left: 10px; font-size: 14px;"><?= htmlspecialchars($resultado['msg']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($zipFile): ?>
                    <a href="gerados/<?= htmlspecialchars($zipFile) ?>" download class="btn btn-download">
                        📦 Baixar todos os arquivos (ZIP)
                    </a>
                <?php else: ?>
                    <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                        ⚠️ <strong>ZIP não disponível:</strong> Extensão ZipArchive não habilitada.<br>
                        📂 Acesse os arquivos diretamente em: <code><?= OUTPUT_DIR ?></code>
                    </div>
                <?php endif; ?>
                
                <div class="info-box">
                    📂 <strong>Local dos arquivos:</strong> <code><?= OUTPUT_DIR ?></code><br>
                    💾 Arquivos são mantidos por 7 dias e depois excluídos automaticamente
                </div>
            <?php endif; ?>
            
            <form method="POST" id="formGerador">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-group">
                    <label for="cnpj">🏢 CNPJ * <small style="color: #999; font-weight: normal;">(com validação de dígitos)</small></label>
                    <input type="text" id="cnpj" name="cnpj" placeholder="00.000.000/0000-00" required 
                           pattern="[0-9]{2}[\.]?[0-9]{3}[\.]?[0-9]{3}[\/]?[0-9]{4}[-]?[0-9]{2}">
                </div>
                
                <div class="form-group">
                    <label for="email">📧 E-mail de Contato *</label>
                    <input type="email" id="email" name="email" placeholder="contato@empresa.com" required>
                </div>
                
                <div class="form-group">
                    <label for="site">🌐 Domínio do Site <small style="color: #999; font-weight: normal;">(opcional)</small></label>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <div style="flex: 1; display: flex; gap: 5px; min-width: 250px;">
                            <span style="padding: 12px 10px; background: #f8f9fa; border: 2px solid #e0e0e0; border-radius: 8px 0 0 8px; font-weight: 600; color: #667eea;">https://</span>
                            <input type="text" id="dominio" placeholder="pgmntopg5a7x" style="flex: 1; border-radius: 0; border-left: none;">
                            <span style="padding: 12px 10px; background: #f8f9fa; border: 2px solid #e0e0e0; border-radius: 0; font-weight: 600; color: #667eea;">.</span>
                            <select id="tld" style="padding: 12px 10px; border: 2px solid #e0e0e0; border-radius: 0 8px 8px 0; background: white; font-size: 16px; font-weight: 600; color: #333; cursor: pointer; border-left: none;">
                                <?php foreach (TLDS as $tld): ?>
                                    <option value="<?= $tld ?>"><?= $tld ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" class="btn-gerar" onclick="gerarDominio()">
                            🎲 Gerar
                        </button>
                    </div>
                    <input type="hidden" id="site" name="site">
                </div>
                
                <button type="submit" class="btn">✨ Gerar Site Agora</button>
            </form>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p style="margin-top: 10px; color: #667eea;">Consultando API e gerando arquivos...</p>
            </div>
        </div>
        
        <div class="footer">
            <p>🔐 Sistema com proteção CSRF, validação de CNPJ e cache inteligente</p>
            <p style="margin-top: 5px;">📊 Logs salvos em: <code><?= LOG_DIR ?></code></p>
            <?php if (file_exists(HISTORICO_FILE)): ?>
                <p style="margin-top: 10px;">
                    <a href="?ver_historico=1" style="color: #667eea; text-decoration: none; font-weight: 600;">
                        📜 Ver Histórico de Gerações
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Copiar para clipboard
        function copiar(texto) {
            navigator.clipboard.writeText(texto).then(() => {
                // Feedback visual
                const buttons = document.querySelectorAll('.btn-copy');
                buttons.forEach(btn => {
                    if (btn.onclick.toString().includes(texto.substring(0, 10))) {
                        btn.textContent = '✅';
                        setTimeout(() => {
                            btn.textContent = '📋';
                        }, 1000);
                    }
                });
            }).catch(err => {
                alert('Erro ao copiar: ' + err);
            });
        }
        
        // Gerar domínio aleatório
        function gerarDominio() {
            const primary = <?= json_encode(CHUNKS_PRIMARY) ?>;
            const secondary = <?= json_encode(CHUNKS_SECONDARY) ?>;
            
            // Selecionar aleatório
            const primaryChunk = primary[Math.floor(Math.random() * primary.length)];
            const secondaryChunk = secondary[Math.floor(Math.random() * secondary.length)];
            
            // Gerar código de 4 caracteres
            const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            let code = '';
            for (let i = 0; i < 4; i++) {
                code += chars[Math.floor(Math.random() * chars.length)];
            }
            
            // 50% de chance de incluir secondary
            let domain;
            if (Math.random() > 0.5) {
                domain = primaryChunk + secondaryChunk + code;
            } else {
                domain = primaryChunk + code;
            }
            
            // Atualizar campo do domínio
            document.getElementById('dominio').value = domain;
            atualizarSiteCompleto();
            
            // Animação de sucesso
            const input = document.getElementById('dominio');
            input.style.borderColor = '#38ef7d';
            setTimeout(() => {
                input.style.borderColor = '';
            }, 1000);
        }
        
        // Atualizar site completo quando domínio ou TLD mudar
        function atualizarSiteCompleto() {
            const dominio = document.getElementById('dominio').value;
            const tld = document.getElementById('tld').value;
            
            if (dominio) {
                document.getElementById('site').value = 'https://' + dominio + '.' + tld;
            } else {
                document.getElementById('site').value = '';
            }
        }
        
        // Listeners para atualizar automaticamente
        document.getElementById('dominio').addEventListener('input', atualizarSiteCompleto);
        document.getElementById('tld').addEventListener('change', atualizarSiteCompleto);
        
        document.getElementById('formGerador').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'block';
        });
        
        // Auto-formatar CNPJ enquanto digita
        document.getElementById('cnpj').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 14) {
                value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
                e.target.value = value;
            }
        });
    </script>
</body>
</html>