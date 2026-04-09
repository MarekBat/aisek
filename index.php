<?php
/**
 * Jednoduchá ChatGPT-style aplikace
 * - Čisté PHP + vanilla JS
 * - Google AI Studio API (Gemma)
 * - Historie v chat.json
 */

// ============================================================
// KONFIGURACE
// ============================================================

// API klíč – nastav jako env proměnnou GOOGLE_AI_API_KEY,
// nebo vlož přímo sem (méně bezpečné)
$apiKey = getenv('GOOGLE_AI_API_KEY');

// Soubor s historií
$chatFile = __DIR__ . '/chat.json';

// Dostupné modely (label => model ID)
$models = [
    'Gemma 3 27B'  => 'gemma-3-27b-it',
    'Gemma 3 12B'  => 'gemma-3-12b-it',
    'Gemma 3 4B'   => 'gemma-3-4b-it',
];

// ============================================================
// POMOCNÉ FUNKCE
// ============================================================

/**
 * Načte historii z JSON souboru. Vrací pole zpráv.
 */
function loadHistory(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Uloží historii do JSON souboru.
 */
function saveHistory(string $file, array $history): void
{
    file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Zavolá Google AI Studio (Generative Language) API a vrátí odpověď.
 */
function callGoogleAI(string $apiKey, string $model, array $messages): string
{
    // Google API používá 'user' a 'model' (ne 'assistant')
    $contents = array_map(function ($msg) {
        return [
            'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ];
    }, $messages);

    $payload = json_encode([
        'contents' => $contents,
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ];

    $context  = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return 'Chyba spojení: nepodařilo se kontaktovat API.';
    }

    $data = json_decode($response, true);

    // Zjisti HTTP kód z hlaviček
    $httpCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
        $httpCode = (int) $m[0];
    }

    if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $errMsg = $data['error']['message'] ?? 'Neznámá chyba API (HTTP ' . $httpCode . ')';
        return 'Chyba API: ' . $errMsg;
    }

    return $data['candidates'][0]['content']['parts'][0]['text'];
}

// ============================================================
// AJAX ENDPOINTY (POST requesty)
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // --- Odeslání zprávy ---
    if ($action === 'send') {
        $userMessage = trim($input['message'] ?? '');
        $model       = $input['model'] ?? 'gemma-3-27b-it';

        // Validace modelu – povolíme jen známé
        $allowedModels = array_values($models);
        if (!in_array($model, $allowedModels, true)) {
            $model = $allowedModels[0];
        }

        if ($userMessage === '') {
            echo json_encode(['error' => 'Prázdná zpráva.']);
            exit;
        }

        // Načti historii, přidej uživatelovu zprávu
        $history = loadHistory($chatFile);
        $history[] = [
            'role'    => 'user',
            'content' => $userMessage,
            'time'    => date('c'),
        ];

        // Zavolej API s celou historií (kontext)
        $aiResponse = callGoogleAI($apiKey, $model, $history);

        // Přidej odpověď AI
        $history[] = [
            'role'    => 'assistant',
            'content' => $aiResponse,
            'time'    => date('c'),
        ];

        saveHistory($chatFile, $history);

        echo json_encode([
            'reply' => $aiResponse,
            'time'  => end($history)['time'],
        ]);
        exit;
    }

    // --- Načtení historie ---
    if ($action === 'history') {
        echo json_encode(loadHistory($chatFile));
        exit;
    }

    // --- Vymazání chatu ---
    if ($action === 'clear') {
        saveHistory($chatFile, []);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Neznámá akce.']);
    exit;
}

// ============================================================
// HTML + CSS + JS (GET request)
// ============================================================
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batelkův AI Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="chat-container">
    <!-- Hlavička -->
    <div class="chat-header">
        <div class="header-brand">
            <div class="logo">#</div>
            <h1>Batelkův AI <span>Chat</span></h1>
        </div>
        <div class="header-controls">
            <select class="model-select" id="modelSelect">
                <?php foreach ($models as $label => $modelId): ?>
                    <option value="<?= htmlspecialchars($modelId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn-clear" id="btnClear" title="Vymazat chat">✕ Smazat</button>
        </div>
    </div>

    <!-- Zprávy -->
    <div class="chat-messages" id="chatMessages">
        <div class="empty-state" id="emptyState">
            <div class="empty-icon">✦</div>
            <p>Začni konverzaci</p>
            <small>Shift + Enter pro nový řádek</small>
        </div>
    </div>

    <!-- Typing indikátor -->
    <div class="typing-row" id="typingIndicator">
        <div class="msg-avatar" style="background:linear-gradient(135deg,#6c5ce7,#a855f7);box-shadow:0 2px 10px rgba(108,92,231,.3)">✦</div>
        <div class="typing-bubble">
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
        </div>
    </div>

    <!-- Vstup -->
    <div class="chat-input-area">
        <div class="input-wrapper">
            <textarea id="userInput" placeholder="Napiš zprávu…" rows="1"></textarea>
        </div>
        <button class="btn-send" id="btnSend">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            Odeslat
        </button>
    </div>
</div>

<script>
// ====== ELEMENTY ======
const chatMessages    = document.getElementById('chatMessages');
const emptyState      = document.getElementById('emptyState');
const typingIndicator = document.getElementById('typingIndicator');
const userInput       = document.getElementById('userInput');
const btnSend         = document.getElementById('btnSend');
const btnClear        = document.getElementById('btnClear');
const modelSelect     = document.getElementById('modelSelect');

// ====== POMOCNÉ FUNKCE ======

/** Escapuje HTML entity (prevence XSS na klientu) */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/** Formátuje ISO timestamp na hh:mm */
function formatTime(iso) {
    const d = new Date(iso);
    return d.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
}

/** Scrollne chat dolů */
function scrollToBottom() {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

/** Přidá bublinu do chatu */
function addBubble(role, content, time) {
    emptyState.style.display = 'none';

    // Řádek: avatar + bublina
    const row = document.createElement('div');
    row.className = 'msg-row ' + role;

    const avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.textContent = role === 'user' ? '👤' : '✦';

    const bubble = document.createElement('div');
    bubble.className = 'message ' + role;
    bubble.innerHTML = escapeHtml(content)
        + '<span class="msg-time">' + (time ? formatTime(time) : '') + '</span>';

    row.appendChild(avatar);
    row.appendChild(bubble);
    chatMessages.appendChild(row);
    scrollToBottom();
}

/** Zobrazí / skryje typing indikátor */
function setTyping(visible) {
    typingIndicator.classList.toggle('visible', visible);
    if (visible) {
        chatMessages.appendChild(typingIndicator);
        scrollToBottom();
    }
}

/** Zamkne / odemkne odesílání */
function setLocked(locked) {
    btnSend.disabled = locked;
    userInput.disabled = locked;
}

// ====== AJAX VOLÁNÍ ======

async function apiCall(action, extra = {}) {
    const res = await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...extra }),
    });
    return res.json();
}

// ====== ODESLÁNÍ ZPRÁVY ======

async function sendMessage() {
    const text = userInput.value.trim();
    if (!text) return;

    // Zobraz uživatelovu bublinu, vyčisti input
    const now = new Date().toISOString();
    addBubble('user', text, now);
    userInput.value = '';
    autoResize();

    setLocked(true);
    setTyping(true);

    try {
        const data = await apiCall('send', {
            message: text,
            model: modelSelect.value,
        });

        setTyping(false);

        if (data.error) {
            addBubble('assistant', '⚠️ ' + data.error, new Date().toISOString());
        } else {
            addBubble('assistant', data.reply, data.time);
        }
    } catch (err) {
        setTyping(false);
        addBubble('assistant', '⚠️ Chyba sítě: ' + err.message, new Date().toISOString());
    }

    setLocked(false);
    userInput.focus();
}

// ====== NAČTENÍ HISTORIE ======

async function loadHistory() {
    try {
        const history = await apiCall('history');
        if (Array.isArray(history) && history.length > 0) {
            history.forEach(msg => addBubble(msg.role, msg.content, msg.time));
        }
    } catch (e) {
        // Tiché selhání – historie prostě nebude
    }
}

// ====== VYMAZÁNÍ CHATU ======

async function clearChat() {
    if (!confirm('Opravdu smazat celou konverzaci?')) return;

    await apiCall('clear');
    chatMessages.innerHTML = '';
    emptyState.style.display = '';
    chatMessages.appendChild(emptyState);
}

// ====== AUTO-RESIZE TEXTAREA ======

function autoResize() {
    userInput.style.height = 'auto';
    userInput.style.height = Math.min(userInput.scrollHeight, 120) + 'px';
}

// ====== EVENT LISTENERY ======

btnSend.addEventListener('click', sendMessage);
btnClear.addEventListener('click', clearChat);

userInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

userInput.addEventListener('input', autoResize);

// ====== INIT ======
loadHistory();
userInput.focus();
</script>

</body>
</html>
