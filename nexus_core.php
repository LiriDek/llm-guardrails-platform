<?php
// Polyfill PHP < 8.1 (compat XAMPP local)
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool {
        if ($arr === []) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

/**
 * nexus_core.php — Cœur partagé NEXUS UNIFIED v2 (Digital Factory)
 * Utilisable par le web (nexus.php) ET en ligne de commande (worker/cron).
 * Multi-projets cloisonnés · Abstraction PDO (SQLite <-> MySQL via config.json).
 * PHP 8.x · cURL only · chemins relatifs (__DIR__) pour portabilité totale.
 */
define('NEXUS_ROOT', __DIR__);
define('PROJECTS_DIR', NEXUS_ROOT . '/projects');
date_default_timezone_set('Europe/Zurich');

define('NEXUS_PASSWORD', getenv('NEXUS_PASSWORD') ?: 'changez-moi-immediatement');
define('MISTRAL_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions');
define('MAX_CONTEXT_MESSAGES', 0);

define('MODELS', [
    'qwen/qwen-2.5-72b-instruct'          => ['name' => '🧠 Qwen 72B Ultra',        'cat' => 'flagship'],
    'meta-llama/llama-3-70b-instruct'     => ['name' => '🚀 Llama 3 70B',           'cat' => 'flagship'],
    'nousresearch/hermes-3-llama-3.1-70b' => ['name' => '🔓 Hermes 3 70B (peu filtré)', 'cat' => 'flagship'],

    'mistralai/mistral-large-2512'    => ['name' => 'Mistral Large 3',          'cat' => 'flagship'],
    'mistralai/mistral-large-2411'    => ['name' => 'Mistral Large 2 (legacy)', 'cat' => 'flagship'],
    'mistralai/mistral-medium-2508'   => ['name' => 'Mistral Medium Pro',       'cat' => 'balanced'],
    'mistralai/mistral-medium-2505'   => ['name' => 'Mistral Medium Std',       'cat' => 'balanced'],
    'mistralai/mistral-small-2603'    => ['name' => 'Mistral Small 4 (rapide)', 'cat' => 'fast'],
    'mistralai/mistral-small-2506'    => ['name' => 'Mistral Small 3.2',        'cat' => 'fast'],
    'mistralai/codestral-2508'        => ['name' => 'Codestral (code)',         'cat' => 'code'],
    'mistralai/devstral-2512'         => ['name' => 'Devstral (agent)',         'cat' => 'code'],
    'mistralai/devstral-medium-2507'  => ['name' => 'Devstral Medium',          'cat' => 'code'],
    'mistralai/devstral-small-2507'   => ['name' => 'Devstral Small',           'cat' => 'code'],
    'mistralai/magistral-medium-2509' => ['name' => 'Magistral Medium',         'cat' => 'agent'],
    'mistralai/magistral-small-2509'  => ['name' => 'Magistral Small',          'cat' => 'agent'],
    'mistralai/pixtral-large-2411'    => ['name' => 'Pixtral Large (vision)',   'cat' => 'vision'],
    'mistralai/pixtral-12b-2409'      => ['name' => 'Pixtral 12B (vision)',     'cat' => 'vision'],
    'mistralai/ministral-14b-2512'    => ['name' => 'Ministral 14B',            'cat' => 'edge'],
    'mistralai/ministral-8b-2512'     => ['name' => 'Ministral 8B',             'cat' => 'edge'],
    'mistralai/ministral-3b-2512'     => ['name' => 'Ministral 3B',             'cat' => 'edge'],
]);

// ════════════════════════════════════════════════════════════════════════════
// PROJETS (sandboxing : 1 dossier = 1 micro-SaaS, config.json + data/ étanches)
// ════════════════════════════════════════════════════════════════════════════
function projectsDir(): string { if (!is_dir(PROJECTS_DIR)) @mkdir(PROJECTS_DIR, 0755, true); return PROJECTS_DIR; }
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[àáâãäå]/u','a',$s); $s = preg_replace('/[èéêë]/u','e',$s);
    $s = preg_replace('/[ìíîï]/u','i',$s); $s = preg_replace('/[òóôõö]/u','o',$s);
    $s = preg_replace('/[ùúûü]/u','u',$s); $s = preg_replace('/[ç]/u','c',$s);
    $s = preg_replace('/[^a-z0-9]+/','-',$s); $s = trim($s,'-');
    return $s !== '' ? substr($s,0,48) : ('projet-'.substr(md5((string)mt_rand()),0,6));
}
function projectPath(string $key): string { return projectsDir() . '/' . preg_replace('/[^a-z0-9_-]/i','',$key); }
function projectConfigPath(string $key): string { return projectPath($key) . '/config.json'; }

function defaultProjectConfig(string $name, string $sector, string $goal): array {
    return [
        'name' => $name, 'sector' => $sector, 'goal' => $goal,
        'created_at' => date('c'),
        // Bascule SQLite <-> MySQL en changeant uniquement "type" (le code ne change pas).
        'db' => [
            'type' => 'sqlite',            // "sqlite" | "mysql"
            'sqlite_file' => 'data/{key}.sqlite',
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'nexus_{key}', 'user' => '', 'password' => '',
        ],
        'default_model' => 'mistralai/mistral-small-2603',
        // Model tiering : palier léger pour la recherche/extraction, palier expert pour la persuasion.
        'models_tier' => [
            'flash'  => 'mistralai/mistral-small-2603',  // léger, rapide, excellent JSON/vision, ~10x moins cher
            'expert' => 'mistralai/mistral-large-2512',  // lourd, réservé à la copie B2B à forte valeur
        ],
        'budget' => ['max_chf' => 0, 'usd_to_chf' => 0.90], // max_chf=0 => illimité
        // Hypothèses de calcul du rendement (réglables par projet / par régie).
        'yield_hypotheses' => [
            'entretien_pct_prix' => 1.0,   // provision entretien/rénovation, % du prix / an
            'gerance_pct_loyer'  => 5.0,   // frais de gérance, % du loyer
            'vacance_pct_loyer'  => 1.5,   // risque de vacance, % du loyer
        ],
        'scraping' => [
            'delay_min_ms' => 700, 'delay_max_ms' => 2400, // jitter (politesse + robustesse)
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ],
        'agents' => [
            'eclaireur' => [
                'label' => 'Éclaireur', 'model' => 'mistralai/ministral-8b-2512', 'temperature' => 0.3, 'max_tokens' => 1200,
                'system_prompt' => "Tu es l'Éclaireur : agent de recherche web et de repérage. Tu identifies des cibles pertinentes et tu rapportes des URLs et faits vérifiables, sans inventer.",
            ],
            'comptable' => [
                'label' => 'Comptable', 'model' => 'mistralai/mistral-small-2603', 'temperature' => 0.1, 'max_tokens' => 1400,
                'system_prompt' => "Tu es un comptable suisse rigoureux. Tu extrais UNIQUEMENT les informations réellement présentes dans le texte fourni : tu n'inventes, n'estimes ni n'extrapoles AUCUNE valeur. Toute information absente vaut null (jamais une valeur devinée). Distingue explicitement un loyer mensuel d'un prix de vente : si aucun prix de vente n'est présent, ce champ vaut null. Réponds STRICTEMENT en JSON valide, sans aucun texte autour.",
            ],
            'vendeur' => [
                'label' => 'Vendeur', 'model' => 'mistralai/mistral-large-2512', 'temperature' => 0.7, 'max_tokens' => 1200,
                'system_prompt' => "Tu es le Vendeur : tu calcules un ROI en CHF et tu rédiges des messages d'approche B2B percutants et personnalisés.",
                'max_sends_per_day' => 0, // 0 = pas de plafond ; >0 = nombre max d'envois marqués "sent" / jour / projet
                'opt_out_text' => "Pour ne plus être contacté·e, répondez simplement « STOP » à ce message.",
            ],
        ],
    ];
}

function loadProjectConfig(string $key): ?array {
    $p = projectConfigPath($key);
    if (!is_file($p)) return null;
    $c = json_decode((string)file_get_contents($p), true);
    return is_array($c) ? $c : null;
}
function saveProjectConfig(string $key, array $cfg): void {
    $dir = projectPath($key);
    @mkdir($dir . '/data', 0755, true);
    if (!is_file($dir . '/data/.htaccess')) @file_put_contents($dir . '/data/.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n");
    file_put_contents(projectConfigPath($key), json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function listProjects(): array {
    $out = [];
    foreach (glob(projectsDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $key = basename($dir);
        $cfg = loadProjectConfig($key);
        if (!$cfg) continue;
        $out[] = ['key' => $key, 'name' => $cfg['name'] ?? $key, 'sector' => $cfg['sector'] ?? '', 'goal' => $cfg['goal'] ?? '', 'db_type' => $cfg['db']['type'] ?? 'sqlite', 'default_model' => $cfg['default_model'] ?? ''];
    }
    usort($out, fn($a,$b) => strcmp($a['name'], $b['name']));
    return $out;
}
function createProject(string $name, string $sector, string $goal): string {
    $key = slugify($name); $base = $key; $i = 2;
    while (is_dir(projectPath($key))) { $key = $base . '-' . $i; $i++; }
    @mkdir(projectPath($key) . '/data', 0755, true);
    saveProjectConfig($key, defaultProjectConfig($name, $sector, $goal));
    return $key;
}

// ════════════════════════════════════════════════════════════════════════════
// ABSTRACTION PDO — même code pour SQLite et MySQL
// ════════════════════════════════════════════════════════════════════════════
function makePDO(array $cfg): PDO {
    $db = $cfg['db'] ?? [];
    $type = strtolower($db['type'] ?? 'sqlite');
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    if ($type === 'mysql') {
        $host = $db['host'] ?? '127.0.0.1'; $port = (int)($db['port'] ?? 3306);
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $db['database'] ?? 'nexus');
        $user = $db['user'] ?? ''; $pass = $db['password'] ?? '';
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opts);
        } catch (PDOException $e) {
            // Base inexistante : on la crée puis on s'y connecte (anticipation montée en charge).
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opts);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");
        }
        return $pdo;
    }
    // SQLite (chemin déjà résolu en absolu par getProjectDB)
    $file = $db['sqlite_file'] ?? (NEXUS_ROOT . '/data/project.sqlite');
    @mkdir(dirname($file), 0755, true);
    $pdo = new PDO('sqlite:' . $file, null, null, $opts);
    $pdo->exec('PRAGMA journal_mode=WAL'); // meilleure concurrence (worker CLI + web)
    return $pdo;
}

function dbDriver(PDO $pdo): string { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME); }

// DDL adapté au moteur : le seul endroit où SQLite et MySQL divergent.
function schemaStatements(string $driver): array {
    $mysql = ($driver === 'mysql');
    $PK   = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $TXT  = $mysql ? 'LONGTEXT' : 'TEXT';
    $STR  = $mysql ? 'VARCHAR(255)' : 'TEXT';
    $REAL = $mysql ? 'DOUBLE' : 'REAL';
    $TS   = 'DATETIME DEFAULT CURRENT_TIMESTAMP';
    $END  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        "CREATE TABLE IF NOT EXISTS sessions (
            id $PK, session_key $STR, title $STR, model $STR,
            system_prompt $TXT, temperature $REAL DEFAULT 0.7, max_tokens INT DEFAULT 1800,
            conversation $TXT, created_at $TS, updated_at $TS)$END",
        "CREATE TABLE IF NOT EXISTS logs (
            id $PK, timestamp $TS, type $STR, action $STR, model $STR,
            ms INT DEFAULT 0, status $STR, source $STR, payload $TXT, response $TXT)$END",
        "CREATE TABLE IF NOT EXISTS agents (
            id $PK, name $STR, role $STR, system_prompt $TXT,
            model $STR, temperature $REAL DEFAULT 0.7, created_at $TS)$END",
        "CREATE TABLE IF NOT EXISTS leads (
            id $PK, name $STR, company $STR, email $STR, phone $STR, url $TXT,
            source $STR, data $TXT, status $STR DEFAULT 'new', created_at $TS)$END",
        "CREATE TABLE IF NOT EXISTS suppression (
            id $PK, email $STR, reason $STR, created_at $TS)$END",
        "CREATE TABLE IF NOT EXISTS outreach (
            id $PK, lead_id INT DEFAULT 0, email $STR, channel $STR DEFAULT 'email',
            subject $TXT, message $TXT, status $STR DEFAULT 'drafted', created_at $TS)$END",
        "CREATE TABLE IF NOT EXISTS costs (
            id $PK, ts $TS, agent $STR, model $STR,
            prompt_tokens INT DEFAULT 0, completion_tokens INT DEFAULT 0, total_tokens INT DEFAULT 0,
            cost_usd $REAL DEFAULT 0, job_id INT DEFAULT 0)$END",
    ];
}
function initSchema(PDO $pdo): void {
    foreach (schemaStatements(dbDriver($pdo)) as $sql) { $pdo->exec($sql); }
}

// Cache des connexions par projet.
function getProjectDB(string $key): PDO {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $cfg = loadProjectConfig($key);
    if (!$cfg) throw new RuntimeException("Projet introuvable: $key");
    if (strtolower($cfg['db']['type'] ?? 'sqlite') === 'sqlite') {
        $rel = str_replace('{key}', $key, $cfg['db']['sqlite_file'] ?? "data/$key.sqlite");
        $cfg['db']['sqlite_file'] = projectPath($key) . '/' . $rel;
    } else {
        $cfg['db']['database'] = str_replace('{key}', $key, $cfg['db']['database'] ?? "nexus_$key");
    }
    $pdo = makePDO($cfg);
    initSchema($pdo);
    $cache[$key] = $pdo;
    return $pdo;
}

// ════════════════════════════════════════════════════════════════════════════
// JOURNALISATION & COÛTS (par projet)
// ════════════════════════════════════════════════════════════════════════════
function logAction(PDO $pdo, string $type, string $action, string $model = '', int $ms = 0, string $status = 'ok', string $source = '', string $payload = '', string $response = ''): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (timestamp, type, action, model, ms, status, source, payload, response) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([date('Y-m-d H:i:s'), $type, $action, $model, $ms, $status, $source, mb_substr($payload,0,1500), mb_substr($response,0,1500)]);
    } catch (Throwable $e) { /* silencieux : la journalisation ne doit jamais casser un agent */ }
}
function logCost(PDO $pdo, array $usage, string $model, string $agent = '', int $jobId = 0): void {
    if (!$usage) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO costs (ts, agent, model, prompt_tokens, completion_tokens, total_tokens, cost_usd, job_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([date('Y-m-d H:i:s'), $agent, $model,
            (int)($usage['prompt_tokens'] ?? 0), (int)($usage['completion_tokens'] ?? 0),
            (int)($usage['total_tokens'] ?? 0), (float)($usage['cost'] ?? 0), $jobId]);
    } catch (Throwable $e) {}
}
function projectSpentUsd(PDO $pdo): float {
    try { return (float)$pdo->query("SELECT COALESCE(SUM(cost_usd),0) FROM costs")->fetchColumn(); }
    catch (Throwable $e) { return 0.0; }
}

// ════════════════════════════════════════════════════════════════════════════
// HTTP (TLS vérifié) + ANTI-SSRF (protège l'hôte : pas du filtrage de contenu)
// ════════════════════════════════════════════════════════════════════════════
function curlGet(string $url, array $headers = [], int $timeout = 20, string $ua = ''): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $ua ?: 'Mozilla/5.0 (compatible; NexusUnified/2.0)',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $body ?: '', 'status' => $status, 'error' => $err];
}
function curlPost(string $url, array $payload, array $headers = [], int $timeout = 120): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json','Accept: application/json'], $headers),
    ]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $body ?: '', 'status' => $status, 'error' => $err];
}
function curlMultiGet(array $urls, int $timeout = 12, string $ua = ''): array {
    $mh = curl_multi_init(); $handles = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $u, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => $ua ?: 'Mozilla/5.0 (compatible; NexusUnified/2.0)',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_ENCODING => 'gzip, deflate']);
        curl_multi_add_handle($mh, $ch); $handles[$k] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.5); } while ($running > 0);
    $out = [];
    foreach ($handles as $k => $ch) { $out[$k] = curl_multi_getcontent($ch) ?: ''; curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $out;
}
function curlMultiPostJson(array $reqs, int $timeout = 120): array {
    $mh = curl_multi_init(); $handles = [];
    foreach ($reqs as $i => $r) {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $r['url'], CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($r['payload']), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json','Accept: application/json'], $r['headers'] ?? [])]);
        curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.5); } while ($running > 0);
    $out = [];
    foreach ($handles as $i => $ch) { $out[$i] = ['body' => curl_multi_getcontent($ch) ?: '', 'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE)]; curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $out;
}

// Délai aléatoire (jitter) : politesse réseau + robustesse des moissonnages.
function politeJitter(array $cfg): void {
    $min = (int)($cfg['scraping']['delay_min_ms'] ?? 0);
    $max = (int)($cfg['scraping']['delay_max_ms'] ?? 0);
    if ($max > $min && $max > 0) usleep(mt_rand($min, $max) * 1000);
}

// Anti-SSRF : bloque les cibles INTERNES (localhost, IP privées/réservées, métadonnées cloud).
// Ne filtre AUCUN site public ni aucun mot-clé — c'est un bouclier serveur, pas de la censure.
function isSafeUrl(string $url): bool {
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)) return false;
    $host = $parts['host'] ?? ''; if ($host === '') return false;
    $hl = strtolower($host);
    if ($hl === 'localhost' || str_ends_with($hl, '.localhost') || str_ends_with($hl, '.internal')) return false;
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) { $ips[] = $host; }
    else {
        $a = @gethostbynamel($host); if ($a) $ips = array_merge($ips, $a);
        $recs = @dns_get_record($host, DNS_AAAA); if ($recs) foreach ($recs as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        if (!$ips) return false;
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    }
    return true;
}
function safeCurlGet(string $url, int $timeout = 18, string $ua = ''): array {
    if (!isSafeUrl($url)) return ['body' => '', 'status' => 0, 'error' => 'URL refusée (cible interne/réservée)'];
    return curlGet($url, [], $timeout, $ua);
}

// ════════════════════════════════════════════════════════════════════════════
// MODÈLE / OpenRouter (sync + streaming) — clé via getenv('OPENROUTER_API_KEY')
// ════════════════════════════════════════════════════════════════════════════
function getMistralKey(): string { return trim((string)getenv('OPENROUTER_API_KEY')); }
function orHeaders(string $sessionId = '', bool $cache = false, int $cacheTtl = 0): array {
    $h = ['Authorization: Bearer ' . getMistralKey(), 'HTTP-Referer: http://localhost', 'X-Title: Nexus Unified'];
    // session_id : sticky routing OpenRouter -> meilleurs taux de cache (réduction de coût).
    if ($sessionId !== '') $h[] = 'x-session-id: ' . substr($sessionId, 0, 256);
    // Response caching OpenRouter (couche routeur, agnostique au modèle) : les hits sont GRATUITS.
    if ($cache) { $h[] = 'X-OpenRouter-Cache: true'; if ($cacheTtl > 0) $h[] = 'X-OpenRouter-Cache-TTL: ' . $cacheTtl; }
    return $h;
}
function callMistral(array $messages, string $model = 'mistralai/mistral-small-2603', int $maxTokens = 1200, float $temp = 0.7, string $sessionId = '', bool $cache = false, int $cacheTtl = 0): array {
    $start = microtime(true);
    $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temp, 'messages' => $messages, 'usage' => ['include' => true]];
    $res = curlPost(MISTRAL_ENDPOINT, $payload, orHeaders($sessionId, $cache, $cacheTtl), 120);
    $ms = (int)((microtime(true) - $start) * 1000);
    if ($res['error']) return ['ok' => false, 'error' => 'cURL: ' . $res['error'], 'ms' => $ms];
    if ($res['status'] !== 200) return ['ok' => false, 'error' => 'HTTP ' . $res['status'] . ': ' . mb_substr($res['body'],0,200), 'ms' => $ms];
    $data = json_decode($res['body'], true);
    if (isset($data['error'])) return ['ok' => false, 'error' => $data['error']['message'] ?? 'Erreur', 'ms' => $ms];
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) return ['ok' => false, 'error' => 'Réponse vide', 'ms' => $ms];
    return ['ok' => true, 'content' => $content, 'ms' => $ms, 'usage' => $data['usage'] ?? []];
}
function callMistralStream(array $messages, string $model, int $maxTokens, float $temp, callable $onDelta, string $sessionId = ''): array {
    $start = microtime(true); $full = ''; $usage = []; $buffer = '';
    $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temp, 'messages' => $messages, 'stream' => true, 'usage' => ['include' => true]];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MISTRAL_ENDPOINT, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json','Accept: text/event-stream'], orHeaders($sessionId)),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$full, &$usage, $onDelta) {
            if (connection_aborted()) return 0;
            $buffer .= $chunk;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl)); $buffer = substr($buffer, $nl + 1);
                if ($line === '' || $line[0] === ':') continue;
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $data = trim(substr($line, 5)); if ($data === '[DONE]') continue;
                $j = json_decode($data, true); if (!is_array($j)) continue;
                if (isset($j['usage'])) $usage = $j['usage'];
                $delta = $j['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '' && $delta !== null) { $full .= $delta; $onDelta($delta); }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $ms = (int)((microtime(true) - $start) * 1000);
    if ($full === '') {
        $msg = $err ?: ('HTTP ' . $status);
        $j = json_decode(trim(str_replace('data:', '', $buffer)), true);
        if (isset($j['error']['message'])) $msg = $j['error']['message'];
        return ['ok' => false, 'error' => $msg, 'ms' => $ms];
    }
    return ['ok' => true, 'content' => $full, 'usage' => $usage, 'ms' => $ms];
}

function parseJsonRobust(string $raw): ?array {
    $raw = trim($raw); if (!$raw) return null;
    $p = json_decode($raw, true); if (is_array($p)) return $p;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) { $p = json_decode(trim($m[1]), true); if (is_array($p)) return $p; }
    $sb = strpos($raw,'{'); $sk = strpos($raw,'['); $start = false; $end = '}';
    if ($sb !== false && ($sk === false || $sb < $sk)) { $start = $sb; $end = '}'; } elseif ($sk !== false) { $start = $sk; $end = ']'; }
    if ($start !== false) { $e = strrpos($raw, $end); if ($e !== false && $e > $start) { $p = json_decode(substr($raw, $start, $e - $start + 1), true); if (is_array($p)) return $p; } }
    return null;
}
function htmlToText(string $html, int $max = 8000): string {
    $html = preg_replace('/<(script|style|nav|footer|header|aside|iframe|noscript)[^>]*>.*?<\/\1>/si', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<[^>]+>/', ' ', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(mb_substr(preg_replace('/\s{2,}/', ' ', $html), 0, $max));
}

// ════════════════════════════════════════════════════════════════════════════
// MOTEURS DE RECHERCHE (Web + 7 sources ouvertes) — utilisés par l'Éclaireur
// ════════════════════════════════════════════════════════════════════════════
function searchWeb(string $query, int $limit = 6, string $ua = ''): array {
    $res = curlGet('https://html.duckduckgo.com/html/?q=' . urlencode($query), ['Accept-Language: fr-FR,fr;q=0.9,en;q=0.8'], 12, $ua);
    if ($res['status'] !== 200 || !$res['body']) return [];
    $results = [];
    if (preg_match_all('/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $res['body'], $links, PREG_SET_ORDER)) {
        preg_match_all('/<a[^>]+class="result__snippet"[^>]*>(.*?)<\/a>/si', $res['body'], $snips);
        foreach ($links as $i => $m) {
            if (count($results) >= $limit) break;
            $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if (preg_match('/[?&]uddg=([^&]+)/', $href, $u)) $href = urldecode($u[1]);
            if (!preg_match('#^https?://#', $href)) continue;
            $results[] = ['title' => trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8')),
                'snippet' => isset($snips[1][$i]) ? trim(html_entity_decode(strip_tags($snips[1][$i]), ENT_QUOTES, 'UTF-8')) : '',
                'url' => $href, 'source' => 'web'];
        }
    }
    return $results;
}
function searchWikipediaFR(string $query, int $limit = 5): array {
    $res = curlGet('https://fr.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . urlencode($query) . '&srlimit=' . $limit . '&format=json', [], 10);
    if ($res['status'] !== 200) return [];
    $titles = array_column(json_decode($res['body'], true)['query']['search'] ?? [], 'title');
    if (!$titles) return [];
    $urls = [];
    foreach ($titles as $t) $urls[$t] = 'https://fr.wikipedia.org/w/api.php?action=query&titles=' . urlencode($t) . '&prop=extracts&exintro=1&explaintext=1&format=json';
    $bodies = curlMultiGet($urls, 10);
    $out = [];
    foreach ($titles as $t) {
        $extract = ''; $ex = json_decode($bodies[$t] ?? '', true);
        foreach (($ex['query']['pages'] ?? []) as $p) { $extract = $p['extract'] ?? ''; break; }
        $out[] = ['title' => $t, 'snippet' => mb_substr($extract, 0, 600), 'url' => 'https://fr.wikipedia.org/wiki/' . urlencode(str_replace(' ','_',$t)), 'source' => 'wikipedia_fr'];
    }
    return $out;
}
function searchWikipediaEN(string $query, int $limit = 3): array {
    $res = curlGet('https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . urlencode($query) . '&srlimit=' . $limit . '&format=json', [], 10);
    if ($res['status'] !== 200) return [];
    $out = [];
    foreach ((json_decode($res['body'], true)['query']['search'] ?? []) as $r)
        $out[] = ['title' => $r['title'] ?? '', 'snippet' => strip_tags($r['snippet'] ?? ''), 'url' => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ','_',$r['title'] ?? '')), 'source' => 'wikipedia_en'];
    return $out;
}
function searchGoogleNews(string $query, int $limit = 8): array {
    $res = curlGet('https://news.google.com/rss/search?q=' . urlencode($query) . '&hl=fr&gl=FR&ceid=FR:fr', [], 12);
    if ($res['status'] !== 200 || !$res['body']) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($res['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) return [];
    $out = []; $c = 0;
    foreach ($xml->channel->item as $it) { if ($c++ >= $limit) break;
        $out[] = ['title' => (string)($it->title ?? ''), 'snippet' => strip_tags((string)($it->description ?? '')), 'url' => (string)($it->link ?? ''), 'date' => (string)($it->pubDate ?? ''), 'source' => 'google_news']; }
    return $out;
}
function searchArxiv(string $query, int $limit = 5): array {
    $res = curlGet('https://export.arxiv.org/api/query?search_query=all:' . urlencode($query) . '&start=0&max_results=' . $limit, [], 15);
    if ($res['status'] !== 200 || !$res['body']) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($res['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) return [];
    $out = [];
    foreach ($xml->entry as $e)
        $out[] = ['title' => trim((string)($e->title ?? '')), 'snippet' => trim((string)($e->summary ?? '')), 'url' => (string)($e->id ?? ''), 'date' => (string)($e->published ?? ''), 'source' => 'arxiv'];
    return $out;
}
function searchOpenAlex(string $query, int $limit = 5): array {
    $res = curlGet('https://api.openalex.org/works?search=' . urlencode($query) . '&per_page=' . $limit . '&mailto=nexus@example.com', [], 12);
    if ($res['status'] !== 200) return [];
    $out = [];
    foreach ((json_decode($res['body'], true)['results'] ?? []) as $w)
        $out[] = ['title' => $w['title'] ?? '', 'snippet' => !empty($w['abstract_inverted_index']) ? '(abstract disponible)' : 'Publication académique', 'url' => $w['doi'] ?? $w['id'] ?? '', 'date' => $w['publication_year'] ?? '', 'source' => 'openalex'];
    return $out;
}
function searchCrossRef(string $query, int $limit = 5): array {
    $res = curlGet('https://api.crossref.org/works?query=' . urlencode($query) . '&rows=' . $limit, [], 12);
    if ($res['status'] !== 200) return [];
    $out = [];
    foreach ((json_decode($res['body'], true)['message']['items'] ?? []) as $it)
        $out[] = ['title' => $it['title'][0] ?? '', 'snippet' => ($it['type'] ?? 'article') . ' · ' . ($it['publisher'] ?? ''), 'url' => !empty($it['DOI']) ? 'https://doi.org/' . $it['DOI'] : '', 'date' => $it['published-print']['date-parts'][0][0] ?? '', 'source' => 'crossref'];
    return $out;
}
function searchPubMed(string $query, int $limit = 5): array {
    $r = curlGet('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term=' . urlencode($query) . '&retmax=' . $limit . '&retmode=json', [], 12);
    if ($r['status'] !== 200) return [];
    $ids = json_decode($r['body'], true)['esearchresult']['idlist'] ?? [];
    if (!$ids) return [];
    $r2 = curlGet('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id=' . implode(',', $ids) . '&retmode=json', [], 12);
    if ($r2['status'] !== 200) return [];
    $d = json_decode($r2['body'], true); $out = [];
    foreach ($ids as $id) { $it = $d['result'][$id] ?? []; if (!$it) continue;
        $out[] = ['title' => $it['title'] ?? '', 'snippet' => 'PubMed ' . $id . ' · ' . ($it['source'] ?? ''), 'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . $id . '/', 'date' => $it['pubdate'] ?? '', 'source' => 'pubmed']; }
    return $out;
}
function unifiedSearch(string $query, array $sources = ['web','wikipedia_fr'], string $ua = ''): array {
    $map = ['web' => fn($q,$l) => searchWeb($q,$l,$ua), 'wikipedia_fr' => 'searchWikipediaFR', 'wikipedia_en' => 'searchWikipediaEN',
        'google_news' => 'searchGoogleNews', 'arxiv' => 'searchArxiv', 'openalex' => 'searchOpenAlex', 'crossref' => 'searchCrossRef', 'pubmed' => 'searchPubMed'];
    $all = [];
    foreach ($sources as $s) { if (!isset($map[$s])) continue; try { $all = array_merge($all, $map[$s]($query, 5)); } catch (Throwable $e) {} }
    return $all;
}
function buildSourcesContext(array $results, int $maxChars = 1800): array {
    $ctx = ''; $sources = []; $n = 0;
    foreach ($results as $r) { if (empty($r['url'])) continue; $n++;
        $sources[] = ['n' => $n, 'title' => $r['title'] ?? $r['url'], 'url' => $r['url'], 'source' => $r['source'] ?? ''];
        $ctx .= "[$n] " . ($r['title'] ?? '') . "\nURL: " . $r['url'] . "\n" . mb_substr($r['snippet'] ?? '', 0, $maxChars) . "\n\n";
        if ($n >= 8) break; }
    return ['context' => $ctx, 'sources' => $sources];
}

// ════════════════════════════════════════════════════════════════════════════
// EXTRACTION DE DOCUMENTS (txt/md/csv · docx · pdf)
// ════════════════════════════════════════════════════════════════════════════
function extractDocumentText(string $dataUrl, string $filename): array {
    if (!preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) return ['ok' => false, 'error' => 'Format invalide'];
    $bytes = base64_decode($m[2], true); if ($bytes === false) return ['ok' => false, 'error' => 'base64 invalide'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['txt','md','markdown','csv','tsv','json','log','xml','html'], true)) {
        $t = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8'); if ($ext === 'html') $t = htmlToText($t, 200000);
        return ['ok' => true, 'text' => trim($t)];
    }
    if ($ext === 'docx') {
        if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'ZipArchive indisponible'];
        $tmp = tempnam(sys_get_temp_dir(), 'nexdoc'); file_put_contents($tmp, $bytes);
        $zip = new ZipArchive(); $text = '';
        if ($zip->open($tmp) === true) { $xml = $zip->getFromName('word/document.xml'); $zip->close();
            if ($xml !== false) { $xml = preg_replace('/<\/w:p>/', "\n", $xml); $text = trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); } }
        @unlink($tmp);
        return $text === '' ? ['ok' => false, 'error' => 'docx illisible'] : ['ok' => true, 'text' => $text];
    }
    if ($ext === 'pdf') {
        $tmp = tempnam(sys_get_temp_dir(), 'nexpdf') . '.pdf'; file_put_contents($tmp, $bytes); $text = '';
        $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin !== '' && function_exists('shell_exec')) { $out = $tmp . '.txt'; @shell_exec(escapeshellcmd($bin) . ' -enc UTF-8 -q ' . escapeshellarg($tmp) . ' ' . escapeshellarg($out) . ' 2>/dev/null'); if (is_file($out)) { $text = (string)file_get_contents($out); @unlink($out); } }
        if (trim($text) === '' && preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $bytes, $mm)) $text = implode(' ', array_map('stripcslashes', $mm[1]));
        @unlink($tmp); $text = trim(preg_replace('/\s{2,}/', ' ', $text));
        return $text === '' ? ['ok' => false, 'error' => 'PDF non extractible (scanné ?)'] : ['ok' => true, 'text' => mb_substr($text, 0, 200000)];
    }
    return ['ok' => false, 'error' => 'Type non supporté: .' . $ext];
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 2 — FILE DE JOBS (base système globale) + WORKER
// ════════════════════════════════════════════════════════════════════════════
function systemConfigPath(): string { return NEXUS_ROOT . '/system.json'; }
function loadSystemConfig(): array {
    $p = systemConfigPath();
    $def = ['db' => ['type' => 'sqlite', 'sqlite_file' => NEXUS_ROOT . '/data/system.sqlite'], 'max_parallel' => 3, 'poll_seconds' => 2];
    if (is_file($p)) { $c = json_decode((string)file_get_contents($p), true); if (is_array($c)) return array_replace_recursive($def, $c); }
    return $def;
}
function systemMaxParallel(): int { return max(1, (int)(loadSystemConfig()['max_parallel'] ?? 3)); }

function jobsSchema(string $driver): array {
    $mysql = ($driver === 'mysql');
    $PK = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $TXT = $mysql ? 'LONGTEXT' : 'TEXT'; $STR = $mysql ? 'VARCHAR(190)' : 'TEXT';
    $END = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return ["CREATE TABLE IF NOT EXISTS jobs (
        id $PK, project_id $STR, agent_role $STR, payload $TXT,
        status $STR DEFAULT 'pending', priority INT DEFAULT 0, attempts INT DEFAULT 0,
        result $TXT, error $TXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, started_at DATETIME NULL, completed_at DATETIME NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)$END"];
}
function getSystemDB(): PDO {
    static $pdo = null; if ($pdo) return $pdo;
    $cfg = loadSystemConfig();
    if (strtolower($cfg['db']['type'] ?? 'sqlite') === 'sqlite') { @mkdir(dirname($cfg['db']['sqlite_file']), 0755, true); }
    $pdo = makePDO($cfg);
    foreach (jobsSchema(dbDriver($pdo)) as $sql) $pdo->exec($sql);
    return $pdo;
}

function enqueueJob(string $projectKey, string $agentRole, array $payload, int $priority = 0): int {
    $sys = getSystemDB();
    $sys->prepare("INSERT INTO jobs (project_id, agent_role, payload, status, priority) VALUES (?,?,?,'pending',?)")
        ->execute([$projectKey, $agentRole, json_encode($payload, JSON_UNESCAPED_UNICODE), $priority]);
    return (int)$sys->lastInsertId();
}
// Réservation atomique cross-moteur (sans FOR UPDATE) : UPDATE conditionnel = garde-course.
function claimNextJob(PDO $sys, ?string $projectFilter = null): ?array {
    for ($try = 0; $try < 6; $try++) {
        $where = "status='pending'" . ($projectFilter ? " AND project_id=" . $sys->quote($projectFilter) : "");
        $job = $sys->query("SELECT * FROM jobs WHERE $where ORDER BY priority DESC, id ASC LIMIT 1")->fetch();
        if (!$job) return null;
        $now = date('Y-m-d H:i:s');
        $upd = $sys->prepare("UPDATE jobs SET status='running', started_at=?, updated_at=?, attempts=attempts+1 WHERE id=? AND status='pending'");
        $upd->execute([$now, $now, $job['id']]);
        if ($upd->rowCount() === 1) { $job['status'] = 'running'; return $job; }
    }
    return null;
}
function finishJob(int $id, string $status, ?string $result = null, ?string $error = null): void {
    $sys = getSystemDB(); $now = date('Y-m-d H:i:s');
    $sys->prepare("UPDATE jobs SET status=?, result=?, error=?, completed_at=?, updated_at=? WHERE id=?")
        ->execute([$status, $result, $error ? mb_substr($error, 0, 1000) : null, $now, $now, $id]);
}
function getJob(int $id): ?array { $s = getSystemDB()->prepare("SELECT * FROM jobs WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null; }
function listJobsForProject(string $projectKey, int $limit = 60): array {
    $s = getSystemDB()->prepare("SELECT id,project_id,agent_role,status,priority,attempts,created_at,started_at,completed_at,error FROM jobs WHERE project_id=? ORDER BY id DESC LIMIT " . (int)$limit);
    $s->execute([$projectKey]); return $s->fetchAll();
}
function cancelJob(int $id, string $projectKey): bool {
    $s = getSystemDB()->prepare("UPDATE jobs SET status='failed', error='annulé', completed_at=? WHERE id=? AND project_id=? AND status='pending'");
    $s->execute([date('Y-m-d H:i:s'), $id, $projectKey]); return $s->rowCount() === 1;
}

// Journal d'activité par job (fichier tail-able pour le SSE).
function jobLogPath(string $projectKey, int $id): string { return projectPath($projectKey) . '/data/jobs/' . $id . '.log'; }
function appendJobLog(string $projectKey, int $id, string $line): void {
    $p = jobLogPath($projectKey, $id); @mkdir(dirname($p), 0755, true);
    @file_put_contents($p, '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}
// Journal worker consolidé par projet (succès/erreurs) — reste dans data/ du projet (portable VPS).
function logWorkerEvent(string $projectKey, string $line): void {
    $p = projectPath($projectKey) . '/data/worker.log'; @mkdir(dirname($p), 0755, true);
    @file_put_contents($p, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

function budgetExceeded(array $cfg, PDO $db): bool {
    $max = (float)($cfg['budget']['max_chf'] ?? 0); if ($max <= 0) return false;
    $rate = (float)($cfg['budget']['usd_to_chf'] ?? 0.90);
    return (projectSpentUsd($db) * $rate) >= $max;
}
function agentLabel(array $cfg, string $role): string { return $cfg['agents'][$role]['label'] ?? ucfirst($role); }

// Model tiering : modèle explicite de l'agent sinon repli sur le palier (flash pour
// Éclaireur/Comptable, expert pour le Vendeur). Garantit le bon coût pour la bonne tâche.
function resolveAgentModel(array $cfg, string $role, array $payload = []): string {
    $explicit = trim((string)($payload['model'] ?? ($cfg['agents'][$role]['model'] ?? '')));
    if ($explicit !== '') return $explicit;
    $tier = ['eclaireur' => 'flash', 'comptable' => 'flash', 'vendeur' => 'expert'][$role] ?? 'flash';
    $map = $cfg['models_tier'] ?? ['flash' => 'mistralai/mistral-small-2603', 'expert' => 'mistralai/mistral-large-2512'];
    return $map[$tier] ?? 'mistralai/mistral-small-2603';
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 2 — Structured Outputs + cache local de pages + AGENTS pilotés par config
// ════════════════════════════════════════════════════════════════════════════
function callMistralJson(array $messages, string $model, ?array $schema = null, int $maxTokens = 1400, float $temp = 0.1, string $sessionId = '', bool $cache = false, int $cacheTtl = 0): array {
    $start = microtime(true);
    $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temp, 'messages' => $messages, 'usage' => ['include' => true]];
    if ($schema) $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'extraction', 'strict' => true, 'schema' => $schema]];
    else $payload['response_format'] = ['type' => 'json_object'];
    $res = curlPost(MISTRAL_ENDPOINT, $payload, orHeaders($sessionId, $cache, $cacheTtl), 120);
    $ms = (int)((microtime(true) - $start) * 1000);
    if ($res['error'] || $res['status'] !== 200) {
        // Repli : certains modèles refusent json_schema -> on retente en json_object simple.
        if ($schema) { unset($payload['response_format']); $payload['response_format'] = ['type' => 'json_object'];
            $res = curlPost(MISTRAL_ENDPOINT, $payload, orHeaders($sessionId, $cache, $cacheTtl), 120); }
        if ($res['error'] || $res['status'] !== 200) return ['ok' => false, 'error' => $res['error'] ?: ('HTTP ' . $res['status'] . ' ' . mb_substr($res['body'],0,160)), 'ms' => $ms];
    }
    $data = json_decode($res['body'], true);
    if (isset($data['error'])) return ['ok' => false, 'error' => $data['error']['message'] ?? 'Erreur', 'ms' => $ms];
    $content = $data['choices'][0]['message']['content'] ?? '';
    $parsed = parseJsonRobust($content);
    if ($parsed === null) return ['ok' => false, 'error' => 'JSON non parsable', 'raw' => $content, 'ms' => $ms, 'usage' => $data['usage'] ?? []];
    return ['ok' => true, 'data' => $parsed, 'raw' => $content, 'ms' => $ms, 'usage' => $data['usage'] ?? []];
}

// Cache local : on ne re-télécharge jamais une page déjà lue (TTL). Économie réelle + RAM/latence.
function pageCacheDir(string $projectKey): string { $d = projectPath($projectKey) . '/data/cache'; @mkdir($d, 0755, true); return $d; }
function cachedFetch(string $projectKey, array $cfg, string $url, int $ttl = 86400): array {
    if (!isSafeUrl($url)) return ['ok' => false, 'error' => 'URL refusée (cible interne/réservée)', 'from_cache' => false];
    $file = pageCacheDir($projectKey) . '/' . sha1($url) . '.json';
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $c = json_decode((string)file_get_contents($file), true);
        if (is_array($c) && isset($c['body'])) return ['ok' => true, 'body' => $c['body'], 'from_cache' => true];
    }
    politeJitter($cfg);
    $ua = $cfg['scraping']['user_agent'] ?? '';
    $res = safeCurlGet($url, 18, $ua);
    if ($res['error'] || $res['status'] < 200 || $res['status'] >= 400) return ['ok' => false, 'error' => 'HTTP ' . $res['status'] . ' ' . $res['error'], 'from_cache' => false];
    @file_put_contents($file, json_encode(['url' => $url, 'ts' => time(), 'body' => $res['body']]));
    return ['ok' => true, 'body' => $res['body'], 'from_cache' => false];
}

// ── Ingestion de flux AUTORISÉS (IDX/XML, JSON ou CSV) fournis par une régie ──
// Entrée "propre" de l'Éclaireur : un flux que la régie t'autorise, pas une page protégée.
function fetchAuthorizedFeed(string $project, array $cfg, string $url): array {
    $f = cachedFetch($project, $cfg, $url, 3600);          // SSRF + cache + jitter déjà gérés
    if (!$f['ok']) return ['ok' => false, 'error' => $f['error']];
    $body = $f['body']; $trim = ltrim($body);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {           // JSON
        $data = json_decode($body, true);
        return ['ok' => is_array($data), 'type' => 'json', 'data' => $data ?? [], 'from_cache' => $f['from_cache']];
    }
    if ($trim !== '' && $trim[0] === '<') {                                  // XML / IDX
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        return ['ok' => $xml !== false, 'type' => 'xml',
                'data' => $xml !== false ? json_decode(json_encode($xml), true) : [], 'from_cache' => $f['from_cache']];
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($body));                        // CSV
    $head = str_getcsv((string)array_shift($lines)); $out = [];
    foreach ($lines as $ln) { if (trim($ln) === '') continue; $r = str_getcsv($ln); if (count($r) === count($head)) $out[] = array_combine($head, $r); }
    return ['ok' => !empty($out), 'type' => 'csv', 'data' => $out, 'from_cache' => $f['from_cache']];
}

// Respect de robots.txt : transforme un blocage en refus PROPRE et journalisé.
function robotsAllows(string $project, array $cfg, string $url): bool {
    $p = parse_url($url); if (!$p || empty($p['host'])) return false;
    $r = cachedFetch($project, $cfg, ($p['scheme'] ?? 'https') . '://' . $p['host'] . '/robots.txt', 86400);
    if (!$r['ok']) return true;                                  // pas de robots.txt lisible -> on n'invente pas d'interdiction
    $path = $p['path'] ?? '/'; $applies = false; $disallow = [];
    foreach (preg_split('/\r\n|\r|\n/', $r['body']) as $line) {
        $line = trim($line); if ($line === '' || $line[0] === '#') continue;
        if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) { $applies = (trim($m[1]) === '*'); continue; }
        if ($applies && preg_match('/^Disallow:\s*(.*)$/i', $line, $m) && trim($m[1]) !== '') $disallow[] = trim($m[1]);
    }
    foreach ($disallow as $d) if (str_starts_with($path, $d)) return false;
    return true;
}

// Repère la liste d'annonces la plus plausible dans un flux décodé (schémas variés).
function feedItems(array $data): array {
    if (array_is_list($data)) return $data;
    foreach (['listings','properties','objects','items','item','ads','annonces','realestate','offers','results'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) return array_is_list($data[$k]) ? $data[$k] : [$data[$k]];
    }
    foreach ($data as $v) if (is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0])) return $v;
    return [$data];
}
function guessTitle(array $row): string {
    foreach (['title','titre','address','adresse','street','strasse','name','nom','reference','id'] as $k)
        if (!empty($row[$k]) && is_scalar($row[$k])) return (string)$row[$k];
    return 'annonce';
}

/* ── ÉCLAIREUR : recherche + scraping robuste (jitter, UA, cache, anti-SSRF) ── */
function runEclaireur(string $project, array $cfg, PDO $db, array $payload, callable $log): array {
    $label = agentLabel($cfg, 'eclaireur');
    $ua = $cfg['scraping']['user_agent'] ?? '';

    // ── MODE 1 : FLUX AUTORISÉ (IDX/XML, JSON, CSV) — entrée privilégiée ──
    $feedUrl = trim((string)($payload['feed_url'] ?? ''));
    if ($feedUrl !== '') {
        if (!isSafeUrl($feedUrl)) return ['ok' => false, 'error' => 'URL de flux refusée (cible interne/réservée)'];
        $log("[$label] Flux autorisé : " . mb_substr($feedUrl, 0, 80));
        $feed = fetchAuthorizedFeed($project, $cfg, $feedUrl);
        if (!$feed['ok']) { $log("[$label] ✗ Flux illisible : " . ($feed['error'] ?? 'format non reconnu')); return ['ok' => false, 'error' => $feed['error'] ?? 'flux illisible']; }
        $items = feedItems($feed['data']);
        $maxItems = min(500, max(1, (int)($payload['max_items'] ?? 100)));
        $items = array_slice($items, 0, $maxItems);
        $log("[$label] Flux " . strtoupper($feed['type']) . " — " . count($items) . " annonce(s)" . ($feed['from_cache'] ? ' (cache)' : ''));
        $findings = [];
        foreach ($items as $row) {
            $row = is_array($row) ? $row : ['valeur' => $row];
            $findings[] = ['title' => guessTitle($row), 'url' => (string)($row['url'] ?? $row['link'] ?? $feedUrl),
                'source' => 'feed_' . $feed['type'], 'excerpt' => mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE), 0, 3000), 'data' => $row];
        }
        logAction($db, 'agent', 'eclaireur', '', 0, 'ok', 'feed_' . $feed['type'], $feedUrl, count($findings) . ' annonces');
        return ['ok' => true, 'mode' => 'feed', 'feed_type' => $feed['type'], 'count' => count($findings), 'findings' => $findings];
    }

    // ── MODE 2 : RECHERCHE (web + sources ouvertes), robots.txt respecté ──
    $query = trim($payload['query'] ?? '');
    if ($query === '') return ['ok' => false, 'error' => 'Fournis un feed_url (flux régie) ou une query'];
    $sources = $payload['sources'] ?? ['web', 'wikipedia_fr', 'google_news'];
    $maxPages = min(8, max(1, (int)($payload['max_pages'] ?? 4)));
    $log("[$label] Recherche : « $query » sur " . implode(', ', $sources));
    $results = unifiedSearch($query, $sources, $ua);
    $log("[$label] " . count($results) . " résultats trouvés");
    $findings = []; $scanned = 0;
    foreach ($results as $r) {
        if ($scanned >= $maxPages) break;
        if (empty($r['url']) || !isSafeUrl($r['url'])) continue;
        if (!robotsAllows($project, $cfg, $r['url'])) { $log("[$label] ⛔ non autorisé (robots.txt) : " . mb_substr($r['url'], 0, 70)); continue; }
        $scanned++;
        $log("[$label] Scan : " . mb_substr($r['url'], 0, 70));
        $f = cachedFetch($project, $cfg, $r['url']);
        if (!$f['ok']) { $log("[$label] ↳ ignoré (" . $f['error'] . ")"); continue; }
        $txt = htmlToText($f['body'], 3000);
        $findings[] = ['title' => $r['title'] ?? $r['url'], 'url' => $r['url'], 'source' => $r['source'] ?? '', 'excerpt' => $txt, 'cached' => $f['from_cache']];
        $log("[$label] ↳ " . mb_strlen($txt) . " car." . ($f['from_cache'] ? ' (cache)' : ''));
    }
    logAction($db, 'agent', 'eclaireur', '', 0, 'ok', implode(',', $sources), $query, count($findings) . ' pages');
    return ['ok' => true, 'mode' => 'search', 'query' => $query, 'count' => count($findings), 'findings' => $findings];
}

/* ── COMPTABLE : extraction stricte en JSON (Structured Outputs) ── */
function runComptable(string $project, array $cfg, PDO $db, array $payload, callable $log): array {
    $label = agentLabel($cfg, 'comptable');
    $a = $cfg['agents']['comptable'] ?? [];
    $model = resolveAgentModel($cfg, 'comptable', $payload);  // palier flash (léger/JSON)
    $schema = $payload['schema'] ?? null;          // JSON Schema optionnel
    $instruction = trim($payload['instruction'] ?? 'Extrais les données structurées du contenu.');
    $content = trim((string)($payload['content'] ?? ''));
    if ($content === '' && !empty($payload['url'])) {
        $log("[$label] Lecture : " . mb_substr($payload['url'], 0, 70));
        $f = cachedFetch($project, $cfg, $payload['url']);
        if (!$f['ok']) return ['ok' => false, 'error' => $f['error']];
        $content = htmlToText($f['body'], 9000);
    }
    if ($content === '') return ['ok' => false, 'error' => 'content/url manquant'];
    $log("[$label] Extraction JSON (" . mb_strlen($content) . " car.)…");
    $sys = $a['system_prompt'] ?? "Tu extrais des données en JSON strict. Aucune invention.";
    $res = callMistralJson([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $instruction . "\n\nCONTENU :\n" . $content],
    ], $model, $schema, (int)($a['max_tokens'] ?? 1400), (float)($a['temperature'] ?? 0.1), $project . ':comptable', true, 86400);
    if ($res['ok']) logCost($db, $res['usage'] ?? [], $model, 'comptable');
    if (!$res['ok']) { $log("[$label] Échec : " . $res['error']); return ['ok' => false, 'error' => $res['error']]; }
    $data = $res['data'];
    // Si l'extraction ressemble à des leads, on les enregistre (cloisonnés dans le projet).
    $rows = isset($data['leads']) && is_array($data['leads']) ? $data['leads'] : (array_is_list($data) ? $data : []);
    $inserted = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $email = trim((string)($row['email'] ?? ''));
        $db->prepare("INSERT INTO leads (name,company,email,phone,url,source,data,status) VALUES (?,?,?,?,?,?,?, 'new')")
           ->execute([(string)($row['name'] ?? ''), (string)($row['company'] ?? ''), $email, (string)($row['phone'] ?? ''), (string)($row['url'] ?? ''), 'comptable', json_encode($row, JSON_UNESCAPED_UNICODE)]);
        $inserted++;
    }
    if ($inserted) $log("[$label] $inserted lead(s) enregistré(s)");
    logAction($db, 'agent', 'comptable', $model, $res['ms'], 'ok', '', $instruction, $inserted . ' leads');
    return ['ok' => true, 'data' => $data, 'leads_inserted' => $inserted];
}

/* ── VENDEUR : copywriting B2B suisse percutant + suppression + plafonds ── */
// Détecte un VRAI prix de vente : champ numérique explicite, sinon prix de vente
// clairement libellé dans le texte de l'offre. Par prudence, en cas de doute -> 0.
function detectSalePrice(array $payload): float {
    foreach ([$payload['sale_price'] ?? null, $payload['prix_vente'] ?? null,
              $payload['lead']['sale_price'] ?? null, $payload['lead']['prix_vente'] ?? null] as $cand) {
        if (is_numeric($cand) && (float)$cand > 0) return (float)$cand;
    }
    $offer = (string)($payload['offer'] ?? '');
    // Un prix de vente n'est retenu que s'il est explicitement libellé comme tel.
    if (preg_match('/\b(prix\s+(de\s+)?vente|prix\s+d[\'’]?achat|valeur\s+d[\'’]?acquisition|à\s+vendre|en\s+vente)\b/iu', $offer)
        && preg_match('/(\d[\d\'\.\s]{4,}\d)\s*(chf|fr\.?)/iu', $offer, $m)) {
        $n = (float)preg_replace('/[^\d.]/', '', str_replace(["'", ' '], '', $m[1]));
        if ($n > 0) return $n;
    }
    return 0.0;
}
// Barrière anti-invention : repère un rendement/prix fabriqué dans une sortie texte.
function outreachInventsYield(string $text): bool {
    $t = mb_strtolower($text);
    if (preg_match('/\d[\d\.,\s]*\s*%/u', $t)) return true;                 // tout pourcentage chiffré
    if (preg_match('/rendement|retour\s+sur\s+investissement|\broi\b/u', $t)) return true;
    if (preg_match('/valeur\s+(v[ée]nale|d[\'’]?achat|d[\'’]?acquisition)|prix\s+(d[\'’]?achat|de\s+vente)\s+estim/u', $t)) return true;
    return false;
}
// Détecte un langage de garantie/promesse absolue (interdit : un rendement n'est jamais garanti).
function claimsGuarantee(string $text): bool {
    $t = mb_strtolower($text);
    if (preg_match('/\bgaranti(?:e|s|es)?\b|\bgarantit\b/u', $t)) return true;
    if (preg_match('/\bassur[ée](?:e|s|es)?\b/u', $t)) return true;          // "assuré", pas "assurance"
    if (preg_match('/\bsans\s+(?:aucun\s+)?risque\b/u', $t)) return true;
    if (preg_match('/\b100\s*%\s*(?:s[uû]r|s[ée]curis[ée])\b/u', $t)) return true;
    if (preg_match('/\b(?:rendement|revenu|gain)\s+(?:certain|s[uû]r|assur[ée])\b/u', $t)) return true;
    return false;
}
// Retire proprement les qualificatifs de garantie d'un texte (filet de sécurité si le modèle persiste).
function stripGuaranteeClaims(string $text): string {
    $t = $text;
    $t = preg_replace('/\s*\((?:[^)]*\b(?:garanti(?:e|s|es)?|assur[ée](?:e|s|es)?|sans\s+risque)\b[^)]*)\)/iu', '', $t); // "(garanti)"
    $t = preg_replace('/\b(rendement|revenu|gain|net|brut)\s+(garanti(?:e|s|es)?|assur[ée](?:e|s|es)?|certain|s[uû]r)\b/iu', '$1', $t);
    $t = preg_replace('/\b(garanti(?:e|s|es)?|garantit|assur[ée](?:e|s|es)?)\s+(?=\d|\bde\b|\bun\b)/iu', '', $t); // "garanti 2.18%"
    $t = preg_replace('/\bsans\s+(?:aucun\s+)?risque\b/iu', '', $t);
    $t = preg_replace('/\b100\s*%\s*(?:s[uû]r|s[ée]curis[ée])\b/iu', '', $t);
    $t = preg_replace('/\b(garanti(?:e|s|es)?|garantit)\b/iu', '', $t);     // résiduels isolés
    $t = preg_replace('/[ \t]{2,}/', ' ', $t);                              // espaces doublés
    $t = preg_replace('/\s+([.,])/', '$1', $t);                            // espace avant point/virgule
    $t = preg_replace('/\(\s*\)/', '', $t);                                 // parenthèses vidées
    return trim($t);
}

// ── CALCUL DÉTERMINISTE DU RENDEMENT (en PHP, jamais par l'IA) ──
// Garantit par construction net ≤ brut, sans double comptage propriétaire/locataire.
function parseChfNumber(string $s): float {
    $s = str_replace(["'", ' ', "\xC2\xA0", "\xE2\x80\xAF"], '', $s); // apostrophes/espaces (y c. fines)
    if (preg_match('/(\d+(?:\.\d+)?)/', $s, $m)) return (float)$m[1];
    return 0.0;
}
// Hypothèses de rendement effectives : défauts sûrs < config projet < payload (override ponctuel).
// Toute valeur absente, non numérique ou hors [0..100] retombe sur le défaut.
function resolveYieldHypotheses(array $cfg, array $payload = []): array {
    $out = ['entretien_pct_prix' => 1.0, 'gerance_pct_loyer' => 5.0, 'vacance_pct_loyer' => 1.5];
    $sources = [$cfg['yield_hypotheses'] ?? [], $cfg['agents']['vendeur']['hypotheses'] ?? [], $payload['hypotheses'] ?? []];
    foreach ($sources as $src) {
        if (!is_array($src)) continue;
        foreach ($out as $k => $_) {
            if (isset($src[$k]) && is_numeric($src[$k]) && (float)$src[$k] >= 0 && (float)$src[$k] <= 100) $out[$k] = (float)$src[$k];
        }
    }
    return $out;
}
function computeYield(float $prix, float $loyerNetMensuel, array $hyp = []): array {
    $prix = max(0.0, $prix); $loyerNetMensuel = max(0.0, $loyerNetMensuel);
    $h = array_merge(['entretien_pct_prix' => 1.0, 'gerance_pct_loyer' => 5.0, 'vacance_pct_loyer' => 1.5], $hyp);
    $loyerAnnuel = $loyerNetMensuel * 12;
    $brut = $prix > 0 ? ($loyerAnnuel / $prix) * 100 : 0.0;
    $cEntretien = $prix * $h['entretien_pct_prix'] / 100;          // provision entretien : % du PRIX
    $cGerance   = $loyerAnnuel * $h['gerance_pct_loyer'] / 100;    // gérance : % du LOYER
    $cVacance   = $loyerAnnuel * $h['vacance_pct_loyer'] / 100;    // vacance : % du LOYER
    $charges = $cEntretien + $cGerance + $cVacance;
    $loyerNetApres = $loyerAnnuel - $charges;
    $net = $prix > 0 ? ($loyerNetApres / $prix) * 100 : 0.0;
    if ($net > $brut) $net = $brut;                                // garde-fou : net ne dépasse jamais brut
    return [
        'prix' => $prix, 'loyer_net_mensuel' => $loyerNetMensuel, 'loyer_annuel' => round($loyerAnnuel, 2),
        'rendement_brut_pct' => round($brut, 2), 'rendement_net_pct' => round($net, 2),
        'charges' => ['entretien' => round($cEntretien, 2), 'gerance' => round($cGerance, 2), 'vacance' => round($cVacance, 2), 'total' => round($charges, 2)],
        'loyer_net_apres_charges' => round($loyerNetApres, 2), 'hypotheses' => $h,
        'notes' => "Rendement calculé sur le loyer NET (hors charges locatives). Provisions forfaitaires : entretien {$h['entretien_pct_prix']}% du prix, gérance {$h['gerance_pct_loyer']}% du loyer, vacance {$h['vacance_pct_loyer']}% du loyer. La part non récupérable des charges PPE est réputée couverte par la provision d'entretien (pas de double comptage). Hors frais d'acquisition et hors financement.",
    ];
}
// Extrait prix + loyer NET du payload (champs explicites, sinon parsing prudent de l'offre).
// Retourne null si on n'a pas de loyer net fiable -> dans ce cas, aucun rendement n'est calculé.
function extractYieldInputs(array $payload): ?array {
    $prix = detectSalePrice($payload);
    if ($prix <= 0) return null;
    $net = 0.0;
    foreach (['loyer_net_mensuel', 'loyer_net', 'loyer_mensuel_net'] as $k)
        if (isset($payload[$k]) && is_numeric($payload[$k]) && (float)$payload[$k] > 0) { $net = (float)$payload[$k]; break; }
    if ($net <= 0) {
        $offer = (string)($payload['offer'] ?? '');
        if (preg_match('/loyer\s+net\s*[:=]?\s*([\d\'\.\s]{3,})/iu', $offer, $m)
            || preg_match('/soit\s*([\d\'\.\s]{3,})\s*(?:chf\s*)?net\b/iu', $offer, $m)
            || preg_match('/([\d\'\.\s]{3,})\s*chf\s*net\b/iu', $offer, $m)) {
            $net = parseChfNumber($m[1]);
        }
    }
    if ($net <= 0) return null;
    return ['prix' => $prix, 'loyer_net' => $net];
}

function vendeurSentToday(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM outreach WHERE status='sent' AND created_at >= '" . date('Y-m-d') . " 00:00:00'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function isSuppressed(PDO $db, string $email): bool {
    if ($email === '') return false;
    $s = $db->prepare("SELECT COUNT(*) FROM suppression WHERE email = ?"); $s->execute([mb_strtolower($email)]);
    return (int)$s->fetchColumn() > 0;
}
function runVendeur(string $project, array $cfg, PDO $db, array $payload, callable $log): array {
    $label = agentLabel($cfg, 'vendeur');
    $a = $cfg['agents']['vendeur'] ?? [];
    $model = resolveAgentModel($cfg, 'vendeur', $payload);  // palier expert (persuasion B2B)
    $lead = $payload['lead'] ?? [];
    $email = trim((string)($lead['email'] ?? $payload['email'] ?? ''));
    $cap = (int)($a['max_sends_per_day'] ?? 0);                       // 0 = pas de plafond d'envoi
    $optout = $a['opt_out_text'] ?? "Pour ne plus recevoir de messages, répondez STOP.";
    $log("[$label] Préparation du message" . ($email ? " pour $email" : ''));
    if ($email && isSuppressed($db, $email)) { $log("[$label] ⛔ $email est en liste de suppression — ignoré"); return ['ok' => true, 'skipped' => 'suppression', 'email' => $email]; }
    if ($cap > 0 && vendeurSentToday($db) >= $cap) { $log("[$label] ⛔ plafond d'envoi quotidien atteint ($cap)"); return ['ok' => true, 'skipped' => 'cap', 'sent_today' => vendeurSentToday($db)]; }

    // Barrière n°1 (en amont) : y a-t-il un VRAI prix de vente ? Sinon, tout calcul est interdit.
    $salePrice = detectSalePrice($payload);
    $hasSalePrice = $salePrice > 0;
    // Calcul DÉTERMINISTE du rendement en PHP (jamais par l'IA), si prix + loyer net fiables.
    $yield = null;
    if ($hasSalePrice) { $yi = extractYieldInputs($payload); if ($yi) $yield = computeYield($yi['prix'], $yi['loyer_net'], resolveYieldHypotheses($cfg, $payload)); }
    $allowPercent = ($yield !== null);   // les % ne sont autorisés QUE s'ils viennent du serveur

    if ($yield) {
        $log("[$label] Rendement calculé par le serveur — brut " . number_format($yield['rendement_brut_pct'], 2, '.', '') . "% / net " . number_format($yield['rendement_net_pct'], 2, '.', '') . "% (verrouillé)");
    } elseif ($hasSalePrice) {
        $log("[$label] Prix de vente présent mais loyer NET non fiable — aucun rendement calculé (le modèle n'a pas le droit de chiffrer)");
    } else {
        $log("[$label] Aucun prix de vente — rendement INTERDIT (location / sans prix)");
    }

    $lockedBlock = '';
    if ($yield) {
        $f0 = fn($n) => number_format($n, 0, '.', "'");
        $lockedBlock = "\n\nCHIFFRES VERROUILLÉS PAR LE SERVEUR (ne recalcule rien, reprends-les EXACTEMENT) :"
            . "\n- Prix de vente : " . $f0($yield['prix']) . " CHF"
            . "\n- Loyer net mensuel retenu : " . $f0($yield['loyer_net_mensuel']) . " CHF (annuel " . $f0($yield['loyer_annuel']) . " CHF)"
            . "\n- Rendement BRUT : " . number_format($yield['rendement_brut_pct'], 2, '.', '') . " %"
            . "\n- Rendement NET : " . number_format($yield['rendement_net_pct'], 2, '.', '') . " %"
            . "\n- Hypothèses : entretien " . $yield['hypotheses']['entretien_pct_prix'] . "% du prix, gérance " . $yield['hypotheses']['gerance_pct_loyer'] . "%, vacance " . $yield['hypotheses']['vacance_pct_loyer'] . "% (net ≤ brut garanti).";
    }
    $rule = $allowPercent
        ? "Le serveur a DÉJÀ calculé les rendements (bloc « CHIFFRES VERROUILLÉS »). Tu ne fais AUCUN calcul : reprends EXACTEMENT ces pourcentages, ne les modifie pas, n'en invente aucun autre."
        : "INTERDICTION ABSOLUE de mentionner un rendement, un pourcentage, un ROI, une valeur vénale ou un prix d'achat estimé (données insuffisantes pour un calcul fiable). Présente uniquement les faits fournis et le gain de temps en fourchette indicative.";
    $sys = ($a['system_prompt'] ?? "Tu es le Vendeur.")
        . "\nMarché : Suisse (B2B). Style direct, crédible, sans superlatifs creux, sans fausse urgence ni date butoir inventée. "
        . "Tu ne calcules JAMAIS toi-même un rendement. N'affirme aucun fait absent des données (vacance, état du bâtiment, conformité du loyer). $rule "
        . "Termine par un appel à l'action clair, puis sur une ligne distincte la mention d'opt-out fournie. "
        . "Réponds en JSON : {\"subject\":\"...\",\"message\":\"...\"}";
    $ctx = "Cible : " . json_encode($lead, JSON_UNESCAPED_UNICODE) . "\nOffre : " . trim((string)($payload['offer'] ?? '')) . $lockedBlock . "\nMention d'opt-out à inclure : " . $optout;

    // Génération + Barrière n°2 (en aval) : invention de chiffres OU langage de garantie -> on régénère, puis on traite.
    $subject = ''; $message = ''; $lastRes = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $extra = ($attempt === 2) ? "\nRAPPEL STRICT : ta réponse précédente a été rejetée. N'emploie AUCUN mot de garantie (« garanti », « assuré », « sans risque », « certain ») : un rendement est une estimation, jamais une promesse." . ($allowPercent ? '' : " Et ne mets AUCUN pourcentage, rendement, ROI ni prix estimé.") : '';
        $res = callMistralJson([['role' => 'system', 'content' => $sys . $extra], ['role' => 'user', 'content' => $ctx]], $model, null, (int)($a['max_tokens'] ?? 1200), (float)($a['temperature'] ?? 0.7), $project . ':vendeur');
        $lastRes = $res;
        if ($res['ok']) logCost($db, $res['usage'] ?? [], $model, 'vendeur');
        if (!$res['ok']) { $log("[$label] Échec modèle : " . $res['error']); return ['ok' => false, 'error' => $res['error']]; }
        $d = $res['data'];
        $subject = (string)($d['subject'] ?? 'Proposition'); $message = (string)($d['message'] ?? '');
        $invents = (!$allowPercent && outreachInventsYield($subject . "\n" . $message));
        $guarantees = claimsGuarantee($subject . "\n" . $message);
        if (!$invents && !$guarantees) break;                        // sortie propre : on garde
        $why = $invents ? "rendement/chiffre inventé" : "langage de garantie"; 
        $log("[$label] ⚠ Sortie problématique ($why)" . ($attempt === 1 ? " — nouvelle tentative" : ""));
    }
    if (!$allowPercent && outreachInventsYield($subject . "\n" . $message)) {
        $log("[$label] ⛔ Bloqué : invention de rendement sans calcul serveur.");
        logAction($db, 'agent', 'vendeur', $model, $lastRes['ms'] ?? 0, 'blocked', '', $email, 'invention rendement');
        return ['ok' => false, 'error' => "Sortie bloquée : tentative d'inventer un rendement/prix sans calcul serveur. Fournis prix de vente + loyer net, ou traite ce bien en fiche factuelle.", 'blocked_output' => ['subject' => $subject, 'message' => $message]];
    }
    // Filet anti-garantie : si le modèle persiste, on retire nous-mêmes les mots de garantie.
    if (claimsGuarantee($subject . "\n" . $message)) {
        $subject = stripGuaranteeClaims($subject); $message = stripGuaranteeClaims($message);
        $log("[$label] ✂ Mots de garantie retirés (un rendement n'est jamais garanti).");
    }
    // Garantie finale : si le serveur a calculé, on s'assure que les bons chiffres figurent dans le message.
    if ($yield) {
        $b = number_format($yield['rendement_brut_pct'], 2, '.', '');
        if (mb_strpos($message, $b) === false && mb_strpos($message, str_replace('.', ',', $b)) === false) {
            $n = number_format($yield['rendement_net_pct'], 2, '.', '');
            $message .= "\n\nChiffres de référence (calculés automatiquement, hypothèses standard) : rendement brut $b %, rendement net $n %.";
        }
    }

    if (mb_stripos($message, $optout) === false) $message .= "\n\n" . $optout; // garantir l'opt-out
    // On ENREGISTRE le brouillon (status 'drafted'). L'envoi réel reste une action humaine/externe.
    $db->prepare("INSERT INTO outreach (lead_id,email,channel,subject,message,status) VALUES (?,?,?,?,?, 'drafted')")
       ->execute([(int)($lead['id'] ?? 0), $email, (string)($payload['channel'] ?? 'email'), $subject, $message]);
    $log("[$label] Message rédigé ✓ (brouillon enregistré)");
    logAction($db, 'agent', 'vendeur', $model, $lastRes['ms'] ?? 0, 'ok', '', $email, $subject);
    return ['ok' => true, 'subject' => $subject, 'message' => $message, 'has_sale_price' => $hasSalePrice, 'yield' => $yield, 'status' => 'drafted'];
}

// Dispatcher commun (worker CLI et exécution web inline).
function runAgent(string $role, string $project, array $cfg, PDO $db, array $payload, callable $log): array {
    return match ($role) {
        'eclaireur' => runEclaireur($project, $cfg, $db, $payload, $log),
        'comptable' => runComptable($project, $cfg, $db, $payload, $log),
        'vendeur'   => runVendeur($project, $cfg, $db, $payload, $log),
        default     => ['ok' => false, 'error' => "rôle inconnu: $role"],
    };
}
