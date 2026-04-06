<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load channel configuration (no hardcoding)
$channels_config = include('channels_config.php');

// Define constants from config
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('WAITING_FILE', 'waiting.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);

// Build a master list of all channel IDs (for quick lookup)
$all_channel_ids = [];
foreach ($channels_config['public_channels'] as $ch) {
    $all_channel_ids[] = $ch['id'];
}
foreach ($channels_config['private_channels'] as $ch) {
    $all_channel_ids[] = $ch['id'];
}
// Also include request group if needed for posting? Not needed for search, but add if required
define('REQUEST_GROUP_ID', $channels_config['request_group']['id']);

// Ensure files exist
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
    chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    // New CSV header includes channel_id
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
$movie_messages = array(); // Format: $movie_messages[$movie_lower][$channel_id][] = $message_id
$movie_cache = array();
$waiting_users = json_decode(file_get_contents(WAITING_FILE), true);
if (!is_array($waiting_users)) $waiting_users = array();

// ==============================
// Stats management (unchanged)
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

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
// Caching system (unchanged)
// ==============================
function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

// ==============================
// AI-powered search (modified for multi-channel)
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    
    foreach ($movie_messages as $movie => $channels_data) {
        $score = 0;
        if ($movie == $query_lower) {
            $score = 100;
        } elseif (strpos($movie, $query_lower) !== false) {
            $score = 80 - (strlen($movie) - strlen($query_lower));
        } else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        
        if ($score > 0) {
            // Count total messages across all channels for this movie
            $total_count = 0;
            foreach ($channels_data as $msgs) {
                $total_count += count($msgs);
            }
            $results[$movie] = [
                'score' => $score,
                'count' => $total_count,
                'channels' => $channels_data // store channel-wise messages
            ];
        }
    }
    
    uasort($results, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    return array_slice($results, 0, 10);
}

// ==============================
// Multi-language support (unchanged)
// ==============================
function detect_language($text) {
    $hindi_keywords = ['फिल्म', 'मूवी', 'डाउनलोड', 'हिंदी'];
    $english_keywords = ['movie', 'download', 'watch', 'print'];
    $hindi_count = 0; $english_count = 0;
    foreach ($hindi_keywords as $keyword) {
        if (mb_strpos($text, $keyword) !== false) $hindi_count++;
    }
    foreach ($english_keywords as $keyword) {
        if (stripos($text, $keyword) !== false) $english_count++;
    }
    return $hindi_count > $english_count ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
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
    sendMessage($chat_id, $responses[$language][$message_type]);
}

// ==============================
// Auto-backup (unchanged)
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
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE, WAITING_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0777, true);
    foreach ($backup_files as $file) {
        if (file_exists($file)) {
            copy($file, $backup_dir . '/' . basename($file) . '.bak');
        }
    }
    $old_backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    usort($old_backups, function($a, $b) { return filemtime($a) - filemtime($b); });
    while (count($old_backups) > 7) {
        $oldest = array_shift($old_backups);
        delete_directory($oldest);
    }
    set_last_run('backup');
}

// ==============================
// Daily digest (unchanged, but we can enhance later)
// ==============================
function send_daily_digest() {
    if (!should_run_today('digest')) return;
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $yesterday_movies = array();
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 4 && $row[3] == $yesterday) {
                $yesterday_movies[] = $row[0];
            }
        }
        fclose($handle);
    }
    if (!empty($yesterday_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $user_id => $user_data) {
            $msg = "📅 <b>Daily Movie Digest</b>\n\n📢 Join: @EntertainmentTadka786\n\n🎬 Yesterday's Uploads ($yesterday):\n";
            foreach (array_slice($yesterday_movies, 0, 10) as $movie) {
                $msg .= "• " . $movie . "\n";
            }
            if (count($yesterday_movies) > 10) $msg .= "• ... and " . (count($yesterday_movies) - 10) . " more\n";
            $msg .= "\n🔥 Total: " . count($yesterday_movies) . " movies";
            sendMessage($user_id, $msg, null, 'HTML');
        }
    }
    set_last_run('digest');
}

// ==============================
// CSV functions (modified for channel_id)
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,channel_id,date\n");
        return array();
    }
    $data = array();
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            // Support old format (3 columns) and new format (4 columns)
            if (count($row) >= 3) {
                $movie_name = trim($row[0]);
                $message_id = intval($row[1]);
                $channel_id = (count($row) >= 4) ? trim($row[2]) : ''; // old entries may have no channel_id
                $date = (count($row) >= 4) ? $row[3] : $row[2];
                
                if (!empty($movie_name) && is_numeric($message_id)) {
                    $data[] = [
                        'movie_name' => $movie_name,
                        'message_id' => $message_id,
                        'channel_id' => $channel_id,
                        'date' => $date
                    ];
                    $movie = strtolower($movie_name);
                    if (!isset($movie_messages[$movie])) {
                        $movie_messages[$movie] = [];
                    }
                    if (!isset($movie_messages[$movie][$channel_id])) {
                        $movie_messages[$movie][$channel_id] = [];
                    }
                    $movie_messages[$movie][$channel_id][] = $message_id;
                }
            }
        }
        fclose($handle);
    }
    update_stats('total_movies', 0);
    update_stats('total_movies', count($data));
    // Rewrite cleaned data with 4 columns
    $handle = fopen($filename, "w");
    fputcsv($handle, ['movie_name', 'message_id', 'channel_id', 'date']);
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id'], $row['channel_id'], $row['date']]);
    }
    fclose($handle);
    error_log("✅ CSV loaded - " . count($data) . " movies");
    return $data;
}

function load_movies_from_csv() {
    $movies = [];
    if (!file_exists(CSV_FILE)) return $movies;
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 4) {
                $movies[] = [
                    'name' => $row[0],
                    'message_id' => $row[1],
                    'channel_id' => $row[2],
                    'date' => $row[3]
                ];
            } elseif (count($row) == 3) {
                // old format - assume channel_id empty (will be fixed later)
                $movies[] = [
                    'name' => $row[0],
                    'message_id' => $row[1],
                    'channel_id' => '',
                    'date' => $row[2]
                ];
            }
        }
        fclose($handle);
    }
    return $movies;
}

function append_movie($movie_name, $message_id, $channel_id) {
    if (empty(trim($movie_name)) || !is_numeric($message_id)) return;
    $date = date('d-m-Y');
    $data = [trim($movie_name), intval($message_id), $channel_id, $date];
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $data);
    fclose($handle);
    
    global $movie_messages;
    $movie = strtolower(trim($movie_name));
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    if (!isset($movie_messages[$movie][$channel_id])) $movie_messages[$movie][$channel_id] = [];
    $movie_messages[$movie][$channel_id][] = intval($message_id);
    
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
    
    error_log("🎬 '$movie_name' saved (Channel: $channel_id, MsgID: $message_id)");
    update_stats('total_movies', 1);
}

// ==============================
// Advanced search (forwards from all channels)
// ==============================
function advanced_search($chat_id, $user_id, $query) {
    global $movie_messages, $waiting_users;
    $query_lower = strtolower(trim($query));
    if (strlen($query_lower) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters");
        return;
    }
    $found_movies = smart_search($query_lower);
    if (!empty($found_movies)) {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'found', $lang);
        $msg = "🔍 Found " . count($found_movies) . " movies for '$query':\n\n";
        $count = 1;
        foreach ($found_movies as $movie => $data) {
            $msg .= "$count. $movie (" . $data['count'] . " messages)\n";
            $count++; if ($count > 15) break;
        }
        sendMessage($chat_id, $msg);
        
        $top_matches = array_slice(array_keys($found_movies), 0, 5);
        $keyboard = ['inline_keyboard' => []];
        foreach ($top_matches as $movie) {
            $keyboard['inline_keyboard'][] = [['text' => "🎬 " . ucwords($movie), 'callback_data' => $movie]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
    } else {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        if (!isset($waiting_users[$query_lower])) $waiting_users[$query_lower] = [];
        $waiting_users[$query_lower][] = [$chat_id, $user_id];
        file_put_contents(WAITING_FILE, json_encode($waiting_users));
    }
    update_stats('total_searches', 1);
}

// ==============================
// Admin stats (unchanged)
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $msg = "📊 <b>Bot Statistics</b>\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n";
    $msg .= "💾 Last Backup: " . ($stats['last_backup'] ?? 'Never') . "\n";
    $msg .= "📅 Last Digest: " . ($stats['last_digest'] ?? 'Never') . "\n\n";
    $csv_data = load_and_clean_csv();
    $recent_movies = array_slice($csv_data, -5);
    $msg .= "📈 <b>Recent Uploads:</b>\n";
    foreach ($recent_movies as $movie) {
        $msg .= "• " . $movie['movie_name'] . " (" . $movie['date'] . ")\n";
    }
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Telegram API (with error handling)
// ==============================
function apiRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $options = ['http' => [
        'method' => 'POST',
        'content' => http_build_query($params),
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'ignore_errors' => true
    ]];
    $context = stream_context_create($options);
    try {
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("API Request failed: $method");
            return false;
        }
        $decoded = json_decode($result, true);
        if (isset($decoded['ok']) && $decoded['ok'] === true) return $decoded;
        error_log("API Error in $method: " . ($decoded['description'] ?? 'Unknown'));
        return false;
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
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
    $response = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return ($response !== false);
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    return apiRequest('answerCallbackQuery', $data);
}

// ==============================
// Command functions (unchanged except /totaluploads maybe)
// ==============================
function check_date($chat_id) { /* same as before but CSV has 4 columns - adjust if needed */ 
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ No data"); return; }
    $date_counts = [];
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            $date = (count($row) >= 4) ? $row[3] : $row[2];
            if (!isset($date_counts[$date])) $date_counts[$date] = 0;
            $date_counts[$date]++;
        }
        fclose($handle);
    }
    krsort($date_counts);
    $msg = "📅 <b>Movies Upload Record</b>\n\n";
    $total_days = 0; $total_movies = 0;
    foreach ($date_counts as $date => $count) {
        $msg .= "➡️ $date: $count movies\n";
        $total_days++; $total_movies += $count;
    }
    $msg .= "\n📊 <b>Summary:</b>\n• Total Days: $total_days\n• Total Movies: $total_movies\n• Average: " . round($total_movies / max(1, $total_days), 2);
    sendMessage($chat_id, $msg, null, 'HTML');
}

function total_uploads($chat_id, $page = 1) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ No data"); return; }
    $items_per_page = 5;
    $all_movies = load_movies_from_csv();
    $total = count($all_movies);
    $today_str = date('d-m-Y');
    $yesterday_str = date('d-m-Y', strtotime('-1 day'));
    $today_count = 0; $yesterday_count = 0; $weekly_total = 0;
    foreach ($all_movies as $m) {
        if ($m['date'] == $today_str) $today_count++;
        if ($m['date'] == $yesterday_str) $yesterday_count++;
        $movie_date = DateTime::createFromFormat('d-m-Y', $m['date']);
        if ($movie_date && $movie_date->diff(new DateTime())->days <= 7) $weekly_total++;
    }
    $all_movies = array_reverse($all_movies);
    $total_pages = ceil($total / $items_per_page);
    $current_page = max(1, min($page, $total_pages));
    $start_index = ($current_page - 1) * $items_per_page;
    $paginated = array_slice($all_movies, $start_index, $items_per_page);
    $msg = "📊 <b>Upload Statistics</b>\n\n";
    $msg .= "• 🎬 Total: $total movies\n• 🚀 Today: $today_count\n• 📈 Yesterday: $yesterday_count\n• 📅 Last 7 days: $weekly_total\n\n";
    $msg .= "🎬 <b>Movies List (Page $current_page/$total_pages):</b>\n\n";
    $idx = 1;
    foreach ($paginated as $movie) {
        $msg .= "<b>" . ($start_index + $idx) . ".</b> " . $movie['name'] . "\n   📅: " . $movie['date'] . " | Channel: " . ($movie['channel_id'] ?: 'unknown') . "\n\n";
        $idx++;
    }
    $keyboard = null;
    if ($total_pages > 1) {
        $keyboard = ['inline_keyboard' => []];
        $row_buttons = [];
        if ($current_page > 1) $row_buttons[] = ['text' => '⏮️ Previous', 'callback_data' => 'uploads_page_' . ($current_page - 1)];
        if ($current_page < $total_pages) $row_buttons[] = ['text' => '⏭️ Next', 'callback_data' => 'uploads_page_' . ($current_page + 1)];
        if (!empty($row_buttons)) $keyboard['inline_keyboard'][] = $row_buttons;
        $keyboard['inline_keyboard'][] = [['text' => '🎬 View Current Page', 'callback_data' => 'view_current_movie'], ['text' => '🛑 Stop', 'callback_data' => 'uploads_stop']];
    }
    sendMessage($chat_id, $msg, $keyboard, 'HTML');
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ CSV not found"); return; }
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        $index = 1; $msg = "";
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 4) {
                $line = "$index. {$row[0]} | ID: {$row[1]} | Ch: {$row[2]} | Date: {$row[3]}\n";
            } elseif (count($row) == 3) {
                $line = "$index. {$row[0]} | ID: {$row[1]} | Ch: old | Date: {$row[2]}\n";
            } else continue;
            if (strlen($msg) + strlen($line) > 4000) { sendMessage($chat_id, $msg); $msg = ""; }
            $msg .= $line; $index++;
        }
        fclose($handle);
        if (!empty($msg)) sendMessage($chat_id, $msg);
    }
}

// ==============================
// Main update processing (modified for multi-channel)
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();
    auto_backup();
    send_daily_digest();
    
    // Channel post (any channel from our list)
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $chat = $message['chat'];
        $channel_id = $chat['id'];
        // Check if this channel is in our allowed list (optional: accept all, but we restrict to configured channels)
        global $all_channel_ids;
        if (in_array($channel_id, $all_channel_ids)) {
            $message_id = $message['message_id'];
            $text = isset($message['text']) ? $message['text'] : (isset($message['caption']) ? $message['caption'] : '');
            if (!empty(trim($text))) {
                append_movie($text, $message_id, $channel_id);
            }
        } else {
            error_log("⚠️ Unknown channel post ignored: $channel_id");
        }
    }
    
    // User message
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s')
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        
        if (strpos($text, '/') === 0) {
            $command = explode(' ', $text)[0];
            if ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totaluploads') total_uploads($chat_id);
            elseif ($command == '/testcsv') test_csv($chat_id);
            elseif ($command == '/start') {
                $lang = detect_language($text);
                send_multilingual_response($chat_id, 'welcome', $lang);
                $welcome_msg = "\n📢 Join our channels:\n";
                global $channels_config;
                foreach ($channels_config['public_channels'] as $ch) {
                    $welcome_msg .= $ch['username'] . " - " . $ch['name'] . "\n";
                }
                $welcome_msg .= "\n🤖 Commands: /start, /checkdate, /totaluploads, /help";
                sendMessage($chat_id, $welcome_msg, null, 'HTML');
            }
            elseif ($command == '/stats' && $user_id == 1080317415) admin_stats($chat_id);
            elseif ($command == '/help') {
                $help_msg = "🤖 Bot Commands:\n/start - Welcome\n/checkdate - Date stats\n/totaluploads - Movie list\n/testcsv - Raw data\n/help - This";
                sendMessage($chat_id, $help_msg, null, 'HTML');
            }
        } elseif (!empty(trim($text))) {
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $user_id, $text);
        }
    }
    
    // Callback queries (modified to forward from correct channels)
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];
        
        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $total_forwarded = 0;
            foreach ($movie_messages[$movie_lower] as $channel_id => $msg_ids) {
                foreach ($msg_ids as $msg_id) {
                    if (forwardMessage($chat_id, $channel_id, $msg_id)) {
                        $total_forwarded++;
                        usleep(200000);
                    }
                }
            }
            $reply = "✅ '$data' ke $total_forwarded messages forward ho gaye!";
            sendMessage($chat_id, $reply);
            answerCallbackQuery($query['id'], "🎬 $total_forwarded forwarded");
        } 
        elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif ($data == 'view_current_movie') {
            $message_text = $query['message']['text'];
            if (preg_match('/Page (\d+)\/(\d+)/', $message_text, $matches)) {
                $current_page = $matches[1];
                $all_movies = load_movies_from_csv();
                $all_movies = array_reverse($all_movies);
                $items_per_page = 5;
                $start_index = ($current_page - 1) * $items_per_page;
                $current_movies = array_slice($all_movies, $start_index, $items_per_page);
                $forwarded = 0;
                foreach ($current_movies as $movie) {
                    if (forwardMessage($chat_id, $movie['channel_id'], $movie['message_id'])) {
                        $forwarded++;
                        usleep(500000);
                    }
                }
                sendMessage($chat_id, $forwarded > 0 ? "✅ $forwarded movies forward ho gayi" : "❌ Failed");
            }
            answerCallbackQuery($query['id'], "Done");
        }
        elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Stopped. Type /totaluploads again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: $data");
            answerCallbackQuery($query['id'], "Not found");
        }
    }
}

// Webhook setup (unchanged)
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1><p>Result: " . json_encode($result) . "</p><p>URL: $webhook_url</p>";
    exit;
}

// Info page (modified to show channels)
if (!$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 Entertainment Tadka Bot (Multi-Channel)</h1>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<h3>📺 Configured Channels</h3><ul>";
    global $channels_config;
    foreach ($channels_config['public_channels'] as $ch) echo "<li>📢 {$ch['username']} ({$ch['name']}) - ID: {$ch['id']}</li>";
    foreach ($channels_config['private_channels'] as $ch) echo "<li>🔒 {$ch['name']} - ID: {$ch['id']}</li>";
    echo "</ul><p><a href='?setwebhook=1'>Set Webhook</a></p>";
}
?>
