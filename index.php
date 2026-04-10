<?php
/**
 * Jednoduchá ChatGPT-style aplikace
 * - Čisté PHP + vanilla JS
 * - Google AI Studio API (Gemma)
 * - Historie v chat.json
 */

session_start();

// Unikátní ID pro každou relaci prohlížeče
$isNewSession = false;
if (!isset($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = bin2hex(random_bytes(8));
    $isNewSession = true;
}
$sessionId = $_SESSION['chat_session_id'];

// ============================================================
// KONFIGURACE
// ============================================================

// Načtení .env souboru – lokální vývoj
$dotenv = [];
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $dotenv[trim($key)] = trim($val);
        }
    }
}

// API klíč – priorita: env proměnná > .env soubor
$apiKey = getenv('GOOGLE_AI_API_KEY') ?: ($dotenv['GOOGLE_AI_API_KEY'] ?? '');

// Soubor s historií – zkusí lokální cestu, pokud není zapisovatelná, použije /tmp
$chatFile = __DIR__ . '/chat.json';
if (!is_writable($chatFile) && !is_writable(__DIR__)) {
    $chatFile = '/tmp/chat.json';
}


// Dostupné modely – načti z models.json (jen enabled)
$models = [];
$defaultModel = '';
$modelsData = json_decode(@file_get_contents(__DIR__ . '/models.json'), true) ?: [];
foreach ($modelsData as $modelId => $info) {
    if ($modelId === '_info') continue;
    if (!empty($info['enabled'])) {
        $models[$info['label']] = $modelId;
        if (!empty($info['default'])) {
            $defaultModel = $modelId;
        }
    }
}

// Systémové instrukce pro AI – uprav v souboru system-prompt.txt
$systemInstruction = @file_get_contents(__DIR__ . '/system-prompt.txt') ?: '';

// Načtení tématu – název se bere z env THEME (default: dark)
$themeName = getenv('THEME') ?: ($dotenv['THEME'] ?? 'dark');
$themeName = preg_replace('/[^a-z0-9_\-]/i', '', $themeName); // sanitize
$themeFile = __DIR__ . "/themes/{$themeName}.json";
if (!file_exists($themeFile)) $themeFile = __DIR__ . '/themes/dark.json';
$theme = json_decode(@file_get_contents($themeFile), true) ?: [];

// ============================================================
// POMOCNÉ FUNKCE
// ============================================================

/**
 * Načte historii z JSON souboru. Vrací pole zpráv.
 */
function loadHistory(string $file, ?string $sessionId = null): array
{
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    // Soubor je uložen sestupně (nejnovější nahoře) – obrátíme na chronologické pořadí
    $data = array_reverse($data);
    if ($sessionId !== null) {
        $data = array_values(array_filter($data, function ($msg) use ($sessionId) {
            return ($msg['session'] ?? '') === $sessionId;
        }));
    }
    return $data;
}

/**
 * Uloží historii do JSON souboru.
 */
function saveHistory(string $file, array $history): void
{
    // Uložit sestupně – nejnovější záznamy nahoře
    $desc = array_reverse($history);
    file_put_contents($file, json_encode($desc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Zavolá Google AI Studio (Generative Language) API a vrátí odpověď.
 */
function callGoogleAI(string $apiKey, string $model, array $messages, string $systemInstruction = ''): string
{
    @set_time_limit(180);

    // Google API používá 'user' a 'model' (ne 'assistant')
    $contents = array_map(function ($msg) {
        return [
            'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ];
    }, $messages);

    $body = ['contents' => $contents];

    if ($systemInstruction !== '' && !str_starts_with($model, 'gemma-')) {
        $body['system_instruction'] = [
            'parts' => [['text' => $systemInstruction]],
        ];
    }

    $payload = json_encode($body);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ];

    $context  = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if ($response === false || $response === '') {
        return 'Chyba spojení: nepodařilo se kontaktovat API (timeout nebo síťová chyba).';
    }

    $data = json_decode($response, true);

    // Zjisti HTTP kód z hlaviček
    $httpCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
        $httpCode = (int) $m[0];
    }

    if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'])) {
        $errMsg = $data['error']['message'] ?? 'Neznámá chyba API (HTTP ' . $httpCode . ')';
        return 'Chyba API: ' . $errMsg;
    }

    // Gemini 2.5 může vracet "thought" parts — vezmeme poslední textový part
    $parts = $data['candidates'][0]['content']['parts'];
    $text = '';
    foreach ($parts as $part) {
        if (isset($part['text']) && !isset($part['thought'])) {
            $text = $part['text'];
        }
    }
    // Fallback — pokud všechny parts jsou thoughts, vezmi poslední text
    if ($text === '') {
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (isset($parts[$i]['text'])) {
                $text = $parts[$i]['text'];
                break;
            }
        }
    }

    return $text ?: 'Prázdná odpověď od API.';
}

// ============================================================
// ÚVODNÍ ZPRÁVA PRO NOVOU RELACI
// ============================================================

$greetings = [
    'Ahoj! 👋 Jsem Batelkův AI asistent. Můžeš se mě zeptat na cokoliv – rád pomůžu. Co pro tebe můžu udělat?',
    'Zdravím! ✨ Jsem tady, abych ti pomohl. Na co se chceš zeptat?',
    'Čau! 🤖 Připravený odpovídat. Střílej – co tě zajímá?',
    'Ahoj! 🚀 Vítej v Batelkově AI Chatu. Ptej se na cokoliv, pokusím se pomoct!',
    'Hej! 💡 Jsem tvůj AI pomocník. Napiš mi, s čím ti můžu poradit.',
    'Nazdar! 🎯 Jsem připravený. Co potřebuješ vyřešit?',
    'Zdravíčko! 😊 Jsem Batelkův asistent – zeptej se mě na cokoliv.',
    'Ahoj! 🌟 Rád tě tu vidím. O čem si dnes popovídáme?',
    'Čau! 🔥 Jsem tady pro tebe. Jaký máš dotaz?',
    'Vítej! ⚡ Jsem AI chat od Batelky. Jak ti můžu pomoct?',
];

if ($isNewSession) {
    $allHistory = loadHistory($chatFile);
    $allHistory[] = [
        'role'    => 'assistant',
        'content' => $greetings[array_rand($greetings)],
        'time'    => date('c'),
        'session' => $sessionId,
    ];
    saveHistory($chatFile, $allHistory);
}

// ============================================================
// ADMIN ENDPOINT (?admin=SECRET)
// ============================================================

$adminSecret = getenv('ADMIN_SECRET') ?: ($dotenv['ADMIN_SECRET'] ?? '');

if (isset($_GET['admin']) && $adminSecret !== '' && hash_equals($adminSecret, $_GET['admin'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo file_get_contents($chatFile) ?: '[]';
    exit;
}

// ============================================================
// AJAX ENDPOINTY (POST requesty)
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Potlačit PHP chyby v HTML – nesmí rozbít JSON odpověď
    ini_set('display_errors', '0');
    error_reporting(0);
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

        // Načti celou historii a historii aktuální relace
        $allHistory = loadHistory($chatFile);
        $sessionHistory = loadHistory($chatFile, $sessionId);

        // Metadata o klientovi
        $clientIp  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $lang      = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown';
        $referer   = $_SERVER['HTTP_REFERER'] ?? '';
        $screen    = $input['screen'] ?? 'unknown';
        $timezone  = $input['timezone'] ?? 'unknown';
        $platform  = $input['platform'] ?? 'unknown';

        $userMsg = [
            'role'     => 'user',
            'time'     => date('c'),
            'session'  => $sessionId,
            'model'    => $model,
            'ip'       => $clientIp,
            'ua'       => $userAgent,
            'lang'     => strtok($lang, ','),
            'platform' => $platform,
            'screen'   => $screen,
            'timezone' => $timezone,
            'referer'  => $referer ?: null,
            'content'  => $userMessage,
        ];
        $allHistory[] = $userMsg;
        $sessionHistory[] = $userMsg;

        // Zavolej API jen s kontextem aktuální relace
        $aiResponse = callGoogleAI($apiKey, $model, $sessionHistory, $systemInstruction);

        // Přidej odpověď AI
        $aiMsg = [
            'role'    => 'assistant',
            'time'    => date('c'),
            'session' => $sessionId,
            'model'   => $model,
            'content' => $aiResponse,
        ];
        $allHistory[] = $aiMsg;

        saveHistory($chatFile, $allHistory);

        echo json_encode([
            'reply' => $aiResponse,
            'time'  => $aiMsg['time'],
        ]);
        exit;
    }

    // --- Načtení historie ---
    if ($action === 'history') {
        echo json_encode(loadHistory($chatFile, $sessionId));
        exit;
    }

    // --- Vymazání chatu (jen aktuální relace) ---
    if ($action === 'clear') {
        $allHistory = loadHistory($chatFile);
        $allHistory = array_values(array_filter($allHistory, function ($msg) use ($sessionId) {
            return ($msg['session'] ?? '') !== $sessionId;
        }));
        saveHistory($chatFile, $allHistory);
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

    <!-- SEO -->
    <meta name="description" content="Batelkův AI Chat – jednoduchý chatbot poháněný Google Gemma. Ptej se na cokoliv.">
    <meta name="author" content="Batelka">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="Batelkův AI Chat">
    <meta property="og:description" content="Jednoduchý chatbot poháněný Google Gemma. Ptej se na cokoliv.">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="cs_CZ">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Batelkův AI Chat">
    <meta name="twitter:description" content="Jednoduchý chatbot poháněný Google Gemma. Ptej se na cokoliv.">
    <meta name="theme-color" content="<?= htmlspecialchars($theme['accent-start'] ?? '#6c5ce7') ?>">

    <!-- Favicon (inline SVG) -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='<?= rawurlencode($theme['accent-start'] ?? '#6c5ce7') ?>'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white'>✦</text></svg>">

    <?php
    // Generování CSS custom properties z theme.json
    if ($theme) {
        $skip = ['_info', 'name'];
        echo "<style>:root{";
        foreach ($theme as $key => $value) {
            if (in_array($key, $skip, true)) continue;
            $key = preg_replace('/[^a-z0-9\-]/', '', $key);
            echo "--{$key}:{$value};";
        }
        echo "}</style>\n";
    }
    ?>

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
                    <option value="<?= htmlspecialchars($modelId) ?>"<?= $modelId === $defaultModel ? ' selected' : '' ?>>
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

/** Jednoduchý Markdown → HTML parser (pro AI odpovědi) */
function renderMarkdown(text) {
    let html = escapeHtml(text);

    // Code blocks (```lang\n...\n```) – chráníme před dalším zpracováním
    const codeBlocks = [];
    html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (_, lang, code) => {
        codeBlocks.push('<pre><code class="lang-' + lang + '">' + code + '</code></pre>');
        return '\x00CB' + (codeBlocks.length - 1) + '\x00';
    });

    // Inline code (`...`)
    const inlineCodes = [];
    html = html.replace(/`([^`]+)`/g, (_, code) => {
        inlineCodes.push('<code>' + code + '</code>');
        return '\x00IC' + (inlineCodes.length - 1) + '\x00';
    });

    // Links [text](url)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    // Zpracování řádek po řádcích
    const lines = html.split('\n');
    const result = [];
    let inUl = false, inOl = false, inBlockquote = false;

    for (let line of lines) {
        // Blockquote (&gt; text)
        const bqMatch = line.match(/^&gt;\s?(.*)$/);
        if (bqMatch) {
            if (!inBlockquote) { closeList(); result.push('<blockquote>'); inBlockquote = true; }
            result.push(inlineFormat(bqMatch[1]));
            continue;
        } else if (inBlockquote) {
            result.push('</blockquote>');
            inBlockquote = false;
        }

        // Horizontal rule (---, ***, ___)
        if (/^[-*_]{3,}\s*$/.test(line)) {
            closeList();
            result.push('<hr>');
            continue;
        }

        // Headers
        const h3Match = line.match(/^### (.+)$/);
        const h2Match = line.match(/^## (.+)$/);
        const h1Match = line.match(/^# (.+)$/);
        if (h3Match) { closeList(); result.push('<h4>' + inlineFormat(h3Match[1]) + '</h4>'); continue; }
        if (h2Match) { closeList(); result.push('<h3>' + inlineFormat(h2Match[1]) + '</h3>'); continue; }
        if (h1Match) { closeList(); result.push('<h2>' + inlineFormat(h1Match[1]) + '</h2>'); continue; }

        // Unordered list (*, -, •)
        const ulMatch = line.match(/^\s*[*\-•]\s+(.+)$/);
        if (ulMatch) {
            if (inOl) { result.push('</ol>'); inOl = false; }
            if (!inUl) { result.push('<ul>'); inUl = true; }
            result.push('<li>' + inlineFormat(ulMatch[1]) + '</li>');
            continue;
        }

        // Ordered list (1. item)
        const olMatch = line.match(/^\s*\d+\.\s+(.+)$/);
        if (olMatch) {
            if (inUl) { result.push('</ul>'); inUl = false; }
            if (!inOl) { result.push('<ol>'); inOl = true; }
            result.push('<li>' + inlineFormat(olMatch[1]) + '</li>');
            continue;
        }

        // Normální řádek
        closeList();
        result.push(inlineFormat(line));
    }
    closeList();
    if (inBlockquote) result.push('</blockquote>');
    html = result.join('\n');

    function closeList() {
        if (inUl) { result.push('</ul>'); inUl = false; }
        if (inOl) { result.push('</ol>'); inOl = false; }
    }

    /** Inline formátování (bold, italic, strikethrough) */
    function inlineFormat(s) {
        s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
        s = s.replace(/~~(.+?)~~/g, '<del>$1</del>');
        return s;
    }

    // Line breaks
    html = html.replace(/\n\n/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');
    html = '<p>' + html + '</p>';

    // Clean up paragraphs around block elements
    const block = '(?:h[2-4]|ul|ol|pre|blockquote)';
    html = html.replace(new RegExp('<p>\\s*(<' + block + ')', 'g'), '$1');
    html = html.replace(new RegExp('(</' + block + '>)\\s*</p>', 'g'), '$1');
    html = html.replace(new RegExp('<br>\\s*(<' + block + ')', 'g'), '$1');
    html = html.replace(new RegExp('(</' + block + '>)\\s*<br>', 'g'), '$1');
    // <hr> je void element (nemá closing tag)
    html = html.replace(/<p>\s*(<hr>)/g, '$1');
    html = html.replace(/(<hr>)\s*<\/p>/g, '$1');
    html = html.replace(/<br>\s*(<hr>)/g, '$1');
    html = html.replace(/(<hr>)\s*<br>/g, '$1');
    html = html.replace(/<p><\/p>/g, '');

    // Vrátit code blocky a inline code
    html = html.replace(/\x00CB(\d+)\x00/g, (_, i) => codeBlocks[i]);
    html = html.replace(/\x00IC(\d+)\x00/g, (_, i) => inlineCodes[i]);

    return html;
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

    // AI odpovědi renderuj jako Markdown, uživatelské zprávy jako plain text
    const rendered = role === 'assistant' ? renderMarkdown(content) : escapeHtml(content);
    bubble.innerHTML = rendered
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
    const text = await res.text();
    if (!text) throw new Error('Prázdná odpověď serveru.');
    try { return JSON.parse(text); }
    catch { throw new Error('Neplatná odpověď serveru.'); }
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
            screen: screen.width + 'x' + screen.height,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            platform: navigator.platform || navigator.userAgentData?.platform || 'unknown',
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
