<?php
/**
 * nexus.php — Routeur web NEXUS UNIFIED v2 (Digital Factory)
 * S'appuie sur nexus_core.php (cœur partagé web + CLI). Auth + contexte projet + SSE.
 */
require __DIR__ . '/nexus_core.php';
session_start();

function isAuthed(): bool { return !empty($_SESSION['nexus_ok']); }
function requireAuth(): void { if (!isAuthed()) { echo json_encode(['ok' => false, 'error' => 'auth', 'need_login' => true]); exit; } }

// Projet actif (cloisonnement par base). Crée un projet par défaut au besoin.
function currentProject(): string {
    $p = $_SESSION['project'] ?? '';
    if ($p && loadProjectConfig($p)) return $p;
    $list = listProjects();
    if ($list) { $_SESSION['project'] = $list[0]['key']; return $list[0]['key']; }
    $k = createProject('Projet par défaut', 'Général', 'Bac à sable');
    $_SESSION['project'] = $k; return $k;
}

function sseInit(): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0');
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform'); header('X-Accel-Buffering: no'); header('Connection: keep-alive');
    @ob_implicit_flush(true);
}
function sseSend(array $o): void { echo 'data: ' . json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"; @flush(); }

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');
$SSE_ACTIONS = ['chat_stream', 'synthesize', 'web_research', 'agent_run', 'job_watch'];
$isSse = in_array($action, $SSE_ACTIONS, true);

if (!$action) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . (isAuthed() ? '/index.html' : '/login.html'));
    exit;
}
if (!$isSse) header('Content-Type: application/json; charset=utf-8');

if ($action === 'login') {
    $ok = hash_equals(NEXUS_PASSWORD, (string)($input['password'] ?? ''));
    if ($ok) { session_regenerate_id(true); $_SESSION['nexus_ok'] = true; echo json_encode(['ok' => true]); }
    else { usleep(400000); echo json_encode(['ok' => false, 'error' => 'Mot de passe incorrect']); }
    exit;
}
if ($action === 'logout') { $_SESSION = []; session_destroy(); echo json_encode(['ok' => true]); exit; }
if ($action === 'auth_status') { echo json_encode(['ok' => true, 'authed' => isAuthed()]); exit; }

if (!$isSse) requireAuth();
else { if (!isAuthed()) { http_response_code(403); exit; } sseInit(); }

try {
    if ($action === 'models') { echo json_encode(['ok' => true, 'models' => MODELS]); exit; }

    // ── PROJETS ────────────────────────────────────────────────────────────
    if ($action === 'projects_list') {
        echo json_encode(['ok' => true, 'projects' => listProjects(), 'active' => currentProject()]); exit;
    }
    if ($action === 'project_create') {
        $name = trim($input['name'] ?? ''); if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Nom requis']); exit; }
        $key = createProject($name, trim($input['sector'] ?? ''), trim($input['goal'] ?? ''));
        $_SESSION['project'] = $key;
        echo json_encode(['ok' => true, 'key' => $key, 'projects' => listProjects(), 'active' => $key]); exit;
    }
    if ($action === 'project_switch') {
        $key = $input['key'] ?? '';
        if (!loadProjectConfig($key)) { echo json_encode(['ok' => false, 'error' => 'Projet introuvable']); exit; }
        $_SESSION['project'] = $key;
        echo json_encode(['ok' => true, 'active' => $key]); exit;
    }
    if ($action === 'project_active') {
        $key = currentProject(); $cfg = loadProjectConfig($key);
        $db = getProjectDB($key);
        $spentUsd = projectSpentUsd($db);
        $rate = (float)($cfg['budget']['usd_to_chf'] ?? 0.90);
        echo json_encode(['ok' => true, 'key' => $key, 'config' => [
            'name' => $cfg['name'] ?? $key, 'sector' => $cfg['sector'] ?? '', 'goal' => $cfg['goal'] ?? '',
            'default_model' => $cfg['default_model'] ?? 'mistralai/mistral-small-2603',
            'db_type' => $cfg['db']['type'] ?? 'sqlite',
            'budget_max_chf' => (float)($cfg['budget']['max_chf'] ?? 0),
            'spent_chf' => round($spentUsd * $rate, 4), 'spent_usd' => round($spentUsd, 5),
        ]]); exit;
    }

    // À partir d'ici : tout est cloisonné dans la base du projet actif.
    $project = currentProject();
    $cfg = loadProjectConfig($project);
    $db = getProjectDB($project);
    $defaultModel = $cfg['default_model'] ?? 'mistralai/mistral-small-2603';
    $ua = $cfg['scraping']['user_agent'] ?? '';

    if ($action === 'credits') {
        $h = ['Authorization: Bearer ' . getMistralKey()];
        $r = curlGet('https://openrouter.ai/api/v1/credits', $h, 15);
        $d = json_decode($r['body'], true);
        if ($r['status'] === 200 && isset($d['data']['total_credits'])) {
            $tot = (float)$d['data']['total_credits']; $used = (float)($d['data']['total_usage'] ?? 0);
            echo json_encode(['ok' => true, 'source' => 'credits', 'total' => $tot, 'used' => $used, 'remaining' => $tot - $used]); exit;
        }
        $r2 = curlGet('https://openrouter.ai/api/v1/key', $h, 15); $d2 = json_decode($r2['body'], true);
        if ($r2['status'] === 200 && isset($d2['data'])) {
            $used = (float)($d2['data']['usage'] ?? 0); $limit = $d2['data']['limit'];
            echo json_encode(['ok' => true, 'source' => 'key', 'used' => $used, 'limit' => $limit, 'remaining' => $limit !== null ? ((float)$limit - $used) : null]); exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Solde illisible']); exit;
    }
    if ($action === 'logs') {
        $stmt = $db->prepare("SELECT * FROM logs ORDER BY id DESC LIMIT " . min(300, (int)($input['limit'] ?? 150)));
        $stmt->execute(); echo json_encode(['ok' => true, 'logs' => $stmt->fetchAll()]); exit;
    }
    if ($action === 'clear_logs') { $db->exec("DELETE FROM logs"); echo json_encode(['ok' => true]); exit; }

    // ── SESSIONS (cloisonnées par base de projet) ───────────────────────────
    if ($action === 'sessions') {
        $rows = $db->query("SELECT id, session_key, title, model, system_prompt, temperature, max_tokens, created_at, updated_at FROM sessions ORDER BY updated_at DESC LIMIT 100")->fetchAll();
        echo json_encode(['ok' => true, 'sessions' => $rows]); exit;
    }
    if ($action === 'new_session') {
        $key = bin2hex(random_bytes(8));
        $db->prepare("INSERT INTO sessions (session_key, title, model) VALUES (?,?,?)")->execute([$key, $input['title'] ?? 'Nouvelle conversation', $input['model'] ?? $defaultModel]);
        echo json_encode(['ok' => true, 'session' => $key]); exit;
    }
    if ($action === 'delete_session') { $db->prepare("DELETE FROM sessions WHERE session_key = ?")->execute([$input['session'] ?? '']); echo json_encode(['ok' => true]); exit; }
    if ($action === 'rename_session') { $db->prepare("UPDATE sessions SET title = ? WHERE session_key = ?")->execute([mb_substr(trim($input['title'] ?? 'Sans titre'),0,80), $input['session'] ?? '']); echo json_encode(['ok' => true]); exit; }
    if ($action === 'session_settings') {
        $db->prepare("UPDATE sessions SET system_prompt=?, temperature=?, max_tokens=?, model=? WHERE session_key=?")->execute([
            (string)($input['system_prompt'] ?? ''), (float)($input['temperature'] ?? 0.7),
            max(256, min(8000, (int)($input['max_tokens'] ?? 1800))), $input['model'] ?? $defaultModel, $input['session'] ?? '']);
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'load_session') {
        $stmt = $db->prepare("SELECT * FROM sessions WHERE session_key = ? LIMIT 1"); $stmt->execute([$input['session'] ?? '']);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => true, 'conversation' => [], 'settings' => null]); exit; }
        echo json_encode(['ok' => true, 'conversation' => json_decode($row['conversation'], true) ?: [],
            'settings' => ['system_prompt' => $row['system_prompt'], 'temperature' => (float)$row['temperature'], 'max_tokens' => (int)$row['max_tokens'], 'model' => $row['model']]]); exit;
    }

    $ownedSession = function (string $key) use ($db, $isSse) {
        $stmt = $db->prepare("SELECT * FROM sessions WHERE session_key = ? LIMIT 1"); $stmt->execute([$key]);
        $s = $stmt->fetch();
        if (!$s) { if ($isSse) sseSend(['t' => 'err', 'm' => 'Session introuvable']); else echo json_encode(['ok' => false, 'error' => 'Session introuvable']); exit; }
        return $s;
    };

    // ── RECHERCHE / SCRAPE / BULK ───────────────────────────────────────────
    if ($action === 'search') {
        $q = trim($input['query'] ?? ''); if (!$q) { echo json_encode(['ok' => false, 'error' => 'Requête vide']); exit; }
        $start = microtime(true);
        $results = unifiedSearch($q, $input['sources'] ?? ['web','wikipedia_fr'], $ua);
        $ms = (int)((microtime(true) - $start) * 1000);
        logAction($db, 'search', 'unified', '', $ms, 'ok', implode(',', $input['sources'] ?? []), $q, count($results) . ' résultats');
        $grouped = []; foreach ($results as $r) $grouped[$r['source']][] = $r;
        echo json_encode(['ok' => true, 'results' => $results, 'grouped' => $grouped, 'total' => count($results), 'ms' => $ms]); exit;
    }
    if ($action === 'scrape') {
        $url = trim($input['url'] ?? '');
        if (!isSafeUrl($url)) { echo json_encode(['ok' => false, 'error' => 'URL refusée (cible interne/réservée)']); exit; }
        politeJitter($cfg);
        $start = microtime(true);
        $res = safeCurlGet($url, 18, $ua);
        if ($res['error'] || $res['status'] < 200 || $res['status'] >= 400) { echo json_encode(['ok' => false, 'error' => 'HTTP ' . $res['status'] . ' ' . $res['error']]); exit; }
        $text = htmlToText($res['body']);
        if (mb_strlen($text) < 80) { echo json_encode(['ok' => false, 'error' => 'Contenu trop court']); exit; }
        $ai = callMistral([
            ['role' => 'system', 'content' => "Analyse ce contenu web. Réponds UNIQUEMENT en JSON: {\"summary\":\"3-5 phrases\",\"topics\":[],\"questions\":[],\"entities\":[]}"],
            ['role' => 'user', 'content' => "URL: $url\n---\n" . mb_substr($text, 0, 6000)],
        ], $defaultModel, 900, 0.5, $project . ':scrape', true, 86400);
        $ms = (int)((microtime(true) - $start) * 1000);
        if ($ai['ok']) logCost($db, $ai['usage'] ?? [], $defaultModel, 'eclaireur');
        $p = $ai['ok'] ? parseJsonRobust($ai['content']) : null;
        if (!$p || !isset($p['summary'])) $p = ['summary' => $ai['content'] ?? 'Analyse échouée', 'topics' => [], 'questions' => [], 'entities' => []];
        logAction($db, 'scrape', 'ok', $defaultModel, $ms, 'ok', '', $url, $p['summary']);
        echo json_encode(['ok' => true, 'url' => $url, 'summary' => $p['summary'], 'topics' => $p['topics'] ?? [], 'questions' => $p['questions'] ?? [], 'entities' => $p['entities'] ?? [], 'text_length' => mb_strlen($text), 'ms' => $ms]); exit;
    }
    if ($action === 'bulk') {
        $qs = array_values(array_filter(array_map('trim', $input['questions'] ?? []), fn($q) => $q !== ''));
        if (!$qs) { echo json_encode(['ok' => false, 'error' => 'Aucune question']); exit; }
        $model = $input['model'] ?? $defaultModel;
        $sys = trim($input['system_prompt'] ?? 'Réponds de manière concise.');
        $start = microtime(true); $reqs = [];
        foreach ($qs as $q) $reqs[] = ['url' => MISTRAL_ENDPOINT, 'headers' => orHeaders('', true, 86400), 'payload' => ['model' => $model, 'max_tokens' => 800, 'temperature' => 0.7, 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $q]], 'usage' => ['include' => true]]];
        $resp = curlMultiPostJson($reqs, 120); $results = [];
        foreach ($qs as $i => $q) {
            $d = json_decode($resp[$i]['body'] ?? '', true); $st = $resp[$i]['status'] ?? 0;
            $ansr = $d['choices'][0]['message']['content'] ?? null; $okq = ($st === 200 && $ansr);
            if ($okq) logCost($db, $d['usage'] ?? [], $model, 'bulk');
            $results[] = ['index' => $i, 'question' => $q, 'answer' => $okq ? $ansr : ('ERREUR HTTP ' . $st), 'model' => $model, 'ok' => $okq];
        }
        $ms = (int)((microtime(true) - $start) * 1000);
        logAction($db, 'bulk', 'complete', $model, $ms, 'ok', '', count($qs) . ' questions', count($results) . ' réponses');
        echo json_encode(['ok' => true, 'results' => $results, 'ms' => $ms]); exit;
    }

    // ── CHAT STREAMING (SSE) ────────────────────────────────────────────────
    if ($action === 'chat_stream') {
        $sessKey = trim($input['session'] ?? ''); $message = trim($input['message'] ?? '');
        $model = $input['model'] ?? $defaultModel; $orchestrator = !empty($input['orchestrator']);
        $agentId = $input['agent_id'] ?? null; $image = $input['image'] ?? null;
        $docText = trim((string)($input['doc_text'] ?? '')); $docName = trim((string)($input['doc_name'] ?? ''));
        if (!$sessKey || (!$message && !$image)) { sseSend(['t' => 'err', 'm' => 'Session/message manquant']); exit; }
        $sess = $ownedSession($sessKey);
        $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        $systemContent = !empty($sess['system_prompt']) ? $sess['system_prompt'] : "Tu es NEXUS, assistant IA du projet « " . ($cfg['name'] ?? '') . " ». Réponds en français, en markdown.";
        if ($orchestrator) $systemContent .= "\n\nTu peux proposer des actions en fin de réponse :\n[[[ACTIONS]]]\n{\"actions\":[{\"type\":\"web_research\",\"query\":\"...\",\"sources\":[\"web\"],\"label\":\"...\"},{\"type\":\"search\",\"query\":\"...\",\"sources\":[\"web\"],\"label\":\"...\"},{\"type\":\"scrape\",\"url\":\"...\",\"label\":\"...\"}]}\n[[[/ACTIONS]]]\nN'inclus ce bloc QUE s'il est pertinent.";
        $temp = (float)($sess['temperature'] ?? 0.7); $maxTok = (int)($sess['max_tokens'] ?? 1800);
        if ($agentId) { $a = $db->prepare("SELECT * FROM agents WHERE id = ?"); $a->execute([$agentId]); $ag = $a->fetch();
            if ($ag && !empty($ag['system_prompt'])) { $systemContent = $ag['system_prompt']; if (!empty($ag['model'])) $model = $ag['model']; if (isset($ag['temperature'])) $temp = (float)$ag['temperature']; } }
        $messages = [['role' => 'system', 'content' => $systemContent]];
        $hist = $conversation; if (MAX_CONTEXT_MESSAGES > 0 && count($hist) > MAX_CONTEXT_MESSAGES) $hist = array_slice($hist, -MAX_CONTEXT_MESSAGES);
        foreach ($hist as $m) $messages[] = $m;
        $userText = $docText !== '' ? ("Document « " . ($docName ?: 'doc') . " » :\n---\n" . mb_substr($docText, 0, 30000) . "\n---\n\n" . $message) : $message;
        $modelUsed = $model;
        if ($image) { if (!preg_match('/pixtral|mistral-(small|medium|large)/i', $model)) $modelUsed = 'mistralai/pixtral-12b-2409';
            $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $userText ?: 'Décris cette image.'], ['type' => 'image_url', 'image_url' => ['url' => $image]]]];
        } else { $messages[] = ['role' => 'user', 'content' => $userText]; }
        $ai = callMistralStream($messages, $modelUsed, $maxTok, $temp, fn($d) => sseSend(['t' => 'd', 'c' => $d]), $project . ':' . $sessKey);
        if (!$ai['ok']) { sseSend(['t' => 'err', 'm' => $ai['error']]); exit; }
        logCost($db, $ai['usage'] ?? [], $modelUsed, 'chat');
        $answer = $ai['content']; $actions = [];
        if (preg_match('/\[\[\[ACTIONS\]\]\]([\s\S]*?)\[\[\[\/ACTIONS\]\]\]/', $answer, $mm)) { $p = parseJsonRobust($mm[1]); if ($p && isset($p['actions'])) $actions = $p['actions']; $answer = trim(str_replace($mm[0], '', $answer)); }
        $conversation[] = ['role' => 'user', 'content' => ($image ? '🖼️ [image] ' : '') . ($docName ? '📎 [' . $docName . '] ' : '') . $message];
        $conversation[] = ['role' => 'assistant', 'content' => $answer];
        $title = $sess['title'] === 'Nouvelle conversation' ? mb_substr($message ?: 'Image', 0, 50) : $sess['title'];
        $db->prepare("UPDATE sessions SET conversation=?, title=?, model=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?")->execute([json_encode($conversation, JSON_UNESCAPED_UNICODE), $title, $modelUsed, $sessKey]);
        sseSend(['t' => 'end', 'answer' => $answer, 'actions' => $actions, 'model' => $modelUsed, 'usage' => $ai['usage'] ?? [], 'ms' => $ai['ms'], 'title' => $title]); exit;
    }

    // ── WEB_RESEARCH (SSE) : recherche -> lecture -> synthèse citée ──────────
    if ($action === 'web_research') {
        $sessKey = trim($input['session'] ?? ''); $query = trim($input['query'] ?? '');
        $model = $input['model'] ?? $defaultModel; $sources = $input['sources'] ?? ['web','wikipedia_fr','google_news'];
        if (!$sessKey || !$query) { sseSend(['t' => 'err', 'm' => 'Session/requête manquante']); exit; }
        $sess = $ownedSession($sessKey); $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: []; $start = microtime(true);
        sseSend(['t' => 'status', 'm' => 'Recherche sur ' . implode(', ', $sources) . '…']);
        $results = unifiedSearch($query, $sources, $ua);
        if (!$results) { sseSend(['t' => 'err', 'm' => 'Aucun résultat']); exit; }
        sseSend(['t' => 'status', 'm' => 'Lecture des pages…']);
        $toScrape = [];
        foreach ($results as $r) { if (!empty($r['url']) && isSafeUrl($r['url']) && in_array(($r['source'] ?? ''), ['web','wikipedia_fr','wikipedia_en','google_news'], true)) $toScrape[$r['url']] = $r['url']; if (count($toScrape) >= 4) break; }
        if ($toScrape) { $bodies = curlMultiGet($toScrape, 14, $ua);
            foreach ($results as &$r) { if (!empty($r['url']) && !empty($bodies[$r['url']])) { $f = htmlToText($bodies[$r['url']], 2500); if (mb_strlen($f) > mb_strlen($r['snippet'] ?? '')) $r['snippet'] = $f; } } unset($r); }
        $built = buildSourcesContext($results, 2200);
        sseSend(['t' => 'sources', 'sources' => $built['sources']]);
        sseSend(['t' => 'status', 'm' => 'Synthèse…']);
        $ai = callMistralStream([
            ['role' => 'system', 'content' => "Tu es NEXUS. À partir des SOURCES numérotées, réponds en français (markdown), cite chaque affirmation avec [n]. N'invente aucune source."],
            ['role' => 'user', 'content' => "Question : $query\n\nSOURCES :\n" . $built['context']],
        ], $model, (int)($sess['max_tokens'] ?? 1800), 0.4, fn($d) => sseSend(['t' => 'd', 'c' => $d]), $project . ':' . $sessKey);
        if (!$ai['ok']) { sseSend(['t' => 'err', 'm' => $ai['error']]); exit; }
        logCost($db, $ai['usage'] ?? [], $model, 'eclaireur');
        $srcMd = "\n\n**Sources :**\n"; foreach ($built['sources'] as $s) $srcMd .= '[' . $s['n'] . '] [' . $s['title'] . '](' . $s['url'] . ")\n";
        $answer = $ai['content'] . $srcMd;
        $conversation[] = ['role' => 'user', 'content' => '🔎 Recherche web : ' . $query];
        $conversation[] = ['role' => 'assistant', 'content' => $answer];
        $db->prepare("UPDATE sessions SET conversation=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?")->execute([json_encode($conversation, JSON_UNESCAPED_UNICODE), $sessKey]);
        $ms = (int)((microtime(true) - $start) * 1000);
        logAction($db, 'research', 'complete', $model, $ms, 'ok', implode(',', $sources), $query, count($built['sources']) . ' sources');
        sseSend(['t' => 'end', 'answer' => $answer, 'sources' => $built['sources'], 'model' => $model, 'usage' => $ai['usage'] ?? [], 'ms' => $ms]); exit;
    }

    // ── SYNTHESIZE (SSE) ────────────────────────────────────────────────────
    if ($action === 'synthesize') {
        $sessKey = trim($input['session'] ?? ''); $model = $input['model'] ?? $defaultModel;
        $context = trim((string)($input['context'] ?? '')); $instruction = trim((string)($input['instruction'] ?? 'Synthétise.'));
        $label = trim((string)($input['label'] ?? '')); $sources = $input['sources'] ?? [];
        if (!$sessKey || $context === '') { sseSend(['t' => 'err', 'm' => 'Contexte manquant']); exit; }
        $sess = $ownedSession($sessKey); $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        $ai = callMistralStream([
            ['role' => 'system', 'content' => "Tu es NEXUS. Synthétise en français (markdown). Si des sources [n] sont présentes, cite-les. N'invente rien."],
            ['role' => 'user', 'content' => $instruction . "\n\nINFORMATIONS :\n" . mb_substr($context, 0, 14000)],
        ], $model, (int)($sess['max_tokens'] ?? 1800), 0.45, fn($d) => sseSend(['t' => 'd', 'c' => $d]), $project . ':' . $sessKey);
        if (!$ai['ok']) { sseSend(['t' => 'err', 'm' => $ai['error']]); exit; }
        logCost($db, $ai['usage'] ?? [], $model, 'comptable');
        $answer = $ai['content'];
        if ($sources) { $answer .= "\n\n**Sources :**\n"; foreach ($sources as $i => $s) $answer .= '[' . ($s['n'] ?? $i+1) . '] [' . ($s['title'] ?? $s['url']) . '](' . ($s['url'] ?? '') . ")\n"; }
        $conversation[] = ['role' => 'assistant', 'content' => ($label ? '*' . $label . "*\n\n" : '') . $answer];
        $db->prepare("UPDATE sessions SET conversation=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?")->execute([json_encode($conversation, JSON_UNESCAPED_UNICODE), $sessKey]);
        sseSend(['t' => 'end', 'answer' => $answer, 'model' => $model, 'usage' => $ai['usage'] ?? [], 'ms' => 0]); exit;
    }

    // ── CHAT non-stream (repli) ─────────────────────────────────────────────
    if ($action === 'chat') {
        $sessKey = trim($input['session'] ?? ''); $message = trim($input['message'] ?? '');
        $model = $input['model'] ?? $defaultModel; $image = $input['image'] ?? null;
        if (!$sessKey || (!$message && !$image)) { echo json_encode(['ok' => false, 'error' => 'Session/message manquant']); exit; }
        $sess = $ownedSession($sessKey); $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        $sys = !empty($sess['system_prompt']) ? $sess['system_prompt'] : "Tu es NEXUS. Réponds en français, en markdown.";
        $messages = [['role' => 'system', 'content' => $sys]]; foreach ($conversation as $m) $messages[] = $m;
        $modelUsed = $model;
        if ($image) { if (!preg_match('/pixtral|mistral-(small|medium|large)/i', $model)) $modelUsed = 'mistralai/pixtral-12b-2409';
            $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $message ?: 'Décris cette image.'], ['type' => 'image_url', 'image_url' => ['url' => $image]]]]; }
        else { $messages[] = ['role' => 'user', 'content' => $message]; }
        $ai = callMistral($messages, $modelUsed, (int)($sess['max_tokens'] ?? 1800), (float)($sess['temperature'] ?? 0.7), $project . ':' . $sessKey);
        if (!$ai['ok']) { echo json_encode(['ok' => false, 'error' => $ai['error']]); exit; }
        logCost($db, $ai['usage'] ?? [], $modelUsed, 'chat');
        $answer = $ai['content'];
        $conversation[] = ['role' => 'user', 'content' => ($image ? '🖼️ [image] ' : '') . $message];
        $conversation[] = ['role' => 'assistant', 'content' => $answer];
        $title = $sess['title'] === 'Nouvelle conversation' ? mb_substr($message ?: 'Image', 0, 50) : $sess['title'];
        $db->prepare("UPDATE sessions SET conversation=?, title=?, model=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?")->execute([json_encode($conversation, JSON_UNESCAPED_UNICODE), $title, $modelUsed, $sessKey]);
        echo json_encode(['ok' => true, 'answer' => $answer, 'model' => $modelUsed, 'ms' => $ai['ms'], 'usage' => $ai['usage'] ?? [], 'title' => $title]); exit;
    }
    if ($action === 'edit_message') {
        $sessKey = trim($input['session'] ?? ''); $index = (int)($input['index'] ?? -1);
        $sess = $ownedSession($sessKey); $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        if ($index < 0 || $index >= count($conversation)) { echo json_encode(['ok' => false, 'error' => 'Index invalide']); exit; }
        $conversation = array_slice($conversation, 0, $index);
        $db->prepare("UPDATE sessions SET conversation=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?")->execute([json_encode($conversation, JSON_UNESCAPED_UNICODE), $sessKey]);
        echo json_encode(['ok' => true, 'conversation' => $conversation]); exit;
    }
    if ($action === 'regenerate') {
        $sessKey = trim($input['session'] ?? ''); $sess = $ownedSession($sessKey);
        $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        while (!empty($conversation) && end($conversation)['role'] === 'assistant') array_pop($conversation);
        $lastUser = '';
        for ($i = count($conversation) - 1; $i >= 0; $i--) if ($conversation[$i]['role'] === 'user') { $lastUser = $conversation[$i]['content']; break; }
        if (!empty($conversation) && end($conversation)['role'] === 'user') array_pop($conversation);
        $db->prepare("UPDATE sessions SET conversation=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?")->execute([json_encode($conversation, JSON_UNESCAPED_UNICODE), $sessKey]);
        echo json_encode(['ok' => true, 'last_user' => $lastUser, 'conversation' => $conversation]); exit;
    }
    if ($action === 'upload_doc') {
        $data = (string)($input['data'] ?? ''); $name = trim((string)($input['name'] ?? 'document'));
        if ($data === '') { echo json_encode(['ok' => false, 'error' => 'Fichier manquant']); exit; }
        $r = extractDocumentText($data, $name);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        logAction($db, 'upload', 'doc', '', 0, 'ok', '', $name, mb_strlen($r['text']) . ' car.');
        echo json_encode(['ok' => true, 'name' => $name, 'text' => $r['text'], 'chars' => mb_strlen($r['text']), 'note' => $r['note'] ?? '']); exit;
    }

    // ── AGENTS (ad hoc, par projet) ─────────────────────────────────────────
    if ($action === 'agents_list') { echo json_encode(['ok' => true, 'agents' => $db->query("SELECT * FROM agents ORDER BY created_at DESC")->fetchAll()]); exit; }
    if ($action === 'agents_save') { $db->prepare("INSERT INTO agents (name, role, system_prompt, model, temperature) VALUES (?,?,?,?,?)")->execute([trim($input['name'] ?? ''), trim($input['role'] ?? ''), trim($input['system_prompt'] ?? ''), $input['model'] ?? $defaultModel, (float)($input['temperature'] ?? 0.7)]); echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]); exit; }
    if ($action === 'agents_delete') { $db->prepare("DELETE FROM agents WHERE id = ?")->execute([(int)($input['id'] ?? 0)]); echo json_encode(['ok' => true]); exit; }

    // ── STATS (projet actif) ────────────────────────────────────────────────
    if ($action === 'stats') {
        $rate = (float)($cfg['budget']['usd_to_chf'] ?? 0.90); $spentUsd = projectSpentUsd($db);
        echo json_encode(['ok' => true,
            'sessions' => (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
            'logs' => (int)$db->query("SELECT COUNT(*) FROM logs")->fetchColumn(),
            'agents' => (int)$db->query("SELECT COUNT(*) FROM agents")->fetchColumn(),
            'leads' => (int)$db->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
            'api_calls' => (int)$db->query("SELECT COUNT(*) FROM logs WHERE type='api'")->fetchColumn(),
            'avg_ms' => (int)$db->query("SELECT AVG(ms) FROM logs WHERE ms>0")->fetchColumn(),
            'search_calls' => (int)$db->query("SELECT COUNT(*) FROM logs WHERE type IN ('search','research')")->fetchColumn(),
            'spent_chf' => round($spentUsd * $rate, 4),
        ]); exit;
    }

    // ── PHASE 2 : FILE DE JOBS ──────────────────────────────────────────────
    if ($action === 'jobs_enqueue') {
        $role = $input['agent_role'] ?? '';
        if (!in_array($role, ['eclaireur','comptable','vendeur'], true)) { echo json_encode(['ok' => false, 'error' => 'Rôle invalide']); exit; }
        $id = enqueueJob($project, $role, (array)($input['payload'] ?? []), (int)($input['priority'] ?? 0));
        logAction($db, 'job', 'enqueue', '', 0, 'ok', $role, json_encode($input['payload'] ?? []), 'job #' . $id);
        echo json_encode(['ok' => true, 'id' => $id]); exit;
    }
    if ($action === 'jobs_list') { echo json_encode(['ok' => true, 'jobs' => listJobsForProject($project, (int)($input['limit'] ?? 60))]); exit; }
    if ($action === 'job_cancel') { $ok = cancelJob((int)($input['id'] ?? 0), $project); echo json_encode(['ok' => $ok]); exit; }
    if ($action === 'job_result') {
        $j = getJob((int)($input['id'] ?? 0));
        if (!$j || $j['project_id'] !== $project) { echo json_encode(['ok' => false, 'error' => 'Job introuvable']); exit; }
        echo json_encode(['ok' => true, 'job' => $j, 'result' => json_decode($j['result'] ?? 'null', true)]); exit;
    }

    // ── PHASE 2 : EXÉCUTION D'AGENT EN DIRECT (SSE inline, sans worker) ──────
    if ($action === 'agent_run') {
        $role = $input['agent_role'] ?? '';
        if (!in_array($role, ['eclaireur','comptable','vendeur'], true)) { sseSend(['t' => 'err', 'm' => 'Rôle invalide']); exit; }
        if (budgetExceeded($cfg, $db)) { sseSend(['t' => 'err', 'm' => 'Budget CHF du projet dépassé']); exit; }
        sseSend(['t' => 'status', 'm' => agentLabel($cfg, $role) . ' démarre…']);
        $logger = function (string $line) { sseSend(['t' => 'step', 'm' => $line]); };
        try {
            $res = runAgent($role, $project, $cfg, $db, (array)($input['payload'] ?? []), $logger);
            sseSend(['t' => 'end', 'ok' => !empty($res['ok']), 'result' => $res]);
        } catch (Throwable $e) { sseSend(['t' => 'err', 'm' => $e->getMessage()]); }
        exit;
    }

    // ── PHASE 2 : SUIVI EN DIRECT D'UN JOB DE FOND (tail du log + statut) ────
    if ($action === 'job_watch') {
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        $j = getJob($id);
        if (!$j || $j['project_id'] !== $project) { sseSend(['t' => 'err', 'm' => 'Job introuvable']); exit; }
        $logFile = jobLogPath($project, $id); $pos = 0; $deadline = time() + 120;
        sseSend(['t' => 'status', 'm' => 'Job #' . $id . ' — ' . $j['agent_role']]);
        while (time() < $deadline) {
            if (connection_aborted()) break;
            if (is_file($logFile)) {
                clearstatcache(true, $logFile); $size = filesize($logFile);
                if ($size > $pos) {
                    $fh = fopen($logFile, 'r'); fseek($fh, $pos); $chunk = fread($fh, $size - $pos); $pos = ftell($fh); fclose($fh);
                    foreach (explode("\n", $chunk) as $line) { $line = rtrim($line, "\r"); if ($line !== '') sseSend(['t' => 'step', 'm' => $line]); }
                }
            }
            $cur = getJob($id);
            if ($cur && in_array($cur['status'], ['completed','failed'], true)) {
                sseSend(['t' => 'end', 'ok' => $cur['status'] === 'completed', 'status' => $cur['status'], 'result' => json_decode($cur['result'] ?? 'null', true), 'error' => $cur['error'] ?? null]);
                exit;
            }
            usleep(700000);
        }
        sseSend(['t' => 'end', 'ok' => false, 'status' => 'timeout', 'm' => 'Suivi interrompu (toujours en cours côté worker)']); exit;
    }

    // ── PHASE 2 : LEADS / OUTREACH / SUPPRESSION (par projet) ───────────────
    if ($action === 'leads_list') { echo json_encode(['ok' => true, 'leads' => $db->query("SELECT * FROM leads ORDER BY id DESC LIMIT 200")->fetchAll()]); exit; }
    if ($action === 'outreach_list') { echo json_encode(['ok' => true, 'outreach' => $db->query("SELECT * FROM outreach ORDER BY id DESC LIMIT 200")->fetchAll()]); exit; }
    if ($action === 'suppression_list') { echo json_encode(['ok' => true, 'suppression' => $db->query("SELECT * FROM suppression ORDER BY id DESC LIMIT 500")->fetchAll()]); exit; }
    if ($action === 'suppression_add') {
        $emails = $input['emails'] ?? [$input['email'] ?? '']; $n = 0;
        foreach ((array)$emails as $e) { $e = mb_strtolower(trim((string)$e)); if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            if (!isSuppressed($db, $e)) { $db->prepare("INSERT INTO suppression (email, reason) VALUES (?,?)")->execute([$e, (string)($input['reason'] ?? 'opt-out')]); $n++; } }
        echo json_encode(['ok' => true, 'added' => $n]); exit;
    }
    if ($action === 'suppression_remove') { $db->prepare("DELETE FROM suppression WHERE id=?")->execute([(int)($input['id'] ?? 0)]); echo json_encode(['ok' => true]); exit; }

    echo json_encode(['ok' => false, 'error' => 'Action inconnue: ' . $action]);

} catch (Throwable $e) {
    if (in_array($action, ['chat_stream','synthesize','web_research'], true)) sseSend(['t' => 'err', 'm' => 'Erreur serveur: ' . $e->getMessage()]);
    else echo json_encode(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
