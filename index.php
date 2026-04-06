<?php
// Load environment variables
if (file_exists('.env')) {
    $env = parse_ini_file('.env');
    foreach ($env as $key => $value) {
        putenv("$key=$value");
    }
}

// Bot Token & Channel (from env)
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('ADMIN_ID', intval(getenv('ADMIN_ID')));
define('REQUEST_GROUP_ID', intval(getenv('REQUEST_GROUP_ID')));
define('REQUEST_GROUP_USERNAME', getenv('REQUEST_GROUP_USERNAME'));
define('CSV_FILE', getenv('CSV_FILE') ?: 'movies.csv');
define('USERS_FILE', getenv('USERS_FILE') ?: 'users.json');
define('STATS_FILE', getenv('STATS_FILE') ?: 'bot_stats.json');
define('WAITING_FILE', getenv('WAITING_FILE') ?: 'waiting.json');
define('BACKUP_DIR', getenv('BACKUP_DIR') ?: 'backups/');
define('CACHE_EXPIRY', 300);

// Parse public channels
$publicChannelsStr = getenv('PUBLIC_CHANNELS');
$publicChannels = [];
if ($publicChannelsStr) {
    $pairs = explode(',', $publicChannelsStr);
    foreach ($pairs as $pair) {
        list($username, $id) = explode('|', $pair);
        $publicChannels[trim($id)] = trim($username);
    }
}
define('PUBLIC_CHANNELS', serialize($publicChannels));

// Parse private channels (optional)
$privateChannelsStr = getenv('PRIVATE_CHANNELS');
$privateChannels = $privateChannelsStr ? array_map('trim', explode(',', $privateChannelsStr)) : [];
define('PRIVATE_CHANNELS', serialize($privateChannels));

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure files exist
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
    chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    // New CSV with channel_id column
    file_put_contents(CSV_FILE, "movie_name,message_id,channel_id,date\n");
    chmod(CSV_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0,
        'total_users' => 0,
        'total_searches' => 0,
        'last_updated' => date('Y-m-d H:i:s'),
        'last_backup' => null,
        'last_digest' => null
    ]));
    chmod(STATS_FILE, 0666);
}

if (!file_exists(WAITING_FILE)) {
    file_put_contents(WAITING_FILE, json_encode([]));
    chmod(WAITING_FILE, 0666);
}

if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0777, true);
}

// Global arrays
$movie_messages = array(); // stores ['movie_name' => [['msg_id','channel_id'],...]]
$movie_cache = array();
$waiting_users = json_decode(file_get_contents(WAITING_FILE), true);
if (!is_array($waiting_users)) $waiting_users = array();

// ==============================
// Stats functions
// ==============================
function update_stats($field, $increment = 1) {
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    return json_decode(file_get_contents(STATS_FILE), true);
}

function set_last_run($type, $timestamp = null) {
    $stats = get_stats();
    $stats["last_$type"] = $timestamp ?? date('Y-m-d');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function should_run_today($type) {
    $stats = get_stats();
    $last = $stats["last_$type"] ?? '';
    return ($last != date('Y-m-d'));
}

// ==============================
// Caching
// ==============================
function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = ['data' => load_and_clean_csv(), 'timestamp' => time()];
    return $movie_cache['data'];
}

// ==============================
// AI Smart Search
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = [];
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else { similar_text($movie, $query_lower, $similarity); if ($similarity > 60) $score = $similarity; }
        if ($score > 0) $results[$movie] = ['score' => $score, 'count' => count($entries)];
    }
    uasort($results, fn($a,$b) => $b['score'] - $a['score']);
    return array_slice($results, 0, 10);
}

// ==============================
// Multi-language
// ==============================
function detect_language($text) {
    $hindi_keywords = ['फिल्म', 'मूवी', 'डाउनलोड', 'हिंदी'];
    $english_keywords = ['movie', 'download', 'watch', 'print'];
    $hindi_count = $english_count = 0;
    foreach ($hindi_keywords as $kw) if (mb_strpos($text, $kw) !== false) $hindi_count++;
    foreach ($english_keywords as $kw) if (stripos($text, $kw) !== false) $english_count++;
    return $hindi_count > $english_count ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $type, $lang) {
    $responses = [
        'hindi' => ['welcome'=>"🎬 स्वागत है! कौन सी मूवी चाहिए?", 'found'=>"✅ मूवी मिल गई!", 'not_found'=>"❌ अभी यह मूवी उपलब्ध नहीं है", 'searching'=>"🔍 आपकी मूवी ढूंढ रहे हैं..."],
        'english' => ['welcome'=>"🎬 Welcome! Which movie do you want?", 'found'=>"✅ Movie found!", 'not_found'=>"❌ Movie not available yet", 'searching'=>"🔍 Searching for your movie..."]
    ];
    sendMessage($chat_id, $responses[$lang][$type]);
}

// ==============================
// Backup with cleanup
// ==============================
function delete_directory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) if ($item != '.' && $item != '..') delete_directory($dir . DIRECTORY_SEPARATOR . $item);
    return rmdir($dir);
}

function auto_backup() {
    if (!should_run_today('backup')) return;
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0777, true);
    foreach ([CSV_FILE, USERS_FILE, STATS_FILE, WAITING_FILE] as $file) {
        if (file_exists($file)) copy($file, $backup_dir . '/' . basename($file) . '.bak');
    }
    $old_backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    usort($old_backups, fn($a,$b) => filemtime($a) - filemtime($b));
    while (count($old_backups) > 7) delete_directory(array_shift($old_backups));
    set_last_run('backup');
}

// ==============================
// Daily digest
// ==============================
function send_daily_digest() {
    if (!should_run_today('digest')) return;
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $yesterday_movies = [];
    $handle = fopen(CSV_FILE, "r");
    if ($handle) {
        fgetcsv($handle); // skip header
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 4 && $row[3] == $yesterday) $yesterday_movies[] = $row[0];
        }
        fclose($handle);
    }
    if (!empty($yesterday_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $user_id => $user_data) {
            $msg = "📅 <b>Daily Movie Digest</b>\n\n📢 Join our channel: @EntertainmentTadka786\n\n🎬 Yesterday's Uploads ($yesterday):\n";
            foreach (array_slice($yesterday_movies, 0, 10) as $movie) $msg .= "• $movie\n";
            if (count($yesterday_movies) > 10) $msg .= "• ... and " . (count($yesterday_movies)-10) . " more\n";
            $msg .= "\n🔥 Total: " . count($yesterday_movies) . " movies";
            sendMessage($user_id, $msg, null, 'HTML');
        }
    }
    set_last_run('digest');
}

// ==============================
// CSV functions (with channel_id)
// ==============================
function load_and_clean_csv() {
    global $movie_messages;
    $filename = CSV_FILE;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,channel_id,date\n");
        return [];
    }
    $data = [];
    $handle = fopen($filename, "r");
    if ($handle) {
        fgetcsv($handle); // header
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 4 && is_numeric($row[1]) && !empty(trim($row[0]))) {
                $entry = [
                    'movie_name' => trim($row[0]),
                    'message_id' => intval($row[1]),
                    'channel_id' => trim($row[2]),
                    'date' => $row[3]
                ];
                $data[] = $entry;
                $movie = strtolower($entry['movie_name']);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = ['msg_id' => $entry['message_id'], 'channel_id' => $entry['channel_id']];
            }
        }
        fclose($handle);
    }
    update_stats('total_movies', 0);
    update_stats('total_movies', count($data));
    // Rewrite cleaned data
    $handle = fopen($filename, "w");
    fputcsv($handle, ['movie_name', 'message_id', 'channel_id', 'date']);
    foreach ($data as $row) fputcsv($handle, [$row['movie_name'], $row['message_id'], $row['channel_id'], $row['date']]);
    fclose($handle);
    return $data;
}

function append_movie($movie_name, $message_id, $channel_id) {
    if (empty(trim($movie_name)) || !is_numeric($message_id)) return;
    $date = date('d-m-Y');
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, [trim($movie_name), intval($message_id), $channel_id, $date]);
    fclose($handle);
    global $movie_messages;
    $movie = strtolower(trim($movie_name));
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = ['msg_id' => intval($message_id), 'channel_id' => $channel_id];
    // Notify waiting users
    global $waiting_users;
    $changed = false;
    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                forwardMessage($user_chat_id, $channel_id, $message_id);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
            $changed = true;
        }
    }
    if ($changed) file_put_contents(WAITING_FILE, json_encode($waiting_users));
    update_stats('total_movies', 1);
}

function load_movies_from_csv() {
    $movies = [];
    if (!file_exists(CSV_FILE)) return $movies;
    $handle = fopen(CSV_FILE, "r");
    if ($handle) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 4) $movies[] = ['name'=>$row[0], 'message_id'=>$row[1], 'channel_id'=>$row[2], 'date'=>$row[3]];
        }
        fclose($handle);
    }
    return $movies;
}

// ==============================
// Advanced search
// ==============================
function advanced_search($chat_id, $user_id, $query) {
    global $movie_messages, $waiting_users;
    $query_lower = strtolower(trim($query));
    if (strlen($query_lower) < 2) { sendMessage($chat_id, "❌ At least 2 characters"); return; }
    $found = smart_search($query_lower);
    if (!empty($found)) {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'found', $lang);
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i=1; foreach ($found as $movie=>$info) { $msg .= "$i. $movie ({$info['count']})\n"; if(++$i>15) break; }
        sendMessage($chat_id, $msg);
        $top = array_slice(array_keys($found),0,5);
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($top as $movie) $keyboard['inline_keyboard'][] = [['text'=>"🎬 ".ucwords($movie), 'callback_data'=>$movie]];
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
    } else {
        send_multilingual_response($chat_id, 'not_found', detect_language($query));
        if (!isset($waiting_users[$query_lower])) $waiting_users[$query_lower] = [];
        $waiting_users[$query_lower][] = [$chat_id, $user_id];
        file_put_contents(WAITING_FILE, json_encode($waiting_users));
    }
    update_stats('total_searches', 1);
}

// ==============================
// Admin stats
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $msg = "📊 <b>Bot Statistics</b>\n\n🎬 Movies: {$stats['total_movies']}\n👥 Users: $total_users\n🔍 Searches: {$stats['total_searches']}\n🕒 Last Updated: {$stats['last_updated']}\n💾 Last Backup: {$stats['last_backup'] ?? 'Never'}\n📅 Last Digest: {$stats['last_digest'] ?? 'Never'}\n\n📈 Recent Uploads:\n";
    $recent = array_slice(load_and_clean_csv(), -5);
    foreach ($recent as $m) $msg .= "• {$m['movie_name']} ({$m['date']})\n";
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Telegram API (error handling)
// ==============================
function apiRequest($method, $params=[]) {
    $url = "https://api.telegram.org/bot".BOT_TOKEN."/".$method;
    $options = ['http'=>['method'=>'POST','content'=>http_build_query($params),'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'ignore_errors'=>true]];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) { error_log("API Request failed: $method"); return false; }
    $decoded = json_decode($result, true);
    if ($decoded && $decoded['ok'] === true) return $decoded;
    error_log("API Error $method: ".($decoded['description']??'Unknown'));
    return false;
}

function sendMessage($chat_id,$text,$reply_markup=null,$parse_mode=null) {
    $data = ['chat_id'=>$chat_id,'text'=>$text];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    return apiRequest('sendMessage', $data);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $res = apiRequest('forwardMessage', ['chat_id'=>$chat_id, 'from_chat_id'=>$from_chat_id, 'message_id'=>$message_id]);
    return ($res !== false);
}

function answerCallbackQuery($id, $text=null) {
    $data = ['callback_query_id'=>$id];
    if ($text) $data['text'] = $text;
    return apiRequest('answerCallbackQuery', $data);
}

// ==============================
// Command functions (unchanged except using new CSV)
// ==============================
function check_date($chat_id) {
    $date_counts = [];
    $handle = fopen(CSV_FILE, "r");
    if ($handle) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row)>=4) $date_counts[$row[3]] = ($date_counts[$row[3]]??0)+1;
        }
        fclose($handle);
    }
    krsort($date_counts);
    $msg = "📅 <b>Movies Upload Record</b>\n\n";
    $total_days = 0; $total_movies = 0;
    foreach ($date_counts as $date=>$count) { $msg .= "➡️ $date: $count movies\n"; $total_days++; $total_movies+=$count; }
    $msg .= "\n📊 Summary:\n• Total Days: $total_days\n• Total Movies: $total_movies\n• Avg: ".round($total_movies/max(1,$total_days),2);
    sendMessage($chat_id, $msg, null, 'HTML');
}

function total_uploads($chat_id, $page=1) {
    $all_movies = load_movies_from_csv();
    $all_movies = array_reverse($all_movies);
    $total = count($all_movies);
    $items_per_page = 5;
    $total_pages = ceil($total/$items_per_page);
    $current_page = max(1, min($page, $total_pages));
    $start = ($current_page-1)*$items_per_page;
    $paginated = array_slice($all_movies, $start, $items_per_page);
    
    // Calculate today, yesterday, weekly counts
    $today = date('d-m-Y'); $yesterday = date('d-m-Y', strtotime('-1 day'));
    $today_count = $yesterday_count = $weekly_total = 0;
    foreach ($all_movies as $m) {
        if ($m['date'] == $today) $today_count++;
        elseif ($m['date'] == $yesterday) $yesterday_count++;
        $d = DateTime::createFromFormat('d-m-Y', $m['date']);
        if ($d && $d->diff(new DateTime())->days <= 7) $weekly_total++;
    }
    $unique_dates = count(array_unique(array_column($all_movies, 'date')));
    $daily_avg = $unique_dates ? round($total/$unique_dates,2) : 0;
    
    $msg = "📊 <b>Upload Statistics</b>\n\n• 🎬 Total: $total movies\n• 🚀 Today: $today_count\n• 📈 Yesterday: $yesterday_count\n• 📅 Last 7 days: $weekly_total\n• ⭐ Daily avg: $daily_avg\n\n🎬 <b>Movies List (Page $current_page/$total_pages):</b>\n\n";
    $idx = 1;
    foreach ($paginated as $m) $msg .= "<b>".($start+$idx++).".</b> {$m['name']}\n   📅: {$m['date']} | ID: {$m['message_id']}\n\n";
    
    $keyboard = null;
    if ($total_pages > 1) {
        $keyboard = ['inline_keyboard'=>[]];
        $row = [];
        if ($current_page > 1) $row[] = ['text'=>'⏮️ Previous','callback_data'=>'uploads_page_'.($current_page-1)];
        if ($current_page < $total_pages) $row[] = ['text'=>'⏭️ Next','callback_data'=>'uploads_page_'.($current_page+1)];
        if (!empty($row)) $keyboard['inline_keyboard'][] = $row;
        $keyboard['inline_keyboard'][] = [['text'=>'🎬 View Current Movie','callback_data'=>'view_current_movie'],['text'=>'🛑 Stop','callback_data'=>'uploads_stop']];
    }
    sendMessage($chat_id, $msg, $keyboard, 'HTML');
}

function test_csv($chat_id) {
    $movies = load_movies_from_csv();
    if (empty($movies)) { sendMessage($chat_id, "No movies found."); return; }
    $msg = "";
    $i=1;
    foreach ($movies as $m) {
        $line = "$i. {$m['name']} | ID: {$m['message_id']} | Channel: {$m['channel_id']} | Date: {$m['date']}\n";
        if (strlen($msg)+strlen($line) > 4000) { sendMessage($chat_id, $msg); $msg = ""; }
        $msg .= $line;
        $i++;
    }
    if ($msg) sendMessage($chat_id, $msg);
}

// ==============================
// Main processing
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();
    auto_backup();
    send_daily_digest();

    // Channel post (from any public channel the bot is admin of)
    if (isset($update['channel_post'])) {
        $post = $update['channel_post'];
        $chat_id = $post['chat']['id']; // channel ID
        $message_id = $post['message_id'];
        $text = $post['text'] ?? $post['caption'] ?? '';
        if (!empty(trim($text))) {
            // Only save if channel is in our public channels list
            $public = unserialize(PUBLIC_CHANNELS);
            if (isset($public[$chat_id])) {
                append_movie($text, $message_id, $chat_id);
            } else {
                error_log("Channel $chat_id not in public channels list, skipping save.");
            }
        }
    }

    // User message
    if (isset($update['message'])) {
        $msg = $update['message'];
        $chat_id = $msg['chat']['id'];
        $user_id = $msg['from']['id'];
        $text = $msg['text'] ?? '';
        
        // Register/update user
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $msg['from']['first_name'] ?? '',
                'last_name' => $msg['from']['last_name'] ?? '',
                'username' => $msg['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s')
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        } else {
            $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        }
        
        // Commands
        if (strpos($text, '/') === 0) {
            $cmd = explode(' ', $text)[0];
            switch ($cmd) {
                case '/checkdate': check_date($chat_id); break;
                case '/totaluploads': total_uploads($chat_id); break;
                case '/testcsv': test_csv($chat_id); break;
                case '/start':
                    $lang = detect_language($text);
                    send_multilingual_response($chat_id, 'welcome', $lang);
                    sendMessage($chat_id, "\n📢 Join: @EntertainmentTadka786\n\n🤖 Commands:\n/start\n/checkdate\n/totaluploads\n/help\n\n🔍 Type any movie name to search!", null, 'HTML');
                    break;
                case '/stats': if ($user_id == ADMIN_ID) admin_stats($chat_id); break;
                case '/help':
                    sendMessage($chat_id, "🤖 <b>Entertainment Tadka Bot</b>\n\n📢 Join: @EntertainmentTadka786\n\n/start - Welcome\n/checkdate - Date-wise stats\n/totaluploads - Paginated list\n/testcsv - Raw data\n/help - This message", null, 'HTML');
                    break;
                default: break;
            }
        } elseif (!empty(trim($text))) {
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
        if (isset($movie_messages[strtolower($data)])) {
            $entries = $movie_messages[strtolower($data)];
            $count = count($entries);
            foreach ($entries as $entry) {
                forwardMessage($chat_id, $entry['channel_id'], $entry['msg_id']);
            }
            sendMessage($chat_id, "✅ '$data' ke $count messages forward ho gaye!\n\n📢 Join: @EntertainmentTadka786");
            answerCallbackQuery($cb['id'], "🎬 $count forwarded");
        } elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(substr($data, strlen('uploads_page_')));
            total_uploads($chat_id, $page);
            answerCallbackQuery($cb['id'], "Page $page");
        } elseif ($data == 'view_current_movie') {
            $msg_text = $cb['message']['text'];
            if (preg_match('/Page (\d+)\/(\d+)/', $msg_text, $m)) {
                $page = intval($m[1]);
                $all = load_movies_from_csv();
                $all = array_reverse($all);
                $per_page = 5;
                $start = ($page-1)*$per_page;
                $current = array_slice($all, $start, $per_page);
                $fwd=0;
                foreach ($current as $movie) {
                    if (forwardMessage($chat_id, $movie['channel_id'], $movie['message_id'])) $fwd++;
                    usleep(500000);
                }
                sendMessage($chat_id, $fwd > 0 ? "✅ Current page ki $fwd movies forward ho gayi!" : "❌ Forward failed.");
            }
            answerCallbackQuery($cb['id'], "Forwarding...");
        } elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totaluploads again.");
            answerCallbackQuery($cb['id'], "Stopped");
        } else {
            sendMessage($chat_id, "❌ Movie not found: $data");
            answerCallbackQuery($cb['id'], "Not available");
        }
    }
}

// Webhook setup (optional)
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $res = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1><p>Result: ".json_encode($res)."</p><p>URL: $webhook_url</p>";
    exit;
}

// Info page
if (!$update) {
    $stats = get_stats();
    $users = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 Entertainment Tadka Bot</h1><p>Status: ✅ Running</p>";
    echo "<p>Movies: {$stats['total_movies']} | Users: ".count($users['users']??[])." | Searches: {$stats['total_searches']}</p>";
    echo "<p>Last Backup: {$stats['last_backup']} | Digest: {$stats['last_digest']}</p>";
    echo "<h3>Commands</h3><ul><li>/start</li><li>/checkdate</li><li>/totaluploads</li><li>/testcsv</li><li>/help</li><li>/stats (admin)</li></ul>";
}
?>
