<?php
declare(strict_types=1);

const DB_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'tracker.sqlite';
const GEMINI_KEY_FILE = __DIR__ . DIRECTORY_SEPARATOR . '.gemini_key';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS daily_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_date TEXT NOT NULL UNIQUE,
        work_minutes INTEGER NOT NULL DEFAULT 0,
        phone_minutes INTEGER NOT NULL DEFAULT 0,
        tasks_planned INTEGER NOT NULL DEFAULT 0,
        tasks_done INTEGER NOT NULL DEFAULT 0,
        notes TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        priority TEXT NOT NULL DEFAULT "medium",
        done INTEGER NOT NULL DEFAULT 0,
        task_date TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    return $pdo;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

function cleanDate(mixed $date): string
{
    $value = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    return $value;
}

function dayLog(string $date): array
{
    $stmt = db()->prepare('SELECT * FROM daily_logs WHERE log_date = ?');
    $stmt->execute([$date]);
    $log = $stmt->fetch();
    return $log ?: [
        'id' => null,
        'log_date' => $date,
        'work_minutes' => 0,
        'phone_minutes' => 0,
        'tasks_planned' => 0,
        'tasks_done' => 0,
        'notes' => '',
    ];
}

function recentLogs(int $limit = 7): array
{
    $stmt = db()->prepare('SELECT * FROM daily_logs ORDER BY log_date DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}

function currentTasks(string $date): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE task_date = ? ORDER BY done ASC, CASE priority WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END, id DESC');
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}

function geminiAnalysis(array $data, string $mode = 'review'): string
{
    $apiKey = is_file(GEMINI_KEY_FILE) ? trim((string)file_get_contents(GEMINI_KEY_FILE)) : (getenv('GEMINI_API_KEY') ?: '');
    if ($apiKey === '') {
        throw new RuntimeException('GEMINI_API_KEY is not available to the PHP server. Restart PHP after setting the key.');
    }

    $instructions = [
        'review' => 'Give a concise, kind weekly review with: one clear pattern, one bottleneck, and three specific actions for tomorrow. Compare phone use with focused work.',
        'plan' => 'Create a realistic plan for the selected day. Choose the top three tasks, assign a suggested order, and recommend focused work blocks with breaks. Do not invent tasks.',
        'phone' => 'Analyze phone use versus focused work. Identify the likely distraction pattern and give three practical, non-judgmental ways to protect attention tomorrow.',
        'prioritize' => 'Prioritize the listed tasks for the next work session. Explain the order briefly and turn the first task into a very small starting action.',
        'next' => 'Use the notes and numbers to suggest the single best next action. Make it specific enough to start in five minutes.',
    ];
    $prompt = "You are a practical productivity coach. {$instructions[$mode]}. Keep the answer under 220 words, use short headings, and do not shame the person. Data:\n" . json_encode($data, JSON_UNESCAPED_SLASHES);
    $payload = json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]);
    $lastError = 'Gemini returned no response.';
    foreach (['gemini-3.8-flash', 'gemini-2.5-flash'] as $model) {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($apiKey);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        if (defined('CURLSSLOPT_NATIVE_CA') && defined('CURLOPT_SSL_OPTIONS')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $error !== '') {
            throw new RuntimeException('Gemini connection failed: ' . ($error ?: 'unknown network error'));
        }
        $decoded = json_decode($response, true);
        if (isset($decoded['error']['message'])) {
            $lastError = 'Gemini API error: ' . $decoded['error']['message'];
            continue;
        }
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text !== '') {
            return $text;
        }
        $lastError = 'Gemini returned an empty response. Check that the API key has Generative Language API access.';
    }
    throw new RuntimeException($lastError);
}

$action = $_GET['action'] ?? '';
if ($action !== '') {
    try {
        $body = requestBody();
        $date = cleanDate($body['date'] ?? $_GET['date'] ?? date('Y-m-d'));

        if ($action === 'save_day') {
            $stmt = db()->prepare('INSERT INTO daily_logs (log_date, work_minutes, phone_minutes, tasks_planned, tasks_done, notes, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(log_date) DO UPDATE SET work_minutes=excluded.work_minutes, phone_minutes=excluded.phone_minutes, tasks_planned=excluded.tasks_planned, tasks_done=excluded.tasks_done, notes=excluded.notes, updated_at=CURRENT_TIMESTAMP');
            $stmt->execute([$date, max(0, (int)($body['work_minutes'] ?? 0)), max(0, (int)($body['phone_minutes'] ?? 0)), max(0, (int)($body['tasks_planned'] ?? 0)), max(0, (int)($body['tasks_done'] ?? 0)), trim((string)($body['notes'] ?? ''))]);
            jsonResponse(['ok' => true, 'log' => dayLog($date)]);
        }

        if ($action === 'set_gemini_key') {
            $key = trim((string)($body['api_key'] ?? ''));
            if ($key === '' || strlen($key) < 20) {
                jsonResponse(['ok' => false, 'error' => 'Enter a complete Gemini API key.'], 422);
            }
            if (file_put_contents(GEMINI_KEY_FILE, $key . PHP_EOL, LOCK_EX) === false) {
                jsonResponse(['ok' => false, 'error' => 'The key could not be saved. Check folder write permission.'], 500);
            }
            jsonResponse(['ok' => true, 'message' => 'Gemini key updated on this local app.']);
        }

        if ($action === 'add_task') {
            $title = trim((string)($body['title'] ?? ''));
            if ($title === '') {
                jsonResponse(['ok' => false, 'error' => 'Task title is required.'], 422);
            }
            $stmt = db()->prepare('INSERT INTO tasks (title, priority, task_date) VALUES (?, ?, ?)');
            $stmt->execute([$title, in_array($body['priority'] ?? '', ['high', 'medium', 'low'], true) ? $body['priority'] : 'medium', $date]);
            jsonResponse(['ok' => true, 'tasks' => currentTasks($date)]);
        }

        if ($action === 'toggle_task') {
            $stmt = db()->prepare('UPDATE tasks SET done = CASE done WHEN 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([(int)($body['id'] ?? 0)]);
            jsonResponse(['ok' => true, 'tasks' => currentTasks($date)]);
        }

        if ($action === 'delete_task') {
            $stmt = db()->prepare('DELETE FROM tasks WHERE id = ?');
            $stmt->execute([(int)($body['id'] ?? 0)]);
            jsonResponse(['ok' => true, 'tasks' => currentTasks($date)]);
        }

        if ($action === 'analyze') {
            $mode = in_array($body['mode'] ?? 'review', ['review', 'plan', 'phone', 'prioritize', 'next'], true) ? $body['mode'] : 'review';
            jsonResponse(['ok' => true, 'analysis' => geminiAnalysis(['selected_day' => dayLog($date), 'recent_days' => recentLogs(), 'tasks' => currentTasks($date)], $mode)]);
        }

        if ($action === 'data') {
            jsonResponse(['ok' => true, 'log' => dayLog($date), 'tasks' => currentTasks($date), 'recent' => recentLogs()]);
        }
    } catch (Throwable $exception) {
        jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 500);
    }
}

$today = date('Y-m-d');
$log = dayLog($today);
$tasks = currentTasks($today);
$recent = recentLogs();
$workHours = round(((int)$log['work_minutes']) / 60, 1);
$phoneHours = round(((int)$log['phone_minutes']) / 60, 1);
$completion = (int)$log['tasks_planned'] > 0 ? min(100, round(((int)$log['tasks_done'] / (int)$log['tasks_planned']) * 100)) : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daymark | Daily focus tracker</title>
<style>
.ai-actions { display:flex; flex-wrap:wrap; gap:7px; margin-top:15px; }.ai-actions button { border:1px solid #b8ceb0; background:rgba(255,255,255,.45); color:var(--leaf); border-radius:7px; padding:8px 10px; font-size:11px; font-weight:700; }.ai-actions button:hover { background:white; }.reminder-tools { display:flex; align-items:center; gap:8px; }.reminder-tools input { border:1px solid var(--line); border-radius:6px; padding:7px; background:#faf9f4; color:var(--ink); font-size:12px; }
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap');
:root { --ink:#17231f; --muted:#6b7771; --paper:#f6f4ee; --panel:#fffdf8; --line:#dfe4dc; --leaf:#26664c; --lime:#b7da72; --sun:#f5c875; --coral:#ef8f72; --shadow:0 18px 55px rgba(32,54,42,.08); }
* { box-sizing:border-box; } body { margin:0; color:var(--ink); background:var(--paper); font-family:'DM Sans', sans-serif; } button,input,textarea,select { font:inherit; } button { cursor:pointer; border:0; }
.shell { display:grid; grid-template-columns:236px 1fr; min-height:100vh; } .sidebar { position:sticky; top:0; height:100vh; overflow-y:auto; background:#17382e; color:#ecf2e9; padding:30px 21px; display:flex; flex-direction:column; }
.brand { display:flex; align-items:center; gap:10px; margin-bottom:54px; font-weight:700; letter-spacing:-.4px; font-size:20px; }.brand-mark { width:31px;height:31px;border-radius:10px;background:var(--lime);color:#17382e;display:grid;place-items:center;font-weight:700; }
.nav { display:grid; gap:8px; }.nav button { color:#b4c8bc; background:transparent; text-align:left; padding:12px 13px; border-radius:10px; font-weight:600; }.nav button.active,.nav button:hover { color:#17382e;background:var(--lime); }.side-note { margin-top:auto; border-top:1px solid rgba(255,255,255,.16); padding-top:18px; color:#aac0b4; font-size:12px; line-height:1.5; }
main { max-width:1440px; width:100%; margin:auto; padding:30px 46px 55px; }.topbar { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:36px; }.eyebrow { color:var(--leaf); font:700 11px 'Space Mono',monospace; letter-spacing:1.5px; text-transform:uppercase; }.date-picker { display:flex; align-items:center; gap:9px; color:var(--muted); font:12px 'Space Mono',monospace; }.date-picker input { border:0; background:transparent; color:var(--ink); font:700 12px 'Space Mono',monospace; }
h1 { font-size:clamp(28px,4vw,47px); line-height:1.06; letter-spacing:-2px; margin:7px 0 0; max-width:680px; }.intro { display:flex; justify-content:space-between; align-items:end; gap:20px; margin-bottom:28px; }.intro p { max-width:410px; color:var(--muted); line-height:1.55; margin:0; font-size:14px; }.status-pill { background:var(--lime); padding:10px 14px; border-radius:40px; font-size:12px; font-weight:700; white-space:nowrap; }
.grid { display:grid; grid-template-columns:repeat(12,1fr); gap:16px; }.panel { background:var(--panel); border:1px solid var(--line); border-radius:14px; box-shadow:var(--shadow); padding:22px; }.metric { grid-column:span 3; min-height:143px; position:relative; overflow:hidden; }.metric:after { content:''; position:absolute; width:83px; height:83px; border-radius:50%; right:-24px; bottom:-28px; background:var(--lime); opacity:.35; }.metric:nth-child(2):after { background:var(--coral); }.metric:nth-child(3):after { background:var(--sun); }.metric:nth-child(4):after { background:#a9c9ec; }.metric-label { color:var(--muted); font-size:13px; }.metric-number { font:700 38px 'Space Mono',monospace; margin:16px 0 4px; letter-spacing:-3px; }.metric-sub { color:var(--muted); font-size:12px; }
.section-title { font-weight:700; font-size:16px; margin:0 0 4px; }.section-meta { color:var(--muted); font-size:12px; margin:0 0 18px; }.focus { grid-column:span 7; }.tasks { grid-column:span 5; }.log-form { display:grid; grid-template-columns:repeat(2,1fr); gap:13px; }.field { display:grid; gap:7px; }.field label { font-size:12px; color:var(--muted); }.field input,.field textarea,.field select { width:100%; border:1px solid var(--line); background:#faf9f4; border-radius:8px; padding:11px 12px; color:var(--ink); outline:none; }.field input:focus,.field textarea:focus,.field select:focus { border-color:var(--leaf); box-shadow:0 0 0 3px rgba(38,102,76,.1); }.field textarea { min-height:74px; resize:vertical; }.full { grid-column:1/-1; }.save-row { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:4px; }.primary { background:var(--leaf); color:white; padding:11px 16px; border-radius:8px; font-weight:700; }.primary:hover { background:#194d39; }.quiet { background:transparent; color:var(--leaf); font-weight:700; font-size:12px; }
.time-entry { display:grid; grid-template-columns:1fr 1fr; gap:7px; }.time-entry input { min-width:0; }
.task-add { display:grid; grid-template-columns:1fr 95px 42px; gap:7px; margin-bottom:14px; }.task-add input,.task-add select { border:1px solid var(--line); border-radius:8px; padding:10px; background:#faf9f4; min-width:0; }.task-add button { border-radius:8px; background:var(--leaf); color:white; font-size:20px; }.task-list { display:grid; gap:6px; max-height:250px; overflow:auto; }.task { display:flex; gap:10px; align-items:center; border-bottom:1px solid #edf0eb; padding:9px 0; }.check { width:20px;height:20px;border:1px solid #b8c9bc;border-radius:50%; background:transparent; flex:none; color:white; font-size:12px; }.task.done .check { background:var(--leaf); border-color:var(--leaf); }.task-title { flex:1; font-size:13px; }.task.done .task-title { color:var(--muted); text-decoration:line-through; }.priority { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); }.delete { background:none; color:#a9b2ab; font-size:17px; }.empty { color:var(--muted); font-size:13px; padding:15px 0; }
.insight { grid-column:span 7; background:#e6f0dc; border-color:#d4e5c5; min-height:188px; }.insight-head { display:flex; justify-content:space-between; align-items:center; gap:10px; }.ai-button { background:#17382e; color:white; border-radius:7px; padding:9px 12px; font-size:12px; font-weight:700; }.analysis { margin:18px 0 0; white-space:pre-line; line-height:1.6; font-size:14px; }.chart { grid-column:span 5; }.bars { display:flex; gap:10px; height:124px; align-items:end; border-bottom:1px solid var(--line); padding:0 4px; }.bar-wrap { flex:1; height:100%; display:flex; flex-direction:column; justify-content:end; align-items:center; gap:6px; }.bar { width:100%; max-width:34px; min-height:3px; border-radius:5px 5px 0 0; background:var(--leaf); }.bar.phone { background:var(--coral); opacity:.85; }.bar-label { color:var(--muted); font:10px 'Space Mono',monospace; }.legend { display:flex; gap:15px; margin-top:13px; font-size:11px; color:var(--muted); }.dot { display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;background:var(--leaf); }.dot.coral { background:var(--coral); }.toast { position:fixed; bottom:20px; right:20px; background:#17382e; color:white; border-radius:8px; padding:12px 15px; font-size:13px; opacity:0; transform:translateY(10px); transition:.25s; pointer-events:none; }.toast.show { opacity:1; transform:translateY(0); }
@media(max-width:900px) { .shell { grid-template-columns:1fr; }.sidebar { padding:16px 20px; flex-direction:row; align-items:center; gap:20px; }.brand { margin:0; }.nav { display:flex; margin-left:auto; }.nav button { padding:8px; font-size:0; }.nav button:before { font-size:16px; }.nav button:nth-child(1):before { content:'⌂'; }.nav button:nth-child(2):before { content:'✓'; }.nav button:nth-child(3):before { content:'◷'; }.side-note { display:none; } main { padding:26px 20px 42px; }.metric { grid-column:span 6; }.focus,.tasks,.insight,.chart { grid-column:span 12; } }
@media(max-width:560px) { .topbar,.intro { align-items:flex-start; flex-direction:column; }.grid { gap:12px; }.panel { padding:17px; }.log-form { grid-template-columns:1fr; }.full { grid-column:auto; }.save-row { align-items:flex-start; flex-direction:column; }.metric-number { font-size:30px; }.metric { min-height:125px; } }
.time-entry { display:grid; grid-template-columns:1fr 1fr; gap:7px; }.time-entry input { min-width:0; }
@media(max-width:900px) { .sidebar { height:auto; z-index:5; } }
.quote-modal { position:fixed; inset:0; z-index:20; display:grid; place-items:center; padding:20px; background:rgba(13,28,22,.58); backdrop-filter:blur(7px); }.quote-modal[hidden] { display:none; }.quote-card { position:relative; width:min(680px,100%); min-height:430px; overflow:hidden; border-radius:18px; color:white; background-size:cover; background-position:center; box-shadow:0 28px 90px rgba(0,0,0,.32); display:flex; align-items:flex-end; }.quote-card:before { content:''; position:absolute; inset:0; background:linear-gradient(180deg,rgba(11,25,19,.05) 15%,rgba(11,25,19,.82) 100%); }.quote-content { position:relative; z-index:1; padding:38px; max-width:600px; }.quote-kicker { font:700 11px 'Space Mono',monospace; letter-spacing:1.5px; text-transform:uppercase; color:#d9f09d; }.quote-text { font-size:clamp(25px,4vw,43px); line-height:1.1; letter-spacing:-1.4px; margin:12px 0 22px; }.quote-author { display:flex; align-items:center; gap:11px; font-size:13px; font-weight:700; }.quote-author img { width:42px; height:42px; object-fit:cover; border-radius:50%; border:2px solid rgba(255,255,255,.75); }.quote-author span { display:block; color:rgba(255,255,255,.7); font-size:11px; font-weight:400; margin-top:3px; }.quote-close,.quote-next { position:absolute; z-index:2; border-radius:8px; padding:9px 12px; font-size:12px; font-weight:700; }.quote-close { top:16px; right:16px; background:rgba(0,0,0,.28); color:white; font-size:18px; line-height:1; }.quote-next { right:20px; bottom:21px; background:var(--lime); color:#17382e; }.quote-close:hover { background:rgba(0,0,0,.5); }.quote-next:hover { background:white; }
.key-modal { position:fixed; inset:0; z-index:30; display:grid; place-items:center; padding:20px; background:rgba(13,28,22,.64); backdrop-filter:blur(7px); }.key-modal[hidden] { display:none; }.key-card { width:min(480px,100%); background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:25px; box-shadow:0 28px 90px rgba(0,0,0,.25); }.key-card h2 { margin:0 0 7px; font-size:21px; }.key-card p { color:var(--muted); font-size:13px; line-height:1.5; }.key-card input { width:100%; border:1px solid var(--line); border-radius:8px; padding:12px; background:#faf9f4; }.key-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:17px; }.key-error { color:#b34f3e; font-size:12px; min-height:18px; }
</style>
</head>
<body>
<section class="quote-modal" id="quoteModal" role="dialog" aria-modal="true" aria-labelledby="quoteText">
    <div class="quote-card" id="quoteCard">
        <button class="quote-close" id="closeQuote" aria-label="Close motivation quote">×</button>
        <div class="quote-content"><div class="quote-kicker">A thought for today</div><blockquote class="quote-text" id="quoteText"></blockquote><div class="quote-author"><img id="quotePhoto" alt="Writer portrait"><div id="quoteAuthor"></div></div></div>
        <button class="quote-next" id="nextQuote">Another quote</button>
    </div>
</section>
<section class="key-modal" id="keyModal" role="dialog" aria-modal="true" aria-labelledby="keyTitle" hidden>
    <div class="key-card"><h2 id="keyTitle">Gemini needs a new key</h2><p id="keyMessage">The AI request returned an error. Paste a replacement Gemini API key below. Example format: <code>AIzaSy...your-key...</code>. It will be saved only in this local PHP app.</p><input id="apiKeyInput" type="password" autocomplete="off" placeholder="AIzaSy..."><div class="key-error" id="keyError"></div><div class="key-actions"><button type="button" class="quiet" id="closeKeyModal">Cancel</button><button type="button" class="primary" id="saveApiKey">Save key and retry</button></div></div>
</section>
<div class="shell">
<aside class="sidebar">
  <div class="brand"><span class="brand-mark">D</span> Daymark</div>
  <nav class="nav"><button class="active" data-scroll="overview">Overview</button><button data-scroll="tasks-panel">Tasks</button><button data-scroll="insight-panel">AI review</button></nav>
  <div class="side-note">A small daily record makes tomorrow easier to design.<br><br>Local-first. Your entries stay on this device.</div>
</aside>
<main>
  <div class="topbar"><div class="eyebrow">Personal operating rhythm</div><div class="date-picker">DAY <input id="selectedDate" type="date" value="<?= htmlspecialchars($today) ?>"></div></div>
  <section id="overview" class="intro"><div><div class="eyebrow">Good <?= date('l') ?></div><h1>Make the day visible.</h1></div><div class="status-pill" id="statusPill"><?= $completion >= 70 ? 'On a good track' : 'Ready to begin' ?></div><p>Track the hours that matter, notice where your phone pulls you, and turn the next action into something clear.</p></section>
  <section class="grid">
    <article class="panel metric"><div class="metric-label">Focused work</div><div class="metric-number" id="workMetric"><?= $workHours ?>h</div><div class="metric-sub">logged today</div></article>
    <article class="panel metric"><div class="metric-label">Phone time</div><div class="metric-number" id="phoneMetric"><?= $phoneHours ?>h</div><div class="metric-sub" id="phoneSub">keep it observable</div></article>
    <article class="panel metric"><div class="metric-label">Task progress</div><div class="metric-number" id="taskMetric"><?= $completion ?>%</div><div class="metric-sub" id="taskSub"><?= (int)$log['tasks_done'] ?> of <?= (int)$log['tasks_planned'] ?> planned</div></article>
    <article class="panel metric"><div class="metric-label">Reminder</div><div class="metric-number" id="reminderMetric">—</div><div class="metric-sub">browser notification</div></article>
    <article class="panel focus"><h2 class="section-title">Today’s check-in</h2><p class="section-meta">A two-minute reflection is enough. You can edit this anytime.</p><form id="dayForm" class="log-form"><div class="field"><label for="workMinutes">Focused work · minutes</label><input id="workMinutes" type="number" min="0" value="<?= (int)$log['work_minutes'] ?>"></div><div class="field"><label for="phoneMinutes">Phone use · minutes</label><input id="phoneMinutes" type="number" min="0" value="<?= (int)$log['phone_minutes'] ?>"></div><div class="field"><label for="planned">Tasks planned</label><input id="planned" type="number" min="0" value="<?= (int)$log['tasks_planned'] ?>"></div><div class="field"><label for="done">Tasks completed</label><input id="done" type="number" min="0" value="<?= (int)$log['tasks_done'] ?>"></div><div class="field full"><label for="notes">What did you learn about your energy?</label><textarea id="notes" placeholder="A pattern, a win, or what got in the way..."><?= htmlspecialchars((string)$log['notes']) ?></textarea></div><div class="save-row full"><div class="reminder-tools"><input id="reminderTime" type="time" value="09:00" aria-label="Reminder time"><button type="button" class="quiet" id="notifyButton">Enable reminders</button><button type="button" class="quiet" id="testNotifyButton">Test</button></div><button class="primary" type="submit">Save today’s check-in</button></div></form></article>
    <article class="panel tasks" id="tasks-panel"><h2 class="section-title">Next actions</h2><p class="section-meta">Keep the list small enough to finish.</p><form id="taskForm" class="task-add"><input id="taskTitle" placeholder="Add a task..." aria-label="Task title"><select id="taskPriority" aria-label="Task priority"><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select><button aria-label="Add task">+</button></form><div id="taskList" class="task-list"><?php foreach ($tasks as $task): ?><div class="task <?= $task['done'] ? 'done' : '' ?>" data-id="<?= (int)$task['id'] ?>"><button class="check" aria-label="Toggle task"><?= $task['done'] ? '✓' : '' ?></button><span class="task-title"><?= htmlspecialchars($task['title']) ?></span><span class="priority"><?= htmlspecialchars($task['priority']) ?></span><button class="delete" aria-label="Delete task">×</button></div><?php endforeach; ?><?php if (!$tasks): ?><div class="empty">No tasks yet. Add the one thing that would make today count.</div><?php endif; ?></div></article>
    <article class="panel insight" id="insight-panel"><div class="insight-head"><div><h2 class="section-title">AI reflection</h2><p class="section-meta">Five ways to turn your log into a next move.</p></div><button class="ai-button" id="analyzeButton">Review my rhythm</button></div><div class="ai-actions"><button data-ai="review">Weekly review</button><button data-ai="plan">Build my plan</button><button data-ai="phone">Coach my phone use</button><button data-ai="prioritize">Prioritize tasks</button><button data-ai="next">What next?</button></div><div class="analysis" id="analysis">Choose an AI action after saving a check-in. Gemini will use your local tracker data to make the answer specific.</div></article>
    <article class="panel chart"><h2 class="section-title">Seven-day pulse</h2><p class="section-meta">Work and phone time, in hours.</p><div class="bars" id="bars"><?php foreach ($recent as $item): $max = max(1, (int)$item['work_minutes'], (int)$item['phone_minutes']); ?><div class="bar-wrap"><div class="bar" style="height:<?= max(4, round(((int)$item['work_minutes'] / $max) * 85)) ?>%" title="<?= round((int)$item['work_minutes'] / 60, 1) ?>h work"></div><div class="bar phone" style="height:<?= max(4, round(((int)$item['phone_minutes'] / $max) * 85)) ?>%" title="<?= round((int)$item['phone_minutes'] / 60, 1) ?>h phone"></div><span class="bar-label"><?= date('D', strtotime($item['log_date']))[0] ?></span></div><?php endforeach; ?><?php if (!$recent): ?><div class="empty">Your week will take shape here.</div><?php endif; ?></div><div class="legend"><span><i class="dot"></i>work</span><span><i class="dot coral"></i>phone</span></div></article>
  </section>
</main>
</div><div class="toast" id="toast"></div>
<script>
const $ = (id) => document.getElementById(id);
const selectedDate = $('selectedDate');
let reminderTimer;
const motivationQuotes = [
    { text:'The secret of getting ahead is getting started.', author:'Mark Twain', detail:'Writer and humorist', photo:'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=160&q=85', background:'https://images.unsplash.com/photo-1499750310107-5fef28a66643?auto=format&fit=crop&w=1400&q=85' },
    { text:'It always seems impossible until it is done.', author:'Nelson Mandela', detail:'Writer and statesman', photo:'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=160&q=85', background:'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1400&q=85' },
    { text:'Well begun is half done.', author:'Aristotle', detail:'Philosopher and writer', photo:'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=160&q=85', background:'https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?auto=format&fit=crop&w=1400&q=85' },
    { text:'The future depends on what you do today.', author:'Mahatma Gandhi', detail:'Writer and activist', photo:'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?auto=format&fit=crop&w=160&q=85', background:'https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?auto=format&fit=crop&w=1400&q=85' }
];
function showMotivationQuote() { let index = Math.floor(Math.random() * motivationQuotes.length); const quote = motivationQuotes[index]; $('quoteText').textContent = `“${quote.text}”`; $('quoteAuthor').innerHTML = `${quote.author}<span>${quote.detail}</span>`; $('quotePhoto').src = quote.photo; $('quoteCard').style.backgroundImage = `url("${quote.background}")`; }
showMotivationQuote();
$('closeQuote').addEventListener('click', () => $('quoteModal').hidden=true);
$('nextQuote').addEventListener('click', showMotivationQuote);
$('quoteModal').addEventListener('click', event => { if (event.target === $('quoteModal')) $('quoteModal').hidden=true; });
document.addEventListener('keydown', event => { if (event.key === 'Escape') $('quoteModal').hidden=true; });
function setupTimeFields() { [['workMinutes','workHours','Focused work'], ['phoneMinutes','phoneHours','Phone use']].forEach(([minuteId, hourId, labelText]) => { const minutes = $(minuteId); const label = minutes.previousElementSibling; label.textContent = labelText; const hours = document.createElement('input'); hours.id = hourId; hours.type = 'number'; hours.min = '0'; hours.step = '0.25'; hours.placeholder = 'Hours'; hours.setAttribute('aria-label', `${labelText} hours`); const wrapper = document.createElement('div'); wrapper.className = 'time-entry'; minutes.parentNode.insertBefore(wrapper, minutes); wrapper.append(hours, minutes); }); }
function setTimeFields(minutes, minuteId, hourId) { const total = Number(minutes) || 0; $(hourId).value = Math.floor(total / 60); $(minuteId).value = total % 60; }
function totalMinutes(minuteId, hourId) { return Math.max(0, (Number($(hourId).value) || 0) * 60 + (Number($(minuteId).value) || 0)); }
setupTimeFields();
setTimeFields(<?= (int)$log['work_minutes'] ?>, 'workMinutes', 'workHours');
setTimeFields(<?= (int)$log['phone_minutes'] ?>, 'phoneMinutes', 'phoneHours');
document.querySelector('[data-ai="plan"]').textContent = "Make today's plan";
function toast(message) { const el = $('toast'); el.textContent = message; el.classList.add('show'); setTimeout(() => el.classList.remove('show'), 2600); }
function api(action, body = {}) { return fetch(`?action=${action}`, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...body, date:selectedDate.value}) }).then(r => r.json()); }
function hours(minutes) { return `${Math.round((Number(minutes) / 60) * 10) / 10}h`; }
function renderTasks(tasks) { $('taskList').innerHTML = tasks.length ? tasks.map(task => `<div class="task ${Number(task.done) ? 'done' : ''}" data-id="${task.id}"><button class="check" aria-label="Toggle task">${Number(task.done) ? '✓' : ''}</button><span class="task-title">${escapeHtml(task.title)}</span><span class="priority">${escapeHtml(task.priority)}</span><button class="delete" aria-label="Delete task">×</button></div>`).join('') : '<div class="empty">No tasks yet. Add the one thing that would make today count.</div>'; }
function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;', '"':'&quot;'}[char])); }
function renderData(data) { const log=data.log; setTimeFields(log.work_minutes, 'workMinutes', 'workHours'); setTimeFields(log.phone_minutes, 'phoneMinutes', 'phoneHours'); $('planned').value=log.tasks_planned; $('done').value=log.tasks_done; $('notes').value=log.notes; $('workMetric').textContent=hours(log.work_minutes); $('phoneMetric').textContent=hours(log.phone_minutes); const percentage=Number(log.tasks_planned) ? Math.min(100, Math.round((log.tasks_done/log.tasks_planned)*100)) : 0; $('taskMetric').textContent=`${percentage}%`; $('taskSub').textContent=`${log.tasks_done} of ${log.tasks_planned} planned`; $('statusPill').textContent=percentage >= 70 ? 'On a good track' : 'Ready to begin'; renderTasks(data.tasks); }
$('dayForm').addEventListener('submit', e => { e.preventDefault(); api('save_day', {work_minutes:totalMinutes('workMinutes','workHours'), phone_minutes:totalMinutes('phoneMinutes','phoneHours'), tasks_planned:$('planned').value, tasks_done:$('done').value, notes:$('notes').value}).then(result => { if(result.ok) { renderData({log:result.log, tasks:[]}); toast('Today’s check-in saved.'); } else toast(result.error); }).catch(() => toast('Could not save. Check that the PHP server is running.')); });
$('taskForm').addEventListener('submit', e => { e.preventDefault(); if (!$('taskTitle').value.trim()) return; api('add_task', {title:$('taskTitle').value, priority:$('taskPriority').value}).then(result => { if(result.ok) { renderTasks(result.tasks); $('taskTitle').value=''; toast('Task added.'); } }); });
$('taskList').addEventListener('click', e => { const task=e.target.closest('.task'); if(!task) return; const action=e.target.classList.contains('delete') ? 'delete_task' : e.target.classList.contains('check') ? 'toggle_task' : ''; if(action) api(action, {id:task.dataset.id}).then(result => result.ok && renderTasks(result.tasks)); });
selectedDate.addEventListener('change', () => fetch(`?action=data&date=${selectedDate.value}`).then(r=>r.json()).then(result => result.ok && renderData(result)));
$('analyzeButton').addEventListener('click', () => runAi('review'));
document.querySelectorAll('[data-ai]').forEach(button => button.addEventListener('click', () => runAi(button.dataset.ai)));
let retryAiMode = 'review';
function openKeyModal(message, mode) { retryAiMode=mode; $('keyMessage').innerHTML=`The AI request returned an error: <strong>${escapeHtml(message)}</strong><br><br>Paste a replacement Gemini API key below. Example format: <code>AIzaSy...your-key...</code>. It will be saved only in this local PHP app.`; $('keyError').textContent=''; $('apiKeyInput').value=''; $('keyModal').hidden=false; $('apiKeyInput').focus(); }
function runAi(mode) { retryAiMode=mode; document.querySelectorAll('[data-ai], #analyzeButton').forEach(button => button.disabled=true); saveAiButton.style.display='none'; $('analysis').textContent='Gemini is reading your tracker...'; api('analyze', {mode}).then(result => { if (!result.ok) { $('analysis').textContent=result.error; openKeyModal(result.error, mode); } else { $('analysis').textContent=result.analysis; saveAiButton.style.display='inline-block'; } }).catch(() => { const message='The AI request could not reach the PHP server.'; $('analysis').textContent=message; openKeyModal(message, mode); }).finally(() => document.querySelectorAll('[data-ai], #analyzeButton').forEach(button => button.disabled=false)); }
$('closeKeyModal').addEventListener('click', () => $('keyModal').hidden=true);
$('keyModal').addEventListener('click', event => { if (event.target === $('keyModal')) $('keyModal').hidden=true; });
$('saveApiKey').addEventListener('click', () => { const key=$('apiKeyInput').value.trim(); if (key.length < 20) { $('keyError').textContent='Please enter a complete Gemini API key.'; return; } $('saveApiKey').disabled=true; api('set_gemini_key', {api_key:key}).then(result => { if (result.ok) { $('keyModal').hidden=true; toast('Gemini key saved. Retrying AI...'); runAi(retryAiMode); } else $('keyError').textContent=result.error || 'Could not save the key.'; }).catch(() => $('keyError').textContent='Could not save the key. Check PHP folder permissions.').finally(() => $('saveApiKey').disabled=false); });
const saveAiButton = document.createElement('button'); saveAiButton.type='button'; saveAiButton.className='primary'; saveAiButton.textContent="Save as today's check-in"; saveAiButton.style.display='none'; $('analysis').after(saveAiButton);
saveAiButton.addEventListener('click', () => { const notes = $('analysis').textContent.trim(); if (!notes || notes.startsWith('Gemini is reading')) return; api('save_day', {work_minutes:totalMinutes('workMinutes','workHours'), phone_minutes:totalMinutes('phoneMinutes','phoneHours'), tasks_planned:$('planned').value, tasks_done:$('done').value, notes}).then(result => { if (result.ok) { renderData({log:result.log, tasks:[]}); toast("AI result saved to today's check-in."); } else toast(result.error); }).catch(() => toast('Could not save this check-in.')); });
$('notifyButton').addEventListener('click', enableNotifications);
$('testNotifyButton').addEventListener('click', async () => { if (!(await enableNotifications())) return; new Notification('Daymark test reminder', {body:'Notifications are working. Your next work reminder is scheduled.'}); toast('Test notification sent.'); });
async function enableNotifications() { if (!('Notification' in window)) { toast('This browser does not support notifications.'); return false; } const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission(); if (permission === 'granted') { localStorage.setItem('daymark_reminders','on'); localStorage.setItem('daymark_reminder_time', $('reminderTime').value); scheduleReminder(); toast('Reminders are on for this browser.'); return true; } toast('Notifications are blocked in browser settings.'); return false; }
function scheduleReminder() { clearTimeout(reminderTimer); if(localStorage.getItem('daymark_reminders') !== 'on') return; const [hour, minute] = (localStorage.getItem('daymark_reminder_time') || $('reminderTime').value || '09:00').split(':').map(Number); $('reminderTime').value=`${String(hour).padStart(2,'0')}:${String(minute).padStart(2,'0')}`; const now=new Date(), next=new Date(); next.setHours(hour, minute, 0, 0); if(next<=now) next.setDate(next.getDate()+1); reminderTimer=setTimeout(() => { new Notification('Daymark: make time for your work', {body:'Open your tracker and give the next important task your attention.'}); scheduleReminder(); }, next-now); $('reminderMetric').textContent=$('reminderTime').value; }
$('reminderTime').addEventListener('change', () => { localStorage.setItem('daymark_reminder_time', $('reminderTime').value); if(localStorage.getItem('daymark_reminders') === 'on') scheduleReminder(); });
if (localStorage.getItem('daymark_reminder_time')) $('reminderTime').value=localStorage.getItem('daymark_reminder_time');
if (localStorage.getItem('daymark_reminders') === 'on' && Notification.permission === 'granted') scheduleReminder();
document.querySelectorAll('[data-scroll]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.scroll).scrollIntoView({behavior:'smooth'})));
</script>
</body></html>
