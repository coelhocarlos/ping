<?php
/**
 * Monitor de Ping IP • PHP + MySQL (Versão Corrigida + Chart.js)
 * 
 * Instruções para Hostinger:
 * 1. Crie um banco de dados MySQL no hPanel da Hostinger.
 * 2. Preencha as credenciais no arquivo 'config.php'.
 * 3. Suba os arquivos 'index.php' e 'config.php' na pasta 'public_html'.
 */

// Carrega configurações do banco de dados
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fallback caso config.php não exista
    $db_host = 'localhost';
    $db_user = 'SEU_USUARIO';
    $db_pass = 'SUA_SENHA';
    $db_name = 'SEU_BANCO';
    define('ADMIN_TOKEN', 'ALTERE_ESTE_TOKEN');
}

$db_connected = false;
$db_error = null;

// Conexão com o Banco de Dados com Tratamento de Erros
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // Autocriação da tabela de Hosts se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS ping_hosts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        host VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_name (name),
        INDEX idx_host (host)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $db_connected = true;
} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log("Erro de conexão MySQL: " . $e->getMessage());
}

// API RESTful embutida para lidar com solicitações AJAX
if (isset($_GET['action'])) {
    // Evita cache no navegador
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    
    if (!$db_connected) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Sem conexão com MySQL. Verifique as credenciais em config.php.']);
        exit;
    }

    $action = $_GET['action'];

    // Validação básica de ação
    $valid_actions = ['list', 'add', 'update', 'delete', 'clear', 'stats'];
    if (!in_array($action, $valid_actions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
        exit;
    }

    // Ação: Listar hosts
    if ($action === 'list') {
        try {
            // Limita a 500 hosts para performance
            $stmt = $pdo->query("SELECT * FROM ping_hosts ORDER BY id DESC LIMIT 500");
            $rows = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
        } catch (Exception $ex) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao listar hosts.']);
            error_log("Erro list: " . $ex->getMessage());
        }
        exit;
    }

    // Ação: Estatísticas básicas
    if ($action === 'stats') {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM ping_hosts");
            $total = $stmt->fetch()['total'];
            echo json_encode(['success' => true, 'total' => (int)$total]);
        } catch (Exception $ex) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao buscar estatísticas.']);
        }
        exit;
    }

    // Verifica método POST para ações que modificam dados
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método não permitido. Use POST.']);
        exit;
    }

    // Lê o corpo JSON
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido.']);
        exit;
    }

    // Ação: Adicionar host
    if ($action === 'add') {
        $name = trim($input['name'] ?? '');
        $host = trim($input['host'] ?? '');

        // Validações
        if (empty($name) || empty($host)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Preencha todos os campos.']);
            exit;
        }

        if (strlen($name) > 100 || strlen($host) > 255) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome ou host muito longo.']);
            exit;
        }

        // Validação básica de host (IP ou domínio)
        if (!preg_match('/^[a-zA-Z0-9\.\-\_]+$/', $host)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Host contém caracteres inválidos.']);
            exit;
        }

        try {
            // Verifica duplicata
            $check = $pdo->prepare("SELECT id FROM ping_hosts WHERE host = :host LIMIT 1");
            $check->execute([':host' => $host]);
            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Este host já está cadastrado.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO ping_hosts (name, host) VALUES (:name, :host)");
            $stmt->execute([':name' => $name, ':host' => $host]);
            
            $id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => (int)$id, 'message' => 'Host cadastrado com sucesso.']);
        } catch (Exception $ex) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao cadastrar host.']);
            error_log("Erro add: " . $ex->getMessage());
        }
        exit;
    }

    // Ação: Atualizar host
    if ($action === 'update') {
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $host = trim($input['host'] ?? '');

        if ($id <= 0 || empty($name) || empty($host)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dados inválidos.']);
            exit;
        }

        if (strlen($name) > 100 || strlen($host) > 255) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome ou host muito longo.']);
            exit;
        }

        if (!preg_match('/^[a-zA-Z0-9\.\-\_]+$/', $host)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Host contém caracteres inválidos.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE ping_hosts SET name = :name, host = :host WHERE id = :id");
            $stmt->execute([':name' => $name, ':host' => $host, ':id' => $id]);
            
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => true, 'message' => 'Nenhuma alteração realizada.']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Host atualizado com sucesso.']);
            }
        } catch (Exception $ex) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao atualizar host.']);
            error_log("Erro update: " . $ex->getMessage());
        }
        exit;
    }

    // Ação: Deletar host
    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID inválido.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM ping_hosts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Host não encontrado.']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Host removido com sucesso.']);
            }
        } catch (Exception $ex) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao remover host.']);
            error_log("Erro delete: " . $ex->getMessage());
        }
        exit;
    }

    // Ação: Limpar todos os hosts (requer token)
    if ($action === 'clear') {
        $token = $input['token'] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
        
        if ($token !== ADMIN_TOKEN) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Token de autorização inválido.']);
            exit;
        }

        try {
            $pdo->exec("TRUNCATE TABLE ping_hosts");
            echo json_encode(['success' => true, 'message' => 'Banco de dados limpo com sucesso.']);
        } catch (Exception $ex) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao limpar banco.']);
            error_log("Erro clear: " . $ex->getMessage());
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor de Ping IP • PHP + MySQL</title>
    <script>
        (function() {
            var t = localStorage.getItem('pinger-theme');
            if (!t) t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500&family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap');
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.6); }
        ::-webkit-scrollbar-thumb { background: rgba(51, 65, 85, 0.5); border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(71, 85, 105, 0.8); }
        
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }
        
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .animate-pulse-slow {
            animation: pulse-slow 2s ease-in-out infinite;
        }

        /* ==================== TEMA LIGHT ==================== */
        [data-theme="light"] {
            color-scheme: light;
        }
        [data-theme="light"] body {
            background-color: #f8fafc;
            color: #1e293b;
        }

        /* Fundos */
        [data-theme="light"] .bg-slate-950 { background-color: #f8fafc !important; }
        [data-theme="light"] .bg-slate-900\/40 { background-color: rgba(255,255,255,0.85) !important; }
        [data-theme="light"] .bg-slate-900\/30 { background-color: rgba(255,255,255,0.75) !important; }
        [data-theme="light"] .bg-slate-900\/20 { background-color: rgba(255,255,255,0.6) !important; }
        [data-theme="light"] .bg-slate-900 { background-color: #ffffff !important; }
        [data-theme="light"] .bg-slate-950\/40 { background-color: #f1f5f9 !important; }
        [data-theme="light"] .bg-slate-950\/70 { background-color: rgba(248,250,252,0.8) !important; }
        [data-theme="light"] .bg-slate-800\/60 { background-color: #f1f5f9 !important; }
        [data-theme="light"] .bg-slate-800\/50 { background-color: #f1f5f9 !important; }
        [data-theme="light"] .bg-slate-800 { background-color: #e2e8f0 !important; }

        /* Hover fundos */
        [data-theme="light"] .hover\:bg-slate-800:hover { background-color: #e2e8f0 !important; }
        [data-theme="light"] .hover\:bg-slate-800\/60:hover { background-color: #e2e8f0 !important; }

        /* Textos principais */
        [data-theme="light"] .text-slate-100 { color: #0f172a !important; }
        [data-theme="light"] .text-slate-200 { color: #1e293b !important; }
        [data-theme="light"] .text-slate-300 { color: #334155 !important; }
        [data-theme="light"] .text-slate-400 { color: #475569 !important; }
        [data-theme="light"] .text-slate-500 { color: #64748b !important; }
        [data-theme="light"] .text-slate-600 { color: #94a3b8 !important; }
        [data-theme="light"] .text-white { color: #0f172a !important; }

        /* Hover textos */
        [data-theme="light"] .hover\:text-white:hover { color: #0f172a !important; }
        [data-theme="light"] .hover\:text-slate-200:hover { color: #1e293b !important; }
        [data-theme="light"] .hover\:text-slate-300:hover { color: #334155 !important; }

        /* Bordas */
        [data-theme="light"] .border-slate-800 { border-color: #e2e8f0 !important; }
        [data-theme="light"] .border-slate-800\/60 { border-color: rgba(226,232,240,0.7) !important; }
        [data-theme="light"] .border-slate-800\/50 { border-color: rgba(226,232,240,0.6) !important; }
        [data-theme="light"] .border-slate-800\/40 { border-color: rgba(226,232,240,0.5) !important; }
        [data-theme="light"] .border-slate-700\/40 { border-color: #cbd5e1 !important; }
        [data-theme="light"] .border-slate-700\/30 { border-color: #cbd5e1 !important; }
        [data-theme="light"] .border-dashed { border-color: #cbd5e1 !important; }

        /* Hover bordas */
        [data-theme="light"] .hover\:border-slate-700\/60:hover { border-color: #cbd5e1 !important; }
        [data-theme="light"] .hover\:border-emerald-500\/30:hover { border-color: rgba(5,150,105,0.4) !important; }

        /* Cores de destaque (escurecidas p/ legibilidade em fundo claro) */
        [data-theme="light"] .text-rose-300 { color: #e11d48 !important; }
        [data-theme="light"] .text-rose-400 { color: #e11d48 !important; }
        [data-theme="light"] .hover\:text-rose-300:hover { color: #e11d48 !important; }
        [data-theme="light"] .hover\:text-rose-400:hover { color: #e11d48 !important; }
        [data-theme="light"] .text-emerald-300 { color: #059669 !important; }
        [data-theme="light"] .text-emerald-400\/80 { color: rgba(5,150,105,0.9) !important; }
        [data-theme="light"] .text-amber-300 { color: #d97706 !important; }
        [data-theme="light"] .text-amber-400 { color: #d97706 !important; }

        /* Hover botões acento */
        [data-theme="light"] .hover\:bg-rose-500\/30:hover { background-color: rgba(225,29,72,0.15) !important; }
        [data-theme="light"] .hover\:bg-rose-500\/10:hover { background-color: rgba(225,29,72,0.1) !important; }

        /* Botões secundários */
        [data-theme="light"] .text-slate-400:hover { color: #1e293b !important; }
        [data-theme="light"] .text-rose-400\/80 { color: #e11d48 !important; }

        /* Select e inputs */
        [data-theme="light"] select {
            background-color: #ffffff !important;
            color: #334155 !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] input[type="text"] {
            background-color: #f8fafc !important;
            color: #1e293b !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] input::placeholder {
            color: #94a3b8 !important;
        }

        /* Scrollbar */
        [data-theme="light"] ::-webkit-scrollbar-track { background: rgba(241,245,249,0.8); }
        [data-theme="light"] ::-webkit-scrollbar-thumb { background: rgba(203,213,225,0.8); }
        [data-theme="light"] ::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.8); }

        /* Header sticky */
        [data-theme="light"] header {
            background-color: rgba(255,255,255,0.85) !important;
            border-color: rgba(226,232,240,0.7) !important;
        }

        /* Header botões */
        [data-theme="light"] header .bg-slate-800\/60 { background-color: #f1f5f9 !important; }
        [data-theme="light"] header .border-slate-700\/40 { border-color: #cbd5e1 !important; }
        [data-theme="light"] header .text-slate-300 { color: #334155 !important; }
        [data-theme="light"] header .hover\:text-white:hover { color: #0f172a !important; }

        /* Badge / toasts */
        [data-theme="light"] .toast-light {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #334155 !important;
        }

        /* Tooltip Chart.js */
        .chartjs-tooltip-theme { /* placeholder */ }

        /* Transição suave (apenas elementos principais) */
        [data-theme="light"] body,
        [data-theme="light"] header,
        [data-theme="light"] .bg-slate-900\/40,
        [data-theme="light"] .bg-slate-900\/30 {
            transition: background-color 0.25s ease, border-color 0.25s ease, color 0.25s ease;
        }

        /* Icon toggle */
        #themeToggleIcon { transition: transform 0.3s; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen flex flex-col antialiased font-sans text-sm selection:bg-rose-500/20 selection:text-rose-200">

    <header class="border-b border-slate-800/50 bg-slate-900/40 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-300">
                    <i class="fa-solid fa-server text-xs"></i>
                </div>
                <div>
                    <h1 class="font-medium text-slate-100 text-sm tracking-wide flex items-center gap-2">
                        Monitor de Ping IP
                        <span class="text-[10px] bg-emerald-500/10 text-emerald-300 border border-emerald-500/20 px-2 py-0.5 rounded-full font-light">PHP + MySQL</span>
                    </h1>
                    <p class="text-[11px] text-slate-400 font-light">sincronizado centralmente no banco de dados Hostinger</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button onclick="toggleTheme()" class="w-8 h-8 rounded-full bg-slate-800/60 hover:bg-slate-800 border border-slate-700/40 transition-all active:scale-95 flex items-center justify-center text-slate-400 hover:text-slate-200" title="Alternar tema" id="themeToggleBtn">
                    <i class="fa-solid fa-sun text-xs" id="themeToggleIcon"></i>
                </button>
                <button id="btnManualPing" onclick="pingAllTargets()" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium text-slate-300 bg-slate-800/60 hover:bg-slate-800 border border-slate-700/40 transition-all hover:text-white active:scale-95">
                    <i class="fa-solid fa-arrows-rotate text-[10px] text-slate-400" id="refreshIcon"></i>
                    <span>Verificar Ping</span>
                </button>
                <button onclick="openAddModal()" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium text-rose-100 bg-rose-500/20 hover:bg-rose-500/30 border border-rose-500/30 transition-all active:scale-95">
                    <i class="fa-solid fa-plus text-[10px]"></i>
                    <span>Novo IP</span>
                </button>
            </div>
        </div>
    </header>

    <main class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 py-8 space-y-6">

        <?php if (!$db_connected): ?>
        <div class="bg-rose-950/30 border border-rose-500/40 rounded-2xl p-4 text-rose-300 text-xs font-light flex items-center gap-3">
            <i class="fa-solid fa-triangle-exclamation text-base text-rose-400"></i>
            <div>
                <strong class="font-medium block mb-0.5">Erro na Conexão com o Banco de Dados MySQL:</strong>
                <span><?php echo htmlspecialchars($db_error ?? 'Erro desconhecido'); ?></span>
                <p class="mt-1 text-[11px] text-slate-400">Edite as credenciais no arquivo <code>config.php</code> com os dados do seu MySQL criado na Hostinger.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Metric Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-slate-900/40 border border-slate-800/60 rounded-2xl p-4 transition-all hover:border-slate-700/60">
                <div class="flex items-center justify-between text-slate-400 mb-1">
                    <span class="text-[11px] font-light tracking-wide uppercase">Total Cadastrado</span>
                    <i class="fa-solid fa-database text-xs text-slate-500"></i>
                </div>
                <div class="text-xl font-light text-slate-200" id="statTotal">0</div>
            </div>

            <div class="bg-slate-900/40 border border-slate-800/60 rounded-2xl p-4 transition-all hover:border-emerald-500/20">
                <div class="flex items-center justify-between text-slate-400 mb-1">
                    <span class="text-[11px] font-light tracking-wide uppercase text-emerald-400/80">Estáveis</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                </div>
                <div class="text-xl font-light text-emerald-300" id="statOnline">0</div>
            </div>

            <div class="bg-slate-900/40 border border-slate-800/60 rounded-2xl p-4 transition-all hover:border-rose-500/20">
                <div class="flex items-center justify-between text-slate-400 mb-1">
                    <span class="text-[11px] font-light tracking-wide uppercase text-rose-400/80">Alta Latência (&gt;200ms)</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400 animate-pulse"></span>
                </div>
                <div class="text-xl font-light text-rose-300" id="statHighPing">0</div>
            </div>

            <div class="bg-slate-900/40 border border-slate-800/60 rounded-2xl p-4 transition-all hover:border-slate-700/60">
                <div class="flex items-center justify-between text-slate-400 mb-1">
                    <span class="text-[11px] font-light tracking-wide uppercase">Média Geral</span>
                    <i class="fa-solid fa-gauge-simple text-xs text-slate-500"></i>
                </div>
                <div class="text-xl font-light text-slate-200 mono" id="statAvgPing">0 ms</div>
            </div>
        </div>

        <!-- Gráfico de Latência Global -->
        <div class="bg-slate-900/30 border border-slate-800/40 rounded-2xl p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-chart-line text-slate-400 text-sm"></i>
                    <h3 class="text-sm font-medium text-slate-200">Gráfico de Latência Global</h3>
                </div>
                <div class="flex items-center gap-2 text-[11px] text-slate-500">
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span> &lt;100ms
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-amber-400"></span> 100-200ms
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-rose-400"></span> &gt;200ms
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-slate-600"></span> Offline
                    </span>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="globalPingChart"></canvas>
            </div>
            <div id="chartEmpty" class="hidden text-center py-8 text-slate-500 text-xs font-light">
                <i class="fa-solid fa-chart-line text-2xl mb-2 block text-slate-600"></i>
                Aguardando dados de ping para gerar o gráfico...
            </div>
        </div>

        <div class="bg-slate-900/30 border border-slate-800/40 rounded-2xl p-3 flex flex-wrap items-center justify-between gap-3 text-xs">
            <div class="flex items-center gap-2">
                <span class="text-slate-400 font-light">Intervalo de Verificação:</span>
                <select id="intervalSelect" onchange="updateInterval()" class="bg-slate-900 border border-slate-800 text-slate-300 text-xs rounded-lg px-2.5 py-1 focus:ring-1 focus:ring-rose-500/50 focus:outline-none font-light">
                    <option value="5000">A cada 5 segundos</option>
                    <option value="10000" selected>A cada 10 segundos</option>
                    <option value="30000">A cada 30 segundos</option>
                    <option value="60000">A cada 1 minuto</option>
                    <option value="300000">A cada 5 minutos</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button onclick="toggleChartVisibility()" class="px-2.5 py-1 text-slate-400 hover:text-slate-200 hover:bg-slate-800/60 rounded-lg transition-colors flex items-center gap-1.5 font-light">
                    <i class="fa-solid fa-chart-line text-[10px]" id="chartToggleIcon"></i> <span id="chartToggleText">Ocultar Gráfico</span>
                </button>
                <button onclick="fetchDatabaseTargets()" class="px-2.5 py-1 text-slate-400 hover:text-slate-200 hover:bg-slate-800/60 rounded-lg transition-colors flex items-center gap-1.5 font-light">
                    <i class="fa-solid fa-rotate text-[10px]" id="syncIcon"></i> Sincronizar MySQL
                </button>
                <button onclick="clearAllTargets()" class="px-2.5 py-1 text-rose-400/80 hover:text-rose-300 hover:bg-rose-500/10 rounded-lg transition-colors flex items-center gap-1.5 font-light">
                    <i class="fa-solid fa-trash-can text-[10px]"></i> Limpar Banco
                </button>
            </div>
        </div>

        <!-- Gráfico por Host (quando selecionado) -->
        <div id="hostChartSection" class="hidden bg-slate-900/30 border border-slate-800/40 rounded-2xl p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-chart-bar text-slate-400 text-sm"></i>
                    <h3 class="text-sm font-medium text-slate-200">Detalhes: <span id="hostChartTitle" class="text-rose-300"></span></h3>
                </div>
                <button onclick="closeHostChart()" class="text-slate-500 hover:text-slate-300 transition-colors text-xs">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="chart-container">
                <canvas id="hostPingChart"></canvas>
            </div>
            <div class="grid grid-cols-3 gap-3 mt-3">
                <div class="bg-slate-950/40 rounded-xl p-3 border border-slate-800/40">
                    <div class="text-[10px] text-slate-500 uppercase tracking-wide mb-1">Mínimo</div>
                    <div class="text-lg font-light text-emerald-300 mono" id="hostStatMin">—</div>
                </div>
                <div class="bg-slate-950/40 rounded-xl p-3 border border-slate-800/40">
                    <div class="text-[10px] text-slate-500 uppercase tracking-wide mb-1">Média</div>
                    <div class="text-lg font-light text-slate-200 mono" id="hostStatAvg">—</div>
                </div>
                <div class="bg-slate-950/40 rounded-xl p-3 border border-slate-800/40">
                    <div class="text-[10px] text-slate-500 uppercase tracking-wide mb-1">Máximo</div>
                    <div class="text-lg font-light text-rose-300 mono" id="hostStatMax">—</div>
                </div>
            </div>
        </div>

        <div id="targetsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"></div>

        <!-- Empty State -->
        <div id="emptyState" class="hidden bg-slate-900/20 border border-dashed border-slate-800 rounded-3xl p-12 text-center">
            <div class="w-12 h-12 bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-600">
                <i class="fa-solid fa-database text-lg"></i>
            </div>
            <h3 class="text-sm font-medium text-slate-300 mb-1">Nenhum IP Salvo no MySQL</h3>
            <p class="text-slate-500 text-xs max-w-xs mx-auto mb-5 font-light">Adicione novos hosts. Eles ficarão gravados no banco de dados e visíveis para qualquer dispositivo.</p>
            <button onclick="openAddModal()" class="bg-rose-500/20 text-rose-200 hover:bg-rose-500/30 border border-rose-500/30 px-4 py-2 rounded-full font-medium text-xs transition-all">
                Cadastrar Primeiro IP
            </button>
        </div>

    </main>

    <!-- Modal Adicionar/Editar Host -->
    <div id="addModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 z-50">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-sm p-5 shadow-2xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-medium text-slate-100" id="modalTitle">Novo Host no Banco de Dados</h3>
                <button onclick="closeAddModal()" class="text-slate-500 hover:text-slate-300 transition-colors text-xs">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form id="addForm" onsubmit="handleSaveTarget(event)" class="space-y-3.5">
                <input type="hidden" id="targetId" value="">
                <div>
                    <label class="block text-xs font-light text-slate-400 mb-1">Nome do Dispositivo</label>
                    <input type="text" id="targetName" required maxlength="100" placeholder="Ex: Servidor Principal, Roteador" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-200 focus:ring-1 focus:ring-rose-500/50 focus:outline-none text-xs font-light">
                </div>
                
                <div>
                    <label class="block text-xs font-light text-slate-400 mb-1">IP ou Domínio Web</label>
                    <input type="text" id="targetHost" required maxlength="255" placeholder="Ex: 8.8.8.8 ou google.com" pattern="[a-zA-Z0-9\.\-\_]+" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-200 focus:ring-1 focus:ring-rose-500/50 focus:outline-none text-xs mono font-light">
                    <p class="text-[10px] text-slate-600 mt-1">Apenas letras, números, pontos, hífens e underscores.</p>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeAddModal()" class="px-3 py-1.5 rounded-full text-slate-400 hover:text-slate-200 text-xs font-light">Cancelar</button>
                    <button type="submit" class="bg-rose-500/20 text-rose-200 hover:bg-rose-500/30 border border-rose-500/30 px-4 py-1.5 rounded-full font-medium text-xs transition-all">Salvar no MySQL</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Confirmação para Ações Destrutivas -->
    <div id="confirmModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 z-50">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-sm p-5 shadow-2xl">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-400">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h3 class="text-sm font-medium text-slate-100" id="confirmTitle">Confirmar Ação</h3>
            </div>
            
            <p class="text-xs text-slate-400 font-light mb-4" id="confirmMessage">
                Tem certeza que deseja realizar esta ação?
            </p>

            <div class="flex items-center justify-end gap-2">
                <button onclick="closeConfirmModal()" class="px-3 py-1.5 rounded-full text-slate-400 hover:text-slate-200 text-xs font-light">Cancelar</button>
                <button onclick="confirmAction()" id="confirmBtn" class="bg-rose-500/20 text-rose-200 hover:bg-rose-500/30 border border-rose-500/30 px-4 py-1.5 rounded-full font-medium text-xs transition-all">
                    Confirmar
                </button>
            </div>
        </div>
    </div>

    <!-- Notification Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>

    <!-- Token Admin (embutido para uso no clear) -->
    <script>window.__ADMIN_TOKEN__ = <?php echo json_encode(defined('ADMIN_TOKEN') ? ADMIN_TOKEN : ''); ?>;</script>

    <script>
        // ==================== VARIÁVEIS GLOBAIS ====================
        let targets = [];
        let autoPingTimer = null;
        let globalPingChart = null;
        let hostPingChart = null;
        let currentHostChartTarget = null;
        let chartVisible = true;
        let confirmCallback = null;
        
        // Configuração de cores para hosts
        const HOST_COLORS = [
            { border: 'rgba(52, 211, 153, 1)', bg: 'rgba(52, 211, 153, 0.1)' },   // emerald
            { border: 'rgba(96, 165, 250, 1)', bg: 'rgba(96, 165, 250, 0.1)' },    // blue
            { border: 'rgba(251, 191, 36, 1)', bg: 'rgba(251, 191, 36, 0.1)' },    // amber
            { border: 'rgba(244, 114, 182, 1)', bg: 'rgba(244, 114, 182, 0.1)' },  // pink
            { border: 'rgba(167, 139, 250, 1)', bg: 'rgba(167, 139, 250, 0.1)' },  // violet
            { border: 'rgba(251, 146, 60, 1)', bg: 'rgba(251, 146, 60, 0.1)' },    // orange
            { border: 'rgba(45, 212, 191, 1)', bg: 'rgba(45, 212, 191, 0.1)' },    // teal
            { border: 'rgba(129, 140, 248, 1)', bg: 'rgba(129, 140, 248, 0.1)' },  // indigo
        ];

        // ==================== INICIALIZAÇÃO ====================
        document.addEventListener('DOMContentLoaded', () => {
            applyTheme(document.documentElement.getAttribute('data-theme'));
            initGlobalChart();
            fetchDatabaseTargets();
            startAutoPing();
        });

        // ==================== TEMA DARK/LIGHT ====================
        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('pinger-theme', next);
            applyTheme(next);
        }

        function applyTheme(theme) {
            const icon = document.getElementById('themeToggleIcon');
            const btn = document.getElementById('themeToggleBtn');

            if (icon) {
                icon.className = theme === 'dark' ? 'fa-solid fa-sun text-xs' : 'fa-solid fa-moon text-xs';
            }
            if (btn) {
                btn.title = theme === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro';
            }

            // Atualiza cores do Chart.js
            if (globalPingChart) {
                updateGlobalChartTheme(theme);
            }
            if (hostPingChart && currentHostChartTarget) {
                updateHostChartTheme(theme);
            }
        }

        function getChartThemeColors(theme) {
            if (theme === 'light') {
                return {
                    grid: 'rgba(203, 213, 225, 0.5)',
                    tick: 'rgba(100, 116, 139, 0.9)',
                    legendLabel: 'rgba(71, 85, 105, 0.9)',
                    tooltipBg: 'rgba(255, 255, 255, 0.97)',
                    tooltipTitle: '#0f172a',
                    tooltipBody: '#475569',
                    tooltipBorder: 'rgba(203, 213, 225, 0.7)'
                };
            }
            return {
                grid: 'rgba(51, 65, 85, 0.3)',
                tick: 'rgba(100, 116, 139, 0.8)',
                legendLabel: 'rgba(148, 163, 184, 0.8)',
                tooltipBg: 'rgba(15, 23, 42, 0.95)',
                tooltipTitle: 'rgba(226, 232, 240, 1)',
                tooltipBody: 'rgba(148, 163, 184, 1)',
                tooltipBorder: 'rgba(51, 65, 85, 0.5)'
            };
        }

        function updateGlobalChartTheme(theme) {
            if (!globalPingChart) return;
            const c = getChartThemeColors(theme);
            const opts = globalPingChart.options;

            opts.plugins.legend.labels.color = c.legendLabel;
            opts.plugins.tooltip.backgroundColor = c.tooltipBg;
            opts.plugins.tooltip.titleColor = c.tooltipTitle;
            opts.plugins.tooltip.bodyColor = c.tooltipBody;
            opts.plugins.tooltip.borderColor = c.tooltipBorder;
            opts.scales.x.grid.color = c.grid;
            opts.scales.y.grid.color = c.grid;
            opts.scales.x.ticks.color = c.tick;
            opts.scales.y.ticks.color = c.tick;

            globalPingChart.update('none');
        }

        function updateHostChartTheme(theme) {
            if (!hostPingChart) return;
            const c = getChartThemeColors(theme);
            const opts = hostPingChart.options;

            opts.plugins.tooltip.backgroundColor = c.tooltipBg;
            opts.plugins.tooltip.titleColor = c.tooltipTitle;
            opts.plugins.tooltip.bodyColor = c.tooltipBody;
            opts.plugins.tooltip.borderColor = c.tooltipBorder;
            opts.scales.x.grid.color = c.grid;
            opts.scales.y.grid.color = c.grid;
            opts.scales.x.ticks.color = c.tick;
            opts.scales.y.ticks.color = c.tick;

            hostPingChart.update('none');
        }

        // ==================== GRÁFICO GLOBAL ====================
        function initGlobalChart() {
            const ctx = document.getElementById('globalPingChart').getContext('2d');
            const theme = document.documentElement.getAttribute('data-theme') || 'dark';
            const c = getChartThemeColors(theme);
            
            globalPingChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: []
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 300
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: c.legendLabel,
                                font: {
                                    size: 10,
                                    family: "'Plus Jakarta Sans', sans-serif"
                                },
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: c.tooltipBg,
                            titleColor: c.tooltipTitle,
                            bodyColor: c.tooltipBody,
                            borderColor: c.tooltipBorder,
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 8,
                            titleFont: { size: 11 },
                            bodyFont: { size: 11 },
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    const label = context.dataset.label || '';
                                    if (value === null) {
                                        return label + ': Offline';
                                    }
                                    return label + ': ' + value + ' ms';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: c.grid,
                                drawBorder: false
                            },
                            ticks: {
                                color: c.tick,
                                font: { size: 9 },
                                maxTicksLimit: 12
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: c.grid,
                                drawBorder: false
                            },
                            ticks: {
                                color: c.tick,
                                font: { size: 9 },
                                callback: function(value) {
                                    return value + ' ms';
                                }
                            }
                        }
                    }
                }
            });
        }

        function updateGlobalChart() {
            if (!globalPingChart) return;

            // Pega os timestamps mais recentes (últimos 60 pontos)
            const allTimes = [];
            targets.forEach(t => {
                if (t.history) {
                    t.history.forEach(h => allTimes.push(h.time));
                }
            });

            if (allTimes.length === 0) {
                document.getElementById('chartEmpty').classList.remove('hidden');
                document.getElementById('globalPingChart').style.display = 'none';
                return;
            }

            document.getElementById('chartEmpty').classList.add('hidden');
            document.getElementById('globalPingChart').style.display = 'block';

            // Usa os últimos 60 timestamps únicos
            const uniqueTimes = [...new Set(allTimes)].sort().slice(-60);

            const datasets = targets.map((target, index) => {
                const colorIndex = index % HOST_COLORS.length;
                const color = HOST_COLORS[colorIndex];
                
                // Mapeia o history para os timestamps
                const historyMap = new Map();
                (target.history || []).forEach(h => {
                    historyMap.set(h.time, h);
                });

                const data = uniqueTimes.map(time => {
                    const h = historyMap.get(time);
                    if (!h) return null;
                    return h.status === 'online' ? h.ping : null;
                });

                return {
                    label: target.name || target.host,
                    data: data,
                    borderColor: color.border,
                    backgroundColor: color.bg,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: color.border,
                    tension: 0.3,
                    fill: false,
                    spanGaps: true
                };
            });

            globalPingChart.data.labels = uniqueTimes;
            globalPingChart.data.datasets = datasets;
            globalPingChart.update('none');
        }

        function updateHostChart(target) {
            if (!target || !target.history || target.history.length === 0) return;

            currentHostChartTarget = target;
            document.getElementById('hostChartSection').classList.remove('hidden');
            document.getElementById('hostChartTitle').textContent = `${target.name} (${target.host})`;

            // Calcula estatísticas
            const onlinePings = target.history
                .filter(h => h.status === 'online')
                .map(h => h.ping);

            if (onlinePings.length > 0) {
                const min = Math.min(...onlinePings);
                const max = Math.max(...onlinePings);
                const avg = Math.round(onlinePings.reduce((a, b) => a + b, 0) / onlinePings.length);
                
                document.getElementById('hostStatMin').textContent = min + ' ms';
                document.getElementById('hostStatAvg').textContent = avg + ' ms';
                document.getElementById('hostStatMax').textContent = max + ' ms';
            } else {
                document.getElementById('hostStatMin').textContent = '—';
                document.getElementById('hostStatAvg').textContent = '—';
                document.getElementById('hostStatMax').textContent = '—';
            }

            // Cria/atualiza gráfico do host
            const ctx = document.getElementById('hostPingChart').getContext('2d');
            const theme = document.documentElement.getAttribute('data-theme') || 'dark';
            const c = getChartThemeColors(theme);
            
            const labels = target.history.map(h => h.time);
            const data = target.history.map(h => h.status === 'online' ? h.ping : null);
            const backgroundColors = target.history.map(h => {
                if (h.status === 'offline') return 'rgba(251, 191, 36, 0.3)';
                if (h.ping > 200) return 'rgba(244, 63, 94, 0.5)';
                return 'rgba(52, 211, 153, 0.5)';
            });

            if (hostPingChart) {
                hostPingChart.destroy();
            }

            hostPingChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Latência (ms)',
                        data: data,
                        backgroundColor: backgroundColors,
                        borderColor: backgroundColors.map(c => c.replace('0.5', '1').replace('0.3', '1')),
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 300
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: c.tooltipBg,
                            titleColor: c.tooltipTitle,
                            bodyColor: c.tooltipBody,
                            borderColor: c.tooltipBorder,
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    if (value === null) return 'Offline';
                                    return value + ' ms';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: c.grid,
                                drawBorder: false
                            },
                            ticks: {
                                color: c.tick,
                                font: { size: 8 },
                                maxTicksLimit: 10
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: c.grid,
                                drawBorder: false
                            },
                            ticks: {
                                color: c.tick,
                                font: { size: 9 },
                                callback: function(value) {
                                    return value + ' ms';
                                }
                            }
                        }
                    }
                }
            });

            // Rola até o gráfico
            document.getElementById('hostChartSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function closeHostChart() {
            document.getElementById('hostChartSection').classList.add('hidden');
            currentHostChartTarget = null;
            if (hostPingChart) {
                hostPingChart.destroy();
                hostPingChart = null;
            }
        }

        function toggleChartVisibility() {
            chartVisible = !chartVisible;
            const chartSection = document.getElementById('globalPingChart').parentElement.parentElement;
            const icon = document.getElementById('chartToggleIcon');
            const text = document.getElementById('chartToggleText');
            
            if (chartVisible) {
                chartSection.style.display = 'block';
                icon.classList.add('fa-chart-line');
                icon.classList.remove('fa-eye-slash');
                text.textContent = 'Ocultar Gráfico';
            } else {
                chartSection.style.display = 'none';
                icon.classList.remove('fa-chart-line');
                icon.classList.add('fa-eye-slash');
                text.textContent = 'Mostrar Gráfico';
            }
        }

        // ==================== API E DADOS ====================
        async function fetchDatabaseTargets() {
            const syncIcon = document.getElementById('syncIcon');
            if (syncIcon) syncIcon.classList.add('fa-spin');

            try {
                const res = await fetch('index.php?action=list&_t=' + Date.now());
                const data = await res.json();
                
                if (data.success) {
                    const currentMap = new Map(targets.map(t => [t.id, t]));

                    targets = data.data.map(item => {
                        const existing = currentMap.get(item.id.toString()) || currentMap.get(item.id);
                        return {
                            id: item.id.toString(),
                            name: item.name,
                            host: item.host,
                            status: existing ? existing.status : 'unknown',
                            ping: existing ? existing.ping : 0,
                            lastCheck: existing ? existing.lastCheck : null,
                            history: existing ? existing.history : []
                        };
                    });

                    render();
                    updateGlobalChart();
                    pingAllTargets();
                } else if (data.error) {
                    showToast(data.error);
                }
            } catch (err) {
                showToast('Erro ao conectar com a API MySQL do PHP.');
            } finally {
                if (syncIcon) syncIcon.classList.remove('fa-spin');
            }
        }

        async function measurePing(target) {
            const start = performance.now();
            let host = target.host.trim();
            
            let cleanHost = host.replace(/^https?:\/\//i, '');
            let url = 'https://' + cleanHost;

            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000);

                await fetch(url, {
                    method: 'HEAD',
                    mode: 'no-cors',
                    cache: 'no-store',
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
                const pingTime = Math.round(performance.now() - start);
                return { status: 'online', ping: pingTime };
            } catch (err) {
                try {
                    const imgPingStart = performance.now();
                    await new Promise((resolve, reject) => {
                        const img = new Image();
                        const timer = setTimeout(() => reject('timeout'), 3000);
                        img.onload = () => { clearTimeout(timer); resolve(); };
                        img.onerror = () => { clearTimeout(timer); resolve(); };
                        img.src = `${url}/favicon.ico?_=${Date.now()}`;
                    });
                    const pingTime = Math.round(performance.now() - imgPingStart);
                    return { status: 'online', ping: pingTime };
                } catch (e) {
                    return { status: 'offline', ping: 0 };
                }
            }
        }

        // ==================== RENDERIZAÇÃO ====================
        function render() {
            const grid = document.getElementById('targetsGrid');
            const emptyState = document.getElementById('emptyState');

            if (targets.length === 0) {
                grid.innerHTML = '';
                emptyState.classList.remove('hidden');
                updateStats();
                return;
            }

            emptyState.classList.add('hidden');
            grid.innerHTML = targets.map(target => {
                const isOnline = target.status === 'online';
                const isOffline = target.status === 'offline';
                const isHighPing = isOnline && target.ping > 200;
                
                let badgeClass = 'bg-slate-800/50 text-slate-400 border-slate-700/30';
                let cardBorderClass = 'border-slate-800/60 hover:border-slate-700/60';
                let pingTextColor = 'text-slate-400';
                let statusText = 'VERIFICANDO';
                let dotColor = 'bg-slate-500';

                if (isOnline) {
                    if (isHighPing) {
                        badgeClass = 'bg-rose-500/10 text-rose-300 border-rose-500/20';
                        cardBorderClass = 'border-rose-500/30 bg-rose-950/10';
                        pingTextColor = 'text-rose-300 font-normal';
                        statusText = 'ALTA LATÊNCIA';
                        dotColor = 'bg-rose-400 animate-ping';
                    } else {
                        badgeClass = 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20';
                        cardBorderClass = 'border-slate-800/60 hover:border-emerald-500/30';
                        pingTextColor = 'text-emerald-300';
                        statusText = 'ONLINE';
                        dotColor = 'bg-emerald-400';
                    }
                } else if (isOffline) {
                    badgeClass = 'bg-amber-500/10 text-amber-300 border-amber-500/20';
                    cardBorderClass = 'border-amber-500/30 bg-amber-950/10';
                    pingTextColor = 'text-amber-400';
                    statusText = 'OFFLINE';
                    dotColor = 'bg-amber-400';
                }

                // Mini gráfico de barras (últimos 16 pontos)
                const miniChart = (target.history || []).slice(-16).map(h => {
                    let barBg = 'bg-emerald-400/50 hover:bg-emerald-400';
                    if (h.status === 'offline') {
                        barBg = 'bg-amber-400/60';
                    } else if (h.ping > 200) {
                        barBg = 'bg-rose-400/80 hover:bg-rose-400';
                    }
                    const heightPercent = h.status === 'online' ? Math.min(Math.max((h.ping / 300) * 100, 15), 100) : 100;
                    return `<div class="flex-1 rounded-sm transition-all ${barBg}" style="height: ${heightPercent}%" title="${h.status === 'online' ? h.ping + ' ms' : 'Falha'}"></div>`;
                }).join('');

                return `
                <div class="bg-slate-900/40 border ${cardBorderClass} rounded-2xl p-4 transition-all duration-300 flex flex-col justify-between group cursor-pointer hover:shadow-lg" onclick="updateHostChart(targets.find(t => t.id === '${target.id}'))">
                    <div>
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <div class="truncate">
                                <h3 class="font-normal text-slate-200 text-xs tracking-wide truncate">${escapeHtml(target.name)}</h3>
                                <span class="text-[11px] text-slate-500 mono font-light block mt-0.5">${escapeHtml(target.host)}</span>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-light tracking-wider shrink-0 border ${badgeClass}">
                                <span class="w-1 h-1 rounded-full ${dotColor}"></span>
                                ${statusText}
                            </span>
                        </div>

                        <div class="flex items-baseline justify-between mt-2 mb-2">
                            <span class="text-[11px] text-slate-500 font-light">Latência MS</span>
                            <span class="text-lg font-light ${pingTextColor} mono">
                                ${isOnline ? target.ping + ' <span class="text-[10px] text-slate-500 font-sans">ms</span>' : '—'}
                            </span>
                        </div>

                        <div class="pt-2 border-t border-slate-800/40">
                            <div class="flex justify-between text-[10px] text-slate-500 font-light mb-1">
                                <span>Gráfico de Desempenho (MS)</span>
                                <span class="text-slate-600">${(target.history || []).length} medições</span>
                            </div>
                            <div class="flex items-end gap-1 h-7 bg-slate-950/40 p-1 rounded-lg border border-slate-800/40">
                                ${miniChart || '<span class="text-[10px] text-slate-600 font-light mx-auto">Aguardando medição...</span>'}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-3 pt-2 border-t border-slate-800/40 text-[10px] text-slate-500 font-light">
                        <span>Checado: ${target.lastCheck || '—'}</span>
                        <div class="flex items-center gap-2">
                            <button onclick="event.stopPropagation(); openEditModal('${target.id}')" class="text-slate-500 hover:text-rose-300 transition-colors p-1" title="Editar IP">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button onclick="event.stopPropagation(); removeTarget('${target.id}')" class="text-slate-500 hover:text-rose-400 transition-colors p-1" title="Excluir do Banco">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                </div>
                `;
            }).join('');

            updateStats();
        }

        function updateStats() {
            document.getElementById('statTotal').innerText = targets.length;
            const onlineCount = targets.filter(t => t.status === 'online' && t.ping <= 200).length;
            const highPingCount = targets.filter(t => t.status === 'online' && t.ping > 200).length;
            
            document.getElementById('statOnline').innerText = onlineCount;
            document.getElementById('statHighPing').innerText = highPingCount;

            const onlinePings = targets.filter(t => t.status === 'online' && t.ping > 0).map(t => t.ping);
            const avg = onlinePings.length > 0 ? Math.round(onlinePings.reduce((a, b) => a + b, 0) / onlinePings.length) : 0;
            
            const avgElem = document.getElementById('statAvgPing');
            avgElem.innerText = `${avg} ms`;
            if (avg > 200) {
                avgElem.className = "text-xl font-light text-rose-300 mono";
            } else {
                avgElem.className = "text-xl font-light text-slate-200 mono";
            }
        }

        // ==================== AÇÕES DE PING ====================
        async function pingAllTargets() {
            const icon = document.getElementById('refreshIcon');
            if (icon) icon.classList.add('fa-spin');

            await Promise.all(targets.map(async (target) => {
                const res = await measurePing(target);
                
                target.status = res.status;
                target.ping = res.ping;
                target.lastCheck = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

                if (!target.history) target.history = [];
                target.history.push({ status: res.status, ping: res.ping, time: target.lastCheck });
                
                // Limita histórico a 100 medições
                if (target.history.length > 100) target.history.shift();
            }));

            render();
            updateGlobalChart();

            // Atualiza gráfico do host se estiver aberto
            if (currentHostChartTarget) {
                const updatedTarget = targets.find(t => t.id === currentHostChartTarget.id);
                if (updatedTarget) {
                    updateHostChart(updatedTarget);
                }
            }

            if (icon) icon.classList.remove('fa-spin');
        }

        // ==================== CRUD ====================
        async function handleSaveTarget(e) {
            e.preventDefault();
            const id = document.getElementById('targetId').value;
            const name = document.getElementById('targetName').value.trim();
            const host = document.getElementById('targetHost').value.trim();

            if (!name || !host) return;

            const isEdit = Boolean(id);
            const endpoint = isEdit ? 'index.php?action=update' : 'index.php?action=add';

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: isEdit ? parseInt(id, 10) : undefined, name, host })
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message || (isEdit ? 'Host atualizado no MySQL!' : 'Cadastrado no MySQL com sucesso!'));
                    closeAddModal();
                    fetchDatabaseTargets();
                } else {
                    showToast(data.error || 'Erro ao salvar no banco.');
                }
            } catch (err) {
                showToast('Falha na requisição ao servidor PHP.');
            }
        }

        async function removeTarget(id) {
            const target = targets.find(t => t.id === id);
            if (!target) return;

            showConfirmModal(
                'Confirmar Exclusão',
                `Deseja realmente excluir o host "${target.name}" (${target.host})?`,
                async () => {
                    try {
                        const res = await fetch('index.php?action=delete', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: parseInt(id, 10) })
                        });
                        const data = await res.json();

                        if (data.success) {
                            showToast(data.message || 'Host removido do banco.');
                            fetchDatabaseTargets();
                        } else {
                            showToast(data.error || 'Erro ao remover.');
                        }
                    } catch (err) {
                        showToast('Falha de conexão com o servidor.');
                    }
                }
            );
        }

        async function clearAllTargets() {
            showConfirmModal(
                'Limpar Banco de Dados',
                'Deseja realmente apagar TODOS os registros? Esta ação não pode ser desfeita!',
                async () => {
                    try {
                        const res = await fetch('index.php?action=clear', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-Admin-Token': window.__ADMIN_TOKEN__
                            },
                            body: JSON.stringify({ token: window.__ADMIN_TOKEN__ })
                        });
                        const data = await res.json();

                        if (data.success) {
                            showToast(data.message || 'Banco de dados limpo com sucesso.');
                            fetchDatabaseTargets();
                        } else {
                            showToast(data.error || 'Erro ao limpar banco.');
                        }
                    } catch (err) {
                        showToast('Falha na requisição ao servidor.');
                    }
                }
            );
        }

        // ==================== MODAIS E UI ====================
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Novo Host no Banco de Dados';
            document.getElementById('targetId').value = '';
            document.getElementById('addForm').reset();
            document.getElementById('addModal').classList.remove('hidden');
            document.getElementById('addModal').classList.add('flex');
        }

        function openEditModal(id) {
            const target = targets.find(t => t.id === id);
            if (!target) return;

            document.getElementById('modalTitle').innerText = 'Editar Host no Banco de Dados';
            document.getElementById('targetId').value = target.id;
            document.getElementById('targetName').value = target.name;
            document.getElementById('targetHost').value = target.host;

            document.getElementById('addModal').classList.remove('hidden');
            document.getElementById('addModal').classList.add('flex');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
            document.getElementById('addModal').classList.remove('flex');
            document.getElementById('addForm').reset();
        }

        function showConfirmModal(title, message, callback) {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMessage').textContent = message;
            confirmCallback = callback;
            
            document.getElementById('confirmModal').classList.remove('hidden');
            document.getElementById('confirmModal').classList.add('flex');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
            document.getElementById('confirmModal').classList.remove('flex');
            confirmCallback = null;
        }

        function confirmAction() {
            if (confirmCallback) {
                confirmCallback();
            }
            closeConfirmModal();
        }

        function showToast(message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            
            toast.className = `bg-slate-900 border border-slate-800 text-slate-300 text-xs px-3 py-2 rounded-xl shadow-xl transition-all duration-300 font-light`;
            toast.innerHTML = `<span>${escapeHtml(message)}</span>`;

            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-[-4px]');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function startAutoPing() {
            if (autoPingTimer) clearInterval(autoPingTimer);
            const ms = parseInt(document.getElementById('intervalSelect').value, 10) || 10000;
            autoPingTimer = setInterval(pingAllTargets, ms);
        }

        function updateInterval() {
            startAutoPing();
            showToast('Intervalo de medição alterado.');
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }
    </script>
</body>
</html>
