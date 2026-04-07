<?php
// ==============================
// SECURITY HEADERS & BASIC SETUP
// ==============================

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// ==============================
// RENDER.COM SPECIFIC CONFIGURATION
// ==============================

$port = getenv('PORT') ?: '80';

if (!getenv('BOT_TOKEN')) {
    die("❌ BOT_TOKEN environment variable set nahi hai.");
}

// ==============================
// ENVIRONMENT VARIABLES CONFIGURATION
// ==============================
define('BOT_TOKEN', getenv('BOT_TOKEN'));

// UPDATED CHANNEL CONFIGURATIONS
define('MAIN_CHANNEL', '@EntertainmentTadka786');
define('MAIN_CHANNEL_ID', '-1003181705395');
define('THEATER_CHANNEL', '@threater_print_movies');
define('THEATER_CHANNEL_ID', '-1002831605258');
define('SERIAL_CHANNEL', '@Entertainment_Tadka_Serial_786');
define('SERIAL_CHANNEL_ID', '-1003614546520');
define('BACKUP_CHANNEL_USERNAME', '@ETBackup');
define('BACKUP_CHANNEL_ID', '-1002964109368');
define('REQUEST_GROUP', '@EntertainmentTadka7860');
define('REQUEST_GROUP_ID', '-1003083386043');
define('PRIVATE_CHANNEL_1_ID', '-1003251791991');
define('PRIVATE_CHANNEL_2_ID', '-1002337293281');
define('ADMIN_ID', (int)getenv('ADMIN_ID'));

if (!MAIN_CHANNEL_ID || !THEATER_CHANNEL_ID || !BACKUP_CHANNEL_ID) {
    die("❌ Essential channel IDs environment variables set nahi hain.");
}

define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('REQUEST_FILE', 'movie_requests.json');
define('BACKUP_DIR', 'backups/');
define('LOG_FILE', 'bot_activity.log');
define('INDEXING_LOG', 'indexing_log.json'); // NEW: Auto indexing log file
define('FORWARD_SETTINGS_FILE', 'forward_settings.json'); // NEW: Forward header settings

define('CACHE_EXPIRY', 300);
define('ITEMS_PER_PAGE', 5);
define('MAX_SEARCH_RESULTS', 15);
define('DAILY_REQUEST_LIMIT', 5);
define('AUTO_BACKUP_HOUR', '03');

// ==============================
// ENHANCED PAGINATION CONSTANTS
// ==============================
define('MAX_PAGES_TO_SHOW', 7);
define('PAGINATION_CACHE_TIMEOUT', 60);
define('PREVIEW_ITEMS', 3);
define('BATCH_SIZE', 5);

// ==============================
// AUTO INDEXING CONSTANTS
// ==============================
define('AUTO_INDEX_ENABLED', true);
define('INDEX_CHECK_INTERVAL', 60); // seconds
define('MAX_POSTS_PER_SCAN', 50);

// ==============================
// MAINTENANCE MODE
// ==============================
$MAINTENANCE_MODE = false;
$MAINTENANCE_MESSAGE = "🛠️ <b>Bot Under Maintenance</b>\n\nWe're temporarily unavailable.\nWill be back soon!\n\nThanks for patience 🙏";

// ==============================
// GLOBAL VARIABLES
// ==============================
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();
$user_pagination_sessions = array();

// ==============================
// FORWARD HEADER SETTINGS SYSTEM
// ==============================
function initialize_forward_settings() {
    if (!file_exists(FORWARD_SETTINGS_FILE)) {
        $default_settings = [
            'public_channels' => [
                MAIN_CHANNEL_ID => ['forward_header' => true, 'name' => 'Main Channel'],
                THEATER_CHANNEL_ID => ['forward_header' => true, 'name' => 'Theater Channel'],
                SERIAL_CHANNEL_ID => ['forward_header' => true, 'name' => 'Serial Channel'],
                REQUEST_GROUP_ID => ['forward_header' => true, 'name' => 'Request Group']
            ],
            'private_channels' => [
                PRIVATE_CHANNEL_1_ID => ['forward_header' => false, 'name' => 'Private Channel 1'],
                PRIVATE_CHANNEL_2_ID => ['forward_header' => false, 'name' => 'Private Channel 2'],
                BACKUP_CHANNEL_ID => ['forward_header' => false, 'name' => 'Backup Channel']
            ],
            'last_updated' => date('Y-m-d H:i:s')
        ];
        file_put_contents(FORWARD_SETTINGS_FILE, json_encode($default_settings, JSON_PRETTY_PRINT));
    }
    return json_decode(file_get_contents(FORWARD_SETTINGS_FILE), true);
}

function get_forward_header_setting($channel_id) {
    $settings = initialize_forward_settings();
    
    // Check private channels first
    if (isset($settings['private_channels'][$channel_id])) {
        return $settings['private_channels'][$channel_id]['forward_header'];
    }
    
    // Check public channels
    if (isset($settings['public_channels'][$channel_id])) {
        return $settings['public_channels'][$channel_id]['forward_header'];
    }
    
    // Default: false for unknown channels (safe default)
    return false;
}

function set_forward_header_setting($channel_id, $enabled, $channel_type = 'private') {
    $settings = initialize_forward_settings();
    
    if ($channel_type == 'private') {
        if (!isset($settings['private_channels'][$channel_id])) {
            $settings['private_channels'][$channel_id] = [
                'forward_header' => $enabled,
                'name' => 'Channel ' . $channel_id
            ];
        } else {
            $settings['private_channels'][$channel_id]['forward_header'] = $enabled;
        }
    } else {
        if (!isset($settings['public_channels'][$channel_id])) {
            $settings['public_channels'][$channel_id] = [
                'forward_header' => $enabled,
                'name' => 'Channel ' . $channel_id
            ];
        } else {
            $settings['public_channels'][$channel_id]['forward_header'] = $enabled;
        }
    }
    
    $settings['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(FORWARD_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));
    return true;
}

function toggle_forward_header($chat_id, $channel_id, $channel_type = 'private') {
    $current = get_forward_header_setting($channel_id);
    $new_status = !$current;
    set_forward_header_setting($channel_id, $new_status, $channel_type);
    
    $status_text = $new_status ? "✅ ENABLED" : "❌ DISABLED";
    $channel_name = ($channel_type == 'private') ? "Private Channel" : "Public Channel";
    
    sendMessage($chat_id, "🔄 Forward header for $channel_name has been $status_text\n\nChannel ID: <code>$channel_id</code>", null, 'HTML');
    return $new_status;
}

// ==============================
// TYPING INDICATOR SYSTEM
// ==============================
function sendTypingAction($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = [
        'chat_id' => $chat_id,
        'action' => 'typing'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function sendTypingIndicator($chat_id, $duration_seconds = 2) {
    sendTypingAction($chat_id);
    
    if ($duration_seconds > 0) {
        $start = time();
        $interval = 4;
        
        while (time() - $start < $duration_seconds) {
            usleep(500000);
            if ((time() - $start) % $interval == 0) {
                sendTypingAction($chat_id);
            }
        }
    }
}

function sendUploadPhotoAction($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = [
        'chat_id' => $chat_id,
        'action' => 'upload_photo'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function sendUploadDocumentAction($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = [
        'chat_id' => $chat_id,
        'action' => 'upload_document'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function sendFindLocationAction($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = [
        'chat_id' => $chat_id,
        'action' => 'find_location'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function sendRecordVideoAction($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = [
        'chat_id' => $chat_id,
        'action' => 'record_video'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

// ==============================
// CHANNEL MAPPING FUNCTIONS
// ==============================
function get_channel_id_by_username($username) {
    $username = strtolower(trim($username));
    
    $channel_map = [
        '@entertainmenttadka786' => MAIN_CHANNEL_ID,
        '@threater_print_movies' => THEATER_CHANNEL_ID,
        '@entertainment_tadka_serial_786' => SERIAL_CHANNEL_ID,
        '@etbackup' => BACKUP_CHANNEL_ID,
        '@entertainmenttadka7860' => REQUEST_GROUP_ID,
        'entertainmenttadka786' => MAIN_CHANNEL_ID,
        'threater_print_movies' => THEATER_CHANNEL_ID,
        'entertainment_tadka_serial_786' => SERIAL_CHANNEL_ID,
        'etbackup' => BACKUP_CHANNEL_ID,
        'entertainmenttadka7860' => REQUEST_GROUP_ID,
    ];
    
    return $channel_map[$username] ?? null;
}

function get_channel_type_by_id($channel_id) {
    $channel_id = strval($channel_id);
    
    if ($channel_id == MAIN_CHANNEL_ID) return 'main';
    if ($channel_id == THEATER_CHANNEL_ID) return 'theater';
    if ($channel_id == SERIAL_CHANNEL_ID) return 'serial';
    if ($channel_id == BACKUP_CHANNEL_ID) return 'backup';
    if ($channel_id == PRIVATE_CHANNEL_1_ID) return 'private';
    if ($channel_id == PRIVATE_CHANNEL_2_ID) return 'private2';
    if ($channel_id == REQUEST_GROUP_ID) return 'request_group';
    
    return 'other';
}

function get_channel_display_name($channel_type) {
    $names = [
        'main' => '🍿 Main Channel',
        'theater' => '🎭 Theater Prints',
        'serial' => '📺 Serial Channel',
        'backup' => '🔒 Backup Channel',
        'private' => '🔐 Private Channel',
        'private2' => '🔐 Private Channel 2',
        'request_group' => '📥 Request Group',
        'other' => '📢 Other Channel'
    ];
    
    return $names[$channel_type] ?? '📢 Unknown Channel';
}

function get_direct_channel_link($message_id, $channel_id) {
    if (empty($channel_id)) {
        return "Channel ID not available";
    }
    
    $channel_id_clean = str_replace('-100', '', $channel_id);
    return "https://t.me/c/" . $channel_id_clean . "/" . $message_id;
}

function get_channel_username_link($channel_type) {
    switch ($channel_type) {
        case 'main':
            return "https://t.me/" . ltrim(MAIN_CHANNEL, '@');
        case 'theater':
            return "https://t.me/" . ltrim(THEATER_CHANNEL, '@');
        case 'serial':
            return "https://t.me/" . ltrim(SERIAL_CHANNEL, '@');
        case 'backup':
            return "https://t.me/" . ltrim(BACKUP_CHANNEL_USERNAME, '@');
        case 'request_group':
            return "https://t.me/" . ltrim(REQUEST_GROUP, '@');
        default:
            return "https://t.me/EntertainmentTadka786";
    }
}

// ==============================
// FILE INITIALIZATION FUNCTION
// ==============================
function initialize_files() {
    $files = [
        CSV_FILE => "movie_name,message_id,date,channel_id,quality,size,language\n",
        USERS_FILE => json_encode([
            'users' => [],
            'total_requests' => 0,
            'message_logs' => [],
            'daily_stats' => []
        ], JSON_PRETTY_PRINT),
        STATS_FILE => json_encode([
            'total_movies' => 0,
            'total_users' => 0,
            'total_searches' => 0,
            'total_downloads' => 0,
            'successful_searches' => 0,
            'failed_searches' => 0,
            'daily_activity' => [],
            'last_updated' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT),
        REQUEST_FILE => json_encode([
            'requests' => [],
            'pending_approval' => [],
            'completed_requests' => [],
            'user_request_count' => []
        ], JSON_PRETTY_PRINT),
        INDEXING_LOG => json_encode([
            'last_scan' => [],
            'indexed_messages' => [],
            'channel_last_message_id' => [],
            'total_indexed' => 0,
            'last_full_scan' => null
        ], JSON_PRETTY_PRINT)
    ];
    
    foreach ($files as $file => $content) {
        if (!file_exists($file)) {
            file_put_contents($file, $content);
            @chmod($file, 0666);
        }
    }
    
    if (!file_exists(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0777, true);
    }
    
    if (!file_exists(LOG_FILE)) {
        file_put_contents(LOG_FILE, "[" . date('Y-m-d H:i:s') . "] SYSTEM: Files initialized\n");
    }
    
    initialize_forward_settings();
}

initialize_files();

// ==============================
// LOGGING SYSTEM
// ==============================
function bot_log($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $type: $message\n";
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
}

// ==============================
// CACHING SYSTEM
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
    
    bot_log("Movie cache refreshed - " . count($movie_cache['data']) . " movies");
    return $movie_cache['data'];
}

// ==============================
// CSV MANAGEMENT FUNCTIONS (UPDATED FORMAT)
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date,channel_id,quality,size,language\n");
        return [];
    }

    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $date = isset($row[2]) ? trim($row[2]) : '';
                $channel_id = isset($row[3]) ? trim($row[3]) : '';
                $quality = isset($row[4]) ? trim($row[4]) : 'Unknown';
                $size = isset($row[5]) ? trim($row[5]) : 'Unknown';
                $language = isset($row[6]) ? trim($row[6]) : 'Hindi';

                $channel_type = get_channel_type_by_id($channel_id);
                
                $channel_username = '';
                switch ($channel_type) {
                    case 'main':
                        $channel_username = MAIN_CHANNEL;
                        break;
                    case 'theater':
                        $channel_username = THEATER_CHANNEL;
                        break;
                    case 'serial':
                        $channel_username = SERIAL_CHANNEL;
                        break;
                    case 'backup':
                        $channel_username = BACKUP_CHANNEL_USERNAME;
                        break;
                }

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'channel_id' => $channel_id,
                    'quality' => $quality,
                    'size' => $size,
                    'language' => $language,
                    'channel_type' => $channel_type,
                    'channel_username' => $channel_username,
                    'source_channel' => $channel_id
                ];
                
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name','message_id','date','channel_id','quality','size','language'));
    foreach ($data as $row) {
        fputcsv($handle, [
            $row['movie_name'], 
            $row['message_id_raw'], 
            $row['date'], 
            $row['channel_id'],
            $row['quality'],
            $row['size'],
            $row['language']
        ]);
    }
    fclose($handle);

    bot_log("CSV cleaned and reloaded - " . count($data) . " entries");
    return $data;
}

// ==============================
// TELEGRAM API FUNCTIONS
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        if ($res === false) {
            bot_log("CURL ERROR: " . curl_error($ch), 'ERROR');
        }
        curl_close($ch);
        return $res;
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            bot_log("API Request failed for method: $method", 'ERROR');
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'disable_web_page_preview' => true
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    
    $result = apiRequest('sendMessage', $data);
    bot_log("Message sent to $chat_id: " . substr($text, 0, 50) . "...");
    return json_decode($result, true);
}

function editMessage($chat_id, $message_id, $new_text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $new_text,
        'disable_web_page_preview' => true
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    apiRequest('editMessageText', $data);
}

function deleteMessage($chat_id, $message_id) {
    apiRequest('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
}

function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $data = [
        'callback_query_id' => $callback_query_id,
        'show_alert' => $show_alert
    ];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $result = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return $result;
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

// ==============================
// ENHANCED MOVIE DELIVERY SYSTEM WITH FORWARD HEADER CONTROL
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    if (!isset($item['channel_id']) || empty($item['channel_id'])) {
        $source_channel = MAIN_CHANNEL_ID;
        bot_log("Channel ID not found for movie: {$item['movie_name']}, using default", 'WARNING');
    } else {
        $source_channel = $item['channel_id'];
    }
    
    $channel_type = isset($item['channel_type']) ? $item['channel_type'] : 'main';
    $forward_header_enabled = get_forward_header_setting($source_channel);
    
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        if ($forward_header_enabled) {
            // Public channel - forward with header
            $result = json_decode(forwardMessage($chat_id, $source_channel, $item['message_id']), true);
            
            if ($result && $result['ok']) {
                update_stats('total_downloads', 1);
                bot_log("Movie FORWARDED (with header) from $channel_type: {$item['movie_name']} to $chat_id");
                return true;
            }
        } else {
            // Private channel - copy without header
            $result = json_decode(copyMessage($chat_id, $source_channel, $item['message_id']), true);
            
            if ($result && $result['ok']) {
                update_stats('total_downloads', 1);
                bot_log("Movie COPIED (no header) from $channel_type: {$item['movie_name']} to $chat_id");
                return true;
            }
        }
        
        // Fallback: try copy if forward failed or vice versa
        if ($forward_header_enabled) {
            $fallback_result = json_decode(copyMessage($chat_id, $source_channel, $item['message_id']), true);
            if ($fallback_result && $fallback_result['ok']) {
                update_stats('total_downloads', 1);
                bot_log("Movie COPIED (fallback) from $channel_type: {$item['movie_name']} to $chat_id");
                return true;
            }
        } else {
            $fallback_result = json_decode(forwardMessage($chat_id, $source_channel, $item['message_id']), true);
            if ($fallback_result && $fallback_result['ok']) {
                update_stats('total_downloads', 1);
                bot_log("Movie FORWARDED (fallback) from $channel_type: {$item['movie_name']} to $chat_id");
                return true;
            }
        }
    }
    
    if (!empty($item['message_id_raw'])) {
        $message_id_clean = preg_replace('/[^0-9]/', '', $item['message_id_raw']);
        if (is_numeric($message_id_clean) && $message_id_clean > 0) {
            if ($forward_header_enabled) {
                $result = json_decode(forwardMessage($chat_id, $source_channel, $message_id_clean), true);
                if ($result && $result['ok']) {
                    update_stats('total_downloads', 1);
                    bot_log("Movie FORWARDED (raw ID) from $channel_type: {$item['movie_name']} to $chat_id");
                    return true;
                }
            } else {
                $result = json_decode(copyMessage($chat_id, $source_channel, $message_id_clean), true);
                if ($result && $result['ok']) {
                    update_stats('total_downloads', 1);
                    bot_log("Movie COPIED (raw ID) from $channel_type: {$item['movie_name']} to $chat_id");
                    return true;
                }
            }
        }
    }

    $text = "🎬 <b>" . htmlspecialchars($item['movie_name'] ?? 'Unknown') . "</b>\n";
    $text .= "📊 Quality: " . htmlspecialchars($item['quality'] ?? 'Unknown') . "\n";
    $text .= "💾 Size: " . htmlspecialchars($item['size'] ?? 'Unknown') . "\n";
    $text .= "🗣️ Language: " . htmlspecialchars($item['language'] ?? 'Hindi') . "\n";
    $text .= "🎭 Channel: " . get_channel_display_name($channel_type) . "\n";
    $text .= "📅 Date: " . htmlspecialchars($item['date'] ?? 'N/A') . "\n";
    $text .= "📎 Reference: " . htmlspecialchars($item['message_id_raw'] ?? 'N/A') . "\n\n";
    
    if (!empty($item['message_id']) && is_numeric($item['message_id']) && !empty($source_channel)) {
        $text .= "🔗 Direct Link: " . get_direct_channel_link($item['message_id'], $source_channel) . "\n\n";
    }
    
    $text .= "⚠️ Join channel to access content: " . get_channel_username_link($channel_type);
    
    sendMessage($chat_id, $text, null, 'HTML');
    update_stats('total_downloads', 1);
    return false;
}

// ==============================
// AUTO INDEXING SYSTEM - FEATURE 1
// ==============================

function get_channel_messages($channel_id, $offset_message_id = null, $limit = 100) {
    $params = [
        'chat_id' => $channel_id,
        'limit' => $limit
    ];
    
    if ($offset_message_id) {
        $params['offset'] = $offset_message_id;
    }
    
    $result = apiRequest('getChatHistory', $params);
    $decoded = json_decode($result, true);
    
    if ($decoded && $decoded['ok']) {
        return $decoded['result']['messages'] ?? [];
    }
    
    return [];
}

function get_channel_updates($channel_id, $last_message_id = null) {
    $params = [
        'chat_id' => $channel_id,
        'limit' => MAX_POSTS_PER_SCAN
    ];
    
    if ($last_message_id) {
        $params['offset'] = $last_message_id + 1;
    }
    
    $result = apiRequest('getUpdates', $params);
    $decoded = json_decode($result, true);
    
    if ($decoded && $decoded['ok']) {
        $messages = [];
        foreach ($decoded['result'] as $update) {
            if (isset($update['channel_post'])) {
                $messages[] = $update['channel_post'];
            }
        }
        return $messages;
    }
    
    return [];
}

function extract_movie_info_from_message($message, $channel_id) {
    $text = '';
    $quality = 'Unknown';
    $size = 'Unknown';
    $language = 'Hindi';
    $movie_name = '';
    
    if (isset($message['caption'])) {
        $text = $message['caption'];
    } elseif (isset($message['text'])) {
        $text = $message['text'];
    } elseif (isset($message['document'])) {
        $text = $message['document']['file_name'];
        $size = round($message['document']['file_size'] / (1024 * 1024), 2) . ' MB';
    } else {
        $text = 'Uploaded Media - ' . date('d-m-Y H:i');
    }
    
    if (stripos($text, '1080') !== false) $quality = '1080p';
    elseif (stripos($text, '720') !== false) $quality = '720p';
    elseif (stripos($text, '480') !== false) $quality = '480p';
    
    if (stripos($text, 'theater') !== false || stripos($text, 'print') !== false) {
        $quality = 'Theater Print';
    }
    
    if (stripos($text, 'english') !== false) $language = 'English';
    if (stripos($text, 'hindi') !== false) $language = 'Hindi';
    if (stripos($text, 'tamil') !== false) $language = 'Tamil';
    if (stripos($text, 'telugu') !== false) $language = 'Telugu';
    
    $movie_name = clean_movie_name($text);
    
    return [
        'movie_name' => $movie_name,
        'quality' => $quality,
        'size' => $size,
        'language' => $language,
        'original_text' => $text
    ];
}

function clean_movie_name($text) {
    $text = preg_replace('/\s*[\(\[].*?[\)\]]\s*/', '', $text);
    $text = preg_replace('/\b(1080p|720p|480p|HD|FHD|4K|theater|print|camrip|hdcam|HQ|BluRay|WEB-DL|WEBRip)\b/i', '', $text);
    $text = preg_replace('/\b(Hindi|English|Tamil|Telugu|Malayalam|Kannada)\b/i', '', $text);
    $text = preg_replace('/\b\d+\s*(GB|MB)\b/i', '', $text);
    $text = preg_replace('/[^\w\s\-\.]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    if (empty($text) || strlen($text) < 3) {
        $text = 'Unknown Movie ' . date('Y-m-d');
    }
    
    return $text;
}

function is_movie_already_indexed($message_id, $channel_id) {
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    $key = $channel_id . '_' . $message_id;
    
    return isset($indexing_log['indexed_messages'][$key]);
}

function mark_movie_as_indexed($message_id, $channel_id, $movie_name) {
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    $key = $channel_id . '_' . $message_id;
    
    $indexing_log['indexed_messages'][$key] = [
        'message_id' => $message_id,
        'channel_id' => $channel_id,
        'movie_name' => $movie_name,
        'indexed_at' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    
    $indexing_log['total_indexed'] = count($indexing_log['indexed_messages']);
    $indexing_log['last_full_scan'] = date('Y-m-d H:i:s');
    
    file_put_contents(INDEXING_LOG, json_encode($indexing_log, JSON_PRETTY_PRINT));
}

function update_channel_last_message($channel_id, $message_id) {
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    
    if (!isset($indexing_log['channel_last_message_id'][$channel_id]) || 
        $indexing_log['channel_last_message_id'][$channel_id] < $message_id) {
        $indexing_log['channel_last_message_id'][$channel_id] = $message_id;
        file_put_contents(INDEXING_LOG, json_encode($indexing_log, JSON_PRETTY_PRINT));
    }
}

function auto_index_new_posts() {
    if (!AUTO_INDEX_ENABLED) {
        return false;
    }
    
    $channels_to_index = [
        ['id' => MAIN_CHANNEL_ID, 'name' => 'Main Channel', 'type' => 'main'],
        ['id' => THEATER_CHANNEL_ID, 'name' => 'Theater Channel', 'type' => 'theater'],
        ['id' => SERIAL_CHANNEL_ID, 'name' => 'Serial Channel', 'type' => 'serial'],
        ['id' => BACKUP_CHANNEL_ID, 'name' => 'Backup Channel', 'type' => 'backup']
    ];
    
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    $new_indexed_count = 0;
    $indexed_movies = [];
    
    foreach ($channels_to_index as $channel) {
        $channel_id = $channel['id'];
        $last_message_id = $indexing_log['channel_last_message_id'][$channel_id] ?? 0;
        
        bot_log("Auto-indexing channel: {$channel['name']} (ID: $channel_id), Last ID: $last_message_id");
        
        $updates = get_channel_updates($channel_id, $last_message_id);
        
        if (empty($updates)) {
            bot_log("No new messages found in {$channel['name']}");
            continue;
        }
        
        bot_log("Found " . count($updates) . " new messages in {$channel['name']}");
        
        foreach ($updates as $message) {
            $message_id = $message['message_id'];
            
            if (is_movie_already_indexed($message_id, $channel_id)) {
                bot_log("Message $message_id already indexed, skipping");
                continue;
            }
            
            $movie_info = extract_movie_info_from_message($message, $channel_id);
            
            if (!empty($movie_info['movie_name'])) {
                append_movie(
                    $movie_info['movie_name'],
                    $message_id,
                    date('d-m-Y'),
                    $channel_id,
                    $movie_info['quality'],
                    $movie_info['size'],
                    $movie_info['language']
                );
                
                mark_movie_as_indexed($message_id, $channel_id, $movie_info['movie_name']);
                update_channel_last_message($channel_id, $message_id);
                
                $new_indexed_count++;
                $indexed_movies[] = [
                    'channel' => $channel['name'],
                    'movie' => $movie_info['movie_name'],
                    'quality' => $movie_info['quality'],
                    'message_id' => $message_id
                ];
                
                bot_log("Auto-indexed: {$movie_info['movie_name']} from {$channel['name']}");
            }
        }
    }
    
    if ($new_indexed_count > 0) {
        $admin_report = "🤖 <b>Auto-Indexing Report</b>\n\n";
        $admin_report .= "📊 Newly Indexed: <b>$new_indexed_count</b> movies\n";
        $admin_report .= "🕐 Time: " . date('Y-m-d H:i:s') . "\n\n";
        $admin_report .= "📋 <b>Recently Indexed:</b>\n";
        
        $last_few = array_slice($indexed_movies, -10);
        foreach ($last_few as $movie) {
            $admin_report .= "• {$movie['channel']}: {$movie['movie']} ({$movie['quality']})\n";
        }
        
        sendMessage(ADMIN_ID, $admin_report, null, 'HTML');
        
        update_stats('total_movies', $new_indexed_count);
    }
    
    bot_log("Auto-indexing complete. Indexed $new_indexed_count new movies.");
    return $new_indexed_count;
}

function full_channel_scan($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    sendMessage($chat_id, "🔄 Starting full channel scan... This may take a few minutes.");
    
    $channels_to_scan = [
        ['id' => MAIN_CHANNEL_ID, 'name' => 'Main Channel'],
        ['id' => THEATER_CHANNEL_ID, 'name' => 'Theater Channel'],
        ['id' => SERIAL_CHANNEL_ID, 'name' => 'Serial Channel'],
        ['id' => BACKUP_CHANNEL_ID, 'name' => 'Backup Channel']
    ];
    
    $total_scanned = 0;
    $total_indexed = 0;
    
    foreach ($channels_to_scan as $channel) {
        $channel_id = $channel['id'];
        $channel_name = $channel['name'];
        
        sendMessage($chat_id, "📡 Scanning $channel_name...");
        
        $messages = get_channel_messages($channel_id, null, MAX_POSTS_PER_SCAN);
        $scanned_count = count($messages);
        $total_scanned += $scanned_count;
        
        $new_in_channel = 0;
        
        foreach ($messages as $message) {
            $message_id = $message['message_id'];
            
            if (!is_movie_already_indexed($message_id, $channel_id)) {
                $movie_info = extract_movie_info_from_message($message, $channel_id);
                
                if (!empty($movie_info['movie_name'])) {
                    append_movie(
                        $movie_info['movie_name'],
                        $message_id,
                        date('d-m-Y', $message['date'] ?? time()),
                        $channel_id,
                        $movie_info['quality'],
                        $movie_info['size'],
                        $movie_info['language']
                    );
                    
                    mark_movie_as_indexed($message_id, $channel_id, $movie_info['movie_name']);
                    update_channel_last_message($channel_id, $message_id);
                    $new_in_channel++;
                    $total_indexed++;
                }
            }
        }
        
        sendMessage($chat_id, "✅ $channel_name: Scanned $scanned_count messages, Indexed $new_in_channel new movies");
        sleep(1);
    }
    
    $report = "✅ <b>Full Channel Scan Complete</b>\n\n";
    $report .= "📊 <b>Statistics:</b>\n";
    $report .= "• Total Scanned: $total_scanned messages\n";
    $report .= "• Newly Indexed: $total_indexed movies\n";
    $report .= "• Total in Database: " . (get_stats()['total_movies'] ?? 0) . "\n\n";
    $report .= "🕐 Completed at: " . date('Y-m-d H:i:s');
    
    sendMessage($chat_id, $report, null, 'HTML');
    bot_log("Full channel scan completed by admin. Indexed $total_indexed new movies.");
}

// ==============================
// ENHANCED REQUEST SYSTEM WITH PENDING_REQUESTS AND BULK_APPROVE
// ==============================
function can_user_request($user_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $today = date('Y-m-d');
    
    $user_requests_today = 0;
    foreach ($requests_data['requests'] ?? [] as $request) {
        if ($request['user_id'] == $user_id && $request['date'] == $today) {
            $user_requests_today++;
        }
    }
    
    return $user_requests_today < DAILY_REQUEST_LIMIT;
}

function add_movie_request($user_id, $movie_name, $language = 'hindi') {
    if (!can_user_request($user_id)) {
        return false;
    }
    
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    
    $request_id = uniqid();
    $requests_data['requests'][] = [
        'id' => $request_id,
        'user_id' => $user_id,
        'movie_name' => $movie_name,
        'language' => $language,
        'date' => date('Y-m-d'),
        'time' => date('H:i:s'),
        'status' => 'pending'
    ];
    
    if (!isset($requests_data['user_request_count'][$user_id])) {
        $requests_data['user_request_count'][$user_id] = 0;
    }
    $requests_data['user_request_count'][$user_id]++;
    
    file_put_contents(REQUEST_FILE, json_encode($requests_data, JSON_PRETTY_PRINT));
    
    $admin_msg = "🎯 New Movie Request\n\n";
    $admin_msg .= "🎬 Movie: $movie_name\n";
    $admin_msg .= "🗣️ Language: $language\n";
    $admin_msg .= "👤 User ID: $user_id\n";
    $admin_msg .= "📅 Date: " . date('Y-m-d H:i:s') . "\n";
    $admin_msg .= "🆔 Request ID: $request_id";
    
    sendMessage(ADMIN_ID, $admin_msg);
    bot_log("Movie request added: $movie_name by $user_id");
    
    return true;
}

function get_pending_requests($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return [];
    }
    
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $pending = $requests_data['requests'] ?? [];
    
    if (empty($pending)) {
        sendMessage($chat_id, "📝 <b>Pending Requests</b>\n\n✅ No pending requests! All good.", null, 'HTML');
        return [];
    }
    
    $message = "📝 <b>Pending Requests (" . count($pending) . ")</b>\n\n";
    
    foreach (array_slice($pending, 0, 20) as $index => $request) {
        $message .= ($index + 1) . ". 🎬 <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
        $message .= "   👤 User: <code>" . $request['user_id'] . "</code>\n";
        $message .= "   📅 Date: " . $request['date'] . " " . $request['time'] . "\n";
        $message .= "   🗣️ Language: " . ucfirst($request['language']) . "\n";
        $message .= "   🆔 ID: <code>" . $request['id'] . "</code>\n\n";
    }
    
    if (count($pending) > 20) {
        $message .= "... and " . (count($pending) - 20) . " more requests\n\n";
    }
    
    $message .= "💡 Use /bulk_approve [count] to approve multiple requests at once!";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ Approve All', 'callback_data' => 'bulk_approve_all'],
                ['text' => '❌ Reject All', 'callback_data' => 'bulk_reject_all']
            ],
            [
                ['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    return $pending;
}

function bulk_approve_requests($chat_id, $count = null) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $pending = $requests_data['requests'] ?? [];
    
    if (empty($pending)) {
        sendMessage($chat_id, "❌ No pending requests to approve!");
        return;
    }
    
    $approve_count = ($count === null || $count > count($pending)) ? count($pending) : $count;
    $approved_requests = array_slice($pending, 0, $approve_count);
    $approved_count = 0;
    $failed_count = 0;
    
    $progress_msg = sendMessage($chat_id, "🔄 Approving $approve_count requests...\n\nProgress: 0%");
    $progress_msg_id = $progress_msg['result']['message_id'];
    
    foreach ($approved_requests as $index => $request) {
        try {
            // Remove from pending
            $request_index = array_search($request, $requests_data['requests']);
            if ($request_index !== false) {
                unset($requests_data['requests'][$request_index]);
                $requests_data['requests'] = array_values($requests_data['requests']);
            }
            
            // Add to completed
            if (!isset($requests_data['completed_requests'])) {
                $requests_data['completed_requests'] = [];
            }
            
            $request['status'] = 'approved';
            $request['approved_at'] = date('Y-m-d H:i:s');
            $requests_data['completed_requests'][] = $request;
            
            // Notify user
            $user_message = "✅ <b>Movie Request Approved!</b>\n\n";
            $user_message .= "🎬 Movie: <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
            $user_message .= "📅 Request Date: " . $request['date'] . "\n\n";
            $user_message .= "🔍 Use /search " . urlencode($request['movie_name']) . " to find this movie!\n\n";
            $user_message .= "🍿 Join @EntertainmentTadka786 for latest updates!";
            
            sendMessage($request['user_id'], $user_message, null, 'HTML');
            $approved_count++;
            
            if (($index + 1) % 5 == 0) {
                $progress = round((($index + 1) / $approve_count) * 100);
                editMessage($chat_id, $progress_msg_id, "🔄 Approving $approve_count requests...\n\nProgress: $progress%\n✅ Approved: $approved_count\n❌ Failed: $failed_count");
            }
            
            usleep(200000); // Small delay to avoid rate limits
            
        } catch (Exception $e) {
            $failed_count++;
            bot_log("Bulk approve failed for request {$request['id']}: " . $e->getMessage(), 'ERROR');
        }
    }
    
    file_put_contents(REQUEST_FILE, json_encode($requests_data, JSON_PRETTY_PRINT));
    
    editMessage($chat_id, $progress_msg_id, "✅ <b>Bulk Approval Complete!</b>\n\n📊 Total Processed: $approve_count\n✅ Successfully Approved: $approved_count\n❌ Failed: $failed_count\n\n🕐 Completed at: " . date('Y-m-d H:i:s'));
    
    bot_log("Bulk approved $approved_count requests by admin $chat_id");
    
    // Send summary to admin
    $summary = "📊 <b>Bulk Approval Summary</b>\n\n";
    $summary .= "✅ Approved: $approved_count requests\n";
    $summary .= "❌ Failed: $failed_count requests\n";
    $summary .= "📅 Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    if ($approved_count > 0) {
        $summary .= "📋 <b>Approved Movies:</b>\n";
        foreach (array_slice($approved_requests, 0, 10) as $req) {
            $summary .= "• " . htmlspecialchars($req['movie_name']) . " (by user {$req['user_id']})\n";
        }
        if (count($approved_requests) > 10) {
            $summary .= "... and " . (count($approved_requests) - 10) . " more\n";
        }
    }
    
    sendMessage($chat_id, $summary, null, 'HTML');
}

function bulk_reject_requests($chat_id, $count = null) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $pending = $requests_data['requests'] ?? [];
    
    if (empty($pending)) {
        sendMessage($chat_id, "❌ No pending requests to reject!");
        return;
    }
    
    $reject_count = ($count === null || $count > count($pending)) ? count($pending) : $count;
    $rejected_requests = array_slice($pending, 0, $reject_count);
    $rejected_count = 0;
    
    $progress_msg = sendMessage($chat_id, "❌ Rejecting $reject_count requests...\n\nProgress: 0%");
    $progress_msg_id = $progress_msg['result']['message_id'];
    
    foreach ($rejected_requests as $index => $request) {
        try {
            // Remove from pending
            $request_index = array_search($request, $requests_data['requests']);
            if ($request_index !== false) {
                unset($requests_data['requests'][$request_index]);
                $requests_data['requests'] = array_values($requests_data['requests']);
            }
            
            // Add to completed as rejected
            if (!isset($requests_data['completed_requests'])) {
                $requests_data['completed_requests'] = [];
            }
            
            $request['status'] = 'rejected';
            $request['rejected_at'] = date('Y-m-d H:i:s');
            $requests_data['completed_requests'][] = $request;
            
            // Notify user
            $user_message = "❌ <b>Movie Request Rejected</b>\n\n";
            $user_message .= "🎬 Movie: <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
            $user_message .= "📅 Request Date: " . $request['date'] . "\n\n";
            $user_message .= "💡 Possible reasons:\n";
            $user_message .= "• Movie already available\n";
            $user_message .= "• Invalid movie name\n";
            $user_message .= "• Technical limitations\n\n";
            $user_message .= "📝 Try requesting again with correct spelling!\n";
            $user_message .= "🍿 Join @EntertainmentTadka7860 for support!";
            
            sendMessage($request['user_id'], $user_message, null, 'HTML');
            $rejected_count++;
            
            if (($index + 1) % 5 == 0) {
                $progress = round((($index + 1) / $reject_count) * 100);
                editMessage($chat_id, $progress_msg_id, "❌ Rejecting $reject_count requests...\n\nProgress: $progress%\n❌ Rejected: $rejected_count");
            }
            
            usleep(200000);
            
        } catch (Exception $e) {
            bot_log("Bulk reject failed for request {$request['id']}: " . $e->getMessage(), 'ERROR');
        }
    }
    
    file_put_contents(REQUEST_FILE, json_encode($requests_data, JSON_PRETTY_PRINT));
    
    editMessage($chat_id, $progress_msg_id, "✅ <b>Bulk Rejection Complete!</b>\n\n📊 Total Processed: $reject_count\n❌ Successfully Rejected: $rejected_count\n\n🕐 Completed at: " . date('Y-m-d H:i:s'));
    
    bot_log("Bulk rejected $rejected_count requests by admin $chat_id");
}

// ==============================
// ADMIN PANEL SYSTEM - FEATURE 2 (WITHOUT COMMANDS)
// ==============================

$admin_session_active = false;
$admin_menu_message_id = null;
$admin_panel_users = [];

function send_admin_panel($chat_id, $user_id) {
    if ($user_id != ADMIN_ID) {
        return false;
    }
    
    $panel_message = "👑 <b>Admin Control Panel</b>\n\n";
    
    $panel_message .= "📊 <b>System Status:</b>\n";
    
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    
    $panel_message .= "• 🎬 Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $panel_message .= "• 👥 Users: " . count($users_data['users'] ?? []) . "\n";
    $panel_message .= "• 📥 Pending Requests: " . count($requests_data['requests'] ?? []) . "\n";
    $panel_message .= "• 🔍 Today's Searches: " . (($stats['daily_activity'][date('Y-m-d')]['searches'] ?? 0)) . "\n";
    $panel_message .= "• 📥 Today's Downloads: " . (($stats['daily_activity'][date('Y-m-d')]['downloads'] ?? 0)) . "\n\n";
    
    $panel_message .= "🛠️ <b>Quick Actions:</b>\n";
    $panel_message .= "• Click buttons below to manage bot\n";
    $panel_message .= "• View detailed statistics\n";
    $panel_message .= "• Manage movie database\n";
    $panel_message .= "• Handle user requests\n\n";
    
    $panel_message .= "🕐 Last Updated: " . date('Y-m-d H:i:s');
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 Full Statistics', 'callback_data' => 'admin_stats'],
                ['text' => '🎬 Movie Management', 'callback_data' => 'admin_movies']
            ],
            [
                ['text' => '📝 Pending Requests', 'callback_data' => 'admin_requests'],
                ['text' => '👥 User Management', 'callback_data' => 'admin_users']
            ],
            [
                ['text' => '📢 Broadcast Message', 'callback_data' => 'admin_broadcast'],
                ['text' => '🔄 System Actions', 'callback_data' => 'admin_system']
            ],
            [
                ['text' => '🤖 Auto-Indexing', 'callback_data' => 'admin_indexing'],
                ['text' => '💾 Backup & Restore', 'callback_data' => 'admin_backup']
            ],
            [
                ['text' => '📤 Export Data', 'callback_data' => 'admin_export'],
                ['text' => '⚙️ Bot Settings', 'callback_data' => 'admin_settings']
            ],
            [
                ['text' => '🔐 Forward Header Settings', 'callback_data' => 'admin_forward_settings'],
                ['text' => '📋 Bulk Actions', 'callback_data' => 'admin_bulk_actions']
            ],
            [
                ['text' => '❌ Close Panel', 'callback_data' => 'admin_close']
            ]
        ]
    ];
    
    $result = sendMessage($chat_id, $panel_message, $keyboard, 'HTML');
    
    if ($result && isset($result['result']['message_id'])) {
        global $admin_menu_message_id;
        $admin_menu_message_id = $result['result']['message_id'];
    }
    
    return true;
}

function handle_admin_callback($chat_id, $user_id, $callback_data, $callback_query_id) {
    if ($user_id != ADMIN_ID) {
        answerCallbackQuery($callback_query_id, "❌ Admin access only!", true);
        return false;
    }
    
    switch ($callback_data) {
        case 'admin_stats':
            show_admin_stats($chat_id, $callback_query_id);
            break;
            
        case 'admin_movies':
            show_movie_management_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_requests':
            show_pending_requests($chat_id, $callback_query_id);
            break;
            
        case 'admin_users':
            show_user_management_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_broadcast':
            show_broadcast_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_system':
            show_system_actions_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_indexing':
            show_indexing_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_backup':
            show_backup_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_export':
            show_export_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_settings':
            show_settings_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_forward_settings':
            show_forward_settings_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_bulk_actions':
            show_bulk_actions_menu($chat_id, $callback_query_id);
            break;
            
        case 'admin_close':
            deleteMessage($chat_id, $admin_menu_message_id ?? 0);
            sendMessage($chat_id, "👋 Admin Panel Closed.\n\nUse /admin to reopen.");
            answerCallbackQuery($callback_query_id, "Panel closed");
            break;
            
        default:
            if (strpos($callback_data, 'approve_req_') === 0) {
                $request_id = str_replace('approve_req_', '', $callback_data);
                approve_movie_request($chat_id, $request_id, $callback_query_id);
            } elseif (strpos($callback_data, 'reject_req_') === 0) {
                $request_id = str_replace('reject_req_', '', $callback_data);
                reject_movie_request($chat_id, $request_id, $callback_query_id);
            } elseif (strpos($callback_data, 'delete_movie_') === 0) {
                $movie_name = urldecode(str_replace('delete_movie_', '', $callback_data));
                delete_movie_from_db($chat_id, $movie_name, $callback_query_id);
            } elseif (strpos($callback_data, 'view_user_') === 0) {
                $target_user_id = str_replace('view_user_', '', $callback_data);
                view_user_details($chat_id, $target_user_id, $callback_query_id);
            } elseif (strpos($callback_data, 'ban_user_') === 0) {
                $target_user_id = str_replace('ban_user_', '', $callback_data);
                toggle_user_ban($chat_id, $target_user_id, $callback_query_id);
            } elseif (strpos($callback_data, 'toggle_forward_') === 0) {
                $channel_id = str_replace('toggle_forward_', '', $callback_data);
                $channel_type = (strpos($channel_id, 'private') !== false) ? 'private' : 'public';
                toggle_forward_header($chat_id, $channel_id, $channel_type);
                answerCallbackQuery($callback_query_id, "Forward header toggled");
                show_forward_settings_menu($chat_id, $callback_query_id);
            } elseif ($callback_data == 'bulk_approve_all') {
                bulk_approve_requests($chat_id);
                answerCallbackQuery($callback_query_id, "Bulk approve started!");
            } elseif ($callback_data == 'bulk_reject_all') {
                bulk_reject_requests($chat_id);
                answerCallbackQuery($callback_query_id, "Bulk reject started!");
            } elseif ($callback_data == 'bulk_approve_10') {
                bulk_approve_requests($chat_id, 10);
                answerCallbackQuery($callback_query_id, "Approving 10 requests");
            } elseif ($callback_data == 'bulk_approve_25') {
                bulk_approve_requests($chat_id, 25);
                answerCallbackQuery($callback_query_id, "Approving 25 requests");
            } elseif ($callback_data == 'bulk_approve_50') {
                bulk_approve_requests($chat_id, 50);
                answerCallbackQuery($callback_query_id, "Approving 50 requests");
            } elseif ($callback_data == 'run_auto_index') {
                auto_index_new_posts();
                answerCallbackQuery($callback_query_id, "Auto-indexing triggered!");
                sendMessage($chat_id, "✅ Auto-indexing completed! Check logs for details.");
            } elseif ($callback_data == 'full_channel_scan') {
                full_channel_scan($chat_id);
                answerCallbackQuery($callback_query_id, "Full scan started!");
            } elseif ($callback_data == 'indexing_status') {
                show_indexing_status($chat_id, $callback_query_id);
            } elseif ($callback_data == 'manual_backup') {
                manual_backup($chat_id);
                answerCallbackQuery($callback_query_id, "Backup started!");
            } elseif ($callback_data == 'quick_backup') {
                quick_backup($chat_id);
                answerCallbackQuery($callback_query_id, "Quick backup started!");
            } elseif ($callback_data == 'export_csv') {
                export_csv_file($chat_id, $callback_query_id);
            } elseif ($callback_data == 'export_users') {
                export_users_file($chat_id, $callback_query_id);
            } elseif ($callback_data == 'export_requests') {
                export_requests_file($chat_id, $callback_query_id);
            } elseif ($callback_data == 'toggle_maintenance') {
                toggle_maintenance_mode_panel($chat_id, $callback_query_id);
            } elseif ($callback_data == 'clear_cache') {
                clear_system_cache($chat_id, $callback_query_id);
            } elseif ($callback_data == 'refresh_panel') {
                send_admin_panel($chat_id, $user_id);
                answerCallbackQuery($callback_query_id, "Panel refreshed");
            }
            break;
    }
    
    return true;
}

function show_forward_settings_menu($chat_id, $callback_query_id) {
    $settings = initialize_forward_settings();
    
    $message = "🔐 <b>Forward Header Settings</b>\n\n";
    $message .= "📋 <b>Public Channels (Header ON by default):</b>\n";
    
    foreach ($settings['public_channels'] as $channel_id => $channel) {
        $status = $channel['forward_header'] ? "✅ ON" : "❌ OFF";
        $message .= "• {$channel['name']}: $status\n";
        $message .= "  <code>$channel_id</code>\n";
    }
    
    $message .= "\n🔒 <b>Private Channels (Header OFF by default):</b>\n";
    
    foreach ($settings['private_channels'] as $channel_id => $channel) {
        $status = $channel['forward_header'] ? "✅ ON" : "❌ OFF";
        $message .= "• {$channel['name']}: $status\n";
        $message .= "  <code>$channel_id</code>\n";
    }
    
    $message .= "\n💡 <b>How it works:</b>\n";
    $message .= "• ✅ ON = Forward with sender header (shows original channel)\n";
    $message .= "• ❌ OFF = Copy without header (hides original source)\n\n";
    $message .= "🔄 Click a channel below to toggle its setting:";
    
    $keyboard = ['inline_keyboard' => []];
    
    // Add public channel toggles
    $keyboard['inline_keyboard'][] = [['text' => '📢 PUBLIC CHANNELS', 'callback_data' => 'noop']];
    foreach ($settings['public_channels'] as $channel_id => $channel) {
        $status_icon = $channel['forward_header'] ? "✅" : "❌";
        $keyboard['inline_keyboard'][] = [
            ['text' => "$status_icon {$channel['name']}", 'callback_data' => 'toggle_forward_' . $channel_id]
        ];
    }
    
    // Add private channel toggles
    $keyboard['inline_keyboard'][] = [['text' => '🔒 PRIVATE CHANNELS', 'callback_data' => 'noop']];
    foreach ($settings['private_channels'] as $channel_id => $channel) {
        $status_icon = $channel['forward_header'] ? "✅" : "❌";
        $keyboard['inline_keyboard'][] = [
            ['text' => "$status_icon {$channel['name']}", 'callback_data' => 'toggle_forward_' . $channel_id]
        ];
    }
    
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Forward header settings");
}

function show_bulk_actions_menu($chat_id, $callback_query_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $pending_count = count($requests_data['requests'] ?? []);
    
    $message = "📋 <b>Bulk Actions Menu</b>\n\n";
    $message .= "📊 Current Pending Requests: <b>$pending_count</b>\n\n";
    
    $message .= "🛠️ <b>Available Bulk Actions:</b>\n\n";
    $message .= "• <b>Bulk Approve</b> - Approve multiple pending requests\n";
    $message .= "• <b>Bulk Reject</b> - Reject multiple pending requests\n";
    $message .= "• <b>View All Pending</b> - See all pending requests\n\n";
    
    $message .= "💡 <b>Commands:</b>\n";
    $message .= "• <code>/pending_request</code> - View all pending requests\n";
    $message .= "• <code>/bulk_approve 10</code> - Approve 10 requests\n";
    $message .= "• <code>/bulk_reject 5</code> - Reject 5 requests\n\n";
    
    $message .= "⚠️ <b>Warning:</b> Bulk actions cannot be undone!";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ Bulk Approve All', 'callback_data' => 'bulk_approve_all'],
                ['text' => '❌ Bulk Reject All', 'callback_data' => 'bulk_reject_all']
            ],
            [
                ['text' => '✅ Approve 10', 'callback_data' => 'bulk_approve_10'],
                ['text' => '✅ Approve 25', 'callback_data' => 'bulk_approve_25'],
                ['text' => '✅ Approve 50', 'callback_data' => 'bulk_approve_50']
            ],
            [
                ['text' => '📝 View Pending Requests', 'callback_data' => 'admin_requests'],
                ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Bulk actions menu");
}

function show_admin_stats($chat_id, $callback_query_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    
    $message = "📊 <b>Detailed Statistics</b>\n\n";
    
    $message .= "🎬 <b>Movie Database:</b>\n";
    $message .= "• Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $message .= "• Auto-Indexed: " . ($indexing_log['total_indexed'] ?? 0) . "\n";
    $message .= "• Last Indexed: " . ($indexing_log['last_full_scan'] ?? 'Never') . "\n\n";
    
    $message .= "👥 <b>User Statistics:</b>\n";
    $message .= "• Total Users: " . count($users_data['users'] ?? []) . "\n";
    $message .= "• Total Requests: " . ($users_data['total_requests'] ?? 0) . "\n";
    $message .= "• Pending Requests: " . count($requests_data['requests'] ?? []) . "\n\n";
    
    $message .= "🔍 <b>Search Statistics:</b>\n";
    $message .= "• Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $message .= "• Successful: " . ($stats['successful_searches'] ?? 0) . "\n";
    $message .= "• Failed: " . ($stats['failed_searches'] ?? 0) . "\n";
    $message .= "• Success Rate: " . (($stats['total_searches'] ?? 0) > 0 ? round((($stats['successful_searches'] ?? 0) / ($stats['total_searches'] ?? 0)) * 100, 2) : 0) . "%\n\n";
    
    $message .= "📥 <b>Download Statistics:</b>\n";
    $message .= "• Total Downloads: " . ($stats['total_downloads'] ?? 0) . "\n\n";
    
    $message .= "📅 <b>Today's Activity:</b>\n";
    $today = date('Y-m-d');
    $message .= "• Searches: " . (($stats['daily_activity'][$today]['searches'] ?? 0)) . "\n";
    $message .= "• Downloads: " . (($stats['daily_activity'][$today]['downloads'] ?? 0)) . "\n\n";
    
    $message .= "💾 <b>System Info:</b>\n";
    $message .= "• PHP Version: " . phpversion() . "\n";
    $message .= "• Memory Usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
    $message .= "• Last Updated: " . ($stats['last_updated'] ?? 'N/A');
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Refresh Stats', 'callback_data' => 'admin_stats'],
                ['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Statistics displayed");
}

function show_movie_management_menu($chat_id, $callback_query_id) {
    $stats = get_stats();
    
    $message = "🎬 <b>Movie Management</b>\n\n";
    $message .= "📊 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n\n";
    $message .= "🛠️ <b>Available Actions:</b>\n";
    $message .= "• Delete movies from database\n";
    $message .= "• View all movies\n";
    $message .= "• Search and edit movies\n";
    $message .= "• Add movies manually\n\n";
    $message .= "💡 <b>Quick Commands:</b>\n";
    $message .= "• /checkcsv - View CSV data\n";
    $message .= "• /totalupload - Browse all movies\n";
    $message .= "• /checkdate - Upload statistics";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📋 View All Movies', 'callback_data' => 'admin_view_all_movies'],
                ['text' => '🔍 Search Movie', 'callback_data' => 'admin_search_movie']
            ],
            [
                ['text' => '➕ Add Movie Manually', 'callback_data' => 'admin_add_movie'],
                ['text' => '🗑️ Delete Movie', 'callback_data' => 'admin_delete_movie']
            ],
            [
                ['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Movie management menu");
}

function show_pending_requests($chat_id, $callback_query_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $pending = $requests_data['requests'] ?? [];
    
    if (empty($pending)) {
        $message = "📝 <b>Pending Requests</b>\n\n✅ No pending requests! All good.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']]
            ]
        ];
        
        sendMessage($chat_id, $message, $keyboard, 'HTML');
        answerCallbackQuery($callback_query_id, "No pending requests");
        return;
    }
    
    $message = "📝 <b>Pending Requests (" . count($pending) . ")</b>\n\n";
    
    foreach (array_slice($pending, 0, 10) as $request) {
        $message .= "🎬 <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
        $message .= "👤 User: <code>" . $request['user_id'] . "</code>\n";
        $message .= "📅 Date: " . $request['date'] . " " . $request['time'] . "\n";
        $message .= "🗣️ Language: " . ucfirst($request['language']) . "\n";
        $message .= "🆔 ID: <code>" . $request['id'] . "</code>\n\n";
    }
    
    if (count($pending) > 10) {
        $message .= "... and " . (count($pending) - 10) . " more requests\n\n";
    }
    
    $message .= "💡 Use buttons below to approve/reject requests:\n";
    $message .= "📋 Use /bulk_approve for bulk actions!";
    
    $keyboard = ['inline_keyboard' => []];
    
    foreach (array_slice($pending, 0, 5) as $request) {
        $keyboard['inline_keyboard'][] = [
            ['text' => "✅ Approve: " . htmlspecialchars($request['movie_name']), 'callback_data' => 'approve_req_' . $request['id']],
            ['text' => "❌ Reject", 'callback_data' => 'reject_req_' . $request['id']]
        ];
    }
    
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔄 Refresh', 'callback_data' => 'admin_requests'],
        ['text' => '📋 Bulk Actions', 'callback_data' => 'admin_bulk_actions'],
        ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, count($pending) . " pending requests");
}

function approve_movie_request($chat_id, $request_id, $callback_query_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    
    $request_index = null;
    $request = null;
    
    foreach ($requests_data['requests'] as $index => $req) {
        if ($req['id'] == $request_id) {
            $request_index = $index;
            $request = $req;
            break;
        }
    }
    
    if ($request === null) {
        sendMessage($chat_id, "❌ Request not found!");
        answerCallbackQuery($callback_query_id, "Request not found", true);
        return;
    }
    
    unset($requests_data['requests'][$request_index]);
    $requests_data['requests'] = array_values($requests_data['requests']);
    
    if (!isset($requests_data['completed_requests'])) {
        $requests_data['completed_requests'] = [];
    }
    
    $request['status'] = 'approved';
    $request['approved_at'] = date('Y-m-d H:i:s');
    $requests_data['completed_requests'][] = $request;
    
    file_put_contents(REQUEST_FILE, json_encode($requests_data, JSON_PRETTY_PRINT));
    
    $user_message = "✅ <b>Movie Request Approved!</b>\n\n";
    $user_message .= "🎬 Movie: <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
    $user_message .= "📅 Request Date: " . $request['date'] . "\n\n";
    $user_message .= "🔍 Use /search " . urlencode($request['movie_name']) . " to find this movie!\n\n";
    $user_message .= "🍿 Join @EntertainmentTadka786 for latest updates!";
    
    sendMessage($request['user_id'], $user_message, null, 'HTML');
    
    sendMessage($chat_id, "✅ Request approved and user notified!\n\nMovie: " . htmlspecialchars($request['movie_name']));
    answerCallbackQuery($callback_query_id, "Request approved");
    
    show_pending_requests($chat_id, $callback_query_id);
}

function reject_movie_request($chat_id, $request_id, $callback_query_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    
    $request_index = null;
    $request = null;
    
    foreach ($requests_data['requests'] as $index => $req) {
        if ($req['id'] == $request_id) {
            $request_index = $index;
            $request = $req;
            break;
        }
    }
    
    if ($request === null) {
        sendMessage($chat_id, "❌ Request not found!");
        answerCallbackQuery($callback_query_id, "Request not found", true);
        return;
    }
    
    unset($requests_data['requests'][$request_index]);
    $requests_data['requests'] = array_values($requests_data['requests']);
    
    file_put_contents(REQUEST_FILE, json_encode($requests_data, JSON_PRETTY_PRINT));
    
    $user_message = "❌ <b>Movie Request Rejected</b>\n\n";
    $user_message .= "🎬 Movie: <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
    $user_message .= "📅 Request Date: " . $request['date'] . "\n\n";
    $user_message .= "💡 Possible reasons:\n";
    $user_message .= "• Movie already available\n";
    $user_message .= "• Invalid movie name\n";
    $user_message .= "• Technical limitations\n\n";
    $user_message .= "📝 Try requesting again with correct spelling!\n";
    $user_message .= "🍿 Join @EntertainmentTadka7860 for support!";
    
    sendMessage($request['user_id'], $user_message, null, 'HTML');
    
    sendMessage($chat_id, "❌ Request rejected.\n\nMovie: " . htmlspecialchars($request['movie_name']));
    answerCallbackQuery($callback_query_id, "Request rejected");
    
    show_pending_requests($chat_id, $callback_query_id);
}

function show_user_management_menu($chat_id, $callback_query_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $users = $users_data['users'] ?? [];
    
    $message = "👥 <b>User Management</b>\n\n";
    $message .= "📊 Total Users: " . count($users) . "\n";
    $message .= "📝 Total Requests: " . ($users_data['total_requests'] ?? 0) . "\n\n";
    $message .= "👤 <b>Recent Users:</b>\n";
    
    $recent_users = array_slice($users, -10, 10, true);
    $recent_users = array_reverse($recent_users);
    
    foreach ($recent_users as $user_id => $user) {
        $name = $user['first_name'] ?? 'Unknown';
        if (!empty($user['username'])) {
            $name .= " (@{$user['username']})";
        }
        $message .= "• <code>$user_id</code> - $name\n";
        $message .= "  Joined: {$user['joined']}\n";
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 User Statistics', 'callback_data' => 'admin_user_stats'],
                ['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']
            ],
            [
                ['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "User management menu");
}

function show_broadcast_menu($chat_id, $callback_query_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    
    $message = "📢 <b>Broadcast Message</b>\n\n";
    $message .= "👥 Total Users: $total_users\n\n";
    $message .= "💡 <b>How to send broadcast:</b>\n";
    $message .= "Use command:\n";
    $message .= "<code>/broadcast Your message here</code>\n\n";
    $message .= "⚠️ <b>Warning:</b>\n";
    $message .= "• Message will be sent to ALL users\n";
    $message .= "• Use carefully!\n";
    $message .= "• Can't be undone\n\n";
    $message .= "📝 <b>Example:</b>\n";
    $message .= "<code>/broadcast New movie added: Pushpa 2!</code>";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 View Users', 'callback_data' => 'admin_users'],
                ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Broadcast instructions");
}

function show_system_actions_menu($chat_id, $callback_query_id) {
    $message = "🔄 <b>System Actions</b>\n\n";
    $message .= "🛠️ <b>Available Actions:</b>\n\n";
    $message .= "• <b>Clear Cache</b> - Refresh movie database\n";
    $message .= "• <b>Toggle Maintenance</b> - Put bot in maintenance mode\n";
    $message .= "• <b>Full Scan</b> - Complete channel scan\n";
    $message .= "• <b>Auto-Index</b> - Index new posts\n\n";
    $message .= "⚠️ Some actions may take time!";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🧹 Clear Cache', 'callback_data' => 'clear_cache'],
                ['text' => '🔧 Maintenance Mode', 'callback_data' => 'toggle_maintenance']
            ],
            [
                ['text' => '🔄 Full Channel Scan', 'callback_data' => 'full_channel_scan'],
                ['text' => '🤖 Run Auto-Index', 'callback_data' => 'run_auto_index']
            ],
            [
                ['text' => '🔙 Back to Panel', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "System actions menu");
}

function show_indexing_menu($chat_id, $callback_query_id) {
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    
    $message = "🤖 <b>Auto-Indexing Control Panel</b>\n\n";
    $message .= "📊 <b>Indexing Statistics:</b>\n";
    $message .= "• Total Indexed: " . ($indexing_log['total_indexed'] ?? 0) . "\n";
    $message .= "• Last Full Scan: " . ($indexing_log['last_full_scan'] ?? 'Never') . "\n\n";
    
    $message .= "📡 <b>Channel Status:</b>\n";
    foreach (['main', 'theater', 'serial', 'backup'] as $type) {
        $channel_id = constant(strtoupper($type) . '_CHANNEL_ID');
        $last_id = $indexing_log['channel_last_message_id'][$channel_id] ?? 0;
        $message .= "• " . ucfirst($type) . ": Last ID $last_id\n";
    }
    
    $message .= "\n🛠️ <b>Actions:</b>\n";
    $message .= "• Auto-indexing runs automatically every " . INDEX_CHECK_INTERVAL . " seconds\n";
    $message .= "• Manual scan available below\n";
    $message .= "• Full scan checks all channels completely";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Run Auto-Index Now', 'callback_data' => 'run_auto_index'],
                ['text' => '📡 Full Channel Scan', 'callback_data' => 'full_channel_scan']
            ],
            [
                ['text' => '📊 Indexing Status', 'callback_data' => 'indexing_status'],
                ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Indexing control panel");
}

function show_indexing_status($chat_id, $callback_query_id) {
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    $recent_indexed = array_slice($indexing_log['indexed_messages'], -10, 10, true);
    
    $message = "📊 <b>Recent Indexed Movies</b>\n\n";
    
    if (empty($recent_indexed)) {
        $message .= "No movies indexed yet.\nRun auto-indexing to start!";
    } else {
        foreach (array_reverse($recent_indexed) as $item) {
            $message .= "🎬 <b>" . htmlspecialchars($item['movie_name']) . "</b>\n";
            $message .= "📅 Indexed: " . $item['indexed_at'] . "\n";
            $message .= "🆔 Message ID: " . $item['message_id'] . "\n\n";
        }
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Refresh', 'callback_data' => 'indexing_status'],
                ['text' => '🔙 Back', 'callback_data' => 'admin_indexing']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Indexing status");
}

function show_backup_menu($chat_id, $callback_query_id) {
    $message = "💾 <b>Backup & Restore</b>\n\n";
    $message .= "🛡️ <b>Backup Options:</b>\n\n";
    $message .= "• <b>Full Backup</b> - Complete system backup\n";
    $message .= "• <b>Quick Backup</b> - Essential files only\n";
    $message .= "• <b>Auto-Backup</b> - Runs daily at " . AUTO_BACKUP_HOUR . ":00\n\n";
    $message .= "📡 <b>Backup Channel:</b> " . BACKUP_CHANNEL_USERNAME . "\n\n";
    $message .= "💾 Backups include:\n";
    $message .= "• Movies database (CSV)\n";
    $message .= "• User data (JSON)\n";
    $message .= "• Statistics (JSON)\n";
    $message .= "• Requests (JSON)\n";
    $message .= "• Activity logs";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '💾 Full Backup', 'callback_data' => 'manual_backup'],
                ['text' => '⚡ Quick Backup', 'callback_data' => 'quick_backup']
            ],
            [
                ['text' => '📊 Backup Status', 'callback_data' => 'backup_status'],
                ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Backup menu");
}

function show_export_menu($chat_id, $callback_query_id) {
    $message = "📤 <b>Export Data</b>\n\n";
    $message .= "📁 <b>Available Exports:</b>\n\n";
    $message .= "• <b>Export CSV</b> - Movies database\n";
    $message .= "• <b>Export Users</b> - User information\n";
    $message .= "• <b>Export Requests</b> - Movie requests\n\n";
    $message .= "💡 Files will be sent as downloadable documents.";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📊 Export CSV', 'callback_data' => 'export_csv'],
                ['text' => '👥 Export Users', 'callback_data' => 'export_users']
            ],
            [
                ['text' => '📝 Export Requests', 'callback_data' => 'export_requests'],
                ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Export menu");
}

function show_settings_menu($chat_id, $callback_query_id) {
    global $MAINTENANCE_MODE;
    
    $message = "⚙️ <b>Bot Settings</b>\n\n";
    $message .= "🔧 <b>Current Settings:</b>\n";
    $message .= "• Auto-Indexing: " . (AUTO_INDEX_ENABLED ? "✅ ON" : "❌ OFF") . "\n";
    $message .= "• Maintenance Mode: " . ($MAINTENANCE_MODE ? "🔧 ON" : "✅ OFF") . "\n";
    $message .= "• Daily Request Limit: " . DAILY_REQUEST_LIMIT . "\n";
    $message .= "• Items Per Page: " . ITEMS_PER_PAGE . "\n";
    $message .= "• Cache Expiry: " . CACHE_EXPIRY . " seconds\n\n";
    
    $message .= "🛠️ <b>Quick Settings:</b>\n";
    $message .= "• Toggle maintenance mode\n";
    $message .= "• Clear system cache\n";
    $message .= "• Refresh configuration";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔧 Toggle Maintenance', 'callback_data' => 'toggle_maintenance'],
                ['text' => '🧹 Clear Cache', 'callback_data' => 'clear_cache']
            ],
            [
                ['text' => '🔄 Refresh Config', 'callback_data' => 'refresh_panel'],
                ['text' => '🔙 Back', 'callback_data' => 'refresh_panel']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
    answerCallbackQuery($callback_query_id, "Settings menu");
}

function toggle_maintenance_mode_panel($chat_id, $callback_query_id) {
    global $MAINTENANCE_MODE;
    $MAINTENANCE_MODE = !$MAINTENANCE_MODE;
    
    $status = $MAINTENANCE_MODE ? "ENABLED" : "DISABLED";
    $message = "🔧 Maintenance mode $status!\n\n";
    $message .= $MAINTENANCE_MODE ? "Bot is now in maintenance mode." : "Bot is now operational.";
    
    sendMessage($chat_id, $message);
    answerCallbackQuery($callback_query_id, "Maintenance mode " . ($MAINTENANCE_MODE ? "ON" : "OFF"));
    
    show_settings_menu($chat_id, $callback_query_id);
}

function clear_system_cache($chat_id, $callback_query_id) {
    global $movie_cache;
    $movie_cache = [];
    
    if (file_exists(CACHE_FILE)) {
        @unlink(CACHE_FILE);
    }
    
    sendMessage($chat_id, "✅ System cache cleared successfully!\n\nMovie database refreshed.");
    answerCallbackQuery($callback_query_id, "Cache cleared");
}

function export_csv_file($chat_id, $callback_query_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found!");
        answerCallbackQuery($callback_query_id, "CSV file not found", true);
        return;
    }
    
    $post_fields = [
        'chat_id' => $chat_id,
        'document' => new CURLFile(CSV_FILE),
        'caption' => "📊 Movies Database Export\n📅 " . date('Y-m-d H:i:s') . "\n📁 File: " . CSV_FILE
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    answerCallbackQuery($callback_query_id, "CSV file exported!");
}

function export_users_file($chat_id, $callback_query_id) {
    if (!file_exists(USERS_FILE)) {
        sendMessage($chat_id, "❌ Users file not found!");
        answerCallbackQuery($callback_query_id, "Users file not found", true);
        return;
    }
    
    $post_fields = [
        'chat_id' => $chat_id,
        'document' => new CURLFile(USERS_FILE),
        'caption' => "👥 Users Database Export\n📅 " . date('Y-m-d H:i:s') . "\n📁 File: " . USERS_FILE
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    answerCallbackQuery($callback_query_id, "Users file exported!");
}

function export_requests_file($chat_id, $callback_query_id) {
    if (!file_exists(REQUEST_FILE)) {
        sendMessage($chat_id, "❌ Requests file not found!");
        answerCallbackQuery($callback_query_id, "Requests file not found", true);
        return;
    }
    
    $post_fields = [
        'chat_id' => $chat_id,
        'document' => new CURLFile(REQUEST_FILE),
        'caption' => "📝 Movie Requests Export\n📅 " . date('Y-m-d H:i:s') . "\n📁 File: " . REQUEST_FILE
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    answerCallbackQuery($callback_query_id, "Requests file exported!");
}

// ==============================
// STATISTICS SYSTEM
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    
    $today = date('Y-m-d');
    if (!isset($stats['daily_activity'][$today])) {
        $stats['daily_activity'][$today] = [
            'searches' => 0,
            'downloads' => 0,
            'users' => 0
        ];
    }
    
    if ($field == 'total_searches') $stats['daily_activity'][$today]['searches'] += $increment;
    if ($field == 'total_downloads') $stats['daily_activity'][$today]['downloads'] += $increment;
    
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// USER MANAGEMENT
// ==============================
function update_user_data($user_id, $user_info = []) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    if (!isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id] = [
            'first_name' => $user_info['first_name'] ?? '',
            'last_name' => $user_info['last_name'] ?? '',
            'username' => $user_info['username'] ?? '',
            'joined' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s')
        ];
        $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
        update_stats('total_users', 1);
        bot_log("New user registered: $user_id");
    }
    
    $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
    
    return $users_data['users'][$user_id];
}

// ==============================
// SEARCH SYSTEM
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    
    $is_theater_search = false;
    $theater_keywords = ['theater', 'theatre', 'print', 'hdcam', 'camrip', 'hq', 'hdrip'];
    foreach ($theater_keywords as $keyword) {
        if (strpos($query_lower, $keyword) !== false) {
            $is_theater_search = true;
            $query_lower = str_replace($keyword, '', $query_lower);
            break;
        }
    }
    $query_lower = trim($query_lower);
    
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        
        foreach ($entries as $entry) {
            $entry_channel_type = $entry['channel_type'] ?? 'main';
            
            if ($is_theater_search && $entry_channel_type == 'theater') {
                $score += 20;
            } elseif (!$is_theater_search && $entry_channel_type == 'main') {
                $score += 10;
            }
            
            if (in_array($entry_channel_type, ['backup', 'private', 'private2', 'serial'])) {
                $score += 5;
            }
        }
        
        if ($movie == $query_lower) {
            $score = 100;
        }
        elseif (strpos($movie, $query_lower) !== false) {
            $score = 80 - (strlen($movie) - strlen($query_lower));
        }
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        
        foreach ($entries as $entry) {
            if (stripos($entry['quality'] ?? '', '1080') !== false) $score += 5;
            if (stripos($entry['quality'] ?? '', '720') !== false) $score += 3;
            if (stripos($entry['language'] ?? '', 'hindi') !== false) $score += 2;
        }
        
        if ($score > 0) {
            $channel_types = array_column($entries, 'channel_type');
            $results[$movie] = [
                'score' => $score,
                'count' => count($entries),
                'latest_entry' => end($entries),
                'qualities' => array_unique(array_column($entries, 'quality')),
                'has_theater' => in_array('theater', $channel_types),
                'has_main' => in_array('main', $channel_types),
                'has_serial' => in_array('serial', $channel_types),
                'has_backup' => in_array('backup', $channel_types),
                'has_private' => in_array('private', $channel_types) || in_array('private2', $channel_types),
                'all_channels' => array_unique($channel_types)
            ];
        }
    }
    
    uasort($results, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    return array_slice($results, 0, MAX_SEARCH_RESULTS);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म', 'मूवी', 'डाउनलोड', 'हिंदी', 'चाहिए', 'कहाँ', 'कैसे', 'खोज', 'तलाश'];
    $english_keywords = ['movie', 'download', 'watch', 'print', 'search', 'find', 'looking', 'want', 'need'];
    
    $hindi_score = 0;
    $english_score = 0;
    
    foreach ($hindi_keywords as $k) {
        if (strpos($text, $k) !== false) $hindi_score++;
    }
    
    foreach ($english_keywords as $k) {
        if (stripos($text, $k) !== false) $english_score++;
    }
    
    $hindi_chars = preg_match('/[\x{0900}-\x{097F}]/u', $text);
    if ($hindi_chars) $hindi_score += 3;
    
    return $hindi_score > $english_score ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi' => [
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie info bhej raha hoon...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: " . REQUEST_GROUP,
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo",
            'multiple_found' => "🎯 Kai versions mili hain! Aap konsi chahte hain?",
            'request_success' => "✅ Request receive ho gayi! Hum jald hi add karenge.",
            'request_limit' => "❌ Aaj ke liye aap maximum " . DAILY_REQUEST_LIMIT . " requests hi kar sakte hain."
        ],
        'english' => [
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Sending movie info...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: " . REQUEST_GROUP,
            'searching' => "🔍 Searching... Please wait",
            'multiple_found' => "🎯 Multiple versions found! Which one do you want?",
            'request_success' => "✅ Request received! We'll add it soon.",
            'request_limit' => "❌ You've reached the daily limit of " . DAILY_REQUEST_LIMIT . " requests."
        ]
    ];
    
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function advanced_search($chat_id, $query, $user_id = null) {
    global $movie_messages;
    $q = strtolower(trim($query));
    
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    $invalid_keywords = [
        'vlc', 'audio', 'track', 'change', 'open', 'kar', 'me', 'hai',
        'how', 'what', 'problem', 'issue', 'help', 'solution', 'fix',
        'error', 'not working', 'download', 'play', 'video', 'sound',
        'subtitle', 'quality', 'hd', 'full', 'part', 'scene',
        'hi', 'hello', 'hey', 'good', 'morning', 'night', 'bye',
        'thanks', 'thank', 'ok', 'okay', 'yes', 'no', 'maybe',
        'who', 'when', 'where', 'why', 'how', 'can', 'should',
        'kaise', 'kya', 'kahan', 'kab', 'kyun', 'kon', 'kisne',
        'hai', 'hain', 'ho', 'raha', 'raha', 'rah', 'tha', 'thi',
        'mere', 'apne', 'tumhare', 'hamare', 'sab', 'log', 'group'
    ];
    
    $query_words = explode(' ', $q);
    $total_words = count($query_words);
    
    $invalid_count = 0;
    foreach ($query_words as $word) {
        if (in_array($word, $invalid_keywords)) {
            $invalid_count++;
        }
    }
    
    if ($invalid_count > 0 && ($invalid_count / $total_words) > 0.5) {
        $help_msg = "🎬 Please enter a movie name!\n\n";
        $help_msg .= "🔍 Examples of valid movie names:\n";
        $help_msg .= "• Mandala Murders 2025\n• Zebra 2024\n• Now You See Me All Parts\n";
        $help_msg .= "• Squid Game All Seasons\n• Show Time (2024)\n• Taskaree S01 (2025)\n\n";
        $help_msg .= "❌ Technical queries like 'vlc', 'audio track', etc. are not movie names.\n\n";
        $help_msg .= "📢 Join Our Channels:\n";
        $help_msg .= "🍿 Main: @EntertainmentTadka786\n";
        $help_msg .= "📥 Request: @EntertainmentTadka7860\n";
        $help_msg .= "🎭 Theater: @threater_print_movies\n";
        $help_msg .= "📂 Backup: @ETBackup\n";
        $help_msg .= "📺 Serial: @Entertainment_Tadka_Serial_786";
        sendMessage($chat_id, $help_msg, null, 'HTML');
        return;
    }
    
    $movie_pattern = '/^[a-zA-Z0-9\s\-\.\,\&\+\(\)\:\'\"]+$/';
    if (!preg_match($movie_pattern, $query)) {
        sendMessage($chat_id, "❌ Invalid movie name format. Only letters, numbers, and basic punctuation allowed.");
        return;
    }
    
    $found = smart_search($q);
    
    if (!empty($found)) {
        update_stats('successful_searches', 1);
        
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i = 1;
        foreach ($found as $movie => $data) {
            $quality_info = !empty($data['qualities']) ? implode('/', $data['qualities']) : 'Unknown';
            $channel_info = "";
            if ($data['has_theater']) $channel_info .= "🎭 ";
            if ($data['has_main']) $channel_info .= "🍿 ";
            if ($data['has_serial']) $channel_info .= "📺 ";
            if ($data['has_backup']) $channel_info .= "🔒 ";
            if ($data['has_private']) $channel_info .= "🔐 ";
            $msg .= "$i. $movie ($channel_info" . $data['count'] . " versions, $quality_info)\n";
            $i++;
            if ($i > 10) break;
        }
        
        sendMessage($chat_id, $msg);
        
        $keyboard = ['inline_keyboard' => []];
        $top_movies = array_slice(array_keys($found), 0, 5);
        
        foreach ($top_movies as $movie) {
            $movie_data = $found[$movie];
            $channel_icon = '🍿';
            if ($movie_data['has_theater']) $channel_icon = '🎭';
            elseif ($movie_data['has_serial']) $channel_icon = '📺';
            elseif ($movie_data['has_backup']) $channel_icon = '🔒';
            elseif ($movie_data['has_private']) $channel_icon = '🔐';
            
            $keyboard['inline_keyboard'][] = [[ 
                'text' => $channel_icon . ucwords($movie), 
                'callback_data' => $movie 
            ]];
        }
        
        $keyboard['inline_keyboard'][] = [[
            'text' => "📝 Request Different Movie", 
            'callback_data' => 'request_movie'
        ]];
        
        sendMessage($chat_id, "🚀 Top matches (click for info):", $keyboard);
        
    } else {
        update_stats('failed_searches', 1);
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        
        $request_keyboard = [
            'inline_keyboard' => [[
                ['text' => '📝 Request This Movie', 'callback_data' => 'auto_request_' . base64_encode($query)]
            ]]
        ];
        
        sendMessage($chat_id, "💡 Click below to automatically request this movie:", $request_keyboard);
    }
    
    update_stats('total_searches', 1);
}

// ==============================
// ENHANCED PAGINATION SYSTEM
// ==============================
function paginate_movies(array $all, int $page, array $filters = []): array {
    if (!empty($filters)) {
        $all = apply_movie_filters($all, $filters);
    }
    
    $total = count($all);
    if ($total === 0) {
        return [
            'total' => 0,
            'total_pages' => 1, 
            'page' => 1,
            'slice' => [],
            'filters' => $filters,
            'has_next' => false,
            'has_prev' => false,
            'start_item' => 0,
            'end_item' => 0
        ];
    }
    
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * ITEMS_PER_PAGE;
    
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE),
        'filters' => $filters,
        'has_next' => $page < $total_pages,
        'has_prev' => $page > 1,
        'start_item' => $start + 1,
        'end_item' => min($start + ITEMS_PER_PAGE, $total)
    ];
}

function build_totalupload_keyboard(int $page, int $total_pages, string $session_id = '', array $filters = []): array {
    $kb = ['inline_keyboard' => []];
    
    $nav_row = [];
    
    if ($page > 1) {
        $nav_row[] = ['text' => '⏪', 'callback_data' => 'pag_first_' . $session_id];
        $nav_row[] = ['text' => '◀️', 'callback_data' => 'pag_prev_' . $page . '_' . $session_id];
    }
    
    $start_page = max(1, $page - 3);
    $end_page = min($total_pages, $start_page + 6);
    
    if ($end_page - $start_page < 6) {
        $start_page = max(1, $end_page - 6);
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $page) {
            $nav_row[] = ['text' => "【{$i}】", 'callback_data' => 'current'];
        } else {
            $nav_row[] = ['text' => "{$i}", 'callback_data' => 'pag_' . $i . '_' . $session_id];
        }
    }
    
    if ($page < $total_pages) {
        $nav_row[] = ['text' => '▶️', 'callback_data' => 'pag_next_' . $page . '_' . $session_id];
        $nav_row[] = ['text' => '⏩', 'callback_data' => 'pag_last_' . $session_id];
    }
    
    if (!empty($nav_row)) {
        $kb['inline_keyboard'][] = $nav_row;
    }
    
    $action_row = [];
    $action_row[] = ['text' => '📥 Send Page', 'callback_data' => 'send_' . $page . '_' . $session_id];
    $action_row[] = ['text' => '👁️ Preview', 'callback_data' => 'prev_' . $page . '_' . $session_id];
    $action_row[] = ['text' => '📊 Stats', 'callback_data' => 'stats_' . $session_id];
    
    $kb['inline_keyboard'][] = $action_row;
    
    if (empty($filters)) {
        $filter_row = [];
        $filter_row[] = ['text' => '🎬 HD Only', 'callback_data' => 'flt_hd_' . $session_id];
        $filter_row[] = ['text' => '🎭 Theater Only', 'callback_data' => 'flt_theater_' . $session_id];
        $filter_row[] = ['text' => '📺 Serial Only', 'callback_data' => 'flt_serial_' . $session_id];
        $filter_row[] = ['text' => '🔒 Backup Only', 'callback_data' => 'flt_backup_' . $session_id];
        $kb['inline_keyboard'][] = $filter_row;
    } else {
        $filter_row = [];
        $filter_row[] = ['text' => '🧹 Clear Filter', 'callback_data' => 'flt_clr_' . $session_id];
        $kb['inline_keyboard'][] = $filter_row;
    }
    
    $ctrl_row = [];
    $ctrl_row[] = ['text' => '💾 Save', 'callback_data' => 'save_' . $session_id];
    $ctrl_row[] = ['text' => '🔍 Search', 'switch_inline_query_current_chat' => ''];
    $ctrl_row[] = ['text' => '❌ Close', 'callback_data' => 'close_' . $session_id];
    
    $kb['inline_keyboard'][] = $ctrl_row;
    
    return $kb;
}

function totalupload_controller($chat_id, $page = 1, $filters = [], $session_id = null) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili! Pehle kuch movies add karo.");
        return;
    }
    
    if (!$session_id) {
        $session_id = uniqid('sess_', true);
    }
    
    $pg = paginate_movies($all, (int)$page, $filters);
    
    if ($page == 1 && PREVIEW_ITEMS > 0 && count($pg['slice']) > 0) {
        $preview_msg = "👁️ <b>Quick Preview (First " . PREVIEW_ITEMS . "):</b>\n\n";
        $preview_count = min(PREVIEW_ITEMS, count($pg['slice']));
        
        for ($i = 0; $i < $preview_count; $i++) {
            $movie = $pg['slice'][$i];
            $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
            $preview_msg .= ($i + 1) . ". $channel_icon <b>" . htmlspecialchars($movie['movie_name']) . "</b>\n";
            $preview_msg .= "   ⭐ " . ($movie['quality'] ?? 'Unknown') . " | ";
            $preview_msg .= "🗣️ " . ($movie['language'] ?? 'Hindi') . "\n\n";
        }
        
        sendMessage($chat_id, $preview_msg, null, 'HTML');
    }
    
    $title = "🎬 <b>Enhanced Movie Browser</b>\n\n";
    
    $title .= "🆔 <b>Session:</b> <code>" . substr($session_id, 0, 8) . "</code>\n";
    
    $title .= "📊 <b>Statistics:</b>\n";
    $title .= "• Total Movies: <b>{$pg['total']}</b>\n";
    $title .= "• Page: <b>{$pg['page']}/{$pg['total_pages']}</b>\n";
    $title .= "• Items: <b>{$pg['start_item']}-{$pg['end_item']}</b>\n";
    
    if (!empty($filters)) {
        $title .= "• Filters: <b>" . count($filters) . " active</b>\n";
    }
    
    $title .= "\n";
    
    $title .= "📋 <b>Page {$page} Movies:</b>\n\n";
    $i = $pg['start_item'];
    foreach ($pg['slice'] as $movie) {
        $movie_name = htmlspecialchars($movie['movie_name'] ?? 'Unknown');
        $quality = $movie['quality'] ?? 'Unknown';
        $language = $movie['language'] ?? 'Hindi';
        $date = $movie['date'] ?? 'N/A';
        $size = $movie['size'] ?? 'Unknown';
        $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
        
        $title .= "<b>{$i}.</b> $channel_icon {$movie_name}\n";
        $title .= "   🏷️ {$quality} | 🗣️ {$language}\n";
        $title .= "   💾 {$size} | 📅 {$date}\n\n";
        $i++;
    }
    
    $title .= "📍 <i>Use number buttons for direct page access</i>\n";
    $title .= "🔧 <i>Apply filters using buttons below</i>";
    
    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages'], $session_id, $filters);
    
    delete_pagination_message($chat_id, $session_id);
    
    $result = sendMessage($chat_id, $title, $kb, 'HTML');
    save_pagination_message($chat_id, $session_id, $result['result']['message_id']);
    
    bot_log("Enhanced pagination - Chat: $chat_id, Page: $page, Session: " . substr($session_id, 0, 8));
}

function apply_movie_filters($movies, $filters) {
    if (empty($filters)) return $movies;
    
    $filtered = [];
    foreach ($movies as $movie) {
        $pass = true;
        
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'quality':
                    if (stripos($movie['quality'] ?? '', $value) === false) {
                        $pass = false;
                    }
                    break;
                    
                case 'language':
                    if (stripos($movie['language'] ?? '', $value) === false) {
                        $pass = false;
                    }
                    break;
                    
                case 'channel_type':
                    if (($movie['channel_type'] ?? 'main') != $value) {
                        $pass = false;
                    }
                    break;
            }
            
            if (!$pass) break;
        }
        
        if ($pass) {
            $filtered[] = $movie;
        }
    }
    
    return $filtered;
}

function save_pagination_message($chat_id, $session_id, $message_id) {
    global $user_pagination_sessions;
    
    if (!isset($user_pagination_sessions[$session_id])) {
        $user_pagination_sessions[$session_id] = [];
    }
    
    $user_pagination_sessions[$session_id]['last_message_id'] = $message_id;
    $user_pagination_sessions[$session_id]['chat_id'] = $chat_id;
    $user_pagination_sessions[$session_id]['last_updated'] = time();
}

function delete_pagination_message($chat_id, $session_id) {
    global $user_pagination_sessions;
    
    if (isset($user_pagination_sessions[$session_id]) && 
        isset($user_pagination_sessions[$session_id]['last_message_id'])) {
        
        $message_id = $user_pagination_sessions[$session_id]['last_message_id'];
        deleteMessage($chat_id, $message_id);
    }
}

function batch_download_with_progress($chat_id, $movies, $page_num) {
    $total = count($movies);
    if ($total === 0) return;
    
    $progress_msg = sendMessage($chat_id, "📦 <b>Batch Info Started</b>\n\nPage: {$page_num}\nTotal: {$total} movies\n\n⏳ Initializing...");
    $progress_id = $progress_msg['result']['message_id'];
    
    $success = 0;
    $failed = 0;
    
    for ($i = 0; $i < $total; $i++) {
        $movie = $movies[$i];
        
        if ($i % 2 == 0) {
            $progress = round(($i / $total) * 100);
            editMessage($chat_id, $progress_id, 
                "📦 <b>Sending Page {$page_num} Info</b>\n\n" .
                "Progress: {$progress}%\n" .
                "Processed: {$i}/{$total}\n" .
                "✅ Success: {$success}\n" .
                "❌ Failed: {$failed}\n\n" .
                "⏳ Please wait..."
            );
        }
        
        try {
            $result = deliver_item_to_chat($chat_id, $movie);
            if ($result) {
                $success++;
            } else {
                $failed++;
            }
        } catch (Exception $e) {
            $failed++;
        }
        
        usleep(500000);
    }
    
    editMessage($chat_id, $progress_id,
        "✅ <b>Batch Info Complete</b>\n\n" .
        "📄 Page: {$page_num}\n" .
        "🎬 Total: {$total} movies\n" .
        "✅ Successfully sent: {$success}\n" .
        "❌ Failed: {$failed}\n\n" .
        "📊 Success rate: " . round(($success / $total) * 100, 2) . "%\n" .
        "⏱️ Time: " . date('H:i:s') . "\n\n" .
        "🔗 Join channels to download:\n" .
        "🍿 Main: @EntertainmentTadka786\n" .
        "🎭 Theater: @threater_print_movies\n" .
        "📺 Serial: @Entertainment_Tadka_Serial_786"
    );
}

function get_all_movies_list() {
    return get_cached_movies();
}

// ==============================
// BACKUP SYSTEM
// ==============================
function auto_backup() {
    bot_log("Starting auto-backup process...");
    
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE, REQUEST_FILE, LOG_FILE, INDEXING_LOG, FORWARD_SETTINGS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d_H-i-s');
    $backup_success = true;
    
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    foreach ($backup_files as $file) {
        if (file_exists($file)) {
            $backup_path = $backup_dir . '/' . basename($file) . '.bak';
            if (!copy($file, $backup_path)) {
                bot_log("Failed to backup: $file", 'ERROR');
                $backup_success = false;
            } else {
                bot_log("Backed up: $file");
            }
        }
    }
    
    $summary = create_backup_summary();
    file_put_contents($backup_dir . '/backup_summary.txt', $summary);
    
    if ($backup_success) {
        $channel_backup_success = upload_backup_to_channel($backup_dir, $summary);
        
        if ($channel_backup_success) {
            bot_log("Backup successfully uploaded to channel");
        } else {
            bot_log("Failed to upload backup to channel", 'WARNING');
        }
    }
    
    clean_old_backups();
    send_backup_report($backup_success, $summary);
    
    bot_log("Auto-backup process completed");
    return $backup_success;
}

function create_backup_summary() {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    $forward_settings = json_decode(file_get_contents(FORWARD_SETTINGS_FILE), true);
    
    $summary = "📊 BACKUP SUMMARY\n";
    $summary .= "================\n\n";
    
    $summary .= "📅 Backup Date: " . date('Y-m-d H:i:s') . "\n";
    $summary .= "🤖 Bot: Entertainment Tadka\n\n";
    
    $summary .= "📈 STATISTICS:\n";
    $summary .= "• Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $summary .= "• Auto-Indexed: " . ($indexing_log['total_indexed'] ?? 0) . "\n";
    $summary .= "• Total Users: " . count($users_data['users'] ?? []) . "\n";
    $summary .= "• Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $summary .= "• Total Downloads: " . ($stats['total_downloads'] ?? 0) . "\n";
    $summary .= "• Pending Requests: " . count($requests_data['requests'] ?? []) . "\n\n";
    
    $summary .= "🔐 FORWARD HEADER SETTINGS:\n";
    $summary .= "• Public Channels: " . count($forward_settings['public_channels'] ?? []) . "\n";
    $summary .= "• Private Channels: " . count($forward_settings['private_channels'] ?? []) . "\n\n";
    
    $summary .= "💾 FILES BACKED UP:\n";
    $summary .= "• " . CSV_FILE . " (" . (file_exists(CSV_FILE) ? filesize(CSV_FILE) : 0) . " bytes)\n";
    $summary .= "• " . USERS_FILE . " (" . (file_exists(USERS_FILE) ? filesize(USERS_FILE) : 0) . " bytes)\n";
    $summary .= "• " . STATS_FILE . " (" . (file_exists(STATS_FILE) ? filesize(STATS_FILE) : 0) . " bytes)\n";
    $summary .= "• " . REQUEST_FILE . " (" . (file_exists(REQUEST_FILE) ? filesize(REQUEST_FILE) : 0) . " bytes)\n";
    $summary .= "• " . LOG_FILE . " (" . (file_exists(LOG_FILE) ? filesize(LOG_FILE) : 0) . " bytes)\n";
    $summary .= "• " . INDEXING_LOG . " (" . (file_exists(INDEXING_LOG) ? filesize(INDEXING_LOG) : 0) . " bytes)\n";
    $summary .= "• " . FORWARD_SETTINGS_FILE . " (" . (file_exists(FORWARD_SETTINGS_FILE) ? filesize(FORWARD_SETTINGS_FILE) : 0) . " bytes)\n\n";
    
    $summary .= "🔄 Backup Type: Automated Daily Backup\n";
    $summary .= "📍 Stored In: " . BACKUP_DIR . "\n";
    $summary .= "📡 Channel: " . BACKUP_CHANNEL_USERNAME . "\n";
    
    return $summary;
}

function upload_backup_to_channel($backup_dir, $summary) {
    try {
        $summary_message = "🔄 <b>Daily Auto-Backup Report</b>\n\n";
        $summary_message .= "📅 " . date('Y-m-d H:i:s') . "\n\n";
        
        $stats = get_stats();
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        
        $summary_message .= "📊 <b>Current Stats:</b>\n";
        $summary_message .= "• 🎬 Movies: " . ($stats['total_movies'] ?? 0) . "\n";
        $summary_message .= "• 👥 Users: " . count($users_data['users'] ?? []) . "\n";
        $summary_message .= "• 🔍 Searches: " . ($stats['total_searches'] ?? 0) . "\n";
        $summary_message .= "• 📥 Downloads: " . ($stats['total_downloads'] ?? 0) . "\n\n";
        
        $summary_message .= "✅ <b>Backup Status:</b> Successful\n";
        $summary_message .= "📁 <b>Location:</b> " . $backup_dir . "\n";
        $summary_message .= "💾 <b>Files:</b> 7 data files\n";
        $summary_message .= "📡 <b>Channel:</b> " . BACKUP_CHANNEL_USERNAME . "\n\n";
        
        $summary_message .= "🔗 <a href=\"https://t.me/ETBackup\">Visit Backup Channel</a>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Visit ' . BACKUP_CHANNEL_USERNAME, 'url' => 'https://t.me/ETBackup']
                ]
            ]
        ];
        
        $message_result = sendMessage(BACKUP_CHANNEL_ID, $summary_message, $keyboard, 'HTML');
        
        if (!$message_result || !isset($message_result['ok']) || !$message_result['ok']) {
            bot_log("Failed to send backup summary to channel", 'ERROR');
            return false;
        }
        
        $critical_files = [
            CSV_FILE => "🎬 Movies Database",
            USERS_FILE => "👥 Users Data", 
            STATS_FILE => "📊 Bot Statistics",
            REQUEST_FILE => "📝 Movie Requests",
            INDEXING_LOG => "🤖 Indexing Log",
            FORWARD_SETTINGS_FILE => "🔐 Forward Header Settings"
        ];
        
        foreach ($critical_files as $file => $description) {
            if (file_exists($file)) {
                $upload_success = upload_file_to_channel($file, $backup_dir, $description);
                if (!$upload_success) {
                    bot_log("Failed to upload $file to channel", 'WARNING');
                }
                sleep(2);
            }
        }
        
        $zip_success = create_and_upload_zip($backup_dir);
        
        $completion_message = "✅ <b>Backup Process Completed</b>\n\n";
        $completion_message .= "📅 " . date('Y-m-d H:i:s') . "\n";
        $completion_message .= "💾 All files backed up successfully\n";
        $completion_message .= "📦 Zip archive created\n";
        $completion_message .= "📡 Uploaded to: " . BACKUP_CHANNEL_USERNAME . "\n\n";
        $completion_message .= "🛡️ <i>Your data is now securely backed up!</i>";
        
        sendMessage(BACKUP_CHANNEL_ID, $completion_message, null, 'HTML');
        
        return true;
        
    } catch (Exception $e) {
        bot_log("Channel backup failed: " . $e->getMessage(), 'ERROR');
        
        $error_message = "❌ <b>Backup Process Failed</b>\n\n";
        $error_message .= "📅 " . date('Y-m-d H:i:s') . "\n";
        $error_message .= "🚨 Error: " . $e->getMessage() . "\n\n";
        $error_message .= "⚠️ Please check server logs immediately!";
        
        sendMessage(BACKUP_CHANNEL_ID, $error_message, null, 'HTML');
        
        return false;
    }
}

function upload_file_to_channel($file_path, $backup_dir, $description = "") {
    if (!file_exists($file_path)) {
        return false;
    }
    
    $file_name = basename($file_path);
    $backup_file_path = $backup_dir . '/' . $file_name . '.bak';
    
    if (!file_exists($backup_file_path)) {
        return false;
    }
    
    $file_size = filesize($backup_file_path);
    $file_size_mb = round($file_size / (1024 * 1024), 2);
    $backup_time = date('Y-m-d H:i:s');
    
    $caption = "💾 " . $description . "\n";
    $caption .= "📅 " . $backup_time . "\n";
    $caption .= "📊 Size: " . $file_size_mb . " MB\n";
    $caption .= "🔄 Auto-backup\n";
    $caption .= "📡 " . BACKUP_CHANNEL_USERNAME;
    
    if ($file_size > 45 * 1024 * 1024) {
        bot_log("File too large for Telegram: $file_name ($file_size_mb MB)", 'WARNING');
        
        if ($file_name == 'movies.csv') {
            return split_and_upload_large_csv($backup_file_path, $backup_dir, $description);
        }
        return false;
    }
    
    $post_fields = [
        'chat_id' => BACKUP_CHANNEL_ID,
        'document' => new CURLFile($backup_file_path),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result_data = json_decode($result, true);
    $success = ($http_code == 200 && $result_data && $result_data['ok']);
    
    if ($success) {
        bot_log("Uploaded to channel: $file_name");
        
        if ($file_size > 10 * 1024 * 1024) {
            $confirmation = "✅ <b>Large File Uploaded</b>\n\n";
            $confirmation .= "📁 File: " . $description . "\n";
            $confirmation .= "💾 Size: " . $file_size_mb . " MB\n";
            $confirmation .= "✅ Status: Successfully uploaded to " . BACKUP_CHANNEL_USERNAME;
            sendMessage(BACKUP_CHANNEL_ID, $confirmation, null, 'HTML');
        }
    } else {
        bot_log("Failed to upload to channel: $file_name", 'ERROR');
    }
    
    return $success;
}

function split_and_upload_large_csv($csv_file_path, $backup_dir, $description) {
    if (!file_exists($csv_file_path)) {
        return false;
    }
    
    $file_size = filesize($csv_file_path);
    $file_size_mb = round($file_size / (1024 * 1024), 2);
    
    bot_log("Splitting large CSV file: $file_size_mb MB", 'INFO');
    
    $rows = [];
    $handle = fopen($csv_file_path, 'r');
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            $rows[] = $row;
        }
        fclose($handle);
    }
    
    $total_rows = count($rows);
    $rows_per_file = ceil($total_rows / 3);
    
    $upload_success = true;
    
    for ($i = 0; $i < 3; $i++) {
        $start = $i * $rows_per_file;
        $end = min($start + $rows_per_file, $total_rows);
        $part_rows = array_slice($rows, $start, $end - $start);
        
        $part_file = $backup_dir . '/movies_part_' . ($i + 1) . '.csv';
        $part_handle = fopen($part_file, 'w');
        fputcsv($part_handle, $header);
        foreach ($part_rows as $row) {
            fputcsv($part_handle, $row);
        }
        fclose($part_handle);
        
        $part_caption = "💾 " . $description . " (Part " . ($i + 1) . "/3)\n";
        $part_caption .= "📅 " . date('Y-m-d H:i:s') . "\n";
        $part_caption .= "📊 Rows: " . count($part_rows) . "\n";
        $part_caption .= "🔄 Split backup\n";
        $part_caption .= "📡 " . BACKUP_CHANNEL_USERNAME;
        
        $post_fields = [
            'chat_id' => BACKUP_CHANNEL_ID,
            'document' => new CURLFile($part_file),
            'caption' => $part_caption,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        @unlink($part_file);
        
        if ($http_code != 200) {
            $upload_success = false;
            bot_log("Failed to upload CSV part " . ($i + 1), 'ERROR');
        } else {
            bot_log("Uploaded CSV part " . ($i + 1));
        }
        
        sleep(2);
    }
    
    if ($upload_success) {
        $split_message = "📦 <b>Large CSV Split Successfully</b>\n\n";
        $split_message .= "📁 File: " . $description . "\n";
        $split_message .= "💾 Original Size: " . $file_size_mb . " MB\n";
        $split_message .= "📊 Total Rows: " . $total_rows . "\n";
        $split_message .= "🔀 Split into: 3 parts\n";
        $split_message .= "✅ All parts uploaded to " . BACKUP_CHANNEL_USERNAME;
        
        sendMessage(BACKUP_CHANNEL_ID, $split_message, null, 'HTML');
    }
    
    return $upload_success;
}

function create_and_upload_zip($backup_dir) {
    $zip_file = $backup_dir . '/complete_backup.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zip_file, ZipArchive::CREATE) !== TRUE) {
        bot_log("Cannot open zip file: $zip_file", 'ERROR');
        return false;
    }
    
    $files = glob($backup_dir . '/*.bak');
    foreach ($files as $file) {
        $zip->addFile($file, basename($file));
    }
    
    if (file_exists($backup_dir . '/backup_summary.txt')) {
        $zip->addFile($backup_dir . '/backup_summary.txt', 'backup_summary.txt');
    }
    
    $zip->close();
    
    $zip_size = filesize($zip_file);
    $zip_size_mb = round($zip_size / (1024 * 1024), 2);
    
    $caption = "📦 Complete Backup Archive\n";
    $caption .= "📅 " . date('Y-m-d H:i:s') . "\n";
    $caption .= "💾 Size: " . $zip_size_mb . " MB\n";
    $caption .= "📁 Contains all data files\n";
    $caption .= "🔄 Auto-generated backup\n";
    $caption .= "📡 " . BACKUP_CHANNEL_USERNAME;
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔗 ' . BACKUP_CHANNEL_USERNAME, 'url' => 'https://t.me/ETBackup']
            ]
        ]
    ];
    
    $post_fields = [
        'chat_id' => BACKUP_CHANNEL_ID,
        'document' => new CURLFile($zip_file),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    @unlink($zip_file);
    
    $success = ($http_code == 200);
    
    if ($success) {
        bot_log("Zip backup uploaded to channel successfully");
        
        $zip_confirmation = "✅ <b>Zip Archive Uploaded</b>\n\n";
        $zip_confirmation .= "📦 File: Complete Backup Archive\n";
        $zip_confirmation .= "💾 Size: " . $zip_size_mb . " MB\n";
        $zip_confirmation .= "✅ Status: Successfully uploaded\n";
        $zip_confirmation .= "📡 Channel: " . BACKUP_CHANNEL_USERNAME . "\n\n";
        $zip_confirmation .= "🛡️ <i>All data securely backed up!</i>";
        
        sendMessage(BACKUP_CHANNEL_ID, $zip_confirmation, $keyboard, 'HTML');
    } else {
        bot_log("Failed to upload zip backup to channel", 'WARNING');
    }
    
    return $success;
}

function clean_old_backups() {
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $deleted_count = 0;
        foreach (array_slice($old, 0, count($old) - 7) as $d) {
            $files = glob($d . '/*');
            foreach ($files as $ff) @unlink($ff);
            if (@rmdir($d)) {
                $deleted_count++;
                bot_log("Deleted old backup: $d");
            }
        }
        
        bot_log("Cleaned $deleted_count old backups");
    }
}

function send_backup_report($success, $summary) {
    $report_message = "🔄 <b>Backup Completion Report</b>\n\n";
    
    if ($success) {
        $report_message .= "✅ <b>Status:</b> SUCCESS\n";
        $report_message .= "📅 <b>Time:</b> " . date('Y-m-d H:i:s') . "\n";
        $report_message .= "📡 <b>Channel:</b> " . BACKUP_CHANNEL_USERNAME . "\n\n";
    } else {
        $report_message .= "❌ <b>Status:</b> FAILED\n";
        $report_message .= "📅 <b>Time:</b> " . date('Y-m-d H:i:s') . "\n";
        $report_message .= "📡 <b>Channel:</b> " . BACKUP_CHANNEL_USERNAME . "\n\n";
        $report_message .= "⚠️ Some backup operations may have failed. Check logs for details.\n\n";
    }
    
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    $report_message .= "📊 <b>Current System Status:</b>\n";
    $report_message .= "• 🎬 Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $report_message .= "• 👥 Users: " . count($users_data['users'] ?? []) . "\n";
    $report_message .= "• 🔍 Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $report_message .= "• 📥 Downloads: " . ($stats['total_downloads'] ?? 0) . "\n\n";
    
    $report_message .= "💾 <b>Backup Locations:</b>\n";
    $report_message .= "• Local: " . BACKUP_DIR . "\n";
    $report_message .= "• Channel: " . BACKUP_CHANNEL_USERNAME . "\n\n";
    
    $report_message .= "🕒 <b>Next Backup:</b> " . AUTO_BACKUP_HOUR . ":00 daily";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📡 Visit Backup Channel', 'url' => 'https://t.me/ETBackup'],
                ['text' => '📊 Backup Status', 'callback_data' => 'backup_status']
            ]
        ]
    ];
    
    sendMessage(ADMIN_ID, $report_message, $keyboard, 'HTML');
}

function manual_backup($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $progress_msg = sendMessage($chat_id, "🔄 Starting manual backup...");
    
    try {
        $success = auto_backup();
        
        if ($success) {
            editMessage($chat_id, $progress_msg['result']['message_id'], "✅ Manual backup completed successfully!\n\n📊 Backup has been saved locally and uploaded to backup channel.");
        } else {
            editMessage($chat_id, $progress_msg['result']['message_id'], "⚠️ Backup completed with some warnings.\n\nSome files may not have been backed up properly. Check logs for details.");
        }
        
    } catch (Exception $e) {
        editMessage($chat_id, $progress_msg['result']['message_id'], "❌ Backup failed!\n\nError: " . $e->getMessage());
        bot_log("Manual backup failed: " . $e->getMessage(), 'ERROR');
    }
}

function quick_backup($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $progress_msg = sendMessage($chat_id, "💾 Creating quick backup...");
    
    try {
        $essential_files = [CSV_FILE, USERS_FILE, FORWARD_SETTINGS_FILE];
        $backup_dir = BACKUP_DIR . 'quick_' . date('Y-m-d_H-i-s');
        
        if (!file_exists($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        
        foreach ($essential_files as $file) {
            if (file_exists($file)) {
                copy($file, $backup_dir . '/' . basename($file) . '.bak');
            }
        }
        
        $summary = "🚀 Quick Backup\n" . date('Y-m-d H:i:s') . "\nEssential files only";
        file_put_contents($backup_dir . '/quick_backup_info.txt', $summary);
        
        foreach ($essential_files as $file) {
            $backup_file = $backup_dir . '/' . basename($file) . '.bak';
            if (file_exists($backup_file)) {
                upload_file_to_channel($file, $backup_dir);
                sleep(1);
            }
        }
        
        editMessage($chat_id, $progress_msg['result']['message_id'], "✅ Quick backup completed!\n\nEssential files backed up to channel.");
        
    } catch (Exception $e) {
        editMessage($chat_id, $progress_msg['result']['message_id'], "❌ Quick backup failed!\n\nError: " . $e->getMessage());
    }
}

function backup_status($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $backup_dirs = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    $latest_backup = null;
    $total_size = 0;
    
    if (!empty($backup_dirs)) {
        usort($backup_dirs, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $latest_backup = $backup_dirs[0];
    }
    
    foreach ($backup_dirs as $dir) {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            $total_size += filesize($file);
        }
    }
    
    $total_size_mb = round($total_size / (1024 * 1024), 2);
    
    $status_message = "💾 <b>Backup System Status</b>\n\n";
    
    $status_message .= "📊 <b>Storage Info:</b>\n";
    $status_message .= "• Total Backups: " . count($backup_dirs) . "\n";
    $status_message .= "• Storage Used: " . $total_size_mb . " MB\n";
    $status_message .= "• Backup Channel: " . BACKUP_CHANNEL_USERNAME . "\n";
    $status_message .= "• Channel ID: " . BACKUP_CHANNEL_ID . "\n\n";
    
    if ($latest_backup) {
        $latest_time = date('Y-m-d H:i:s', filemtime($latest_backup));
        $status_message .= "🕒 <b>Latest Backup:</b>\n";
        $status_message .= "• Time: " . $latest_time . "\n";
        $status_message .= "• Folder: " . basename($latest_backup) . "\n\n";
    } else {
        $status_message .= "❌ <b>No backups found!</b>\n\n";
    }
    
    $status_message .= "⏰ <b>Auto-backup Schedule:</b>\n";
    $status_message .= "• Daily at " . AUTO_BACKUP_HOUR . ":00\n";
    $status_message .= "• Keep last 7 backups\n";
    $status_message .= "• Upload to " . BACKUP_CHANNEL_USERNAME . "\n\n";
    
    $status_message .= "🛠️ <b>Manual Commands:</b>\n";
    $status_message .= "• <code>/backup</code> - Full backup\n";
    $status_message .= "• <code>/quickbackup</code> - Quick backup\n";
    $status_message .= "• <code>/backupstatus</code> - This info\n\n";
    
    $status_message .= "🔗 <b>Backup Channel:</b> " . BACKUP_CHANNEL_USERNAME;
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📡 Visit ' . BACKUP_CHANNEL_USERNAME, 'url' => 'https://t.me/ETBackup'],
                ['text' => '🔄 Run Backup', 'callback_data' => 'run_backup']
            ]
        ]
    ];
    
    sendMessage($chat_id, $status_message, $keyboard, 'HTML');
}

// ==============================
// CHANNEL MANAGEMENT FUNCTIONS (UPDATED)
// ==============================
function show_channel_info($chat_id) {
    $message = "📢 <b>Join Our Channels</b>\n\n";
    
    $message .= "🔥 <b>Channels:</b>\n";
    $message .= "🍿 Main: @EntertainmentTadka786\n";
    $message .= "📥 Request: @EntertainmentTadka7860\n";
    $message .= "🎭 Theater: @threater_print_movies\n";
    $message .= "📂 Backup: @ETBackup\n";
    $message .= "📺 Serial: @Entertainment_Tadka_Serial_786\n\n";
    
    $message .= "🎯 <b>How to Use:</b>\n";
    $message .= "• Simply type movie name to search\n";
    $message .= "• Add 'theater' for theater prints\n";
    $message .= "• Use /request for missing movies\n\n";
    
    $message .= "🔔 <b>Don't forget to join all channels!</b>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🍿 Main', 'url' => 'https://t.me/EntertainmentTadka786'],
                ['text' => '📥 Request', 'url' => 'https://t.me/EntertainmentTadka7860']
            ],
            [
                ['text' => '🎭 Theater', 'url' => 'https://t.me/threater_print_movies'],
                ['text' => '📂 Backup', 'url' => 'https://t.me/ETBackup']
            ],
            [
                ['text' => '📺 Serial', 'url' => 'https://t.me/Entertainment_Tadka_Serial_786']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_main_channel_info($chat_id) {
    $message = "🍿 <b>Main Channel - " . MAIN_CHANNEL . "</b>\n\n";
    
    $message .= "🎬 <b>What you get:</b>\n";
    $message .= "• Latest Bollywood & Hollywood movies\n";
    $message .= "• HD/1080p/720p quality prints\n";
    $message .= "• Daily new uploads\n";
    $message .= "• Fast direct downloads\n\n";
    
    $message .= "📊 <b>Current Stats:</b>\n";
    $stats = get_stats();
    $message .= "• Total Movies: " . ($stats['total_movies'] ?? 0) . "\n\n";
    
    $message .= "🔔 <b>Join now for latest movies!</b>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🍿 Join Main Channel', 'url' => 'https://t.me/EntertainmentTadka786']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_request_channel_info($chat_id) {
    $message = "📥 <b>Requests Channel - " . REQUEST_GROUP . "</b>\n\n";
    
    $message .= "🎯 <b>How to request movies:</b>\n";
    $message .= "1. Join this channel first\n";
    $message .= "2. Use <code>/request movie_name</code> in bot\n";
    $message .= "3. We'll add within 24 hours\n\n";
    
    $message .= "📝 <b>Also available:</b>\n";
    $message .= "• Bug reports & issues\n";
    $message .= "• Feature suggestions\n";
    $message .= "• Bot help & guidance\n\n";
    
    $message .= "⚠️ <b>Please check these before requesting:</b>\n";
    $message .= "• Search in bot first\n";
    $message .= "• Check spelling\n";
    $message .= "• Use correct movie name\n\n";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📥 Join Requests Channel', 'url' => 'https://t.me/EntertainmentTadka7860']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_theater_channel_info($chat_id) {
    $message = "🎭 <b>Theater Prints - " . THEATER_CHANNEL . "</b>\n\n";
    
    $message .= "🎥 <b>What you get:</b>\n";
    $message .= "• Latest theater prints\n";
    $message .= "• HD screen recordings\n";
    $message .= "• Fast uploads after release\n\n";
    
    $message .= "📥 <b>How to access:</b>\n";
    $message .= "1. Join " . THEATER_CHANNEL . "\n";
    $message .= "2. Search in bot\n";
    $message .= "3. Get movie info\n\n";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎭 Join Theater Channel', 'url' => 'https://t.me/threater_print_movies']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_serial_channel_info($chat_id) {
    $message = "📺 <b>Serial Channel - " . SERIAL_CHANNEL . "</b>\n\n";
    
    $message .= "📺 <b>What you get:</b>\n";
    $message .= "• Latest web series\n";
    $message .= "• TV serial episodes\n";
    $message .= "• All seasons available\n";
    $message .= "• Regular updates\n\n";
    
    $message .= "🔥 <b>Popular Series:</b>\n";
    $message .= "• Squid Game All Seasons\n";
    $message .= "• Now You See Me All Parts\n";
    $message .= "• Taskaree S01 (2025)\n\n";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📺 Join Serial Channel', 'url' => 'https://t.me/Entertainment_Tadka_Serial_786']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_backup_channel_info($chat_id) {
    $message = "🔒 <b>Backup Channel - " . BACKUP_CHANNEL_USERNAME . "</b>\n\n";
    
    $message .= "🛡️ <b>Purpose:</b>\n";
    $message .= "• Secure data backups\n";
    $message .= "• Database protection\n";
    $message .= "• Disaster prevention\n\n";
    
    $message .= "💾 <b>What's backed up:</b>\n";
    $message .= "• Movies database\n";
    $message .= "• Users data\n";
    $message .= "• Bot statistics\n\n";
    
    $message .= "🔐 <b>Note:</b> This is a private channel for admin use only.";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔒 ' . BACKUP_CHANNEL_USERNAME, 'url' => 'https://t.me/ETBackup']
            ]
        ]
    ];
    
    if ($chat_id == ADMIN_ID) {
        sendMessage($chat_id, $message, $keyboard, 'HTML');
    } else {
        sendMessage($chat_id, "🔒 <b>Backup Channel</b>\n\nThis is a private admin-only channel for data protection.", null, 'HTML');
    }
}

// ==============================
// BROWSE COMMANDS
// ==============================
function show_latest_movies($chat_id, $limit = 10) {
    $all_movies = get_all_movies_list();
    $latest_movies = array_slice($all_movies, -$limit);
    $latest_movies = array_reverse($latest_movies);
    
    if (empty($latest_movies)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili!");
        return;
    }
    
    $message = "🎬 <b>Latest $limit Movies</b>\n\n";
    $i = 1;
    
    foreach ($latest_movies as $movie) {
        $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
        $message .= "$i. $channel_icon <b>" . htmlspecialchars($movie['movie_name']) . "</b>\n";
        $message .= "   📊 " . ($movie['quality'] ?? 'Unknown') . " | 🗣️ " . ($movie['language'] ?? 'Hindi') . "\n";
        $message .= "   📅 " . ($movie['date'] ?? 'N/A') . "\n\n";
        $i++;
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📥 Get All Latest Info', 'callback_data' => 'download_latest'],
                ['text' => '📊 Browse All', 'callback_data' => 'browse_all']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_trending_movies($chat_id) {
    $all_movies = get_all_movies_list();
    $trending_movies = array_slice($all_movies, -15);
    
    if (empty($trending_movies)) {
        sendMessage($chat_id, "📭 Koi trending movies nahi mili!");
        return;
    }
    
    $message = "🔥 <b>Trending Movies</b>\n\n";
    $i = 1;
    
    foreach (array_slice($trending_movies, 0, 10) as $movie) {
        $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
        $message .= "$i. $channel_icon <b>" . htmlspecialchars($movie['movie_name']) . "</b>\n";
        $message .= "   ⭐ " . ($movie['quality'] ?? 'HD') . " | 🗣️ " . ($movie['language'] ?? 'Hindi') . "\n\n";
        $i++;
    }
    
    $message .= "💡 <i>Based on recent popularity</i>";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function show_movies_by_quality($chat_id, $quality) {
    $all_movies = get_all_movies_list();
    $filtered_movies = [];
    
    foreach ($all_movies as $movie) {
        if (stripos($movie['quality'] ?? '', $quality) !== false) {
            $filtered_movies[] = $movie;
        }
    }
    
    if (empty($filtered_movies)) {
        sendMessage($chat_id, "❌ Koi $quality quality movies nahi mili!");
        return;
    }
    
    $message = "🎬 <b>$quality Quality Movies</b>\n\n";
    $message .= "📊 Total Found: " . count($filtered_movies) . "\n\n";
    
    $i = 1;
    foreach (array_slice($filtered_movies, 0, 10) as $movie) {
        $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
        $message .= "$i. $channel_icon " . htmlspecialchars($movie['movie_name']) . "\n";
        $i++;
    }
    
    if (count($filtered_movies) > 10) {
        $message .= "\n... and " . (count($filtered_movies) - 10) . " more";
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📥 Get All Info', 'callback_data' => 'download_quality_' . $quality],
                ['text' => '🔄 Other Qualities', 'callback_data' => 'show_qualities']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_movies_by_language($chat_id, $language) {
    $all_movies = get_all_movies_list();
    $filtered_movies = [];
    
    foreach ($all_movies as $movie) {
        if (stripos($movie['language'] ?? '', $language) !== false) {
            $filtered_movies[] = $movie;
        }
    }
    
    if (empty($filtered_movies)) {
        sendMessage($chat_id, "❌ Koi $language movies nahi mili!");
        return;
    }
    
    $message = "🎬 <b>" . ucfirst($language) . " Movies</b>\n\n";
    $message .= "📊 Total Found: " . count($filtered_movies) . "\n\n";
    
    $i = 1;
    foreach (array_slice($filtered_movies, 0, 10) as $movie) {
        $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
        $message .= "$i. $channel_icon " . htmlspecialchars($movie['movie_name']) . "\n";
        $message .= "   📊 " . ($movie['quality'] ?? 'Unknown') . "\n\n";
        $i++;
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📥 Get All Info', 'callback_data' => 'download_lang_' . $language],
                ['text' => '🔄 Other Languages', 'callback_data' => 'show_languages']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// ==============================
// REQUEST MANAGEMENT
// ==============================
function show_user_requests($chat_id, $user_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $user_requests = [];
    
    foreach ($requests_data['requests'] ?? [] as $request) {
        if ($request['user_id'] == $user_id) {
            $user_requests[] = $request;
        }
    }
    
    if (empty($user_requests)) {
        sendMessage($chat_id, "📭 Aapne abhi tak koi movie request nahi ki hai!");
        return;
    }
    
    $message = "📝 <b>Your Movie Requests</b>\n\n";
    $i = 1;
    
    foreach (array_slice($user_requests, 0, 10) as $request) {
        $status_emoji = $request['status'] == 'completed' ? '✅' : '⏳';
        $message .= "$i. $status_emoji <b>" . htmlspecialchars($request['movie_name']) . "</b>\n";
        $message .= "   📅 " . $request['date'] . " | 🗣️ " . ucfirst($request['language']) . "\n";
        $message .= "   🆔 " . $request['id'] . "\n\n";
        $i++;
    }
    
    $pending_count = count(array_filter($user_requests, function($req) {
        return $req['status'] == 'pending';
    }));
    
    $message .= "📊 <b>Summary:</b>\n";
    $message .= "• Total Requests: " . count($user_requests) . "\n";
    $message .= "• Pending: $pending_count\n";
    $message .= "• Completed: " . (count($user_requests) - $pending_count);
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function show_request_limit($chat_id, $user_id) {
    $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
    $today = date('Y-m-d');
    $today_requests = 0;
    
    foreach ($requests_data['requests'] ?? [] as $request) {
        if ($request['user_id'] == $user_id && $request['date'] == $today) {
            $today_requests++;
        }
    }
    
    $remaining = DAILY_REQUEST_LIMIT - $today_requests;
    
    $message = "📋 <b>Your Request Limit</b>\n\n";
    $message .= "✅ Daily Limit: " . DAILY_REQUEST_LIMIT . " requests\n";
    $message .= "📅 Used Today: $today_requests requests\n";
    $message .= "🎯 Remaining Today: $remaining requests\n\n";
    
    if ($remaining > 0) {
        $message .= "💡 Use <code>/request movie_name</code> to request movies!";
    } else {
        $message .= "⏳ Limit resets at midnight!";
    }
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// ADMIN COMMANDS
// ==============================
function admin_stats($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    
    $msg = "📊 <b>Bot Statistics</b>\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "✅ Successful Searches: " . ($stats['successful_searches'] ?? 0) . "\n";
    $msg .= "❌ Failed Searches: " . ($stats['failed_searches'] ?? 0) . "\n";
    $msg .= "📥 Total Downloads: " . ($stats['total_downloads'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    
    $today = date('Y-m-d');
    if (isset($stats['daily_activity'][$today])) {
        $today_stats = $stats['daily_activity'][$today];
        $msg .= "📈 <b>Today's Activity:</b>\n";
        $msg .= "• Searches: " . ($today_stats['searches'] ?? 0) . "\n";
        $msg .= "• Downloads: " . ($today_stats['downloads'] ?? 0) . "\n";
    }
    
    $csv_data = load_and_clean_csv();
    $recent = array_slice($csv_data, -5);
    $msg .= "\n📦 Recent Uploads:\n";
    foreach ($recent as $r) {
        $channel_icon = get_channel_display_name($r['channel_type'] ?? 'main');
        $msg .= "• $channel_icon " . $r['movie_name'] . " (" . $r['date'] . ")\n";
    }
    
    sendMessage($chat_id, $msg, null, 'HTML');
    bot_log("Admin stats viewed by $chat_id");
}

function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    fgetcsv($handle);
    $movies = [];
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies);
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 <b>CSV Movie Database</b>\n\n";
    $message .= "📁 Total Movies: " . count($movies) . "\n";
    
    if (!$show_all) {
        $message .= "🔍 Showing latest 10 entries\n";
        $message .= "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message .= "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $date = $movie[2] ?? 'N/A';
        $channel_id = $movie[3] ?? '';
        $quality = $movie[4] ?? 'Unknown';
        $language = $movie[6] ?? 'Hindi';
        $channel_type = get_channel_type_by_id($channel_id);
        $channel_icon = get_channel_display_name($channel_type);
        
        $message .= "$i. $channel_icon " . htmlspecialchars($movie_name) . "\n";
        $message .= "   📝 ID: $message_id | 🗣️ $language | 📊 $quality\n";
        $message .= "   📅 Date: $date\n\n";
        
        $i++;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message .= "💾 File: " . CSV_FILE . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
    bot_log("CSV data viewed by $chat_id - Show all: " . ($show_all ? 'Yes' : 'No'));
}

function send_broadcast($chat_id, $message) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $success_count = 0;
    
    $progress_msg = sendMessage($chat_id, "📢 Broadcasting to $total_users users...\n\nProgress: 0%");
    $progress_msg_id = $progress_msg['result']['message_id'];
    
    $i = 0;
    foreach ($users_data['users'] as $user_id => $user) {
        try {
            sendMessage($user_id, "📢 <b>Announcement from Admin:</b>\n\n$message", null, 'HTML');
            $success_count++;
            
            if ($i % 10 === 0) {
                $progress = round(($i / $total_users) * 100);
                editMessage($chat_id, $progress_msg_id, "📢 Broadcasting to $total_users users...\n\nProgress: $progress%");
            }
            
            usleep(100000);
            $i++;
        } catch (Exception $e) {
        }
    }
    
    editMessage($chat_id, $progress_msg_id, "✅ Broadcast completed!\n\n📊 Sent to: $success_count/$total_users users");
    bot_log("Broadcast sent by $chat_id to $success_count users");
}

function toggle_maintenance_mode($chat_id, $mode) {
    global $MAINTENANCE_MODE;
    
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    if ($mode == 'on') {
        $MAINTENANCE_MODE = true;
        sendMessage($chat_id, "🔧 Maintenance mode ENABLED\n\nBot is now in maintenance mode. Users will see maintenance message.");
        bot_log("Maintenance mode enabled by $chat_id");
    } elseif ($mode == 'off') {
        $MAINTENANCE_MODE = false;
        sendMessage($chat_id, "✅ Maintenance mode DISABLED\n\nBot is now operational.");
        bot_log("Maintenance mode disabled by $chat_id");
    } else {
        sendMessage($chat_id, "❌ Usage: <code>/maintenance on</code> or <code>/maintenance off</code>", null, 'HTML');
    }
}

function perform_cleanup($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $deleted_count = 0;
        foreach (array_slice($old, 0, count($old) - 7) as $d) {
            $files = glob($d . '/*');
            foreach ($files as $ff) @unlink($ff);
            if (@rmdir($d)) $deleted_count++;
        }
    }
    
    global $movie_cache;
    $movie_cache = [];
    
    sendMessage($chat_id, "🧹 Cleanup completed!\n\n• Old backups removed\n• Cache cleared\n• System optimized");
    bot_log("Cleanup performed by $chat_id");
}

function send_alert_to_all($chat_id, $alert_message) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Access denied. Admin only command.");
        return;
    }
    
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $success_count = 0;
    
    foreach ($users_data['users'] as $user_id => $user) {
        try {
            sendMessage($user_id, "🚨 <b>Important Alert:</b>\n\n$alert_message", null, 'HTML');
            $success_count++;
            usleep(50000);
        } catch (Exception $e) {
        }
    }
    
    sendMessage($chat_id, "✅ Alert sent to $success_count users!");
    bot_log("Alert sent by $chat_id: " . substr($alert_message, 0, 50));
}

// ==============================
// UTILITY FUNCTIONS
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua.");
        return;
    }
    
    $date_counts = [];
    $h = fopen(CSV_FILE, 'r');
    
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r) >= 3) {
                $d = $r[2];
                if (!isset($date_counts[$d])) $date_counts[$d] = 0;
                $date_counts[$d]++;
            }
        }
        fclose($h);
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

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ CSV file not found.");
        return;
    }
    
    $h = fopen(CSV_FILE, 'r');
    if ($h !== FALSE) {
        fgetcsv($h);
        $i = 1;
        $msg = "";
        
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r) >= 3) {
                $channel_id = $r[3] ?? '';
                $channel_type = get_channel_type_by_id($channel_id);
                $channel_icon = get_channel_display_name($channel_type);
                $line = "$i. $channel_icon {$r[0]} | ID/Ref: {$r[1]} | Date: {$r[2]}";
                if (isset($r[4])) $line .= " | Quality: {$r[4]}";
                if (isset($r[6])) $line .= " | Language: {$r[6]}";
                $line .= "\n";
                
                if (strlen($msg) + strlen($line) > 4000) {
                    sendMessage($chat_id, $msg);
                    $msg = "";
                }
                $msg .= $line;
                $i++;
            }
        }
        fclose($h);
        
        if (!empty($msg)) {
            sendMessage($chat_id, $msg);
        }
    }
}

function show_bot_info($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    $message = "🤖 <b>Entertainment Tadka Bot</b>\n\n";
    $message .= "📱 <b>Version:</b> 2.1.0\n";
    $message .= "🆙 <b>Last Updated:</b> " . date('Y-m-d') . "\n";
    $message .= "👨‍💻 <b>Developer:</b> @EntertainmentTadka0786\n\n";
    
    $message .= "📊 <b>Bot Statistics:</b>\n";
    $message .= "• 🎬 Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $message .= "• 👥 Users: " . count($users_data['users'] ?? []) . "\n";
    $message .= "• 🔍 Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $message .= "• 📥 Downloads: " . ($stats['total_downloads'] ?? 0) . "\n\n";
    
    $message .= "🎯 <b>Features:</b>\n";
    $message .= "• Smart movie search\n";
    $message .= "• Multi-language support\n";
    $message .= "• Quality filtering\n";
    $message .= "• Movie requests\n";
    $message .= "• Enhanced pagination\n";
    $message .= "• Typing indicators\n";
    $message .= "• Auto-indexing for new posts\n";
    $message .= "• Admin panel without commands\n";
    $message .= "• Bulk approve/reject requests\n";
    $message .= "• Forward header toggle for channels\n\n";
    
    $message .= "📢 <b>Channels:</b>\n";
    $message .= "🍿 Main: " . MAIN_CHANNEL . "\n";
    $message .= "📥 Support: " . REQUEST_GROUP . "\n";
    $message .= "🎭 Theater: " . THEATER_CHANNEL . "\n";
    $message .= "📺 Serial: " . SERIAL_CHANNEL;
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function show_support_info($chat_id) {
    $message = "🆘 <b>Support & Contact</b>\n\n";
    
    $message .= "📞 <b>Need Help?</b>\n";
    $message .= "• Movie not found?\n";
    $message .= "• Technical issues?\n";
    $message .= "• Feature requests?\n\n";
    
    $message .= "🎯 <b>Quick Solutions:</b>\n";
    $message .= "1. Use <code>/request movie_name</code> for new movies\n";
    $message .= "2. Check <code>/help</code> for all commands\n";
    $message .= "3. Join support channel below\n\n";
    
    $message .= "📢 <b>Support Channel:</b> " . REQUEST_GROUP . "\n";
    $message .= "👨‍💻 <b>Admin:</b> @EntertainmentTadka0786\n\n";
    
    $message .= "💡 <b>Pro Tip:</b> Always check spelling before reporting!";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📢 Support Channel', 'url' => 'https://t.me/EntertainmentTadka7860']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function show_donate_info($chat_id) {
    $message = "💝 <b>Support Our Work</b>\n\n";
    
    $message .= "🤖 <b>Why Donate?</b>\n";
    $message .= "• Server maintenance costs\n";
    $message .= "• Bot development & updates\n";
    $message .= "• 24/7 service availability\n\n";
    
    $message .= "💰 <b>Donation Methods:</b>\n";
    $message .= "• UPI: entertainmenttadka@upi\n\n";
    
    $message .= "💌 <b>Contact for other methods:</b> " . REQUEST_GROUP;
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function submit_bug_report($chat_id, $user_id, $bug_report) {
    $report_id = uniqid();
    
    $admin_message = "🐛 <b>New Bug Report</b>\n\n";
    $admin_message .= "🆔 Report ID: $report_id\n";
    $admin_message .= "👤 User ID: $user_id\n";
    $admin_message .= "📅 Time: " . date('Y-m-d H:i:s') . "\n\n";
    $admin_message .= "📝 <b>Bug Description:</b>\n$bug_report";
    
    sendMessage(ADMIN_ID, $admin_message, null, 'HTML');
    sendMessage($chat_id, "✅ Bug report submitted!\n\n🆔 Report ID: <code>$report_id</code>\n\nWe'll fix it soon! 🛠️", null, 'HTML');
    
    bot_log("Bug report submitted by $user_id: $report_id");
}

function submit_feedback($chat_id, $user_id, $feedback) {
    $feedback_id = uniqid();
    
    $admin_message = "💡 <b>New User Feedback</b>\n\n";
    $admin_message .= "🆔 Feedback ID: $feedback_id\n";
    $admin_message .= "👤 User ID: $user_id\n";
    $admin_message .= "📅 Time: " . date('Y-m-d H:i:s') . "\n\n";
    $admin_message .= "📝 <b>Feedback:</b>\n$feedback";
    
    sendMessage(ADMIN_ID, $admin_message, null, 'HTML');
    sendMessage($chat_id, "✅ Feedback submitted!\n\n🆔 Feedback ID: <code>$feedback_id</code>\n\nThanks for your input! 🌟", null, 'HTML');
    
    bot_log("Feedback submitted by $user_id: $feedback_id");
}

function show_version_info($chat_id) {
    $message = "🔄 <b>Bot Version Information</b>\n\n";
    
    $message .= "📱 <b>Current Version:</b> v2.1.0\n";
    $message .= "🆙 <b>Release Date:</b> " . date('Y-m-d') . "\n";
    $message .= "🐛 <b>Status:</b> Stable Release\n\n";
    
    $message .= "🎯 <b>What's New in v2.1.0:</b>\n";
    $message .= "• Complete command overhaul\n";
    $message .= "• Enhanced search algorithm\n";
    $message .= "• Movie request system\n";
    $message .= "• Quality filtering\n";
    $message .= "• Advanced statistics\n";
    $message .= "• Typing indicators\n";
    $message .= "• Auto-indexing for new posts\n";
    $message .= "• Admin panel without commands\n";
    $message .= "• Bulk approve/reject requests\n";
    $message .= "• Forward header toggle for channels\n";
    $message .= "• Bug fixes & improvements\n\n";
    
    $message .= "📋 <b>Upcoming Features:</b>\n";
    $message .= "• More coming soon...\n\n";
    
    $message .= "🐛 <b>Found a bug?</b> Use <code>/report</code>\n";
    $message .= "💡 <b>Suggestions?</b> Use <code>/feedback</code>";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// GROUP MESSAGE FILTER
// ==============================
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    if (strpos($text, '/') === 0) {
        return true;
    }
    
    if (strlen($text) < 3) {
        return false;
    }
    
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood',
        'theater', 'theatre', 'print', 'hdcam', 'camrip'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==============================
// MOVIE APPEND FUNCTION (UPDATED FORMAT)
// ==============================
function append_movie($movie_name, $message_id_raw, $date = null, $channel_id = '', $quality = 'Unknown', $size = 'Unknown', $language = 'Hindi') {
    global $movie_messages, $movie_cache;
    
    if (empty(trim($movie_name))) return;
    
    if ($date === null) $date = date('d-m-Y');
    
    if (empty($channel_id)) {
        $channel_id = MAIN_CHANNEL_ID;
    }
    
    $channel_type = get_channel_type_by_id($channel_id);
    
    $channel_username = '';
    switch ($channel_type) {
        case 'main':
            $channel_username = MAIN_CHANNEL;
            break;
        case 'theater':
            $channel_username = THEATER_CHANNEL;
            break;
        case 'serial':
            $channel_username = SERIAL_CHANNEL;
            break;
        case 'backup':
            $channel_username = BACKUP_CHANNEL_USERNAME;
            break;
    }
    
    $entry = [$movie_name, $message_id_raw, $date, $channel_id, $quality, $size, $language];
    
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);

    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'channel_id' => $channel_id,
        'quality' => $quality,
        'size' => $size,
        'language' => $language,
        'channel_type' => $channel_type,
        'channel_username' => $channel_username,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null,
        'source_channel' => $channel_id
    ];
    
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    update_stats('total_movies', 1);
    bot_log("Movie appended: $movie_name with ID $message_id_raw from channel $channel_type ($channel_id)");
}

// ==============================
// COMPLETE COMMAND HANDLER
// ==============================
function handle_command($chat_id, $user_id, $command, $params = []) {
    switch ($command) {
        case '/start':
            sendTypingIndicator($chat_id, 2);
            $welcome = "🎬 Welcome to Entertainment Tadka!\n\n";
            
            $welcome .= "📢 <b>How to use this bot:</b>\n";
            $welcome .= "• Simply type any movie name\n";
            $welcome .= "• Use English or Hindi\n";
            $welcome .= "• Add 'theater' for theater prints\n";
            $welcome .= "• Partial names also work\n\n";
            
            $welcome .= "🔍 <b>Examples:</b>\n";
            $welcome .= "• Mandala Murders 2025\n";
            $welcome .= "• Zebra 2024\n";
            $welcome .= "• Now You See Me All Parts\n";
            $welcome .= "• Squid Game All Seasons\n";
            $welcome .= "• Show Time (2024)\n";
            $welcome .= "• Taskaree S01 (2025)\n\n";
            
            $welcome .= "❌ <b>Don't type:</b>\n";
            $welcome .= "• Technical questions\n";
            $welcome .= "• Player instructions\n";
            $welcome .= "• Non-movie queries\n\n";
            
            $welcome .= "📢 <b>Join Our Channels:</b>\n";
            $welcome .= "🍿 Main: @EntertainmentTadka786\n";
            $welcome .= "📥 Request: @EntertainmentTadka7860\n";
            $welcome .= "🎭 Theater: @threater_print_movies\n";
            $welcome .= "📂 Backup: @ETBackup\n";
            $welcome .= "📺 Serial: @Entertainment_Tadka_Serial_786\n\n";
            
            $welcome .= "👑 <b>Admin:</b> Use /admin to open control panel\n\n";
            
            $welcome .= "💬 <b>Need help?</b> Use /help for all commands";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔍 Search Movies', 'switch_inline_query_current_chat' => ''],
                        ['text' => '🍿 Main', 'url' => 'https://t.me/EntertainmentTadka786']
                    ],
                    [
                        ['text' => '📥 Request', 'url' => 'https://t.me/EntertainmentTadka7860'],
                        ['text' => '🎭 Theater', 'url' => 'https://t.me/threater_print_movies']
                    ],
                    [
                        ['text' => '📂 Backup', 'url' => 'https://t.me/ETBackup'],
                        ['text' => '📺 Serial', 'url' => 'https://t.me/Entertainment_Tadka_Serial_786']
                    ],
                    [
                        ['text' => '❓ Help', 'callback_data' => 'help_command']
                    ]
                ]
            ];
            
            if ($user_id == ADMIN_ID) {
                $keyboard['inline_keyboard'][] = [
                    ['text' => '👑 Admin Panel', 'callback_data' => 'refresh_panel']
                ];
            }
            
            sendMessage($chat_id, $welcome, $keyboard, 'HTML');
            break;

        case '/admin':
        case '/adminpanel':
            if ($user_id == ADMIN_ID) {
                send_admin_panel($chat_id, $user_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/pending_request':
        case '/pending':
        case '/pendingrequests':
            if ($user_id == ADMIN_ID) {
                get_pending_requests($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/bulk_approve':
        case '/bulkapprove':
            if ($user_id == ADMIN_ID) {
                $count = isset($params[0]) ? intval($params[0]) : null;
                bulk_approve_requests($chat_id, $count);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/bulk_reject':
        case '/bulkreject':
            if ($user_id == ADMIN_ID) {
                $count = isset($params[0]) ? intval($params[0]) : null;
                bulk_reject_requests($chat_id, $count);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/forward_settings':
        case '/forwardheader':
            if ($user_id == ADMIN_ID) {
                show_forward_settings_menu($chat_id, null);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/help':
        case '/commands':
            sendTypingIndicator($chat_id, 2);
            $help = "🤖 <b>Entertainment Tadka Bot - Complete Guide</b>\n\n";
            
            $help .= "📢 <b>Our Channels:</b>\n";
            $help .= "🍿 Main: @EntertainmentTadka786 - Latest movies\n";
            $help .= "📥 Request: @EntertainmentTadka7860 - Support & requests\n";
            $help .= "🎭 Theater: @threater_print_movies - HD prints\n";
            $help .= "📂 Backup: @ETBackup - Data protection\n";
            $help .= "📺 Serial: @Entertainment_Tadka_Serial_786 - Web series\n\n";
            
            $help .= "🎯 <b>Search Commands:</b>\n";
            $help .= "• Just type movie name - Smart search\n";
            $help .= "• Add 'theater' for theater prints\n";
            $help .= "• <code>/search movie</code> - Direct search\n";
            $help .= "• <code>/s movie</code> - Quick search\n\n";
            
            $help .= "📁 <b>Browse Commands:</b>\n";
            $help .= "• <code>/totalupload</code> - All movies\n";
            $help .= "• <code>/latest</code> - New additions\n";
            $help .= "• <code>/trending</code> - Popular movies\n";
            $help .= "• <code>/theater</code> - Theater prints only\n\n";
            
            $help .= "📝 <b>Request Commands:</b>\n";
            $help .= "• <code>/request movie</code> - Request movie\n";
            $help .= "• <code>/myrequests</code> - Request status\n";
            $help .= "• Join @EntertainmentTadka7860 for support\n\n";
            
            $help .= "👤 <b>User Commands:</b>\n";
            $help .= "• <code>/mystats</code> - Your statistics\n\n";
            
            $help .= "🔗 <b>Channel Commands:</b>\n";
            $help .= "• <code>/channel</code> - All channels\n";
            $help .= "• <code>/mainchannel</code> - Main channel\n";
            $help .= "• <code>/requestchannel</code> - Requests\n";
            $help .= "• <code>/theaterchannel</code> - Theater prints\n";
            $help .= "• <code>/serialchannel</code> - Serial channel\n";
            $help .= "• <code>/backupchannel</code> - Backup info\n\n";
            
            $help .= "👑 <b>Admin Commands:</b>\n";
            $help .= "• <code>/admin</code> - Open admin panel\n";
            $help .= "• <code>/stats</code> - Bot statistics\n";
            $help .= "• <code>/pending_request</code> - View pending requests\n";
            $help .= "• <code>/bulk_approve [count]</code> - Bulk approve requests\n";
            $help .= "• <code>/bulk_reject [count]</code> - Bulk reject requests\n";
            $help .= "• <code>/forward_settings</code> - Toggle forward headers\n";
            $help .= "• <code>/backup</code> - Full backup\n";
            $help .= "• <code>/quickbackup</code> - Quick backup\n";
            $help .= "• <code>/broadcast</code> - Send message to all\n";
            $help .= "• <code>/fullscan</code> - Full channel scan\n\n";
            
            $help .= "💡 <b>Pro Tips:</b>\n";
            $help .= "• Use partial names (e.g., 'squid')\n";
            $help .= "• Add 'theater' for theater prints\n";
            $help .= "• Join all channels for updates\n";
            $help .= "• Request movies you can't find";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🍿 Main', 'url' => 'https://t.me/EntertainmentTadka786'],
                        ['text' => '📥 Request', 'url' => 'https://t.me/EntertainmentTadka7860']
                    ],
                    [
                        ['text' => '🎭 Theater', 'url' => 'https://t.me/threater_print_movies'],
                        ['text' => '📂 Backup', 'url' => 'https://t.me/ETBackup']
                    ],
                    [
                        ['text' => '📺 Serial', 'url' => 'https://t.me/Entertainment_Tadka_Serial_786']
                    ],
                    [
                        ['text' => '🎬 Search Movies', 'switch_inline_query_current_chat' => '']
                    ]
                ]
            ];
            
            if ($user_id == ADMIN_ID) {
                $keyboard['inline_keyboard'][] = [
                    ['text' => '👑 Open Admin Panel', 'callback_data' => 'refresh_panel']
                ];
            }
            
            sendMessage($chat_id, $help, $keyboard, 'HTML');
            break;

        case '/search':
        case '/s':
        case '/find':
            sendTypingIndicator($chat_id, 3);
            $movie_name = implode(' ', $params);
            if (empty($movie_name)) {
                sendMessage($chat_id, "❌ Usage: <code>/search movie_name</code>\nExample: <code>/search squid game</code>", null, 'HTML');
                return;
            }
            $lang = detect_language($movie_name);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $movie_name, $user_id);
            break;

        case '/totalupload':
        case '/totaluploads':
        case '/allmovies':
        case '/browse':
            sendTypingIndicator($chat_id, 2);
            $page = isset($params[0]) ? intval($params[0]) : 1;
            totalupload_controller($chat_id, $page);
            break;

        case '/latest':
        case '/recent':
        case '/new':
            sendTypingIndicator($chat_id, 1);
            show_latest_movies($chat_id, isset($params[0]) ? intval($params[0]) : 10);
            break;

        case '/trending':
        case '/popular':
            sendTypingIndicator($chat_id, 1);
            show_trending_movies($chat_id);
            break;

        case '/quality':
            sendTypingIndicator($chat_id, 1);
            $quality = isset($params[0]) ? $params[0] : '1080p';
            show_movies_by_quality($chat_id, $quality);
            break;

        case '/language':
            sendTypingIndicator($chat_id, 1);
            $language = isset($params[0]) ? $params[0] : 'hindi';
            show_movies_by_language($chat_id, $language);
            break;

        case '/theater':
        case '/theatermovies':
        case '/theateronly':
            sendTypingIndicator($chat_id, 1);
            show_movies_by_quality($chat_id, 'theater');
            break;

        case '/theaterchannel':
            sendTypingIndicator($chat_id, 1);
            show_theater_channel_info($chat_id);
            break;

        case '/serialchannel':
        case '/serial':
            sendTypingIndicator($chat_id, 1);
            show_serial_channel_info($chat_id);
            break;

        case '/request':
        case '/req':
        case '/requestmovie':
            sendTypingIndicator($chat_id, 1);
            $movie_name = implode(' ', $params);
            if (empty($movie_name)) {
                sendMessage($chat_id, "❌ Usage: <code>/request movie_name</code>\nExample: <code>/request Squid Game</code>", null, 'HTML');
                return;
            }
            $lang = detect_language($movie_name);
            if (add_movie_request($user_id, $movie_name, $lang)) {
                send_multilingual_response($chat_id, 'request_success', $lang);
            } else {
                send_multilingual_response($chat_id, 'request_limit', $lang);
            }
            break;

        case '/myrequests':
        case '/myreqs':
            sendTypingIndicator($chat_id, 1);
            show_user_requests($chat_id, $user_id);
            break;

        case '/requestlimit':
        case '/reqlimit':
            sendTypingIndicator($chat_id, 1);
            show_request_limit($chat_id, $user_id);
            break;

        case '/mystats':
        case '/mystatistics':
            sendTypingIndicator($chat_id, 1);
            show_user_stats($chat_id, $user_id);
            break;

        case '/channel':
        case '/channels':
        case '/join':
            sendTypingIndicator($chat_id, 1);
            show_channel_info($chat_id);
            break;

        case '/mainchannel':
        case '/entertainmenttadka':
            sendTypingIndicator($chat_id, 1);
            show_main_channel_info($chat_id);
            break;

        case '/requestchannel':
        case '/requests':
        case '/support':
            sendTypingIndicator($chat_id, 1);
            show_request_channel_info($chat_id);
            break;

        case '/backupchannel':
        case '/etbackup':
            sendTypingIndicator($chat_id, 1);
            show_backup_channel_info($chat_id);
            break;

        case '/checkdate':
        case '/datestats':
        case '/uploadstats':
            sendTypingIndicator($chat_id, 2);
            check_date($chat_id);
            break;

        case '/stats':
        case '/statistics':
        case '/botstats':
            sendTypingIndicator($chat_id, 2);
            if ($user_id == ADMIN_ID) {
                admin_stats($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/checkcsv':
        case '/csvdata':
        case '/database':
            sendTypingIndicator($chat_id, 2);
            $show_all = (isset($params[0]) && strtolower($params[0]) == 'all');
            show_csv_data($chat_id, $show_all);
            break;

        case '/testcsv':
        case '/rawdata':
        case '/export':
            sendTypingIndicator($chat_id, 2);
            test_csv($chat_id);
            break;

        case '/info':
        case '/about':
        case '/botinfo':
            sendTypingIndicator($chat_id, 1);
            show_bot_info($chat_id);
            break;

        case '/support':
        case '/contact':
            sendTypingIndicator($chat_id, 1);
            show_support_info($chat_id);
            break;

        case '/version':
        case '/changelog':
            sendTypingIndicator($chat_id, 1);
            show_version_info($chat_id);
            break;

        case '/fullscan':
        case '/fullchannelscan':
            if ($user_id == ADMIN_ID) {
                full_channel_scan($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/autoindex':
        case '/runindex':
            if ($user_id == ADMIN_ID) {
                auto_index_new_posts();
                sendMessage($chat_id, "✅ Auto-indexing completed!");
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/broadcast':
            if ($user_id == ADMIN_ID) {
                $message = implode(' ', $params);
                if (empty($message)) {
                    sendMessage($chat_id, "❌ Usage: <code>/broadcast your_message</code>", null, 'HTML');
                    return;
                }
                send_broadcast($chat_id, $message);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/backup':
            if ($user_id == ADMIN_ID) {
                manual_backup($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/quickbackup':
        case '/qbackup':
            if ($user_id == ADMIN_ID) {
                quick_backup($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/backupstatus':
        case '/backupinfo':
            if ($user_id == ADMIN_ID) {
                backup_status($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/maintenance':
            if ($user_id == ADMIN_ID) {
                $mode = isset($params[0]) ? strtolower($params[0]) : '';
                toggle_maintenance_mode($chat_id, $mode);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/cleanup':
            if ($user_id == ADMIN_ID) {
                perform_cleanup($chat_id);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/sendalert':
            if ($user_id == ADMIN_ID) {
                $alert_message = implode(' ', $params);
                if (empty($alert_message)) {
                    sendMessage($chat_id, "❌ Usage: <code>/sendalert your_alert</code>", null, 'HTML');
                    return;
                }
                send_alert_to_all($chat_id, $alert_message);
            } else {
                sendMessage($chat_id, "❌ Access denied. Admin only command.");
            }
            break;

        case '/ping':
        case '/status':
            sendTypingIndicator($chat_id, 1);
            sendMessage($chat_id, "🏓 <b>Bot Status:</b> ✅ Online\n⏰ <b>Server Time:</b> " . date('Y-m-d H:i:s'), null, 'HTML');
            break;

        case '/donate':
        case '/supportus':
            sendTypingIndicator($chat_id, 1);
            show_donate_info($chat_id);
            break;

        case '/report':
        case '/reportbug':
            sendTypingIndicator($chat_id, 1);
            $bug_report = implode(' ', $params);
            if (empty($bug_report)) {
                sendMessage($chat_id, "❌ Usage: <code>/report bug_description</code>", null, 'HTML');
                return;
            }
            submit_bug_report($chat_id, $user_id, $bug_report);
            break;

        case '/feedback':
            sendTypingIndicator($chat_id, 1);
            $feedback = implode(' ', $params);
            if (empty($feedback)) {
                sendMessage($chat_id, "❌ Usage: <code>/feedback your_feedback</code>", null, 'HTML');
                return;
            }
            submit_feedback($chat_id, $user_id, $feedback);
            break;

        default:
            sendMessage($chat_id, "❌ Unknown command. Use <code>/help</code> to see all available commands.", null, 'HTML');
    }
}

// ==============================
// SHOW USER STATS (SIMPLE VERSION - NO POINTS/LEADERBOARD)
// ==============================
function show_user_stats($chat_id, $user_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $user = $users_data['users'][$user_id] ?? null;
    
    if (!$user) {
        sendMessage($chat_id, "❌ User data not found!");
        return;
    }
    
    $message = "👤 <b>Your Statistics</b>\n\n";
    $message .= "🆔 User ID: <code>$user_id</code>\n";
    $message .= "📅 Joined: " . ($user['joined'] ?? 'N/A') . "\n";
    $message .= "🕒 Last Active: " . ($user['last_active'] ?? 'N/A') . "\n";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// MAIN UPDATE PROCESSING
// ==============================
$update = json_decode(file_get_contents('php://input'), true);

if ($update) {
    global $MAINTENANCE_MODE, $MAINTENANCE_MESSAGE;
    if ($MAINTENANCE_MODE && isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        sendMessage($chat_id, $MAINTENANCE_MESSAGE, null, 'HTML');
        bot_log("Maintenance mode active - message blocked from $chat_id");
        exit;
    }

    get_cached_movies();

    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];

        $channel_type = 'other';
        if ($chat_id == MAIN_CHANNEL_ID) {
            $channel_type = 'main';
        } elseif ($chat_id == THEATER_CHANNEL_ID) {
            $channel_type = 'theater';
        } elseif ($chat_id == SERIAL_CHANNEL_ID) {
            $channel_type = 'serial';
        } elseif ($chat_id == BACKUP_CHANNEL_ID) {
            $channel_type = 'backup';
        } elseif ($chat_id == PRIVATE_CHANNEL_1_ID) {
            $channel_type = 'private';
        } elseif ($chat_id == PRIVATE_CHANNEL_2_ID) {
            $channel_type = 'private2';
        } else {
            exit;
        }

        $text = '';
        $quality = 'Unknown';
        $size = 'Unknown';
        $language = 'Hindi';

        if (isset($message['caption'])) {
            $text = $message['caption'];
            if (stripos($text, '1080') !== false) $quality = '1080p';
            elseif (stripos($text, '720') !== false) $quality = '720p';
            elseif (stripos($text, '480') !== false) $quality = '480p';
            
            if (stripos($text, 'english') !== false) $language = 'English';
            if (stripos($text, 'hindi') !== false) $language = 'Hindi';
        }
        elseif (isset($message['text'])) {
            $text = $message['text'];
        }
        elseif (isset($message['document'])) {
            $text = $message['document']['file_name'];
            $size = round($message['document']['file_size'] / (1024 * 1024), 2) . ' MB';
        }
        else {
            $text = 'Uploaded Media - ' . date('d-m-Y H:i');
        }

        if (!empty(trim($text))) {
            append_movie($text, $message_id, date('d-m-Y'), $chat_id, $quality, $size, $language);
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';

        $user_info = [
            'first_name' => $message['from']['first_name'] ?? '',
            'last_name' => $message['from']['last_name'] ?? '',
            'username' => $message['from']['username'] ?? ''
        ];
        update_user_data($user_id, $user_info);

        if ($chat_type !== 'private') {
            if (strpos($text, '/') === 0) {
            } else {
                if (!is_valid_movie_query($text)) {
                    bot_log("Invalid group message blocked from $chat_id: $text");
                    return;
                }
            }
        }

        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = strtolower($parts[0]);
            $params = array_slice($parts, 1);
            
            handle_command($chat_id, $user_id, $command, $params);
        } else if (!empty(trim($text))) {
            sendTypingIndicator($chat_id, 3);
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $text, $user_id);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $query['from']['id'];
        $data = $query['data'];

        // Check if this is an admin panel callback
        if (strpos($data, 'admin_') === 0 || 
            strpos($data, 'approve_req_') === 0 || 
            strpos($data, 'reject_req_') === 0 ||
            strpos($data, 'delete_movie_') === 0 ||
            strpos($data, 'view_user_') === 0 ||
            strpos($data, 'ban_user_') === 0 ||
            strpos($data, 'toggle_forward_') === 0 ||
            $data == 'bulk_approve_all' ||
            $data == 'bulk_reject_all' ||
            $data == 'bulk_approve_10' ||
            $data == 'bulk_approve_25' ||
            $data == 'bulk_approve_50' ||
            $data == 'run_auto_index' ||
            $data == 'full_channel_scan' ||
            $data == 'indexing_status' ||
            $data == 'manual_backup' ||
            $data == 'quick_backup' ||
            $data == 'export_csv' ||
            $data == 'export_users' ||
            $data == 'export_requests' ||
            $data == 'toggle_maintenance' ||
            $data == 'clear_cache' ||
            $data == 'refresh_panel') {
            
            handle_admin_callback($chat_id, $user_id, $data, $query['id']);
        }
        else {
            global $movie_messages;
            
            $movie_lower = strtolower($data);
            if (isset($movie_messages[$movie_lower])) {
                sendTypingIndicator($chat_id, 2);
                $entries = $movie_messages[$movie_lower];
                $cnt = 0;
                
                foreach ($entries as $entry) {
                    deliver_item_to_chat($chat_id, $entry);
                    usleep(200000);
                    $cnt++;
                }
                
                sendMessage($chat_id, "✅ '$data' ke $cnt items ka info mil gaya!\n\n📢 Join our channels:\n🍿 @EntertainmentTadka786\n🎭 @threater_print_movies\n📺 @Entertainment_Tadka_Serial_786");
                answerCallbackQuery($query['id'], "🎬 $cnt items ka info sent!");
            }
            elseif (strpos($data, 'pag_') === 0) {
                $parts = explode('_', $data);
                $action = $parts[1];
                $session_id = isset($parts[2]) ? $parts[2] : '';
                
                if ($action == 'first') {
                    totalupload_controller($chat_id, 1, [], $session_id);
                    answerCallbackQuery($query['id'], "First page");
                } 
                elseif ($action == 'last') {
                    $all = get_all_movies_list();
                    $total_pages = ceil(count($all) / ITEMS_PER_PAGE);
                    totalupload_controller($chat_id, $total_pages, [], $session_id);
                    answerCallbackQuery($query['id'], "Last page");
                }
                elseif ($action == 'prev') {
                    $current_page = isset($parts[2]) ? intval($parts[2]) : 1;
                    $session_id = isset($parts[3]) ? $parts[3] : '';
                    totalupload_controller($chat_id, max(1, $current_page - 1), [], $session_id);
                    answerCallbackQuery($query['id'], "Previous page");
                }
                elseif ($action == 'next') {
                    $current_page = isset($parts[2]) ? intval($parts[2]) : 1;
                    $session_id = isset($parts[3]) ? $parts[3] : '';
                    $all = get_all_movies_list();
                    $total_pages = ceil(count($all) / ITEMS_PER_PAGE);
                    totalupload_controller($chat_id, min($total_pages, $current_page + 1), [], $session_id);
                    answerCallbackQuery($query['id'], "Next page");
                }
                elseif (is_numeric($action)) {
                    $page_num = intval($action);
                    $session_id = isset($parts[2]) ? $parts[2] : '';
                    totalupload_controller($chat_id, $page_num, [], $session_id);
                    answerCallbackQuery($query['id'], "Page $page_num");
                }
            }
            elseif (strpos($data, 'send_') === 0) {
                $parts = explode('_', $data);
                $page_num = isset($parts[1]) ? intval($parts[1]) : 1;
                $session_id = isset($parts[2]) ? $parts[2] : '';
                
                $all = get_all_movies_list();
                $pg = paginate_movies($all, $page_num, []);
                batch_download_with_progress($chat_id, $pg['slice'], $page_num);
                answerCallbackQuery($query['id'], "📦 Batch info started!");
            }
            elseif (strpos($data, 'prev_') === 0) {
                $parts = explode('_', $data);
                $page_num = isset($parts[1]) ? intval($parts[1]) : 1;
                $session_id = isset($parts[2]) ? $parts[2] : '';
                
                $all = get_all_movies_list();
                $pg = paginate_movies($all, $page_num, []);
                
                $preview_msg = "👁️ <b>Page {$page_num} Preview</b>\n\n";
                $limit = min(5, count($pg['slice']));
                
                for ($i = 0; $i < $limit; $i++) {
                    $movie = $pg['slice'][$i];
                    $channel_icon = get_channel_display_name($movie['channel_type'] ?? 'main');
                    $preview_msg .= ($i + 1) . ". $channel_icon <b>" . htmlspecialchars($movie['movie_name']) . "</b>\n";
                    $preview_msg .= "   ⭐ " . ($movie['quality'] ?? 'Unknown') . "\n\n";
                }
                
                sendMessage($chat_id, $preview_msg, null, 'HTML');
                answerCallbackQuery($query['id'], "Preview sent");
            }
            elseif (strpos($data, 'flt_') === 0) {
                $parts = explode('_', $data);
                $filter_type = $parts[1];
                $session_id = isset($parts[2]) ? $parts[2] : '';
                
                $filters = [];
                if ($filter_type == 'hd') {
                    $filters = ['quality' => '1080'];
                    answerCallbackQuery($query['id'], "HD filter applied");
                } elseif ($filter_type == 'theater') {
                    $filters = ['channel_type' => 'theater'];
                    answerCallbackQuery($query['id'], "Theater filter applied");
                } elseif ($filter_type == 'serial') {
                    $filters = ['channel_type' => 'serial'];
                    answerCallbackQuery($query['id'], "Serial filter applied");
                } elseif ($filter_type == 'backup') {
                    $filters = ['channel_type' => 'backup'];
                    answerCallbackQuery($query['id'], "Backup filter applied");
                } elseif ($filter_type == 'clr') {
                    answerCallbackQuery($query['id'], "Filters cleared");
                }
                
                totalupload_controller($chat_id, 1, $filters, $session_id);
            }
            elseif ($data == 'search_theater') {
                sendMessage($chat_id, "🎭 <b>Theater Prints Search</b>\n\nType any movie name to search for theater prints!\n\nExamples:\n<code>pushpa 2 theater</code>\n<code>avengers endgame print</code>\n<code>hindi movie theater</code>", null, 'HTML');
                answerCallbackQuery($query['id'], "Search theater movies");
            }
            elseif ($data == 'close_' || strpos($data, 'close_') === 0) {
                deleteMessage($chat_id, $message['message_id']);
                sendMessage($chat_id, "🗂️ Pagination closed. Use /totalupload to browse again.");
                answerCallbackQuery($query['id'], "Pagination closed");
            }
            elseif (strpos($data, 'auto_request_') === 0) {
                $movie_name = base64_decode(str_replace('auto_request_', '', $data));
                $lang = detect_language($movie_name);
                
                if (add_movie_request($user_id, $movie_name, $lang)) {
                    send_multilingual_response($chat_id, 'request_success', $lang);
                    answerCallbackQuery($query['id'], "Request sent successfully!");
                } else {
                    send_multilingual_response($chat_id, 'request_limit', $lang);
                    answerCallbackQuery($query['id'], "Daily limit reached!", true);
                }
            }
            elseif ($data === 'request_movie') {
                sendMessage($chat_id, "📝 To request a movie, use:\n<code>/request movie_name</code>\n\nExample: <code>/request Squid Game</code>", null, 'HTML');
                answerCallbackQuery($query['id'], "Request instructions sent");
            }
            elseif ($data === 'request_help') {
                show_request_channel_info($chat_id);
                answerCallbackQuery($query['id'], "Request channel info");
            }
            elseif ($data === 'my_stats') {
                show_user_stats($chat_id, $user_id);
                answerCallbackQuery($query['id'], "Your statistics");
            }
            elseif ($data === 'backup_status') {
                if ($chat_id == ADMIN_ID) {
                    backup_status($chat_id);
                    answerCallbackQuery($query['id'], "Backup status");
                } else {
                    answerCallbackQuery($query['id'], "Admin only command!", true);
                }
            }
            elseif ($data === 'run_backup') {
                if ($chat_id == ADMIN_ID) {
                    manual_backup($chat_id);
                    answerCallbackQuery($query['id'], "Backup started");
                } else {
                    answerCallbackQuery($query['id'], "Admin only command!", true);
                }
            }
            elseif ($data === 'help_command') {
                $command = '/help';
                $params = [];
                handle_command($chat_id, $user_id, $command, $params);
                answerCallbackQuery($query['id'], "Help menu");
            }
            elseif ($data === 'refresh_stats') {
                show_user_stats($chat_id, $user_id);
                answerCallbackQuery($query['id'], "Refreshed");
            }
            elseif ($data === 'download_latest') {
                $all = get_all_movies_list();
                $latest = array_slice($all, -10);
                $latest = array_reverse($latest);
                batch_download_with_progress($chat_id, $latest, "latest");
                answerCallbackQuery($query['id'], "Latest movies info sent");
            }
            elseif ($data === 'browse_all') {
                totalupload_controller($chat_id, 1);
                answerCallbackQuery($query['id'], "Browse all movies");
            }
            elseif (strpos($data, 'download_quality_') === 0) {
                $quality = str_replace('download_quality_', '', $data);
                $all = get_all_movies_list();
                $filtered = [];
                foreach ($all as $movie) {
                    if (stripos($movie['quality'] ?? '', $quality) !== false) {
                        $filtered[] = $movie;
                    }
                }
                batch_download_with_progress($chat_id, $filtered, $quality . " quality");
                answerCallbackQuery($query['id'], "$quality movies info sent");
            }
            elseif (strpos($data, 'download_lang_') === 0) {
                $language = str_replace('download_lang_', '', $data);
                $all = get_all_movies_list();
                $filtered = [];
                foreach ($all as $movie) {
                    if (stripos($movie['language'] ?? '', $language) !== false) {
                        $filtered[] = $movie;
                    }
                }
                batch_download_with_progress($chat_id, $filtered, $language . " language");
                answerCallbackQuery($query['id'], "$language movies info sent");
            }
            else {
                sendMessage($chat_id, "❌ Movie not found: " . $data . "\n\nTry searching with exact name!");
                answerCallbackQuery($query['id'], "❌ Movie not available");
            }
        }
    }

    // Run auto-indexing periodically (every ~INDEX_CHECK_INTERVAL seconds)
    $last_index_run = get_cached_value('last_index_run', 0);
    if (AUTO_INDEX_ENABLED && (time() - $last_index_run) >= INDEX_CHECK_INTERVAL) {
        auto_index_new_posts();
        set_cached_value('last_index_run', time());
    }

    $current_hour = date('H');
    $current_minute = date('i');

    if ($current_hour == AUTO_BACKUP_HOUR && $current_minute == '00') {
        auto_backup();
        bot_log("Daily auto-backup completed");
    }

    if ($current_minute == '30') {
        global $movie_cache;
        $movie_cache = [];
        bot_log("Hourly cache cleanup");
    }
}

// Simple cache helper functions
function get_cached_value($key, $default = null) {
    $cache_file = 'cache_' . $key . '.json';
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        if ($data && isset($data['value']) && isset($data['expires']) && $data['expires'] > time()) {
            return $data['value'];
        }
    }
    return $default;
}

function set_cached_value($key, $value, $ttl = 300) {
    $cache_file = 'cache_' . $key . '.json';
    $data = [
        'value' => $value,
        'expires' => time() + $ttl
    ];
    file_put_contents($cache_file, json_encode($data));
}

// ==============================
// MANUAL TESTING FUNCTIONS
// ==============================
if (isset($_GET['test_save'])) {
    function manual_save_to_csv($movie_name, $message_id, $channel_id, $quality = '1080p', $language = 'Hindi') {
        $entry = [$movie_name, $message_id, date('d-m-Y'), $channel_id, $quality, '1.5GB', $language];
        $handle = fopen(CSV_FILE, "a");
        if ($handle !== FALSE) {
            fputcsv($handle, $entry);
            fclose($handle);
            @chmod(CSV_FILE, 0666);
            return true;
        }
        return false;
    }
    
    manual_save_to_csv("Mandala Murders 2025", 1001, MAIN_CHANNEL_ID, "1080p", "Hindi");
    manual_save_to_csv("Zebra 2024", 1002, MAIN_CHANNEL_ID, "1080p", "Hindi");
    manual_save_to_csv("Now You See Me All Parts", 1003, SERIAL_CHANNEL_ID, "1080p", "English");
    manual_save_to_csv("Squid Game All Seasons", 1004, SERIAL_CHANNEL_ID, "1080p", "English");
    manual_save_to_csv("Show Time (2024)", 1005, MAIN_CHANNEL_ID, "720p", "Hindi");
    manual_save_to_csv("Taskaree S01 (2025)", 1006, SERIAL_CHANNEL_ID, "1080p", "Hindi");
    
    echo "✅ Movies manually saved!<br>";
    exit;
}

if (isset($_GET['check_csv'])) {
    echo "<h3>CSV Content:</h3>";
    if (file_exists(CSV_FILE)) {
        $lines = file(CSV_FILE);
        foreach ($lines as $line) {
            echo htmlspecialchars($line) . "<br>";
        }
    } else {
        echo "❌ CSV file not found!";
    }
    exit;
}

if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
    }
    
    exit;
}

if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $indexing_log = json_decode(file_get_contents(INDEXING_LOG), true);
    $forward_settings = json_decode(file_get_contents(FORWARD_SETTINGS_FILE), true);
    
    echo "<h1>🎬 Entertainment Tadka Bot v2.1.0</h1>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Auto-Indexed:</strong> " . ($indexing_log['total_indexed'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<p><strong>Pending Requests:</strong> " . count($requests_data['requests'] ?? []) . "</p>";
    
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<p><a href='?test_save=1'>Test Movie Save</a></p>";
    echo "<p><a href='?check_csv=1'>Check CSV Data</a></p>";
    
    echo "<h3>📋 New Features in v2.1.0</h3>";
    echo "<ul>";
    echo "<li><code>/pending_request</code> - Check pending movie requests</li>";
    echo "<li><code>/bulk_approve [count]</code> - Bulk approve requests</li>";
    echo "<li><code>/bulk_reject [count]</code> - Bulk reject requests</li>";
    echo "<li><code>/forward_settings</code> - Toggle forward header for channels</li>";
    echo "<li>Public channels: Forward header ON (shows original source)</li>";
    echo "<li>Private channels: Forward header OFF (hides source)</li>";
    echo "</ul>";
    
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/help</code> - All commands</li>";
    echo "<li><code>/search movie</code> - Search movies</li>";
    echo "<li><code>/totalupload</code> - Browse all movies</li>";
    echo "<li><code>/theater</code> - Theater prints only</li>";
    echo "<li><code>/request movie</code> - Request movie</li>";
    echo "<li><code>/mystats</code> - User statistics</li>";
    echo "<li><code>/channel</code> - Join channels</li>";
    echo "<li><code>/checkdate</code> - Upload statistics</li>";
    echo "<li><code>/stats</code> - Admin statistics</li>";
    echo "<li><code>/admin</code> - Open admin panel (Admin only)</li>";
    echo "<li><code>/pending_request</code> - View pending requests (Admin only)</li>";
    echo "<li><code>/bulk_approve 10</code> - Bulk approve (Admin only)</li>";
    echo "<li><code>/bulk_reject 5</code> - Bulk reject (Admin only)</li>";
    echo "<li><code>/forward_settings</code> - Toggle forward headers (Admin only)</li>";
    echo "<li><code>/fullscan</code> - Full channel scan (Admin only)</li>";
    echo "</ul>";
    
    echo "<h3>🔥 Channels:</h3>";
    echo "<ul>";
    echo "<li>🍿 Main: @EntertainmentTadka786</li>";
    echo "<li>📥 Request: @EntertainmentTadka7860</li>";
    echo "<li>🎭 Theater: @threater_print_movies</li>";
    echo "<li>📂 Backup: @ETBackup</li>";
    echo "<li>📺 Serial: @Entertainment_Tadka_Serial_786</li>";
    echo "</ul>";
    
    echo "<h3>🔐 Forward Header Settings:</h3>";
    echo "<ul>";
    echo "<li>Public Channels: " . (($forward_settings['public_channels'][MAIN_CHANNEL_ID]['forward_header'] ?? true) ? "✅ ON" : "❌ OFF") . "</li>";
    echo "<li>Private Channels: " . (($forward_settings['private_channels'][PRIVATE_CHANNEL_1_ID]['forward_header'] ?? false) ? "✅ ON" : "❌ OFF") . "</li>";
    echo "</ul>";
    
    echo "<h3>🤖 Auto-Indexing Status:</h3>";
    echo "<ul>";
    echo "<li>Status: " . (AUTO_INDEX_ENABLED ? "✅ ENABLED" : "❌ DISABLED") . "</li>";
    echo "<li>Check Interval: " . INDEX_CHECK_INTERVAL . " seconds</li>";
    echo "<li>Last Run: " . (get_cached_value('last_index_run') ? date('Y-m-d H:i:s', get_cached_value('last_index_run')) : 'Never') . "</li>";
    echo "</ul>";
}
?>
