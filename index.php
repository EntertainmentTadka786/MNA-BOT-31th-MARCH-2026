<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bot Token & Channel
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CHANNEL_ID', '@EntertainmentTadka786');
define('GROUP_CHANNEL_ID', '@EntertainmentTadka7860');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('WAITING_FILE', 'waiting.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);

// Ensure files exist with proper permissions
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
    chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date\n");
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

// Movie messages array
$movie_messages = array();
$movie_cache = array();

// Load waiting list from file
$waiting_users = json_decode(file_get_contents(WAITING_FILE), true);
if (!is_array($waiting_users)) $waiting_users = array();

// ==============================
// Stats management functions
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
// Smart caching system
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
// AI-powered smart search
// ==============================
function smart_search($query) {
    global $movie_messages;
    
    $query_lower = strtolower(trim($query));
    $results = array();
    
    foreach ($movie_messages as $movie => $msg_ids) {
        $score = 0;
        
        if ($movie == $query_lower) {
            $score = 100;
        }
        elseif (strpos($movie, $query_lower) !== false) {
            $score = 80 - (strlen($movie) - strlen($query_lower));
        }
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) {
                $score = $similarity;
            }
        }
        
        if ($score > 0) {
            $results[$movie] = [
                'score' => $score,
                'count' => count($msg_ids)
            ];
        }
    }
    
    uasort($results, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    return array_slice($results, 0, 10);
}

// ==============================
// Multi-language support
// ==============================
function detect_language($text) {
    $hindi_keywords = ['फिल्म', 'मूवी', 'डाउनलोड', 'हिंदी'];
    $english_keywords = ['movie', 'download', 'watch', 'print'];
    
    $hindi_count = 0;
    $english_count = 0;
    
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
// Auto-backup system with cleanup
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
    
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    foreach ($backup_files as $file) {
        if (file_exists($file)) {
            copy($file, $backup_dir . '/' . basename($file) . '.bak');
        }
    }
    
    // Keep only last 7 backups
    $old_backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    usort($old_backups, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    while (count($old_backups) > 7) {
        $oldest = array_shift($old_backups);
        delete_directory($oldest);
    }
    
    set_last_run('backup');
}

// ==============================
// Daily digest feature
// ==============================
function send_daily_digest() {
    if (!should_run_today('digest')) return;
    
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $yesterday_movies = array();
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && $row[2] == $yesterday) {
                $yesterday_movies[] = $row[0];
            }
        }
        fclose($handle);
    }
    
    if (!empty($yesterday_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $user_id => $user_data) {
            $msg = "📅 <b>Daily Movie Digest</b>\n\n";
            $msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            
            foreach (array_slice($yesterday_movies, 0, 10) as $movie) {
                $msg .= "• " . $movie . "\n";
            }
            
            if (count($yesterday_movies) > 10) {
                $msg .= "• ... and " . (count($yesterday_movies) - 10) . " more\n";
            }
            
            $msg .= "\n🔥 Total: " . count($yesterday_movies) . " movies";
            sendMessage($user_id, $msg, null, 'HTML');
        }
    }
    
    set_last_run('digest');
}

// ==============================
// CSV functions
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date\n");
        return array();
    }
    
    $data = array();
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && is_numeric($row[1]) && !empty(trim($row[0]))) {
                $data[] = array(
                    'movie_name' => trim($row[0]),
                    'message_id' => intval($row[1]),
                    'date' => $row[2]
                );
                
                $movie = strtolower(trim($row[0]));
                if (!isset($movie_messages[$movie])) {
                    $movie_messages[$movie] = array();
                }
                $movie_messages[$movie][] = intval($row[1]);
            }
        }
        fclose($handle);
    }
    
    update_stats('total_movies', 0);
    update_stats('total_movies', count($data));
    
    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name', 'message_id', 'date'));
    foreach ($data as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    
    error_log("✅ CSV loaded and cleaned - " . count($data) . " movies");
    return $data;
}

function load_movies_from_csv() {
    $movies = array();
    
    if (!file_exists(CSV_FILE)) {
        return $movies;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $movies[] = [
                    'name' => $row[0],
                    'message_id' => $row[1],
                    'date' => $row[2]
                ];
            }
        }
        fclose($handle);
    }
    
    return $movies;
}

function append_movie($movie_name, $message_id) {
    if (empty(trim($movie_name))) {
        error_log("❌ Empty movie_name skipped");
        return;
    }
    
    if (!is_numeric($message_id)) {
        error_log("❌ Non-numeric message_id skipped: " . $message_id);
        return;
    }
    
    $date = date('d-m-Y');
    $data = array(
        'movie_name' => trim($movie_name),
        'message_id' => intval($message_id),
        'date' => $date
    );
    
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $data);
    fclose($handle);
    
    global $movie_messages;
    $movie = strtolower(trim($movie_name));
    if (!isset($movie_messages[$movie])) {
        $movie_messages[$movie] = array();
    }
    $movie_messages[$movie][] = intval($message_id);
    
    // Notify waiting users
    global $waiting_users;
    $changed = false;
    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                forwardMessage($user_chat_id, CHANNEL_ID, $message_id);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
            $changed = true;
        }
    }
    if ($changed) {
        file_put_contents(WAITING_FILE, json_encode($waiting_users));
    }
    
    error_log("🎬 '" . $movie_name . "' saved to CSV (Message ID: " . $message_id . ")");
    update_stats('total_movies', 1);
}

// ==============================
// Advanced search (without points)
// ==============================
function advanced_search($chat_id, $user_id, $query) {
    global $movie_messages, $waiting_users;
    
    $query_lower = strtolower(trim($query));
    if (strlen($query_lower) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
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
            $count++;
            if ($count > 15) break;
        }
        
        sendMessage($chat_id, $msg);
        
        $top_matches = array_slice(array_keys($found_movies), 0, 5);
        $keyboard = array('inline_keyboard' => array());
        foreach ($top_matches as $movie) {
            $keyboard['inline_keyboard'][] = array(
                array('text' => "🎬 " . ucwords($movie), 'callback_data' => $movie)
            );
        }
        
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        
    } else {
        $language = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $language);
        
        // Add to waiting list (persistent)
        if (!isset($waiting_users[$query_lower])) {
            $waiting_users[$query_lower] = array();
        }
        $waiting_users[$query_lower][] = array($chat_id, $user_id);
        file_put_contents(WAITING_FILE, json_encode($waiting_users));
    }
    
    update_stats('total_searches', 1);
}

// ==============================
// Admin commands
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
// Telegram API functions (with error handling)
// ==============================
function apiRequest($method, $params = array()) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $options = array(
        'http' => array(
            'method' => 'POST',
            'content' => http_build_query($params),
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'ignore_errors' => true
        )
    );
    $context = stream_context_create($options);
    
    try {
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("API Request failed: $method");
            return false;
        }
        $decoded = json_decode($result, true);
        if (isset($decoded['ok']) && $decoded['ok'] === true) {
            return $decoded;
        } else {
            error_log("API Error in $method: " . ($decoded['description'] ?? 'Unknown error'));
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception in apiRequest: " . $e->getMessage());
        return false;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = array(
        'chat_id' => $chat_id,
        'text' => $text
    );
    
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    if ($parse_mode) {
        $data['parse_mode'] = $parse_mode;
    }
    
    return apiRequest('sendMessage', $data);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $response = apiRequest('forwardMessage', array(
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ));
    return ($response !== false);
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = array('callback_query_id' => $callback_query_id);
    if ($text) {
        $data['text'] = $text;
    }
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
    
    $date_counts = array();
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $date = $row[2];
                if (!isset($date_counts[$date])) {
                    $date_counts[$date] = 0;
                }
                $date_counts[$date]++;
            }
        }
        fclose($handle);
    }
    
    krsort($date_counts);
    
    $msg = "📅 <b>Movies Upload Record</b>\n\n";
    $total_days = 0;
    $total_movies = 0;
    
    foreach ($date_counts as $date => $count) {
        $msg .= "➡️ $date: $count movies\n";
        $total_days++;
        $total_movies += $count;
    }
    
    $msg .= "\n📊 <b>Summary:</b>\n";
    $msg .= "• Total Days: $total_days\n";
    $msg .= "• Total Movies: $total_movies\n";
    $msg .= "• Average per day: " . round($total_movies / max(1, $total_days), 2);
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

function total_uploads($chat_id, $page = 1) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua.");
        return;
    }
    
    $items_per_page = 5;
    $total = 0;
    $today_str = date('d-m-Y');
    $yesterday_str = date('d-m-Y', strtotime('-1 day'));
    $today_count = 0;
    $yesterday_count = 0;
    $weekly_total = 0;
    $all_movies = array();
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $total++;
                $movie_name = $row[0];
                $date = $row[2];
                
                $all_movies[] = [
                    'name' => $movie_name,
                    'date' => $date,
                    'message_id' => $row[1]
                ];
                
                if ($date == $today_str) {
                    $today_count++;
                } elseif ($date == $yesterday_str) {
                    $yesterday_count++;
                }
                
                $movie_date = DateTime::createFromFormat('d-m-Y', $date);
                if ($movie_date) {
                    $diff = $movie_date->diff(new DateTime());
                    if ($diff->days <= 7) {
                        $weekly_total++;
                    }
                }
            }
        }
        fclose($handle);
    }
    
    $all_movies = array_reverse($all_movies);
    $total_pages = ceil(count($all_movies) / $items_per_page);
    $current_page = max(1, min($page, $total_pages));
    $start_index = ($current_page - 1) * $items_per_page;
    $paginated_movies = array_slice($all_movies, $start_index, $items_per_page);
    
    $msg = "📊 <b>Upload Statistics</b>\n\n";
    $msg .= "• 🎬 Total: $total movies\n";
    $msg .= "• 🚀 Today: $today_count movies\n";
    $msg .= "• 📈 Yesterday: $yesterday_count movies\n";
    $msg .= "• 📅 Last 7 days: $weekly_total movies\n";
    $msg .= "• ⭐ Daily avg: " . round($total / max(1, count(array_unique(array_column($all_movies, 'date')))), 2) . " movies\n\n";
    
    $msg .= "🎬 <b>Movies List (Page $current_page/$total_pages):</b>\n\n";
    
    $index = 1;
    foreach ($paginated_movies as $movie) {
        $msg .= "<b>" . ($start_index + $index) . ".</b> " . $movie['name'] . "\n";
        $msg .= "   📅: " . $movie['date'] . " | ID: " . $movie['message_id'] . "\n\n";
        $index++;
    }
    
    $keyboard = null;
    if ($total_pages > 1) {
        $keyboard = ['inline_keyboard' => []];
        $row_buttons = [];
        if ($current_page > 1) {
            $row_buttons[] = ['text' => '⏮️ Previous', 'callback_data' => 'uploads_page_' . ($current_page - 1)];
        }
        if ($current_page < $total_pages) {
            $row_buttons[] = ['text' => '⏭️ Next', 'callback_data' => 'uploads_page_' . ($current_page + 1)];
        }
        if (!empty($row_buttons)) {
            $keyboard['inline_keyboard'][] = $row_buttons;
        }
        $keyboard['inline_keyboard'][] = [
            ['text' => '🎬 View Current Movie', 'callback_data' => 'view_current_movie'],
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
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        $index = 1;
        $msg = "";
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $line = "$index. {$row[0]} | ID: {$row[1]} | Date: {$row[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) {
                    sendMessage($chat_id, $msg);
                    $msg = "";
                }
                $msg .= $line;
                $index++;
            }
        }
        fclose($handle);
        
        if (!empty($msg)) {
            sendMessage($chat_id, $msg);
        }
    }
}

// ==============================
// Main update processing
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();
    auto_backup();
    send_daily_digest();
    
    // Channel post
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $text = isset($message['text']) ? $message['text'] : (isset($message['caption']) ? $message['caption'] : '');
        
        if (!empty(trim($text))) {
            append_movie($text, $message_id);
        }
    }
    
    // User message
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        
        // User registration (without points)
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
        
        // Commands
        if (strpos($text, '/') === 0) {
            $command = explode(' ', $text)[0];
            
            if ($command == '/checkdate') {
                check_date($chat_id);
            } 
            elseif ($command == '/totaluploads') {
                total_uploads($chat_id);
            }
            elseif ($command == '/testcsv') {
                test_csv($chat_id);
            }
            elseif ($command == '/start') {
                $lang = detect_language($text);
                send_multilingual_response($chat_id, 'welcome', $lang);
                $welcome_msg = "\n📢 Join our channel: @EntertainmentTadka786\n\n";
                $welcome_msg .= "🤖 <b>Bot Commands:</b>\n";
                $welcome_msg .= "/start - Welcome message\n";
                $welcome_msg .= "/checkdate - Date-wise upload stats\n";
                $welcome_msg .= "/totaluploads - Total upload counts\n";
                $welcome_msg .= "/help - Help message\n\n";
                $welcome_msg .= "🔍 <b>Simply type any movie name to search!</b>";
                
                sendMessage($chat_id, $welcome_msg, null, 'HTML');
            }
            elseif ($command == '/stats' && $user_id == 1080317415) {
                admin_stats($chat_id);
            }
            elseif ($command == '/help') {
                $help_msg = "🤖 <b>Entertainment Tadka Bot</b>\n\n";
                $help_msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
                $help_msg .= "📋 <b>Available Commands:</b>\n\n";
                $help_msg .= "/start - Welcome message\n";
                $help_msg .= "/checkdate - Date-wise upload stats\n";
                $help_msg .= "/totaluploads - Total upload counts\n";
                $help_msg .= "/testcsv - View all movies\n";
                $help_msg .= "/help - This help message\n\n";
                $help_msg .= "🔍 <b>Simply type any movie name to search!</b>";
                
                sendMessage($chat_id, $help_msg, null, 'HTML');
            }
        } 
        else if (!empty(trim($text))) {
            $language = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $language);
            advanced_search($chat_id, $user_id, $text);
        }
    }
    
    // Callback queries
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];
        
        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $message_count = count($movie_messages[$movie_lower]);
            foreach ($movie_messages[$movie_lower] as $msg_id) {
                forwardMessage($chat_id, CHANNEL_ID, $msg_id);
            }
            
            $forward_msg = "✅ '$data' ke $message_count messages forward ho gaye!\n\n";
            $forward_msg .= "📢 Join our channel: @EntertainmentTadka786";
            
            sendMessage($chat_id, $forward_msg);
            answerCallbackQuery($query['id'], "🎬 $message_count messages forwarded!");
        } 
        elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page loaded");
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
                
                $forwarded_count = 0;
                foreach ($current_movies as $movie) {
                    if (forwardMessage($chat_id, CHANNEL_ID, $movie['message_id'])) {
                        $forwarded_count++;
                        usleep(500000);
                    }
                }
                
                if ($forwarded_count > 0) {
                    sendMessage($chat_id, "✅ Current page ki $forwarded_count movies forward ho gayi!\n\n📢 Join: @EntertainmentTadka786");
                } else {
                    sendMessage($chat_id, "❌ Kuch technical issue hai. Baad mein try karein.");
                }
            }
            answerCallbackQuery($query['id'], "Movies forwarding...");
        }
        elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totaluploads again to restart.");
            answerCallbackQuery($query['id'], "Pagination stopped");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: " . $data);
            answerCallbackQuery($query['id'], "❌ Movie not available");
        }
    }
}

// Webhook setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                   "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    
    $result = apiRequest('setWebhook', array('url' => $webhook_url));
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . json_encode($result) . "</p>";
    echo "<p>Webhook URL: " . $webhook_url . "</p>";
    
    $bot_info = apiRequest('getMe');
    if ($bot_info && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . $bot_info['result']['first_name'] . "</p>";
        echo "<p>Username: @" . $bot_info['result']['username'] . "</p>";
        echo "<p>Channel: @EntertainmentTadka786</p>";
    }
    exit;
}

// Info page
if (!$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<p><strong>Last Backup:</strong> " . ($stats['last_backup'] ?? 'Never') . "</p>";
    echo "<p><strong>Last Digest:</strong> " . ($stats['last_digest'] ?? 'Never') . "</p>";
    
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/checkdate</code> - Date-wise stats</li>";
    echo "<li><code>/totaluploads</code> - Upload statistics</li>";
    echo "<li><code>/testcsv</code> - View all movies</li>";
    echo "<li><code>/help</code> - Help message</li>";
    echo "<li><code>/stats</code> - Admin statistics</li>";
    echo "</ul>";
    
    echo "<h3>📊 File Status</h3>";
    echo "<ul>";
    echo "<li>CSV File: " . (is_writable(CSV_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Users File: " . (is_writable(USERS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Stats File: " . (is_writable(STATS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Waiting File: " . (is_writable(WAITING_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "</ul>";
    
    echo "<h3>🌟 Special Features</h3>";
    echo "<ul>";
    echo "<li>🤖 AI-Powered Search</li>";
    echo "<li>🔔 Persistent Waiting List (waiting.json)</li>";
    echo "<li>📊 Advanced Analytics</li>";
    echo "<li>🌐 Multi-Language Support</li>";
    echo "<li>⚡ Smart Caching</li>";
    echo "<li>🛡️ Auto-Backup with cleanup</li>";
    echo "<li>📅 Daily Digest (once per day)</li>";
    echo "<li>✅ API Error Handling & Logging</li>";
    echo "</ul>";
}
?>
