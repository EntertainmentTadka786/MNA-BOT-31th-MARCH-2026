<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load channel configuration
$channels_config = include('channels_config.php');

// Define constants
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('WAITING_FILE', 'waiting.json');
define('REQUESTS_FILE', 'requests.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);

// Build master channel ID list
$all_channel_ids = [];
foreach ($channels_config['public_channels'] as $ch) $all_channel_ids[] = $ch['id'];
foreach ($channels_config['private_channels'] as $ch) $all_channel_ids[] = $ch['id'];

// Ensure files exist
foreach ([USERS_FILE, STATS_FILE, WAITING_FILE, REQUESTS_FILE] as $file) {
    if (!file_exists($file)) {
        if ($file == USERS_FILE) file_put_contents($file, json_encode(['users' => [], 'total_requests' => 0]));
        elseif ($file == STATS_FILE) file_put_contents($file, json_encode(['total_movies' => 0, 'total_users' => 0, 'total_searches' => 0, 'last_updated' => date('Y-m-d H:i:s'), 'last_backup' => null, 'last_digest' => null]));
        elseif ($file == WAITING_FILE) file_put_contents($file, json_encode([]));
        elseif ($file == REQUESTS_FILE) file_put_contents($file, json_encode(['requests' => [], 'next_id' => 1]));
        chmod($file, 0666);
    }
}
if (!file_exists(CSV_FILE)) file_put_contents(CSV_FILE, "movie_name,message_id,channel_id,date\n");
if (!file_exists(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);

// Global arrays
$movie_messages = [];
$movie_cache = [];
$waiting_users = json_decode(file_get_contents(WAITING_FILE), true);
if (!is_array($waiting_users)) $waiting_users = [];

// ==============================
// Stats management
// ==============================
function update_stats($field, $increment = 1) {
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}
function get_stats() { return json_decode(file_get_contents(STATS_FILE), true); }
function set_last_run($type, $timestamp = null) {
    $stats = get_stats();
    if ($type == 'backup') $stats['last_backup'] = $timestamp ?? date('Y-m-d');
    if ($type == 'digest') $stats['last_digest'] = $timestamp ?? date('Y-m-d');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}
function should_run_today($type) {
    $stats = get_stats();
    $last = ($type == 'backup') ? ($stats['last_backup'] ?? '') : ($stats['last_digest'] ?? '');
    return ($last != date('Y-m-d'));
}

// ==============================
// Request System Functions
// ==============================
function load_requests() {
    return json_decode(file_get_contents(REQUESTS_FILE), true);
}
function save_requests($data) {
    file_put_contents(REQUESTS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}
function add_request($user_id, $movie_name, $username = '') {
    $data = load_requests();
    $request_id = $data['next_id']++;
    $data['requests'][] = [
        'id' => $request_id,
        'user_id' => $user_id,
        'movie' => trim($movie_name),
        'status' => 'pending',
        'timestamp' => date('Y-m-d H:i:s'),
        'username' => $username
    ];
    save_requests($data);
    return $request_id;
}
function get_pending_requests() {
    $data = load_requests();
    $pending = [];
    foreach ($data['requests'] as $req) {
        if ($req['status'] == 'pending') $pending[] = $req;
    }
    return $pending;
}
function approve_request($request_id, $admin_id) {
    $data = load_requests();
    foreach ($data['requests'] as &$req) {
        if ($req['id'] == $request_id && $req['status'] == 'pending') {
            $req['status'] = 'approved';
            $req['approved_by'] = $admin_id;
            $req['approved_at'] = date('Y-m-d H:i:s');
            save_requests($data);
            return $req['user_id'];
        }
    }
    return false;
}
function bulk_approve($count, $admin_id) {
    $pending = get_pending_requests();
    $approved_users = [];
    $approved_count = 0;
    foreach ($pending as $req) {
        if ($approved_count >= $count) break;
        $user_id = approve_request($req['id'], $admin_id);
        if ($user_id) {
            $approved_users[] = $user_id;
            $approved_count++;
        }
    }
    return ['users' => $approved_users, 'count' => $approved_count];
}
function get_user_requests($user_id) {
    $data = load_requests();
    $user_reqs = [];
    foreach ($data['requests'] as $req) {
        if ($req['user_id'] == $user_id && $req['status'] == 'pending') {
            $user_reqs[] = $req;
        }
    }
    return $user_reqs;
}

// ==============================
// Caching & Search
// ==============================
function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) return $movie_cache['data'];
    $movie_cache = ['data' => load_and_clean_csv(), 'timestamp' => time()];
    return $movie_cache['data'];
}
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = [];
    foreach ($movie_messages as $movie => $channels_data) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else { similar_text($movie, $query_lower, $similarity); if ($similarity > 60) $score = $similarity; }
        if ($score > 0) {
            $total_count = 0; foreach ($channels_data as $msgs) $total_count += count($msgs);
            $results[$movie] = ['score' => $score, 'count' => $total_count, 'channels' => $channels_data];
        }
    }
    uasort($results, fn($a,$b)=>$b['score']-$a['score']);
    return array_slice($results, 0, 10);
}
function detect_language($text) {
    $hindi = ['फिल्म','मूवी','डाउनलोड','हिंदी']; $eng = ['movie','download','watch','print'];
    $hc=0; $ec=0;
    foreach ($hindi as $k) if (mb_strpos($text,$k)!==false) $hc++;
    foreach ($eng as $k) if (stripos($text,$k)!==false) $ec++;
    return $hc > $ec ? 'hindi' : 'english';
}
function send_multilingual_response($chat_id, $type, $lang) {
    $resp = [
        'hindi' => [
            'welcome' => "🎬 स्वागत है! कौन सी मूवी चाहिए?",
            'found' => "✅ मूवी मिल गई!",
            'not_found' => "❌ अभी यह मूवी उपलब्ध नहीं है",
            'searching' => "🔍 आपकी मूवी ढूंढ रहे हैं..."
        ],
        'english' => [
            'welcome' => "🎬 Welcome! Which movie do you want?",
            'found' => "✅ Movie found!",
            'not_found' => "❌ Movie not available yet",
            'searching' => "🔍 Searching for your movie..."
        ]
    ];
    sendMessage($chat_id, $resp[$lang][$type]);
}

// ==============================
// Backup & Digest
// ==============================
function delete_directory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!delete_directory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}
function auto_backup() {
    if (!should_run_today('backup')) return;
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0777, true);
    foreach ([CSV_FILE, USERS_FILE, STATS_FILE, WAITING_FILE, REQUESTS_FILE] as $f) {
        if (file_exists($f)) copy($f, $backup_dir . '/' . basename($f) . '.bak');
    }
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    usort($old, fn($a,$b) => filemtime($a) - filemtime($b));
    while (count($old) > 7) delete_directory(array_shift($old));
    set_last_run('backup');
}
function send_daily_digest() {
    if (!should_run_today('digest')) return;
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $movies = [];
    $h = fopen(CSV_FILE, 'r');
    fgetcsv($h);
    while ($r = fgetcsv($h)) {
        if (count($r) >= 4 && $r[3] == $yesterday) $movies[] = $r[0];
    }
    fclose($h);
    if (!empty($movies)) {
        $users = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users['users'] as $uid => $ud) {
            $msg = "📅 Daily Movie Digest\n📢 Join: @EntertainmentTadka786\n\n🎬 Yesterday's Uploads ($yesterday):\n";
            $msg .= implode("\n", array_slice($movies, 0, 10));
            if (count($movies) > 10) $msg .= "\n... and " . (count($movies) - 10) . " more";
            $msg .= "\n\n🔥 Total: " . count($movies);
            sendMessage($uid, $msg, null, 'HTML');
        }
    }
    set_last_run('digest');
}

// ==============================
// CSV Functions (with channel_id)
// ==============================
function load_and_clean_csv() {
    global $movie_messages;
    if (!file_exists(CSV_FILE)) {
        file_put_contents(CSV_FILE, "movie_name,message_id,channel_id,date\n");
        return [];
    }
    $data = [];
    $h = fopen(CSV_FILE, 'r');
    fgetcsv($h);
    while ($r = fgetcsv($h)) {
        if (count($r) >= 3) {
            $name = trim($r[0]);
            $mid = intval($r[1]);
            $ch = (count($r) >= 4) ? $r[2] : '';
            $date = (count($r) >= 4) ? $r[3] : $r[2];
            if (!empty($name) && is_numeric($mid)) {
                $data[] = ['movie_name' => $name, 'message_id' => $mid, 'channel_id' => $ch, 'date' => $date];
                $movie = strtolower($name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                if (!isset($movie_messages[$movie][$ch])) $movie_messages[$movie][$ch] = [];
                $movie_messages[$movie][$ch][] = $mid;
            }
        }
    }
    fclose($h);
    update_stats('total_movies', 0);
    update_stats('total_movies', count($data));
    $h = fopen(CSV_FILE, 'w');
    fputcsv($h, ['movie_name', 'message_id', 'channel_id', 'date']);
    foreach ($data as $row) fputcsv($h, [$row['movie_name'], $row['message_id'], $row['channel_id'], $row['date']]);
    fclose($h);
    return $data;
}
function load_movies_from_csv() {
    $movies = [];
    if (!file_exists(CSV_FILE)) return $movies;
    $h = fopen(CSV_FILE, 'r');
    fgetcsv($h);
    while ($r = fgetcsv($h)) {
        if (count($r) >= 4) $movies[] = ['name' => $r[0], 'message_id' => $r[1], 'channel_id' => $r[2], 'date' => $r[3]];
        elseif (count($r) == 3) $movies[] = ['name' => $r[0], 'message_id' => $r[1], 'channel_id' => '', 'date' => $r[2]];
    }
    fclose($h);
    return $movies;
}
function append_movie($movie_name, $message_id, $channel_id) {
    if (empty(trim($movie_name)) || !is_numeric($message_id)) return;
    $date = date('d-m-Y');
    $h = fopen(CSV_FILE, 'a');
    fputcsv($h, [trim($movie_name), intval($message_id), $channel_id, $date]);
    fclose($h);
    global $movie_messages;
    $movie = strtolower(trim($movie_name));
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    if (!isset($movie_messages[$movie][$channel_id])) $movie_messages[$movie][$channel_id] = [];
    $movie_messages[$movie][$channel_id][] = intval($message_id);
    global $waiting_users;
    $changed = false;
    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $ud) {
                list($chat, $uid) = $ud;
                forwardMessage($chat, $channel_id, $message_id);
                sendMessage($chat, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
            $changed = true;
        }
    }
    if ($changed) file_put_contents(WAITING_FILE, json_encode($waiting_users));
    update_stats('total_movies', 1);
}
function advanced_search($chat_id, $user_id, $query) {
    global $movie_messages, $waiting_users;
    $q = strtolower(trim($query));
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters");
        return;
    }
    $found = smart_search($q);
    if (!empty($found)) {
        send_multilingual_response($chat_id, 'found', detect_language($query));
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $c = 1;
        foreach ($found as $movie => $d) {
            $msg .= "$c. $movie (" . $d['count'] . " messages)\n";
            $c++; if ($c > 15) break;
        }
        sendMessage($chat_id, $msg);
        $keyboard = ['inline_keyboard' => []];
        foreach (array_slice(array_keys($found), 0, 5) as $m) {
            $keyboard['inline_keyboard'][] = [['text' => "🎬 " . ucwords($m), 'callback_data' => $m]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
    } else {
        send_multilingual_response($chat_id, 'not_found', detect_language($query));
        if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
        $waiting_users[$q][] = [$chat_id, $user_id];
        file_put_contents(WAITING_FILE, json_encode($waiting_users));
    }
    update_stats('total_searches', 1);
}

// ==============================
// Admin stats
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users['users'] ?? []);
    $pending_reqs = count(get_pending_requests());
    $msg = "📊 <b>Bot Statistics</b>\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "📋 Pending Requests: $pending_reqs\n";
    $msg .= "💾 Last Backup: " . ($stats['last_backup'] ?? 'Never') . "\n";
    $msg .= "📅 Last Digest: " . ($stats['last_digest'] ?? 'Never') . "\n\n";
    $recent = array_slice(load_and_clean_csv(), -5);
    $msg .= "📈 <b>Recent Uploads:</b>\n";
    foreach ($recent as $m) $msg .= "• " . $m['movie_name'] . " (" . $m['date'] . ")\n";
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Telegram API
// ==============================
function apiRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $opts = ['http' => [
        'method' => 'POST',
        'content' => http_build_query($params),
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'ignore_errors' => true
    ]];
    $ctx = stream_context_create($opts);
    try {
        $res = file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log("API Request failed: $method");
            return false;
        }
        $dec = json_decode($res, true);
        if (isset($dec['ok']) && $dec['ok'] === true) return $dec;
        error_log("API Error in $method: " . ($dec['description'] ?? 'Unknown error'));
        return false;
    } catch (Exception $e) {
        error_log("Exception in apiRequest: " . $e->getMessage());
        return false;
    }
}
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    return apiRequest('sendMessage', $data);
}
function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $res = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return ($res !== false);
}
function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    return apiRequest('answerCallbackQuery', $data);
}

// ==============================
// Command functions
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua.");
        return;
    }
    $counts = [];
    $h = fopen(CSV_FILE, 'r');
    fgetcsv($h);
    while ($r = fgetcsv($h)) {
        $date = (count($r) >= 4) ? $r[3] : $r[2];
        $counts[$date] = ($counts[$date] ?? 0) + 1;
    }
    fclose($h);
    krsort($counts);
    $msg = "📅 <b>Movies Upload Record</b>\n\n";
    $total_movies = 0;
    $total_days = 0;
    foreach ($counts as $date => $c) {
        $msg .= "➡️ $date: $c movies\n";
        $total_movies += $c;
        $total_days++;
    }
    $msg .= "\n📊 <b>Summary:</b>\n• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1, $total_days), 2);
    sendMessage($chat_id, $msg, null, 'HTML');
}
function total_uploads($chat_id, $page = 1) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua.");
        return;
    }
    $all = load_movies_from_csv();
    $total = count($all);
    $today = date('d-m-Y');
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $today_c = $yest_c = $weekly = 0;
    foreach ($all as $m) {
        if ($m['date'] == $today) $today_c++;
        if ($m['date'] == $yesterday) $yest_c++;
        $d = DateTime::createFromFormat('d-m-Y', $m['date']);
        if ($d && $d->diff(new DateTime())->days <= 7) $weekly++;
    }
    $all = array_reverse($all);
    $per_page = 5;
    $total_pages = ceil($total / $per_page);
    $current_page = max(1, min($page, $total_pages));
    $start = ($current_page - 1) * $per_page;
    $paginated = array_slice($all, $start, $per_page);
    $msg = "📊 <b>Upload Statistics</b>\n\n";
    $msg .= "• 🎬 Total: $total movies\n";
    $msg .= "• 🚀 Today: $today_c movies\n";
    $msg .= "• 📈 Yesterday: $yest_c movies\n";
    $msg .= "• 📅 Last 7 days: $weekly movies\n";
    $msg .= "• ⭐ Daily avg: " . round($total / max(1, count(array_unique(array_column($all, 'date')))), 2) . " movies\n\n";
    $msg .= "🎬 <b>Movies List (Page $current_page/$total_pages):</b>\n\n";
    $idx = 1;
    foreach ($paginated as $m) {
        $msg .= "<b>" . ($start + $idx) . ".</b> " . $m['name'] . "\n   📅: " . $m['date'] . " | Ch: " . ($m['channel_id'] ?: 'unknown') . "\n\n";
        $idx++;
    }
    $keyboard = null;
    if ($total_pages > 1) {
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        if ($current_page > 1) $row[] = ['text' => '⏮️ Previous', 'callback_data' => 'uploads_page_' . ($current_page - 1)];
        if ($current_page < $total_pages) $row[] = ['text' => '⏭️ Next', 'callback_data' => 'uploads_page_' . ($current_page + 1)];
        if (!empty($row)) $keyboard['inline_keyboard'][] = $row;
        $keyboard['inline_keyboard'][] = [
            ['text' => '🎬 View Current Page', 'callback_data' => 'view_current_movie'],
            ['text' => '🛑 Stop', 'callback_data' => 'uploads_stop']
        ];
    }
    sendMessage($chat_id, $msg, $keyboard, 'HTML');
}
function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ CSV file not found.");
        return;
    }
    $h = fopen(CSV_FILE, 'r');
    fgetcsv($h);
    $index = 1;
    $msg = "";
    while ($r = fgetcsv($h)) {
        if (count($r) >= 4) $line = "$index. {$r[0]} | ID: {$r[1]} | Ch: {$r[2]} | Date: {$r[3]}\n";
        elseif (count($r) == 3) $line = "$index. {$r[0]} | ID: {$r[1]} | Ch: old | Date: {$r[2]}\n";
        else continue;
        if (strlen($msg) + strlen($line) > 4000) {
            sendMessage($chat_id, $msg);
            $msg = "";
        }
        $msg .= $line;
        $index++;
    }
    fclose($h);
    if (!empty($msg)) sendMessage($chat_id, $msg);
}

// ==============================
// Main update processing
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();
    auto_backup();
    send_daily_digest();

    // Channel posts
    if (isset($update['channel_post'])) {
        $msg = $update['channel_post'];
        $ch_id = $msg['chat']['id'];
        if (in_array($ch_id, $all_channel_ids)) {
            $text = $msg['text'] ?? $msg['caption'] ?? '';
            if (!empty(trim($text))) append_movie($text, $msg['message_id'], $ch_id);
        }
    }

    // User messages
    if (isset($update['message'])) {
        $msg = $update['message'];
        $chat_id = $msg['chat']['id'];
        $user_id = $msg['from']['id'];
        $text = trim($msg['text'] ?? '');
        $username = $msg['from']['username'] ?? '';

        // Register/update user
        $users = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users['users'][$user_id])) {
            $users['users'][$user_id] = [
                'first_name' => $msg['from']['first_name'] ?? '',
                'last_name' => $msg['from']['last_name'] ?? '',
                'username' => $username,
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s')
            ];
            $users['total_requests'] = ($users['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        } else {
            $users['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
            file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        }

        // Command handling
        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text, 2);
            $cmd = $parts[0];
            $arg = $parts[1] ?? '';

            switch ($cmd) {
                case '/start':
                    $lang = detect_language($text);
                    send_multilingual_response($chat_id, 'welcome', $lang);
                    $ch_list = "";
                    foreach ($channels_config['public_channels'] as $ch) $ch_list .= $ch['username'] . " - " . $ch['name'] . "\n";
                    sendMessage($chat_id, "📢 Join our channels:\n$ch_list\n\n🤖 Commands:\n/request [movie] - Request a movie\n/myrequests - Your pending requests\n/checkdate - Date-wise stats\n/totaluploads - Movie list\n/help - Help\n\n🔍 Simply type any movie name to search!", null, 'HTML');
                    break;
                case '/help':
                    $help = "🤖 <b>Available Commands</b>\n\n";
                    $help .= "📌 <b>For Everyone:</b>\n";
                    $help .= "/start - Welcome message\n";
                    $help .= "/help - This help\n";
                    $help .= "/request [movie] - Request a movie\n";
                    $help .= "/myrequests - View your pending requests\n";
                    $help .= "/checkdate - Date-wise upload stats\n";
                    $help .= "/totaluploads - Paginated movie list\n";
                    $help .= "/testcsv - Raw CSV viewer\n\n";
                    $help .= "👑 <b>Admin Only:</b>\n";
                    $help .= "/pending_request - View all pending requests\n";
                    $help .= "/bulk_approve [count] - Approve first N requests\n";
                    $help .= "/stats - Bot statistics\n\n";
                    $help .= "🔍 <b>Just type a movie name to search!</b>";
                    sendMessage($chat_id, $help, null, 'HTML');
                    break;
                case '/checkdate':
                    check_date($chat_id);
                    break;
                case '/totaluploads':
                    total_uploads($chat_id);
                    break;
                case '/testcsv':
                    test_csv($chat_id);
                    break;
                case '/stats':
                    if ($user_id == 1080317415) admin_stats($chat_id);
                    else sendMessage($chat_id, "⛔ This command is only for admin.");
                    break;
                case '/request':
                    if (empty($arg)) {
                        sendMessage($chat_id, "❌ Usage: /request [movie name]\nExample: /request Avengers Endgame");
                    } else {
                        $req_id = add_request($user_id, $arg, $username);
                        sendMessage($chat_id, "✅ Your request for '<b>" . htmlspecialchars($arg) . "</b>' has been submitted (ID: $req_id).\nAdmin will review it and notify you.", null, 'HTML');
                    }
                    break;
                case '/myrequests':
                    $user_reqs = get_user_requests($user_id);
                    if (empty($user_reqs)) {
                        sendMessage($chat_id, "📭 You have no pending requests.");
                    } else {
                        $txt = "📋 <b>Your pending requests:</b>\n\n";
                        foreach ($user_reqs as $req) {
                            $txt .= "• [{$req['id']}] {$req['movie']} (requested on {$req['timestamp']})\n";
                        }
                        sendMessage($chat_id, $txt, null, 'HTML');
                    }
                    break;
                case '/pending_request':
                    if ($user_id != 1080317415) {
                        sendMessage($chat_id, "⛔ Admin only command.");
                        break;
                    }
                    $pending = get_pending_requests();
                    if (empty($pending)) {
                        sendMessage($chat_id, "📭 No pending requests.");
                    } else {
                        $txt = "📋 <b>Pending Requests (Total: " . count($pending) . ")</b>\n\n";
                        $idx = 1;
                        foreach ($pending as $req) {
                            $txt .= "$idx. [ID:{$req['id']}] <b>{$req['movie']}</b>\n   👤 User: {$req['user_id']} (@{$req['username']})\n   🕒 {$req['timestamp']}\n\n";
                            $idx++;
                        }
                        $txt .= "Use <code>/bulk_approve [count]</code> to approve first 'count' requests.";
                        sendMessage($chat_id, $txt, null, 'HTML');
                    }
                    break;
                case '/bulk_approve':
                    if ($user_id != 1080317415) {
                        sendMessage($chat_id, "⛔ Admin only command.");
                        break;
                    }
                    if (!is_numeric($arg) || $arg <= 0) {
                        sendMessage($chat_id, "❌ Usage: /bulk_approve [count]\nExample: /bulk_approve 5");
                    } else {
                        $result = bulk_approve(intval($arg), $user_id);
                        if ($result['count'] == 0) {
                            sendMessage($chat_id, "⚠️ No pending requests to approve.");
                        } else {
                            sendMessage($chat_id, "✅ Successfully approved <b>{$result['count']}</b> request(s).", null, 'HTML');
                            foreach ($result['users'] as $uid) {
                                sendMessage($uid, "🎉 <b>Good News!</b> Your movie request has been <b>approved</b> by admin. The movie will be added soon. Stay tuned!", null, 'HTML');
                            }
                        }
                    }
                    break;
                default:
                    sendMessage($chat_id, "❌ Unknown command. Type /help for available commands.");
            }
        }
        // Non-command text: search
        elseif (!empty($text)) {
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $user_id, $text);
        }
    }

    // Callback queries
    if (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        $chat_id = $cb['message']['chat']['id'];
        $data = $cb['data'];
        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $total = 0;
            foreach ($movie_messages[$movie_lower] as $ch => $ids) {
                foreach ($ids as $mid) {
                    if (forwardMessage($chat_id, $ch, $mid)) $total++;
                    usleep(200000);
                }
            }
            sendMessage($chat_id, "✅ '<b>" . htmlspecialchars($data) . "</b>' ke $total messages forward ho gaye!\n\n📢 Join our channel: @EntertainmentTadka786", null, 'HTML');
            answerCallbackQuery($cb['id'], "🎬 $total messages forwarded!");
        } elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($cb['id'], "Page $page loaded");
        } elseif ($data == 'view_current_movie') {
            $msg_text = $cb['message']['text'];
            if (preg_match('/Page (\d+)\/(\d+)/', $msg_text, $matches)) {
                $page = $matches[1];
                $all = load_movies_from_csv();
                $all = array_reverse($all);
                $per = 5;
                $start = ($page - 1) * $per;
                $current = array_slice($all, $start, $per);
                $fwd = 0;
                foreach ($current as $m) {
                    if (forwardMessage($chat_id, $m['channel_id'], $m['message_id'])) $fwd++;
                    usleep(500000);
                }
                sendMessage($chat_id, $fwd > 0 ? "✅ Current page ki $fwd movies forward ho gayi!" : "❌ Kuch technical issue hai. Baad mein try karein.");
            }
            answerCallbackQuery($cb['id'], "Movies forwarding...");
        } elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totaluploads again to restart.");
            answerCallbackQuery($cb['id'], "Pagination stopped");
        } else {
            sendMessage($chat_id, "❌ Movie not found: " . htmlspecialchars($data));
            answerCallbackQuery($cb['id'], "❌ Not available");
        }
    }
}

// Webhook setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $url]);
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . json_encode($result) . "</p>";
    echo "<p>Webhook URL: " . $url . "</p>";
    $bot_info = apiRequest('getMe');
    if ($bot_info && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . $bot_info['result']['first_name'] . "</p>";
        echo "<p>Username: @" . $bot_info['result']['username'] . "</p>";
    }
    exit;
}

// Info page
if (!$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $pending_count = count(get_pending_requests());
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<p><strong>Pending Requests:</strong> $pending_count</p>";
    echo "<p><strong>Last Backup:</strong> " . ($stats['last_backup'] ?? 'Never') . "</p>";
    echo "<p><strong>Last Digest:</strong> " . ($stats['last_digest'] ?? 'Never') . "</p>";
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/request [movie]</code> - Request a movie</li>";
    echo "<li><code>/myrequests</code> - Your pending requests</li>";
    echo "<li><code>/checkdate</code> - Date-wise stats</li>";
    echo "<li><code>/totaluploads</code> - Upload statistics</li>";
    echo "<li><code>/testcsv</code> - View all movies</li>";
    echo "<li><code>/help</code> - Help message</li>";
    echo "<li><code>/pending_request</code> - (Admin) View all requests</li>";
    echo "<li><code>/bulk_approve [count]</code> - (Admin) Bulk approve</li>";
    echo "<li><code>/stats</code> - (Admin) Bot statistics</li>";
    echo "</ul>";
    echo "<h3>📊 File Status</h3>";
    echo "<ul>";
    echo "<li>CSV File: " . (is_writable(CSV_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Users File: " . (is_writable(USERS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Stats File: " . (is_writable(STATS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Waiting File: " . (is_writable(WAITING_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Requests File: " . (is_writable(REQUESTS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "</ul>";
    echo "<h3>🌟 Special Features</h3>";
    echo "<ul>";
    echo "<li>🤖 AI-Powered Search</li>";
    echo "<li>📺 Multi-Channel Support (Public + Private)</li>";
    echo "<li>📋 Movie Request System</li>";
    echo "<li>🔔 Persistent Waiting List</li>";
    echo "<li>🌐 Multi-Language (Hindi/English)</li>";
    echo "<li>⚡ Smart Caching</li>";
    echo "<li>🛡️ Auto-Backup with Cleanup</li>";
    echo "<li>📅 Daily Digest</li>";
    echo "<li>✅ API Error Handling</li>";
    echo "</ul>";
}
?>
